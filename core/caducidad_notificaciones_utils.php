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
 * Arma el HTML del correo: encabezado + una tarjeta numerada por cada lote que
 * cambio de severidad, con los datos necesarios para decidir si ponerlo en
 * oferta o retirarlo (producto, lote, caducidad, cantidad, excedente, %
 * descuento sugerido, y si ya no es vendible a tiempo).
 */
function loteBuildNotificacionHtml(array $cambios): string
{
    $filas = '';
    $n = 0;
    foreach ($cambios as $c) {
        $n++;
        $antes = loteSeveridadInfo($c['severidad_anterior'] ?? null);
        $ahora = loteSeveridadInfo($c['severidad'] ?? null);
        $dias = (int) ($c['dias_hasta_caducar'] ?? 0);
        $diasTexto = $dias < 0 ? (abs($dias) . ' días de caducado') : ($dias . ' días restantes');
        $excedente = $c['excedente_proyectado'] ?? null;
        $descuento = (int) ($c['descuento_sugerido_pct'] ?? 0);
        $noVendible = !empty($c['no_vendible']);
        $producto = esc((string) ($c['producto_nombre'] ?? 'Producto'));
        $sku = trim((string) ($c['producto_sku'] ?? ''));
        $codigoLote = esc((string) ($c['codigo_lote'] ?? ''));
        $fecha = esc((string) ($c['fecha_caducidad'] ?? ''));
        $cantidad = (int) ($c['cantidad_restante'] ?? 0);
        $urlEditar = esc(appAbsoluteAssetUrl('views/products.php?id_producto=' . (int) ($c['id_producto'] ?? 0)));

        $badgeAntes = $antes['label'] === 'Ok' && ($c['severidad_anterior'] ?? null) === null
            ? '<span style="color:#90a4ae;">(nuevo)</span>'
            : '<span style="background:' . $antes['color'] . ';color:#fff;border-radius:4px;padding:2px 8px;font-size:11px;">' . esc($antes['label']) . '</span>';
        $badgeAhora = '<span style="background:' . $ahora['color'] . ';color:#fff;border-radius:4px;padding:2px 8px;font-size:11px;">' . esc($ahora['label']) . '</span>';
        $noVendibleHtml = $noVendible
            ? '<div style="margin-top:6px;"><span style="background:#b71c1c;color:#fff;border-radius:4px;padding:2px 8px;font-size:11px;">NO VENDIBLE A TIEMPO</span></div>'
            : '';
        $excedenteHtml = $excedente === null
            ? '<span style="color:#90a4ae;">sin histórico de venta</span>'
            : ($excedente > 0
                ? '<strong style="color:#c62828;">' . (int) $excedente . ' unidades no se venderían a tiempo</strong>' . ($descuento > 0 ? " · oferta sugerida -{$descuento}%" : '')
                : '<span style="color:#2e7d32;">se vendería completo a tiempo</span>');

        $filas .= '
            <div style="padding:14px 16px;border:1px solid #eceff1;border-radius:8px;margin-bottom:12px;">
                <div style="font-size:14px;color:#90a4ae;font-weight:700;margin-bottom:4px;">#' . $n . '</div>
                <div style="font-weight:700;color:#263238;font-size:15px;">' . $producto . ($sku !== '' ? ' <span style="color:#90a4ae;font-weight:400;">(' . esc($sku) . ')</span>' : '') . '</div>
                <div style="color:#546e7a;font-size:13px;margin-top:2px;">Lote <strong>' . $codigoLote . '</strong> · Caduca ' . $fecha . ' (' . $diasTexto . ') · Restante: ' . $cantidad . '</div>
                <div style="margin-top:8px;">' . $badgeAntes . ' <span style="color:#b0bec5;">&rarr;</span> ' . $badgeAhora . '</div>
                <div style="margin-top:8px;font-size:13px;color:#263238;">' . $excedenteHtml . '</div>
                ' . $noVendibleHtml . '
                <div style="margin-top:10px;">
                    <a href="' . $urlEditar . '" style="color:#1a237e;font-size:13px;font-weight:600;text-decoration:none;">Ver / poner en oferta &rarr;</a>
                </div>
            </div>';
    }

    $total = count($cambios);
    $tituloResumen = $total === 1 ? '1 lote cambió de estado' : "{$total} lotes cambiaron de estado";

    return '
    <div style="background:#f4f6f7;padding:24px 12px;font-family:Arial,Helvetica,sans-serif;">
        <div style="max-width:560px;margin:0 auto;background:#ffffff;border-radius:12px;overflow:hidden;box-shadow:0 2px 10px rgba(0,0,0,0.06);">
            <div style="background:#ef6c00;padding:20px 24px;">
                <div style="color:#ffffff;font-size:18px;font-weight:700;">Belleza y Bienestar</div>
                <div style="color:#ffe0b2;font-size:13px;margin-top:2px;">Control de Caducidades &mdash; ' . esc($tituloResumen) . '</div>
            </div>
            <div style="padding:20px 24px;">
                <p style="color:#546e7a;font-size:13px;margin:0 0 16px;">Revisa estos lotes y decide si conviene ponerlos en oferta, venderlos rápido o retirarlos.</p>
                ' . $filas . '
                <div style="margin-top:20px;text-align:center;">
                    <a href="' . esc(appAbsoluteAssetUrl('views/products.php')) . '" style="display:inline-block;background:#ef6c00;color:#ffffff;text-decoration:none;padding:12px 24px;border-radius:8px;font-size:14px;font-weight:600;">Ir a Gestionar Productos</a>
                </div>
            </div>
        </div>
    </div>';
}

/**
 * Orquesta todo: detecta cambios, arma y envia el correo a los destinatarios
 * activos, y (salvo dry-run) marca los lotes como notificados para no
 * repetir el aviso. Nunca lanza excepcion hacia afuera (igual que
 * sendNewOrderNotificationEmails()) para que un cron no truene por un fallo
 * de correo.
 *
 * @param callable|null $mailer  fn(string $correo, string $asunto, string $html): bool
 *   (inyectable para pruebas; por defecto appSendHtmlEmail())
 * @return array{cambios:int, correos_enviados:int}
 */
function loteEnviarNotificacionesDeCambios(PDO $pdo, ?callable $mailer = null, bool $dryRun = false): array
{
    $resultado = ['cambios' => 0, 'correos_enviados' => 0];

    try {
        $cambios = loteDetectarCambiosDeSeveridad($pdo);
        if ($cambios === []) {
            return $resultado;
        }
        $resultado['cambios'] = count($cambios);

        $destinatarios = dbGetCaducidadNotificationEmails($pdo, true);
        if ($destinatarios !== []) {
            $total = count($cambios);
            $asunto = $total === 1 ? '1 lote cambió de estado de caducidad' : "{$total} lotes cambiaron de estado de caducidad";
            $html = loteBuildNotificacionHtml($cambios);
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

        if (!$dryRun) {
            loteMarcarSeveridadesNotificadas($pdo, $cambios);
        }
    } catch (Throwable $e) {
        error_log('WARNING: No fue posible enviar notificaciones de caducidad: ' . $e->getMessage());
    }

    return $resultado;
}
