<?php
declare(strict_types=1);

require_once __DIR__ . '/../core/config.php';
require_once __DIR__ . '/../core/auth.php';

requireAuth();
if (!isAdmin()) {
    header('Location: ' . BASE_URL . 'views/dashboard.php');
    exit;
}

$pageTitle = 'Salud del sistema';
$pdo = getPDO();

/** Ejecuta una consulta y nunca rompe la página: devuelve [] si algo falla. */
function saludQuery(PDO $pdo, string $sql, array $params = []): array
{
    try {
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } catch (Throwable $e) {
        error_log('salud_sistema: ' . $e->getMessage());
        return [];
    }
}

// 1) Cuentas bloqueadas ahora mismo.
$cuentasBloqueadas = saludQuery($pdo,
    "SELECT u.nombre, u.email, u.intentos_fallidos, u.bloqueado_hasta, r.nombre AS rol
     FROM usuarios u JOIN roles r ON r.id_rol = u.id_rol
     WHERE u.bloqueado_hasta IS NOT NULL AND u.bloqueado_hasta > NOW()
     ORDER BY u.bloqueado_hasta DESC");

// 2) Bloqueos de cuenta registrados en auditoría (últimos 10).
$bloqueosRecientes = saludQuery($pdo,
    "SELECT la.fecha, la.id_registro, la.detalles, u.nombre, u.email
     FROM logs_auditoria la
     LEFT JOIN usuarios u ON u.id_usuario = la.id_registro
     WHERE la.accion = 'BLOQUEO_CUENTA'
     ORDER BY la.id_log DESC LIMIT 10");

// 3) Inicios de sesión correctos (últimos 8), como pulso de actividad.
$loginsRecientes = saludQuery($pdo,
    "SELECT la.fecha, u.nombre, u.email, r.nombre AS rol
     FROM logs_auditoria la
     LEFT JOIN usuarios u ON u.id_usuario = la.id_registro
     LEFT JOIN roles r ON r.id_rol = u.id_rol
     WHERE la.accion = 'LOGIN_EXITOSO'
     ORDER BY la.id_log DESC LIMIT 8");

// 4) Migraciones pendientes: archivos en database/migrations/ cuya versión no está
//    registrada en migration_history. (No se invoca el runner completo para no
//    re-ejecutar config.php dentro de la request.)
$migracionesPendientes = [];
$migracionesError = '';
try {
    $aplicadas = [];
    foreach ($pdo->query("SELECT version FROM migration_history") as $row) {
        $aplicadas[(string) $row['version']] = true;
    }
    foreach (glob(__DIR__ . '/../database/migrations/*.sql') ?: [] as $file) {
        $base = basename($file);
        if (preg_match('/^([0-9]{8}_[0-9]{6})_/', $base, $m) && !isset($aplicadas[$m[1]])) {
            $migracionesPendientes[] = $base;
        }
    }
    sort($migracionesPendientes);
} catch (Throwable $e) {
    $migracionesError = $e->getMessage();
}

// 5) Archivos de log locales.
$mailLogPath = __DIR__ . '/../mail_log.txt';
$auditFallbackPath = __DIR__ . '/../audit_fallback.log';
$mailLogExiste = is_file($mailLogPath);
$mailLogTamano = $mailLogExiste ? filesize($mailLogPath) : 0;
$mailLogFecha = $mailLogExiste ? date('Y-m-d H:i', filemtime($mailLogPath)) : '';
$auditFallbackExiste = is_file($auditFallbackPath);
$auditFallbackTamano = $auditFallbackExiste ? filesize($auditFallbackPath) : 0;

function saludFormatoBytes(int $bytes): string
{
    if ($bytes >= 1048576) {
        return round($bytes / 1048576, 1) . ' MB';
    }
    if ($bytes >= 1024) {
        return round($bytes / 1024, 1) . ' KB';
    }
    return $bytes . ' B';
}

// 6) Permisos "sin efecto" (activos pero que ningún check de código consulta todavía)
//    y duplicados conocidos.
$permisosActivos = saludQuery($pdo,
    "SELECT clave, nombre FROM permisos WHERE estado = 'activo' ORDER BY clave");
$enUso = array_map('strval', PERMISOS_EN_USO);
$permisosSinEfecto = array_values(array_filter($permisosActivos, static function ($p) use ($enUso) {
    return !in_array((string) $p['clave'], $enUso, true);
}));

// 7) Overrides individuales con caducidad próxima (7 días) — para no perder de vista accesos temporales.
$caducidadesProximas = saludQuery($pdo,
    "SELECT u.nombre, u.email, p.clave, up.expira_en, up.nota
     FROM usuario_permisos up
     JOIN usuarios u ON u.id_usuario = up.id_usuario
     JOIN permisos p ON p.id_permiso = up.id_permiso
     WHERE up.efecto = 'conceder' AND up.expira_en IS NOT NULL
       AND up.expira_en > NOW() AND up.expira_en <= DATE_ADD(NOW(), INTERVAL 7 DAY)
     ORDER BY up.expira_en ASC");

include __DIR__ . '/includes/header.php';
?>
<style>
    .salud-wrap { margin: 24px 0 60px; }
    .salud-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(300px, 1fr)); gap: 16px; }
    .salud-card { background: #fff; border: 1px solid #e0e0e0; border-radius: 10px; padding: 16px 18px; box-shadow: 0 1px 3px rgba(0,0,0,.06); }
    .salud-card h6 { margin: 0 0 12px; font-weight: 700; display: flex; align-items: center; gap: 8px; color: #1a237e; }
    .salud-num { font-size: 2.1rem; font-weight: 700; line-height: 1; }
    .salud-ok { color: #2e7d32; }
    .salud-warn { color: #ef6c00; }
    .salud-bad { color: #c62828; }
    .salud-muted { color: #90a4ae; font-size: .82rem; }
    .salud-list { list-style: none; padding: 0; margin: 8px 0 0; }
    .salud-list li { padding: 6px 0; border-top: 1px solid #f0f0f0; font-size: .88rem; }
    .salud-list li:first-child { border-top: 0; }
    .salud-chip { display: inline-block; font-family: monospace; font-size: .78rem; background: #eceff1; border-radius: 3px; padding: 1px 6px; }
</style>

<div class="container salud-wrap">
    <div style="display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:10px;">
        <h4 style="margin:0;">Salud del sistema</h4>
        <a href="<?php echo BASE_URL; ?>views/dashboard.php" class="btn-flat"><i class="material-icons left">arrow_back</i>Volver al panel</a>
    </div>
    <p class="salud-muted">Vista de solo lectura. Reúne señales de seguridad y mantenimiento que ya se registran pero no se ven juntas en ningún sitio.</p>

    <div class="salud-grid">

        <div class="salud-card">
            <h6><i class="material-icons">lock</i> Cuentas bloqueadas ahora</h6>
            <div class="salud-num <?php echo empty($cuentasBloqueadas) ? 'salud-ok' : 'salud-bad'; ?>">
                <?php echo count($cuentasBloqueadas); ?>
            </div>
            <?php if (empty($cuentasBloqueadas)): ?>
                <p class="salud-muted">Ninguna cuenta bloqueada por intentos fallidos.</p>
            <?php else: ?>
                <ul class="salud-list">
                    <?php foreach ($cuentasBloqueadas as $c): ?>
                        <li>
                            <b><?php echo esc($c['nombre'] ?: $c['email']); ?></b> <span class="salud-muted">(<?php echo esc($c['rol']); ?>)</span><br>
                            <span class="salud-muted"><?php echo (int) $c['intentos_fallidos']; ?> intentos · hasta <?php echo esc($c['bloqueado_hasta']); ?></span>
                        </li>
                    <?php endforeach; ?>
                </ul>
            <?php endif; ?>
        </div>

        <div class="salud-card">
            <h6><i class="material-icons">gpp_maybe</i> Bloqueos recientes (auditoría)</h6>
            <?php if (empty($bloqueosRecientes)): ?>
                <p class="salud-muted">Sin bloqueos de cuenta registrados.</p>
            <?php else: ?>
                <ul class="salud-list">
                    <?php foreach ($bloqueosRecientes as $b): ?>
                        <li>
                            <b><?php echo esc($b['nombre'] ?: ('usuario #' . $b['id_registro'])); ?></b><br>
                            <span class="salud-muted"><?php echo esc($b['fecha']); ?> — <?php echo esc($b['detalles']); ?></span>
                        </li>
                    <?php endforeach; ?>
                </ul>
            <?php endif; ?>
        </div>

        <div class="salud-card">
            <h6><i class="material-icons">update</i> Migraciones pendientes</h6>
            <?php if ($migracionesError !== ''): ?>
                <div class="salud-num salud-warn">!</div>
                <p class="salud-bad" style="font-size:.85rem;">El runner de migraciones devolvió un error:</p>
                <p class="salud-muted"><?php echo esc($migracionesError); ?></p>
            <?php else: ?>
                <div class="salud-num <?php echo empty($migracionesPendientes) ? 'salud-ok' : 'salud-warn'; ?>">
                    <?php echo count($migracionesPendientes); ?>
                </div>
                <?php if (empty($migracionesPendientes)): ?>
                    <p class="salud-muted">La base de datos está al día.</p>
                <?php else: ?>
                    <ul class="salud-list">
                        <?php foreach ($migracionesPendientes as $m): ?>
                            <li><span class="salud-chip"><?php echo esc($m); ?></span></li>
                        <?php endforeach; ?>
                    </ul>
                    <p class="salud-muted">Corre <code>php scripts/migrate.php</code> para aplicarlas.</p>
                <?php endif; ?>
            <?php endif; ?>
        </div>

        <div class="salud-card">
            <h6><i class="material-icons">mail</i> Correo y logs locales</h6>
            <ul class="salud-list">
                <li>
                    <b>mail_log.txt</b>:
                    <?php if ($mailLogExiste): ?>
                        <?php echo saludFormatoBytes((int) $mailLogTamano); ?>
                        <span class="salud-muted">· última escritura <?php echo esc($mailLogFecha); ?></span>
                        <?php if ($mailLogTamano > 5 * 1048576): ?>
                            <span class="salud-warn">· conviene rotarlo</span>
                        <?php endif; ?>
                    <?php else: ?>
                        <span class="salud-muted">no existe (aún no se ha enviado correo en local)</span>
                    <?php endif; ?>
                </li>
                <li>
                    <b>audit_fallback.log</b>:
                    <?php if ($auditFallbackExiste): ?>
                        <span class="salud-warn"><?php echo saludFormatoBytes((int) $auditFallbackTamano); ?> — la auditoría está cayendo a archivo</span>
                    <?php else: ?>
                        <span class="salud-ok">no existe (la auditoría escribe en la BD con normalidad)</span>
                    <?php endif; ?>
                </li>
            </ul>
        </div>

        <div class="salud-card">
            <h6><i class="material-icons">flaky</i> Permisos sin efecto</h6>
            <div class="salud-num <?php echo empty($permisosSinEfecto) ? 'salud-ok' : 'salud-warn'; ?>">
                <?php echo count($permisosSinEfecto); ?>
            </div>
            <p class="salud-muted">
                Claves activas que ningún check de código consulta todavía (se gatean por rol).
                De <?php echo count($permisosActivos); ?> permisos activos, <?php echo count($enUso); ?> mandan de verdad hoy.
            </p>
            <?php if (!empty($permisosSinEfecto)): ?>
                <ul class="salud-list">
                    <?php foreach ($permisosSinEfecto as $p): ?>
                        <li><span class="salud-chip"><?php echo esc($p['clave']); ?></span> <span class="salud-muted"><?php echo esc($p['nombre']); ?></span></li>
                    <?php endforeach; ?>
                </ul>
            <?php endif; ?>
        </div>

        <div class="salud-card">
            <h6><i class="material-icons">schedule</i> Accesos temporales por vencer (7 días)</h6>
            <div class="salud-num <?php echo empty($caducidadesProximas) ? 'salud-ok' : 'salud-warn'; ?>">
                <?php echo count($caducidadesProximas); ?>
            </div>
            <?php if (empty($caducidadesProximas)): ?>
                <p class="salud-muted">Ningún permiso individual con caducidad en los próximos 7 días.</p>
            <?php else: ?>
                <ul class="salud-list">
                    <?php foreach ($caducidadesProximas as $c): ?>
                        <li>
                            <b><?php echo esc($c['nombre'] ?: $c['email']); ?></b> ·
                            <span class="salud-chip"><?php echo esc($c['clave']); ?></span><br>
                            <span class="salud-muted">vence <?php echo esc($c['expira_en']); ?><?php echo $c['nota'] ? ' — ' . esc($c['nota']) : ''; ?></span>
                        </li>
                    <?php endforeach; ?>
                </ul>
            <?php endif; ?>
        </div>

        <div class="salud-card">
            <h6><i class="material-icons">login</i> Inicios de sesión recientes</h6>
            <?php if (empty($loginsRecientes)): ?>
                <p class="salud-muted">Sin registros de inicio de sesión.</p>
            <?php else: ?>
                <ul class="salud-list">
                    <?php foreach ($loginsRecientes as $l): ?>
                        <li>
                            <b><?php echo esc($l['nombre'] ?: ($l['email'] ?: 'usuario eliminado')); ?></b>
                            <span class="salud-muted">(<?php echo esc($l['rol'] ?? '—'); ?>) · <?php echo esc($l['fecha']); ?></span>
                        </li>
                    <?php endforeach; ?>
                </ul>
            <?php endif; ?>
        </div>

    </div>
</div>

<?php include __DIR__ . '/includes/footer.php'; ?>
