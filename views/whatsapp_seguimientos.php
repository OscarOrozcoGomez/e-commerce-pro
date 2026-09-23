<?php
declare(strict_types=1);

require_once __DIR__ . '/../core/config.php';
require_once __DIR__ . '/../core/auth.php';
require_once __DIR__ . '/../core/ai_assistant.php';
require_once __DIR__ . '/../core/whatsapp_contactos_utils.php';

requireAuth();
// Igual que Contactos de WhatsApp: trae PII de clientes, mismo permiso.
if (!hasPermission('ver_conversaciones_whatsapp') && !isAdmin()) {
    header('Location: ' . BASE_URL . 'views/dashboard.php');
    exit;
}

$pageTitle = 'Seguimientos de WhatsApp del mes';
$pdo = getPDO();
$wcDescifra = static fn($valor): string => waDescifrarPii($valor === null ? null : (string) $valor);

[$mes, $desde, $hasta] = waSeguimientosRangoMes($_GET['mes'] ?? null);
$incluirRespondieron = !empty($_GET['todos']);

$todos = [];
$errorConsulta = '';
try {
    $todos = waSeguimientosDelMes($pdo, $desde, $hasta);
} catch (Throwable $e) {
    $errorConsulta = 'No se pudieron leer las conversaciones (¿migracion del asistente pendiente?).';
    error_log('whatsapp_seguimientos.php: ' . $e->getMessage());
}

$sinRespuesta = array_values(array_filter($todos, static fn(array $f): bool => !$f['respondio']));
$noSalieron = count(array_filter($todos, static fn(array $f): bool => !$f['salio']));
$filas = $incluirRespondieron ? $todos : $sinRespuesta;

include __DIR__ . '/includes/header.php';
?>
<div class="container">
    <div style="display:flex; align-items:center; justify-content:space-between; flex-wrap:wrap; gap:10px; margin-top:20px;">
        <h4 style="margin:0;"><i class="material-icons left">checklist</i> Seguimientos del mes</h4>
        <a href="<?php echo esc(BASE_URL); ?>views/whatsapp_contactos.php" class="btn-flat waves-effect"><i class="material-icons left">arrow_back</i> Contactos de WhatsApp</a>
    </div>

    <p class="grey-text" style="margin-top:4px;">Clientes a quienes Alex les mando el seguimiento de 24h. Sirve para revisarlos a fin de mes y cambiarles la etiqueta a mano en WhatsApp (usa el icono verde para abrir el chat). Por defecto solo salen los que <strong>no respondieron</strong> en 48 h.</p>

    <?php if ($errorConsulta !== ''): ?>
        <div class="card-panel orange lighten-4"><?php echo esc($errorConsulta); ?></div>
    <?php endif; ?>

    <div class="row" style="margin-bottom:0;">
        <div class="col s6 m3"><div class="card-panel blue lighten-5 center-align" style="padding:12px;"><div style="font-size:22px; font-weight:700;"><?php echo count($todos); ?></div><div class="grey-text">seguimientos enviados</div></div></div>
        <div class="col s6 m3"><div class="card-panel orange lighten-5 center-align" style="padding:12px;"><div style="font-size:22px; font-weight:700;"><?php echo count($sinRespuesta); ?></div><div class="grey-text">sin respuesta</div></div></div>
        <div class="col s6 m3"><div class="card-panel green lighten-5 center-align" style="padding:12px;"><div style="font-size:22px; font-weight:700;"><?php echo count($todos) - count($sinRespuesta); ?></div><div class="grey-text">respondieron</div></div></div>
        <div class="col s6 m3"><div class="card-panel red lighten-5 center-align" style="padding:12px;"><div style="font-size:22px; font-weight:700;"><?php echo $noSalieron; ?></div><div class="grey-text">no salieron por WhatsApp</div></div></div>
    </div>

    <div class="card">
        <div class="card-content" style="padding-top:12px; padding-bottom:6px;">
            <form method="get" class="row" style="margin-bottom:0;">
                <div class="input-field col s12 m4">
                    <input type="month" id="mes" name="mes" value="<?php echo esc($mes); ?>">
                    <label for="mes" class="active">Mes</label>
                </div>
                <div class="col s12 m4" style="padding-top:18px;">
                    <label><input type="checkbox" name="todos" value="1" <?php echo $incluirRespondieron ? 'checked' : ''; ?>><span>Incluir los que sí respondieron</span></label>
                </div>
                <div class="col s12 m4" style="padding-top:18px;">
                    <button type="submit" class="btn blue darken-4 waves-effect waves-light w-100">Ver</button>
                </div>
            </form>
        </div>
    </div>

    <?php if (empty($filas)): ?>
        <div class="card-panel grey lighten-4">Sin seguimientos para revisar en este mes.</div>
    <?php else: ?>
        <div class="card">
            <ul class="collection" style="margin:0; border:none;">
                <?php foreach ($filas as $f): ?>
                    <?php
                        $idc = (int) $f['id_conversacion'];
                        $nombre = waContactoNombre($f['nombre_perfil'] ?? '', $wcDescifra($f['cliente_nombre_cifrado'] ?? ''));
                        $subtitulo = waContactoSubtitulo((string) $f['wa_id'], $f['telefono_resuelto'] ?? null);
                        $linkPhone = waWhatsAppLinkPhone((string) $f['wa_id'], $f['telefono_resuelto'] ?? null);
                        $tags = aiGetConversationTags($pdo, $idc);
                    ?>
                    <li class="collection-item" style="border:none; border-bottom:1px solid #eee;">
                        <div style="display:flex; justify-content:space-between; align-items:flex-start; flex-wrap:wrap; gap:8px;">
                            <div style="min-width:0;">
                                <a href="<?php echo esc(BASE_URL); ?>views/whatsapp_contactos.php?id=<?php echo $idc; ?>" style="font-weight:600;"><?php echo esc($nombre); ?></a>
                                <div class="grey-text text-darken-1" style="font-size:12px;">
                                    <?php echo esc($subtitulo); ?>
                                    <?php if ($linkPhone !== ''): ?>
                                        <a href="https://wa.me/<?php echo esc($linkPhone); ?>" target="_blank" class="whatsapp-business-link" data-wa-phone="<?php echo esc($linkPhone); ?>" style="margin-left:6px;" title="Abrir WhatsApp"><i class="material-icons tiny green-text text-darken-1" style="vertical-align:middle;">open_in_new</i></a>
                                    <?php endif; ?>
                                </div>
                                <div style="display:flex; flex-wrap:wrap; gap:4px; margin-top:4px;">
                                    <?php foreach ($tags as $tag): ?>
                                        <span class="chip <?php echo esc((string) $tag['color']); ?> lighten-4" style="margin:0; height:22px; line-height:22px; font-size:11px;"><?php echo esc((string) $tag['nombre']); ?></span>
                                    <?php endforeach; ?>
                                </div>
                            </div>
                            <div class="right-align" style="font-size:12px; color:#607d8b; white-space:nowrap;">
                                <div>Seguimiento: <?php echo esc(date('d/m/Y H:i', (int) strtotime((string) $f['enviado_en']))); ?></div>
                                <span class="chip <?php echo $f['respondio'] ? 'green' : 'orange'; ?> lighten-4" style="margin:2px 0 0; height:22px; line-height:22px; font-size:11px;"><?php echo $f['respondio'] ? 'Respondió' : 'Sin respuesta'; ?></span>
                                <?php if (!$f['salio']): ?>
                                    <span class="chip red lighten-4" style="margin:2px 0 0; height:22px; line-height:22px; font-size:11px;">No salió por WhatsApp</span>
                                <?php endif; ?>
                            </div>
                        </div>
                    </li>
                <?php endforeach; ?>
            </ul>
        </div>
    <?php endif; ?>
</div>
<?php include __DIR__ . '/includes/footer.php'; ?>
