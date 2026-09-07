<?php
declare(strict_types=1);

require_once __DIR__ . '/../core/config.php';
require_once __DIR__ . '/../core/auth.php';
require_once __DIR__ . '/../core/ai_assistant.php';
require_once __DIR__ . '/../core/whatsapp_contactos_utils.php';

requireAuth();
// Permiso 'ver_conversaciones_whatsapp' abre esta vista; 'gestionar_asistente_ia' y el
// rol admin se mantienen como respaldo para no quitarle acceso a nadie.
if (
    !hasPermission('ver_conversaciones_whatsapp')
    && !hasPermission('gestionar_asistente_ia')
    && !isAdmin()
) {
    header('Location: ' . BASE_URL . 'views/dashboard.php');
    exit;
}

$pageTitle = 'Contactos de WhatsApp';
$pdo = getPDO();

/** Descifra un valor PII si viene cifrado; si no, lo regresa tal cual. */
$wcDescifra = static function ($valor): string {
    $valor = (string) $valor;
    if ($valor !== '' && function_exists('piiIsEncryptedValue') && piiIsEncryptedValue($valor)) {
        return (string) piiDecryptValue($valor);
    }
    return $valor;
};

$modoDetalle = isset($_GET['id']) && (int) $_GET['id'] > 0;

// ---------------------------------------------------------------------------
// MODO DETALLE: hilo completo de una conversacion (solo lectura)
// ---------------------------------------------------------------------------
if ($modoDetalle) {
    $idConversacion = (int) $_GET['id'];
    $info = waConversacionInfo($pdo, $idConversacion);

    if ($info === null) {
        include __DIR__ . '/includes/header.php';
        echo '<div class="container"><div class="card-panel red lighten-4 red-text">Esa conversacion no existe.</div>'
            . '<a href="' . esc(BASE_URL) . 'views/whatsapp_contactos.php" class="btn-flat">Volver</a></div>';
        include __DIR__ . '/includes/footer.php';
        exit;
    }

    $clienteNombrePlano = $wcDescifra($info['cliente_nombre_cifrado'] ?? '');
    $tituloContacto = waContactoNombre($info['nombre_perfil'] ?? '', $clienteNombrePlano);
    $subtituloContacto = waContactoSubtitulo((string) $info['wa_id']);
    $mensajes = waConversacionMensajes($pdo, $idConversacion);
    $tags = aiGetConversationTags($pdo, $idConversacion);

    include __DIR__ . '/includes/header.php';
    ?>
    <div class="container">
        <div style="display:flex; align-items:center; justify-content:space-between; flex-wrap:wrap; gap:10px; margin-top:20px;">
            <h4 style="margin:0;"><i class="material-icons left">forum</i> <?php echo esc($tituloContacto); ?></h4>
            <a href="<?php echo esc(BASE_URL); ?>views/whatsapp_contactos.php" class="btn-flat waves-effect"><i class="material-icons left">arrow_back</i> Volver a la lista</a>
        </div>

        <div class="card">
            <div class="card-content">
                <p style="margin:0 0 6px;"><strong><?php echo esc($subtituloContacto); ?></strong>
                    <span class="chip <?php echo $info['estado_bot'] === 'activo' ? 'green lighten-4' : 'orange lighten-4'; ?>" style="margin-left:8px;"><?php echo esc((string) $info['estado_bot']); ?></span>
                </p>
                <?php if (!empty($info['id_cliente'])): ?>
                    <p style="margin:0 0 6px;"><a href="<?php echo esc(BASE_URL); ?>views/manage_customers.php?id=<?php echo (int) $info['id_cliente']; ?>">Ver ficha del cliente #<?php echo (int) $info['id_cliente']; ?></a></p>
                <?php else: ?>
                    <p class="grey-text" style="margin:0 0 6px;">Sin cliente ligado todavia.</p>
                <?php endif; ?>
                <?php if (!empty($tags)): ?>
                    <div style="display:flex; flex-wrap:wrap; gap:4px; margin-top:6px;">
                        <?php foreach ($tags as $tag): ?>
                            <span class="chip <?php echo esc((string) $tag['color']); ?> lighten-4" style="margin:2px;"><?php echo esc((string) $tag['nombre']); ?></span>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
                <?php if (!empty($info['motivo_transferencia'])): ?>
                    <p class="grey-text text-darken-1" style="font-size:13px; margin:8px 0 0;"><i class="material-icons tiny">info</i> <?php echo esc((string) $info['motivo_transferencia']); ?></p>
                <?php endif; ?>
            </div>
        </div>

        <div class="card">
            <div class="card-content">
                <span class="card-title">Conversacion (<?php echo count($mensajes); ?> mensajes)</span>
                <?php if (empty($mensajes)): ?>
                    <p class="grey-text">Sin mensajes registrados.</p>
                <?php else: ?>
                    <div style="display:flex; flex-direction:column; gap:8px; margin-top:10px;">
                        <?php foreach ($mensajes as $msg): ?>
                            <?php
                                $rol = (string) $msg['rol'];
                                $contenido = trim((string) ($msg['contenido'] ?? ''));
                                if ($rol === 'tool' || $rol === 'system') {
                                    // Turnos internos de function-calling: se resumen, no se ocultan.
                                    $resumen = $contenido !== ''
                                        ? mb_substr($contenido, 0, 160)
                                        : ('[' . waRolEtiqueta($rol) . ($msg['tool_name'] ? ': ' . $msg['tool_name'] : '') . ']');
                                    echo '<div style="text-align:center;"><span class="grey-text" style="font-size:11px;">'
                                        . esc($resumen) . '</span></div>';
                                    continue;
                                }
                                if ($contenido === '') {
                                    continue;
                                }
                                $esCliente = waRolEsCliente($rol);
                                $align = $esCliente ? 'flex-start' : 'flex-end';
                                $bg = $esCliente ? '#eceff1' : ($rol === 'humano' ? '#e1f5fe' : '#e8f5e9');
                            ?>
                            <div style="display:flex; justify-content:<?php echo $align; ?>;">
                                <div style="max-width:78%; background:<?php echo $bg; ?>; border-radius:10px; padding:8px 12px;">
                                    <div style="font-size:11px; color:#607d8b; margin-bottom:2px;">
                                        <?php echo esc(waRolEtiqueta($rol)); ?>
                                        &middot; <?php echo esc(date('d/m/Y H:i', strtotime((string) $msg['creado_en']))); ?>
                                        <?php if (!$esCliente && $rol !== 'humano' && (int) $msg['enviado_whatsapp'] !== 1): ?>
                                            <span class="orange-text text-darken-2" title="No se llego a enviar por WhatsApp">&middot; no enviado</span>
                                        <?php endif; ?>
                                    </div>
                                    <div style="white-space:pre-wrap; font-size:14px; color:#263238;"><?php echo esc($contenido); ?></div>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
    <?php
    include __DIR__ . '/includes/footer.php';
    exit;
}

// ---------------------------------------------------------------------------
// MODO LISTA: contactos agrupados por dia
// ---------------------------------------------------------------------------
[$fDesde, $fHasta] = waContactosRangoFechas($_GET['desde'] ?? null, $_GET['hasta'] ?? null);
$fQ = trim((string) ($_GET['q'] ?? ''));

$filas = [];
$errorConsulta = '';
try {
    $filas = waContactosPorDia($pdo, $fDesde, $fHasta, $fQ);
} catch (Throwable $e) {
    // La tabla puede no existir si la migracion del asistente no se ha aplicado aqui.
    $errorConsulta = 'No se pudieron leer las conversaciones (¿migracion del asistente pendiente?).';
    error_log('whatsapp_contactos.php: ' . $e->getMessage());
}

$porDia = waAgruparPorDia($filas);

// Etiquetas por conversacion (una consulta por conversacion unica).
$tagsPorConversacion = [];
foreach ($filas as $fila) {
    $idc = (int) $fila['id_conversacion'];
    if (!array_key_exists($idc, $tagsPorConversacion)) {
        $tagsPorConversacion[$idc] = aiGetConversationTags($pdo, $idc);
    }
}

$hoyStr = date('Y-m-d');
$convHoy = [];
$convRango = [];
$convConVenta = [];
foreach ($filas as $fila) {
    $idc = (int) $fila['id_conversacion'];
    $convRango[$idc] = true;
    if ((string) $fila['dia'] === $hoyStr) {
        $convHoy[$idc] = true;
    }
    foreach ($tagsPorConversacion[$idc] ?? [] as $tag) {
        if (strcasecmp((string) $tag['nombre'], AI_TAG_PEDIDO_AGENDADO) === 0) {
            $convConVenta[$idc] = true;
        }
    }
}

include __DIR__ . '/includes/header.php';
?>
<div class="container">
    <div style="display:flex; align-items:center; justify-content:space-between; flex-wrap:wrap; gap:10px; margin-top:20px;">
        <h4 style="margin:0;"><i class="material-icons left">chat</i> Contactos de WhatsApp por dia</h4>
        <div>
            <a href="<?php echo esc(BASE_URL); ?>views/ai_assistant_settings.php" class="btn-flat waves-effect">Asistente de IA</a>
            <a href="<?php echo esc(BASE_URL); ?>views/dashboard.php" class="btn blue darken-4 waves-effect waves-light"><i class="material-icons left">dashboard</i> Dashboard</a>
        </div>
    </div>

    <p class="grey-text" style="margin-top:4px;">Quien nos escribio por WhatsApp cada dia. Abre una conversacion para leer el hilo completo y cotejar nombre, direccion o lo que pidio el cliente.</p>

    <?php if ($errorConsulta !== ''): ?>
        <div class="card-panel orange lighten-4"><?php echo esc($errorConsulta); ?></div>
    <?php endif; ?>

    <div class="row" style="margin-bottom:0;">
        <div class="col s6 m3"><div class="card-panel teal lighten-5 center-align" style="padding:12px;"><div style="font-size:22px; font-weight:700;"><?php echo count($convHoy); ?></div><div class="grey-text">contactos hoy</div></div></div>
        <div class="col s6 m3"><div class="card-panel blue lighten-5 center-align" style="padding:12px;"><div style="font-size:22px; font-weight:700;"><?php echo count($convRango); ?></div><div class="grey-text">en el rango</div></div></div>
        <div class="col s6 m3"><div class="card-panel green lighten-5 center-align" style="padding:12px;"><div style="font-size:22px; font-weight:700;"><?php echo count($convConVenta); ?></div><div class="grey-text">con pedido agendado</div></div></div>
        <div class="col s6 m3"><div class="card-panel grey lighten-4 center-align" style="padding:12px;"><div style="font-size:22px; font-weight:700;"><?php echo count($filas); ?></div><div class="grey-text">dias-contacto</div></div></div>
    </div>

    <div class="card">
        <div class="card-content" style="padding-top:12px; padding-bottom:0;">
            <form method="get" class="row" style="margin-bottom:0;">
                <div class="input-field col s12 m3">
                    <input type="date" id="desde" name="desde" value="<?php echo esc($fDesde); ?>">
                    <label for="desde" class="active">Desde</label>
                </div>
                <div class="input-field col s12 m3">
                    <input type="date" id="hasta" name="hasta" value="<?php echo esc($fHasta); ?>">
                    <label for="hasta" class="active">Hasta</label>
                </div>
                <div class="input-field col s12 m4">
                    <input type="text" id="q" name="q" value="<?php echo esc($fQ); ?>" placeholder="nombre o numero">
                    <label for="q" class="active">Buscar</label>
                </div>
                <div class="col s12 m2" style="padding-top:18px;">
                    <button type="submit" class="btn blue darken-4 waves-effect waves-light w-100">Filtrar</button>
                </div>
            </form>
            <?php if ($fQ !== '' || isset($_GET['desde']) || isset($_GET['hasta'])): ?>
                <a href="<?php echo esc(BASE_URL); ?>views/whatsapp_contactos.php" class="btn-flat" style="margin-bottom:10px;">Limpiar filtros</a>
            <?php endif; ?>
        </div>
    </div>

    <?php if (empty($porDia)): ?>
        <div class="card-panel grey lighten-4">Sin contactos en este rango.</div>
    <?php else: ?>
        <?php foreach ($porDia as $dia => $filasDia): ?>
            <?php
                $ts = strtotime($dia);
                $etiquetaDia = $dia === $hoyStr ? 'Hoy' : ($dia === date('Y-m-d', strtotime('-1 day')) ? 'Ayer' : '');
                $mesesDiaTxt = date('d/m/Y', $ts);
            ?>
            <h6 style="margin:22px 0 8px; color:#37474f;">
                <i class="material-icons tiny">event</i>
                <?php echo esc($mesesDiaTxt); ?>
                <?php if ($etiquetaDia !== ''): ?><span class="chip teal lighten-4" style="margin-left:6px;"><?php echo esc($etiquetaDia); ?></span><?php endif; ?>
                <span class="grey-text">&middot; <?php echo count($filasDia); ?></span>
            </h6>
            <div class="card">
                <ul class="collection" style="margin:0; border:none;">
                    <?php foreach ($filasDia as $fila): ?>
                        <?php
                            $idc = (int) $fila['id_conversacion'];
                            $clienteNombrePlano = $wcDescifra($fila['cliente_nombre_cifrado'] ?? '');
                            $nombre = waContactoNombre($fila['nombre_perfil'] ?? '', $clienteNombrePlano);
                            $subtitulo = waContactoSubtitulo((string) $fila['wa_id']);
                            $desdeHora = date('H:i', strtotime((string) $fila['primer_mensaje']));
                            $hastaHora = date('H:i', strtotime((string) $fila['ultimo_mensaje']));
                            $rangoHoras = $desdeHora === $hastaHora ? $desdeHora : ($desdeHora . '–' . $hastaHora);
                            $tags = $tagsPorConversacion[$idc] ?? [];
                        ?>
                        <li class="collection-item" style="border:none; border-bottom:1px solid #eee;">
                            <div style="display:flex; justify-content:space-between; align-items:flex-start; flex-wrap:wrap; gap:8px;">
                                <div style="min-width:0;">
                                    <a href="<?php echo esc(BASE_URL); ?>views/whatsapp_contactos.php?id=<?php echo $idc; ?>" style="font-weight:600;"><?php echo esc($nombre); ?></a>
                                    <div class="grey-text text-darken-1" style="font-size:12px;"><?php echo esc($subtitulo); ?></div>
                                    <div style="display:flex; flex-wrap:wrap; gap:4px; margin-top:4px;">
                                        <?php foreach ($tags as $tag): ?>
                                            <span class="chip <?php echo esc((string) $tag['color']); ?> lighten-4" style="margin:0; height:22px; line-height:22px; font-size:11px;"><?php echo esc((string) $tag['nombre']); ?></span>
                                        <?php endforeach; ?>
                                    </div>
                                </div>
                                <div class="right-align" style="font-size:12px; color:#607d8b; white-space:nowrap;">
                                    <div><?php echo esc($rangoHoras); ?></div>
                                    <div><?php echo (int) $fila['mensajes_cliente']; ?> msj. del cliente</div>
                                    <span class="chip <?php echo $fila['estado_bot'] === 'activo' ? 'green lighten-4' : 'orange lighten-4'; ?>" style="margin:2px 0 0; height:22px; line-height:22px; font-size:11px;"><?php echo esc((string) $fila['estado_bot']); ?></span>
                                </div>
                            </div>
                        </li>
                    <?php endforeach; ?>
                </ul>
            </div>
        <?php endforeach; ?>
    <?php endif; ?>
</div>
<?php include __DIR__ . '/includes/footer.php'; ?>
