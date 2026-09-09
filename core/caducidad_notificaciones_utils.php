<?php
declare(strict_types=1);

/**
 * Notificaciones por correo cuando un lote cambia de severidad de caducidad.
 *
 * No hay un evento discreto que dispare esto (a diferencia de "se creo un
 * pedido"): la severidad de un lote cambia solo porque el tiempo avanza o
 * porque alguien ajusta su cantidad/fecha. Por eso el disparador es un cron
 * (scripts/caducidades_notificacion_cron.php) que compara la severidad actual
 * (calculada por loteFetchProyecciones()) contra la ultima que se notifico
 * (columna lotes_inventario.ultima_severidad_notificada) y solo avisa de los
 * que de verdad cambiaron -- asi no se manda el mismo correo cada vez que corre.
 *
 * Reutiliza el mecanismo de correo ya existente para "Notificaciones de
 * Pedidos": misma tabla-patron de destinatarios (dbGet/Add/Set/Delete...
 * CaducidadNotificationEmail en core/auth.php) y el mismo appSendHtmlEmail().
 */

/**
 * Compara la severidad actual de cada lote visible contra la ultima notificada.
 *
 * @return array<int,array<string,mixed>>  filas de loteFetchProyecciones() que
 *   cambiaron, con 'severidad_anterior' agregado (string|null)
 */
function loteDetectarCambiosDeSeveridad(PDO $pdo): array
{
    $cambios = [];
    foreach (loteFetchProyecciones($pdo)['lotes'] as $lote) {
        $anterior = $lote['ultima_severidad_notificada'] ?? null;
        $anterior = ($anterior === null || $anterior === '') ? null : (string) $anterior;
        $actual = (string) $lote['severidad'];

        if ($anterior !== $actual) {
            $lote['severidad_anterior'] = $anterior;
            $cambios[] = $lote;
        }
    }

    return $cambios;
}

/**
 * Registra la severidad recien notificada de cada lote, para no repetir el
 * mismo aviso en la siguiente corrida del cron.
 */
function loteMarcarSeveridadesNotificadas(PDO $pdo, array $cambios): void
{
    if ($cambios === []) {
        return;
    }
    $stmt = $pdo->prepare('UPDATE lotes_inventario SET ultima_severidad_notificada = :sev WHERE id_lote = :id');
    foreach ($cambios as $c) {
        $stmt->execute([':sev' => (string) $c['severidad'], ':id' => (int) $c['id_lote']]);
    }
}

/**
 * Sella la severidad ACTUAL de todos los lotes visibles sin enviar nada. Se corre
 * una sola vez al activar el feature (scripts/caducidades_notificacion_cron.php
 * --sellar-inicial) para no recibir un correo con decenas de lotes de golpe en la
 * primera corrida: a partir de ahí el cron solo avisa de los que CAMBIEN.
 *
 * @return int cuántos lotes se sellaron
 */
function loteSellarSeveridadesActuales(PDO $pdo): int
{
    $lotes = loteFetchProyecciones($pdo)['lotes'];
    loteMarcarSeveridadesNotificadas($pdo, $lotes);
    return count($lotes);
}

/**
 * Mapa de severidad a {label, color} para pintar el correo.
 *
 * @return array{label:string,color:string}
 */
function loteSeveridadInfo(?string $severidad): array
{
    $mapa = [
        'critico'       => ['label' => 'Crítico',       'color' => '#c62828'],
        'urgente'       => ['label' => 'Urgente',        'color' => '#e65100'],
        'planificar'    => ['label' => 'Planificar',     'color' => '#ff8f00'],
        'vigilar'       => ['label' => 'Vigilar',        'color' => '#546e7a'],
        'caducado'      => ['label' => 'Caducado',       'color' => '#212121'],
        'sin_rotacion'  => ['label' => 'Sin rotación',   'color' => '#757575'],
        'sin_historico' => ['label' => 'Sin histórico',  'color' => '#9e9e9e'],
        'ok'            => ['label' => 'Ok',             'color' => '#2e7d32'],
    ];

    return $mapa[(string) $severidad] ?? ['label' => (string) $severidad, 'color' => '#607d8b'];
}

/**
 * Severidades que exigen acción inmediata ("sacar a la de ya").
 */
const LOTE_SEVERIDADES_QUE_URGEN = ['caducado', 'critico', 'urgente'];

/**
 * Lotes que HOY hay que liquidar cuanto antes: severidad caducado/critico/urgente
 * o marcados no_vendible. Vienen ordenados por días efectivos (loteFetchProyecciones).
 *
 * @param int[] $excluirIdLote  ids que no se repiten (p.ej. los que ya van en la
 *   sección de "cambios" del mismo correo)
 * @return array<int,array<string,mixed>>
 */
function loteLotesParaSacarYa(PDO $pdo, array $excluirIdLote = []): array
{
    $excluir = array_fill_keys(array_map('intval', $excluirIdLote), true);
    $out = [];
    foreach (loteFetchProyecciones($pdo)['lotes'] as $lote) {
        if (isset($excluir[(int) ($lote['id_lote'] ?? 0)])) {
            continue;
        }
        $urge = in_array((string) $lote['severidad'], LOTE_SEVERIDADES_QUE_URGEN, true)
            || !empty($lote['no_vendible']);
        if ($urge) {
            $out[] = $lote;
        }
    }

    return $out;
}

/**
 * Tarjeta HTML de un lote para el correo. Con $conTransicion pinta el cambio
 * "antes → ahora" (sección de cambios); sin él, solo la severidad actual
 * (sección "para sacar ya").
 */
function loteTarjetaLoteHtml(array $c, int $n, bool $conTransicion): string
{
    $dias = (int) ($c['dias_hasta_caducar'] ?? 0);
    $diasEfectivos = isset($c['dias_efectivos_venta']) ? (int) $c['dias_efectivos_venta'] : $dias;
    $trat = isset($c['dias_tratamiento_envase']) && $c['dias_tratamiento_envase'] !== null
        ? (int) $c['dias_tratamiento_envase'] : null;
    if ($dias < 0) {
        $diasTexto = abs($dias) . ' días de caducado';
    } elseif ($trat !== null && $diasEfectivos !== $dias) {
        $diasTexto = 'quedan ~' . max(0, $diasEfectivos) . ' días para colocarlo (caduca en ' . $dias
            . ', el envase rinde ' . $trat . ')';
    } else {
        $diasTexto = $dias . ' días restantes';
    }
    $excedente = $c['excedente_proyectado'] ?? null;
    $descuento = (int) ($c['descuento_sugerido_pct'] ?? 0);
    $noVendible = !empty($c['no_vendible']);
    $producto = esc((string) ($c['producto_nombre'] ?? 'Producto'));
    $sku = trim((string) ($c['producto_sku'] ?? ''));
    $codigoLote = esc((string) ($c['codigo_lote'] ?? ''));
    $fecha = esc((string) ($c['fecha_caducidad'] ?? ''));
    $cantidad = (int) ($c['cantidad_restante'] ?? 0);
    $urlEditar = esc(appAbsoluteAssetUrl('views/products.php?id_producto=' . (int) ($c['id_producto'] ?? 0)));

    $ahora = loteSeveridadInfo($c['severidad'] ?? null);
    $badgeAhora = '<span style="background:' . $ahora['color'] . ';color:#fff;border-radius:4px;padding:2px 8px;font-size:11px;">' . esc($ahora['label']) . '</span>';
    if ($conTransicion) {
        $antes = loteSeveridadInfo($c['severidad_anterior'] ?? null);
        $badgeAntes = $antes['label'] === 'Ok' && ($c['severidad_anterior'] ?? null) === null
            ? '<span style="color:#90a4ae;">(nuevo)</span>'
            : '<span style="background:' . $antes['color'] . ';color:#fff;border-radius:4px;padding:2px 8px;font-size:11px;">' . esc($antes['label']) . '</span>';
        $badgeHtml = $badgeAntes . ' <span style="color:#b0bec5;">&rarr;</span> ' . $badgeAhora;
    } else {
        $badgeHtml = $badgeAhora;
    }

    $noVendibleHtml = $noVendible
        ? '<div style="margin-top:6px;"><span style="background:#b71c1c;color:#fff;border-radius:4px;padding:2px 8px;font-size:11px;">NO VENDIBLE A TIEMPO</span></div>'
        : '';
    $excedenteHtml = $excedente === null
        ? '<span style="color:#90a4ae;">sin histórico de venta</span>'
        : ($excedente > 0
            ? '<strong style="color:#c62828;">' . (int) $excedente . ' unidades no se venderían a tiempo</strong>' . ($descuento > 0 ? " · oferta sugerida -{$descuento}%" : '')
            : '<span style="color:#2e7d32;">se vendería completo a tiempo</span>');

    return '
        <div style="padding:14px 16px;border:1px solid #eceff1;border-radius:8px;margin-bottom:12px;">
            <div style="font-size:14px;color:#90a4ae;font-weight:700;margin-bottom:4px;">#' . $n . '</div>
            <div style="font-weight:700;color:#263238;font-size:15px;">' . $producto . ($sku !== '' ? ' <span style="color:#90a4ae;font-weight:400;">(' . esc($sku) . ')</span>' : '') . '</div>
            <div style="color:#546e7a;font-size:13px;margin-top:2px;">Lote <strong>' . $codigoLote . '</strong> · Caduca ' . $fecha . ' (' . $diasTexto . ') · Restante: ' . $cantidad . '</div>
            <div style="margin-top:8px;">' . $badgeHtml . '</div>
            <div style="margin-top:8px;font-size:13px;color:#263238;">' . $excedenteHtml . '</div>
            ' . $noVendibleHtml . '
            <div style="margin-top:10px;">
                <a href="' . $urlEditar . '" style="color:#1a237e;font-size:13px;font-weight:600;text-decoration:none;">Ver / poner en oferta &rarr;</a>
            </div>
        </div>';
}

/**
 * Arma el HTML del correo. Dos secciones:
 *  1. "Cambios de severidad": los lotes que cambiaron desde la última corrida
 *     (con transición antes → ahora).
 *  2. "Para sacar cuanto antes": TODOS los lotes que hoy están en
 *     caducado/crítico/urgente o no_vendible (aunque no hayan cambiado), para
 *     tener la lista de liquidación completa en el mismo correo. Ya viene sin los
 *     que van en la sección 1.
 *
 * @param array<int,array<string,mixed>> $cambios
 * @param array<int,array<string,mixed>> $sacarYa
 * @param int $omitidosCambios  cambios que no caben (tope LOTE_NOTIF_MAX_TARJETAS)
 * @param int $omitidosSacarYa  ídem para la sección de liquidación
 */
function loteBuildNotificacionHtml(array $cambios, array $sacarYa = [], int $omitidosCambios = 0, int $omitidosSacarYa = 0): string
{
    $masHtml = static function (int $omitidos): string {
        return $omitidos > 0
            ? '<p style="color:#90a4ae;font-size:12px;margin:4px 0 0;">… y ' . $omitidos
                . ' lote' . ($omitidos === 1 ? '' : 's') . ' más. Abre "Gestionar Productos" para la lista completa.</p>'
            : '';
    };

    $filasCambios = '';
    $n = 0;
    foreach ($cambios as $c) {
        $filasCambios .= loteTarjetaLoteHtml($c, ++$n, true);
    }

    // Cuántos de los cambios de arriba ya cuentan como "sacar ya" (para no repetir
    // sus tarjetas pero sí sumarlos en el total).
    $urgenEnCambios = count(array_filter($cambios, static fn ($c) =>
        in_array((string) ($c['severidad'] ?? ''), LOTE_SEVERIDADES_QUE_URGEN, true) || !empty($c['no_vendible'])));

    $seccionSacarYa = '';
    $totalUrge = $urgenEnCambios + count($sacarYa) + max(0, $omitidosSacarYa);
    if ($totalUrge > 0) {
        $filasSacar = '';
        $m = 0;
        foreach ($sacarYa as $c) {
            $filasSacar .= loteTarjetaLoteHtml($c, ++$m, false);
        }
        $nota = $urgenEnCambios > 0
            ? ($urgenEnCambios === 1
                ? '1 de los de arriba ya está en esta lista (además cambió de estado).'
                : $urgenEnCambios . ' de los de arriba ya están en esta lista (además cambiaron de estado).')
            : 'Todos los lotes que hoy urge liquidar.';
        $seccionSacarYa = '
                <div style="margin:22px 0 10px;border-top:2px solid #ffe0b2;padding-top:14px;">
                    <div style="font-weight:700;color:#c62828;font-size:15px;">Para sacar cuanto antes (' . $totalUrge . ')</div>
                    <p style="color:#90a4ae;font-size:12px;margin:2px 0 12px;">' . esc($nota) . '</p>
                </div>
                ' . $filasSacar . '
                ' . $masHtml($omitidosSacarYa);
    }

    $totalCambios = count($cambios) + max(0, $omitidosCambios);
    $tituloResumen = $totalCambios === 1 ? '1 lote cambió de estado' : "{$totalCambios} lotes cambiaron de estado";

    return '
    <div style="background:#f4f6f7;padding:24px 12px;font-family:Arial,Helvetica,sans-serif;">
        <div style="max-width:560px;margin:0 auto;background:#ffffff;border-radius:12px;overflow:hidden;box-shadow:0 2px 10px rgba(0,0,0,0.06);">
            <div style="background:#ef6c00;padding:20px 24px;">
                <div style="color:#ffffff;font-size:18px;font-weight:700;">Belleza y Bienestar</div>
                <div style="color:#ffe0b2;font-size:13px;margin-top:2px;">Control de Caducidades &mdash; ' . esc($tituloResumen) . '</div>
            </div>
            <div style="padding:20px 24px;">
                <p style="color:#546e7a;font-size:13px;margin:0 0 16px;">Revisa estos lotes y decide si conviene ponerlos en oferta, venderlos rápido o retirarlos.</p>
                ' . $filasCambios . '
                ' . $masHtml($omitidosCambios) . '
                ' . $seccionSacarYa . '
                <div style="margin-top:20px;text-align:center;">
                    <a href="' . esc(appAbsoluteAssetUrl('views/products.php')) . '" style="display:inline-block;background:#ef6c00;color:#ffffff;text-decoration:none;padding:12px 24px;border-radius:8px;font-size:14px;font-weight:600;">Ir a Gestionar Productos</a>
                </div>
            </div>
        </div>
    </div>';
}

if (!defined('LOTE_NOTIF_MAX_TARJETAS')) {
    // Tope de lotes detallados en un solo correo. Si cambian más a la vez (p.ej.
    // una primera corrida sin sellar, o muchas caducidades juntas) el correo
    // lista los N más urgentes y "… y X más" en vez de un muro de decenas.
    define('LOTE_NOTIF_MAX_TARJETAS', 30);
}

/**
 * Orquesta todo: detecta cambios, arma y envia el correo a los destinatarios
 * activos, y (salvo dry-run) marca los lotes como notificados para no
 * repetir el aviso. Nunca lanza excepcion hacia afuera (igual que
 * sendNewOrderNotificationEmails()) para que un cron no truene por un fallo
 * de correo.
 *
 * dry-run: NO llama al mailer y NO marca nada; solo cuenta los cambios
 * detectados (para revisar qué haría el cron sin efectos secundarios).
 *
 * @param callable|null $mailer  fn(string $correo, string $asunto, string $html): bool
 *   (inyectable para pruebas; por defecto appSendHtmlEmail())
 * @return array{cambios:int, correos_enviados:int, sacar_ya:int}
 */
function loteEnviarNotificacionesDeCambios(PDO $pdo, ?callable $mailer = null, bool $dryRun = false): array
{
    $resultado = ['cambios' => 0, 'correos_enviados' => 0, 'sacar_ya' => 0];

    try {
        $cambios = loteDetectarCambiosDeSeveridad($pdo);
        if ($cambios === []) {
            return $resultado;
        }
        $resultado['cambios'] = count($cambios);

        // Lista completa de liquidación (crítico/urgente/caducado/no_vendible),
        // sin los que ya van en la sección de cambios.
        $sacarYa = loteLotesParaSacarYa($pdo, array_column($cambios, 'id_lote'));
        $resultado['sacar_ya'] = count($sacarYa);

        if ($dryRun) {
            // Sin efectos: ni mailer ni marcado. Solo los conteos de arriba.
            return $resultado;
        }

        $destinatarios = dbGetCaducidadNotificationEmails($pdo, true);
        if ($destinatarios !== []) {
            $total = count($cambios);
            // Ya vienen ordenados por urgencia (loteFetchProyecciones); si son
            // muchos, se detallan solo los primeros y el resto va como "… y X más".
            $detallados = array_slice($cambios, 0, LOTE_NOTIF_MAX_TARJETAS);
            $omitidosCambios = max(0, $total - count($detallados));

            $sacarDetalle = array_slice($sacarYa, 0, LOTE_NOTIF_MAX_TARJETAS);
            $omitidosSacar = max(0, count($sacarYa) - count($sacarDetalle));

            $urgenEnCambios = count(array_filter($cambios, static fn ($c) =>
                in_array((string) ($c['severidad'] ?? ''), LOTE_SEVERIDADES_QUE_URGEN, true) || !empty($c['no_vendible'])));
            $urgenTotal = $urgenEnCambios + count($sacarYa);
            $asunto = $total === 1 ? '1 lote cambió de estado de caducidad' : "{$total} lotes cambiaron de estado de caducidad";
            if ($urgenTotal > 0) {
                $asunto .= " · {$urgenTotal} para sacar ya";
            }
            $html = loteBuildNotificacionHtml($detallados, $sacarDetalle, $omitidosCambios, $omitidosSacar);
            $enviar = $mailer ?? 'appSendHtmlEmail';

            foreach ($destinatarios as $correo) {
                if (!filter_var($correo, FILTER_VALIDATE_EMAIL)) {
                    continue;
                }
                if ($enviar($correo, $asunto, $html)) {
                    $resultado['correos_enviados']++;
                }
            }
        }

        loteMarcarSeveridadesNotificadas($pdo, $cambios);
    } catch (Throwable $e) {
        error_log('WARNING: No fue posible enviar notificaciones de caducidad: ' . $e->getMessage());
    }

    return $resultado;
}
