<?php
declare(strict_types=1);

require_once __DIR__ . '/../core/config.php';
require_once __DIR__ . '/../core/auth.php';

requireAuth();
// Permiso 'ver_auditoria' abre esta vista; el admin entra siempre (short-circuit).
if (!hasPermission('ver_auditoria') && !isAdmin()) {
    auditAccesoDenegado('Logs de Actividad (' . basename((string) ($_SERVER['SCRIPT_NAME'] ?? '')) . ')');
    header('Location: ' . BASE_URL . 'views/dashboard.php');
    exit;
}

$pageTitle = 'Logs de Actividad';
$pdo = getPDO();

/*
 * Dos vistas sobre la actividad del sistema:
 *  - "movimientos": auditoria (logs_auditoria). QUIEN hizo QUE cambio, cuando, desde que
 *    dispositivo, con el valor de antes y de despues. Es la que responde "yo no fui".
 *  - "navegacion": visitas y clics (logs_actividad), lo que ya existia.
 */
// Todo lo que llega en la URL se sanea en un solo lugar (probado en tests/Unit/AuditFiltrosTest.php):
// arreglos (?accion[]=x), fechas imposibles, textos enormes y numeros desbordados caen a su valor por defecto.
$filtros = auditFiltrosDesdeGet($_GET);
$vista = $filtros['vista'];
$filtro_usuario = $filtros['usuario'];
$fecha_inicio = $filtros['fecha_inicio'];
$fecha_fin = $filtros['fecha_fin'];

// Lista de usuarios para el filtro (compartida por las dos vistas).
$usuarios = $pdo->query("SELECT id_usuario, nombre, email FROM usuarios ORDER BY nombre")->fetchAll();

/* ------------------------------------------------------------------ *
 *  VISTA: MOVIMIENTOS (auditoria)
 * ------------------------------------------------------------------ */
$movimientos = [];
$totalMovimientos = 0;
$resumen = ['total' => 0, 'alertas' => 0, 'usuarios' => 0];
$resumenUsuarios = [];
$accionesDisponibles = [];
$modulosDisponibles = [];
$nombresRegistros = [];
$pagina = 1;
$porPagina = 50;
$totalPaginas = 1;
$filtro_accion = '';
$filtro_modulo = '';
$filtro_severidad = '';
$filtro_q = '';
$filtro_tabla = '';
$filtro_registro = 0;
$errorMovimientos = '';

if ($vista === 'movimientos') {
    $filtro_accion = $filtros['accion'];
    $filtro_modulo = $filtros['modulo'];
    $filtro_severidad = $filtros['severidad'];
    $filtro_q = $filtros['q'];
    $filtro_tabla = $filtros['tabla'];
    $filtro_registro = $filtros['registro'];
    $pagina = $filtros['pagina'];

    try {
        // Las columnas nuevas (migracion 20260919_000001) pueden no existir todavia mientras
        // el deploy corre las migraciones: la vista se degrada en vez de tronar.
        $columnasAud = $pdo->query('SHOW COLUMNS FROM logs_auditoria')->fetchAll(PDO::FETCH_COLUMN);
        $tiene = static fn(string $c): bool => in_array($c, $columnasAud, true);

        $where = ' WHERE 1=1';
        $params = [];
        if ($filtro_usuario > 0) {
            $where .= ' AND l.id_usuario = :id_usuario';
            $params[':id_usuario'] = $filtro_usuario;
        } elseif ($filtro_usuario === -1) {
            $where .= ' AND l.id_usuario IS NULL';
        }
        if ($filtro_accion !== '') {
            $where .= ' AND l.accion = :accion';
            $params[':accion'] = $filtro_accion;
        }
        if ($filtro_modulo !== '') {
            $where .= ' AND l.tabla_afectada = :modulo';
            $params[':modulo'] = $filtro_modulo;
        }
        if ($filtro_severidad !== '' && $tiene('severidad')) {
            $where .= ' AND l.severidad = :severidad';
            $params[':severidad'] = $filtro_severidad;
        }
        if ($filtro_q !== '') {
            $where .= ' AND (l.detalles LIKE :q OR l.accion LIKE :q2)';
            $like = '%' . addcslashes($filtro_q, '%_\\') . '%';
            $params[':q'] = $like;
            $params[':q2'] = $like;
        }
        if ($filtro_tabla !== '' && $filtro_registro > 0) {
            // Historial de UN registro (producto, cliente, pedido...). Un producto tambien vive
            // en su inventario y sus categorias: se muestra todo junto.
            if (in_array($filtro_tabla, ['productos', 'inventario_almacen', 'producto_categorias'], true)) {
                $where .= " AND l.tabla_afectada IN ('productos','inventario_almacen','producto_categorias') AND l.id_registro = :registro";
            } else {
                $where .= ' AND l.tabla_afectada = :tabla AND l.id_registro = :registro';
                $params[':tabla'] = $filtro_tabla;
            }
            $params[':registro'] = $filtro_registro;
        }
        if ($fecha_inicio !== '') {
            $where .= ' AND l.fecha >= :inicio';
            $params[':inicio'] = $fecha_inicio . ' 00:00:00';
        }
        if ($fecha_fin !== '') {
            $where .= ' AND l.fecha <= :fin';
            $params[':fin'] = $fecha_fin . ' 23:59:59';
        }

        // Nombre/rol del actor: el que quedo en el log (foto del momento del cambio) y, de respaldo, el
        // actual del usuario. Se traen por separado y se resuelven en PHP: mezclarlos en SQL (COALESCE)
        // truena con "Illegal mix of collations" cuando usuarios y logs_auditoria tienen collation distinta.
        $colLogNombre = $tiene('usuario_nombre') ? 'l.usuario_nombre' : 'NULL';
        $colLogRol = $tiene('usuario_rol') ? 'l.usuario_rol' : 'NULL';
        $exprSev = $tiene('severidad') ? 'l.severidad' : "'info'";
        $from = ' FROM logs_auditoria l LEFT JOIN usuarios u ON u.id_usuario = l.id_usuario LEFT JOIN roles r ON r.id_rol = u.id_rol';

        // Resumen del filtro actual.
        $stmt = $pdo->prepare("SELECT COUNT(*) AS total, COALESCE(SUM({$exprSev} = 'alerta'), 0) AS alertas, COUNT(DISTINCT l.id_usuario) AS usuarios{$from}{$where}");
        $stmt->execute($params);
        $resumen = $stmt->fetch(PDO::FETCH_ASSOC) ?: $resumen;
        $totalMovimientos = (int) $resumen['total'];
        $totalPaginas = max(1, (int) ceil($totalMovimientos / $porPagina));
        $pagina = min($pagina, $totalPaginas);

        // Quien concentra la actividad (y las alertas) en este filtro.
        $stmt = $pdo->prepare(
            "SELECT l.id_usuario, MAX({$colLogNombre}) AS nombre_log, MAX(u.nombre) AS nombre_actual, MAX({$colLogRol}) AS rol_log, MAX(r.nombre) AS rol_actual,
                    COUNT(*) AS total, COALESCE(SUM({$exprSev} = 'alerta'), 0) AS alertas, MAX(l.fecha) AS ultima{$from}{$where}
             GROUP BY l.id_usuario ORDER BY alertas DESC, total DESC LIMIT 8"
        );
        $stmt->execute($params);
        $resumenUsuarios = $stmt->fetchAll(PDO::FETCH_ASSOC);
        foreach ($resumenUsuarios as &$ru) {
            $ru['nombre'] = trim((string) ($ru['nombre_log'] ?? '')) !== '' ? $ru['nombre_log'] : (trim((string) ($ru['nombre_actual'] ?? '')) !== '' ? $ru['nombre_actual'] : 'Sin sesión / sistema');
            $ru['rol'] = trim((string) ($ru['rol_log'] ?? '')) !== '' ? $ru['rol_log'] : ($ru['rol_actual'] ?? null);
        }
        unset($ru);

        // Filas de la pagina.
        $columnasExtra = '';
        foreach (['usuario_nombre', 'usuario_rol', 'id_almacen', 'sesion_hash', 'user_agent', 'url', 'metodo', 'origen', 'severidad', 'datos_antes', 'datos_despues'] as $c) {
            $columnasExtra .= $tiene($c) ? ", l.{$c}" : ", NULL AS {$c}";
        }
        $offset = ($pagina - 1) * $porPagina;
        $stmt = $pdo->prepare(
            "SELECT l.id_log, l.id_usuario, l.accion, l.tabla_afectada, l.id_registro, l.detalles, l.ip_address, l.fecha{$columnasExtra},
                    {$colLogNombre} AS nombre_log, u.nombre AS nombre_actual, {$colLogRol} AS rol_log, r.nombre AS rol_actual{$from}{$where}
             ORDER BY l.id_log DESC LIMIT {$porPagina} OFFSET {$offset}"
        );
        $stmt->execute($params);
        $movimientos = $stmt->fetchAll(PDO::FETCH_ASSOC);
        foreach ($movimientos as &$mv) {
            $mv['nombre_resuelto'] = trim((string) ($mv['nombre_log'] ?? '')) !== '' ? $mv['nombre_log'] : $mv['nombre_actual'];
            $mv['rol_resuelto'] = trim((string) ($mv['rol_log'] ?? '')) !== '' ? $mv['rol_log'] : $mv['rol_actual'];
        }
        unset($mv);

        $accionesDisponibles = $pdo->query('SELECT DISTINCT accion FROM logs_auditoria ORDER BY accion')->fetchAll(PDO::FETCH_COLUMN);
        usort($accionesDisponibles, static fn($a, $b) => strcmp(auditEtiquetaAccion((string) $a), auditEtiquetaAccion((string) $b)));
        $modulosDisponibles = $pdo->query('SELECT DISTINCT tabla_afectada FROM logs_auditoria ORDER BY tabla_afectada')->fetchAll(PDO::FETCH_COLUMN);

        // Nombre legible de cada registro tocado (producto, cliente, pedido...) en una sola
        // consulta por tabla, para no mostrar solo "#123".
        $definiciones = [
            'productos' => ['productos', 'id_producto', 'nombre'],
            'inventario_almacen' => ['productos', 'id_producto', 'nombre'],
            'usuarios' => ['usuarios', 'id_usuario', 'nombre'],
            'clientes' => ['clientes', 'id_cliente', 'nombre'],
            'pedidos' => ['pedidos', 'id_pedido', 'numero_pedido'],
            'almacenes' => ['almacenes', 'id_almacen', 'nombre'],
            'lotes_inventario' => ['lotes_inventario', 'id_lote', 'codigo_lote'],
            'ordenes_compra' => ['ordenes_compra', 'id_orden_compra', 'referencia'],
            'roles' => ['roles', 'id_rol', 'nombre'],
            'permisos' => ['permisos', 'id_permiso', 'clave'],
            'categorias' => ['categorias', 'id_categoria', 'nombre'],
            'blogs' => ['blogs', 'id_blog', 'titulo'],
            'calendario_campanas' => ['calendario_campanas', 'id_campana', 'nombre'],
        ];
        $idsPorTabla = [];
        foreach ($movimientos as $m) {
            if ($m['id_registro'] !== null && isset($definiciones[$m['tabla_afectada']])) {
                $idsPorTabla[$m['tabla_afectada']][(int) $m['id_registro']] = true;
            }
        }
        foreach ($idsPorTabla as $tabla => $ids) {
            [$tablaSql, $pk, $colNombre] = $definiciones[$tabla];
            try {
                $ids = array_keys($ids);
                $st = $pdo->prepare("SELECT `{$pk}` AS id, `{$colNombre}` AS nombre FROM `{$tablaSql}` WHERE `{$pk}` IN (" . implode(',', array_fill(0, count($ids), '?')) . ')');
                $st->execute($ids);
                foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $fila) {
                    $nombre = (string) $fila['nombre'];
                    if ($nombre !== '' && function_exists('piiIsEncryptedValue') && function_exists('piiDecryptValue') && piiIsEncryptedValue($nombre)) {
                        $nombre = (string) piiDecryptValue($nombre);
                    }
                    $nombresRegistros[$tabla][(int) $fila['id']] = $nombre;
                }
            } catch (Throwable $e) {
                // Una tabla que no exista en este entorno solo deja el "#id" sin nombre.
            }
        }
    } catch (Throwable $e) {
        error_log('activity_logs (movimientos): ' . $e->getMessage());
        $errorMovimientos = 'No se pudieron cargar los movimientos. Revisa que las migraciones estén aplicadas.';
    }
}

/**
 * Tabla "campo | antes | despues" a partir de los JSON de datos_antes / datos_despues.
 */
$renderCambios = static function (?string $jsonAntes, ?string $jsonDespues): string {
    $antes = $jsonAntes !== null && $jsonAntes !== '' ? json_decode($jsonAntes, true) : null;
    $despues = $jsonDespues !== null && $jsonDespues !== '' ? json_decode($jsonDespues, true) : null;
    if (!is_array($antes) && !is_array($despues)) {
        return '';
    }
    $antes = is_array($antes) ? $antes : [];
    $despues = is_array($despues) ? $despues : [];

    $texto = static function ($v): string {
        if ($v === null || $v === '') {
            return '—';
        }
        if (is_bool($v)) {
            return $v ? 'sí' : 'no';
        }
        if (is_scalar($v)) {
            return (string) $v;
        }
        return (string) json_encode($v, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PARTIAL_OUTPUT_ON_ERROR);
    };

    $campos = array_values(array_unique(array_merge(array_keys($antes), array_keys($despues))));
    $html = '<table class="mov-cambios"><thead><tr><th>Campo</th><th>Antes</th><th>Después</th></tr></thead><tbody>';
    foreach ($campos as $campo) {
        $html .= '<tr><td>' . esc((string) $campo) . '</td><td class="antes">' . esc($texto($antes[$campo] ?? null)) . '</td><td class="despues">' . esc($texto($despues[$campo] ?? null)) . '</td></tr>';
    }
    return $html . '</tbody></table>';
};

/** Enlace que conserva los filtros actuales y cambia solo lo indicado. */
$urlCon = static function (array $cambios) use ($filtros) {
    // Se parte de los filtros YA saneados (no de $_GET crudo) y se omiten los valores por defecto.
    $q = array_merge($filtros, $cambios);
    foreach ($q as $k => $v) {
        if ($v === '' || $v === null || $v === 0 || $v === '0' || ($k === 'pagina' && (int) $v === 1)) {
            unset($q[$k]);
        }
    }
    return '?' . http_build_query($q);
};

/* ------------------------------------------------------------------ *
 *  VISTA: NAVEGACION (visitas y clics) -- logica original
 * ------------------------------------------------------------------ */
$groupedLogs = [];
$plataformas = [];
$filtro_tipo = '';
$filtro_plataforma = '';
$filtro_origen = '';

if ($vista === 'navegacion') {
    $filtro_tipo = $filtros['tipo'];
    $filtro_plataforma = $filtros['plataforma'];
    // Origen: 'interno' = actividad del personal (admin/encargado/vendedor/repartidor)
    // navegando el sistema; 'externo' = visitantes anonimos y clientes. El log guarda
    // las dos; este filtro solo cambia lo que se lista. Los reportes de marketing
    // (Trafico y Campanas, Comportamiento en el Sitio) siempre miden solo 'externo'.
    $filtro_origen = $filtros['origen'];

    // Plataformas presentes en los datos, para poblar el filtro sin hardcodear valores.
    $plataformas = $pdo->query("SELECT DISTINCT plataforma FROM logs_actividad WHERE plataforma IS NOT NULL AND plataforma != '' ORDER BY plataforma")->fetchAll(PDO::FETCH_COLUMN);

    $query = "SELECT l.*, u.nombre as usuario_nombre, u.email as usuario_email
              FROM logs_actividad l
              LEFT JOIN usuarios u ON l.id_usuario = u.id_usuario
              WHERE 1=1";

    $params = [];
    if ($filtro_usuario > 0) {
        $query .= " AND l.id_usuario = :id_usuario";
        $params[':id_usuario'] = $filtro_usuario;
    } elseif ($filtro_usuario === -1) {
        $query .= " AND l.id_usuario IS NULL";
    }
    if ($filtro_tipo !== '') {
        $query .= " AND l.tipo_accion = :tipo";
        $params[':tipo'] = $filtro_tipo;
    }
    if ($filtro_plataforma !== '') {
        $query .= " AND l.plataforma = :plataforma";
        $params[':plataforma'] = $filtro_plataforma;
    }
    if ($filtro_origen === 'interno') {
        $query .= " AND l.es_interno = 1";
    } elseif ($filtro_origen === 'externo') {
        $query .= " AND l.es_interno = 0";
    }
    if ($fecha_inicio) {
        $query .= " AND DATE(l.fecha_creacion) >= :inicio";
        $params[':inicio'] = $fecha_inicio;
    }
    if ($fecha_fin) {
        $query .= " AND DATE(l.fecha_creacion) <= :fin";
        $params[':fin'] = $fecha_fin;
    }

    $query .= " ORDER BY l.fecha_creacion DESC LIMIT 500";

    $stmt = $pdo->prepare($query);
    $stmt->execute($params);
    $logs = $stmt->fetchAll();

    // Agrupar logs por Mes y Día para la vista colapsable
    foreach ($logs as $log) {
        $dateObj = new DateTime($log['fecha_creacion']);
        $monthKey = $dateObj->format('F Y'); // E.g., "May 2024"
        $dayKey = $dateObj->format('Y-m-d');

        if (!isset($groupedLogs[$monthKey])) $groupedLogs[$monthKey] = [];
        if (!isset($groupedLogs[$monthKey][$dayKey])) $groupedLogs[$monthKey][$dayKey] = [];
        $groupedLogs[$monthKey][$dayKey][] = $log;
    }
}

include __DIR__ . '/includes/header.php';
?>

<div class="container-fluid" style="padding: 20px;">
    <div class="row">
        <div class="col s12">
            <div style="display: flex; align-items: center; justify-content: space-between; margin-bottom: 10px; flex-wrap: wrap; gap: 10px;">
                <h4 style="margin: 0;"><i class="material-icons left" style="font-size: 2.5rem;">history</i> Log de Actividad</h4>
                <a href="dashboard.php" class="btn blue darken-4 waves-effect waves-light"><i class="material-icons left">dashboard</i> Volver al Dashboard</a>
            </div>
            <p class="grey-text">
                <?php if ($vista === 'movimientos'): ?>
                    Quién hizo qué, cuándo y desde dónde: cada cambio de precios, ofertas, inventario, clientes, usuarios y accesos, con el valor de antes y de después.
                <?php else: ?>
                    Seguimiento detallado de clics y visitas de todos los usuarios del sistema.
                <?php endif; ?>
            </p>
        </div>
    </div>

    <!-- Pestañas -->
    <div class="log-tabs">
        <a href="?vista=movimientos" class="log-tab <?php echo $vista === 'movimientos' ? 'activa' : ''; ?>"><i class="material-icons">fact_check</i> Movimientos (auditoría)</a>
        <a href="?vista=navegacion" class="log-tab <?php echo $vista === 'navegacion' ? 'activa' : ''; ?>"><i class="material-icons">ads_click</i> Navegación (visitas y clics)</a>
    </div>

<?php if ($vista === 'movimientos'): ?>

    <!-- Filtros de movimientos -->
    <div class="card">
        <div class="card-content">
            <form method="GET" class="row" style="margin-bottom: 0;">
                <input type="hidden" name="vista" value="movimientos">
                <div class="input-field col s12 m3">
                    <select name="usuario" class="browser-default">
                        <option value="0">Todos los usuarios</option>
                        <option value="-1" <?php echo $filtro_usuario === -1 ? 'selected' : ''; ?>>Sin sesión / sistema</option>
                        <?php foreach ($usuarios as $u): ?>
                            <option value="<?php echo (int) $u['id_usuario']; ?>" <?php echo $filtro_usuario == $u['id_usuario'] ? 'selected' : ''; ?>>
                                <?php echo esc($u['nombre']); ?> (<?php echo esc($u['email']); ?>)
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="input-field col s12 m3">
                    <select name="accion" class="browser-default">
                        <option value="">Todas las acciones</option>
                        <?php foreach ($accionesDisponibles as $a): ?>
                            <option value="<?php echo esc((string) $a); ?>" <?php echo $filtro_accion === $a ? 'selected' : ''; ?>><?php echo esc(auditEtiquetaAccion((string) $a)); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="input-field col s12 m2">
                    <select name="modulo" class="browser-default">
                        <option value="">Todos los módulos</option>
                        <?php foreach ($modulosDisponibles as $mod): ?>
                            <option value="<?php echo esc((string) $mod); ?>" <?php echo $filtro_modulo === $mod ? 'selected' : ''; ?>><?php echo esc(auditEtiquetaTabla((string) $mod)); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="input-field col s12 m2">
                    <select name="severidad" class="browser-default">
                        <option value="">Toda severidad</option>
                        <option value="alerta" <?php echo $filtro_severidad === 'alerta' ? 'selected' : ''; ?>>Solo alertas</option>
                        <option value="aviso" <?php echo $filtro_severidad === 'aviso' ? 'selected' : ''; ?>>Avisos</option>
                        <option value="info" <?php echo $filtro_severidad === 'info' ? 'selected' : ''; ?>>Informativos</option>
                    </select>
                </div>
                <div class="input-field col s12 m2">
                    <input type="text" name="q" id="filtro_q" value="<?php echo esc($filtro_q); ?>" placeholder="producto, cliente, precio...">
                    <label for="filtro_q" class="active">Buscar en el detalle</label>
                </div>
                <div class="input-field col s6 m2">
                    <input type="date" name="fecha_inicio" id="fecha_inicio" value="<?php echo esc((string) $fecha_inicio); ?>">
                    <label for="fecha_inicio" class="active">Desde</label>
                </div>
                <div class="input-field col s6 m2">
                    <input type="date" name="fecha_fin" id="fecha_fin" value="<?php echo esc((string) $fecha_fin); ?>">
                    <label for="fecha_fin" class="active">Hasta</label>
                </div>
                <div class="col s12 m4" style="padding-top: 15px;">
                    <button type="submit" class="btn indigo waves-effect waves-light">Filtrar</button>
                    <a href="?vista=movimientos" class="btn-flat grey-text">Limpiar</a>
                </div>
            </form>
        </div>
    </div>

    <?php if ($errorMovimientos !== ''): ?>
        <div class="card-panel red lighten-4 red-text text-darken-4"><?php echo esc($errorMovimientos); ?></div>
    <?php else: ?>

        <?php if ($filtro_tabla !== '' && $filtro_registro > 0): ?>
            <?php
            $nombreHistorial = $nombresRegistros[$filtro_tabla][$filtro_registro] ?? '';
            if ($nombreHistorial === '' && isset($definiciones[$filtro_tabla])) {
                foreach ($movimientos as $m) {
                    if ((int) $m['id_registro'] === $filtro_registro && isset($nombresRegistros[$m['tabla_afectada']][$filtro_registro])) {
                        $nombreHistorial = $nombresRegistros[$m['tabla_afectada']][$filtro_registro];
                        break;
                    }
                }
            }
            ?>
            <div class="card-panel indigo lighten-5" style="display:flex; justify-content:space-between; align-items:center; gap:10px; flex-wrap:wrap;">
                <span>
                    <i class="material-icons left indigo-text">timeline</i>
                    Historial completo de <strong><?php echo esc(auditEtiquetaTabla($filtro_tabla)); ?> #<?php echo (int) $filtro_registro; ?><?php echo $nombreHistorial !== '' ? ' — ' . esc($nombreHistorial) : ''; ?></strong>
                </span>
                <a class="btn-flat indigo-text" href="<?php echo esc($urlCon(['tabla' => '', 'registro' => '', 'pagina' => ''])); ?>">Quitar este filtro</a>
            </div>
        <?php endif; ?>

        <!-- Resumen -->
        <div class="mov-resumen">
            <div class="mov-stat"><span class="mov-stat-n"><?php echo number_format($totalMovimientos); ?></span><span class="mov-stat-l">movimientos</span></div>
            <div class="mov-stat alerta"><span class="mov-stat-n"><?php echo number_format((int) $resumen['alertas']); ?></span><span class="mov-stat-l">alertas</span></div>
            <div class="mov-stat"><span class="mov-stat-n"><?php echo number_format((int) $resumen['usuarios']); ?></span><span class="mov-stat-l">usuarios distintos</span></div>
        </div>

        <?php if (!empty($resumenUsuarios) && count($resumenUsuarios) > 1): ?>
            <div class="card">
                <div class="card-content" style="padding: 12px 16px;">
                    <strong class="grey-text text-darken-2">Quién concentra la actividad</strong>
                    <div class="mov-quien">
                        <?php foreach ($resumenUsuarios as $ru): ?>
                            <a class="mov-quien-item" href="<?php echo esc($urlCon(['usuario' => $ru['id_usuario'] === null ? '-1' : (string) $ru['id_usuario'], 'pagina' => ''])); ?>">
                                <span class="mov-quien-nombre"><?php echo esc((string) $ru['nombre']); ?><?php echo !empty($ru['rol']) ? ' <small class="grey-text">(' . esc((string) $ru['rol']) . ')</small>' : ''; ?></span>
                                <span class="mov-quien-datos">
                                    <?php echo (int) $ru['total']; ?> mov.
                                    <?php if ((int) $ru['alertas'] > 0): ?><span class="sev sev-alerta"><?php echo (int) $ru['alertas']; ?> alertas</span><?php endif; ?>
                                    <small class="grey-text"> · último <?php echo esc(date('d/m H:i', strtotime((string) $ru['ultima']))); ?></small>
                                </span>
                            </a>
                        <?php endforeach; ?>
                    </div>
                </div>
            </div>
        <?php endif; ?>

        <?php if (empty($movimientos)): ?>
            <div class="card-panel center-align grey-text">No se encontraron movimientos para los filtros seleccionados.</div>
        <?php else: ?>
            <div class="card">
                <div class="mov-lista">
                    <?php foreach ($movimientos as $m):
                        $sev = in_array($m['severidad'], AUDIT_SEVERIDADES, true) ? $m['severidad'] : 'info';
                        $nombreRegistro = $m['id_registro'] !== null ? ($nombresRegistros[$m['tabla_afectada']][(int) $m['id_registro']] ?? '') : '';
                        $cambios = $renderCambios($m['datos_antes'], $m['datos_despues']);
                        $dispositivo = auditResumirUserAgent($m['user_agent']);
                        $nombreActor = trim((string) ($m['nombre_resuelto'] ?? ''));
                        ?>
                        <div class="mov-item sev-borde-<?php echo esc($sev); ?>">
                            <div class="mov-hora">
                                <strong><?php echo esc(date('H:i:s', strtotime((string) $m['fecha']))); ?></strong>
                                <small class="grey-text"><?php echo esc(date('d/m/Y', strtotime((string) $m['fecha']))); ?></small>
                            </div>
                            <div class="mov-actor">
                                <span class="mov-actor-nombre"><?php echo $nombreActor !== '' ? esc($nombreActor) : '<span class="grey-text">Sin sesión / sistema</span>'; ?></span>
                                <?php if (!empty($m['rol_resuelto'])): ?><small class="grey-text"><?php echo esc((string) $m['rol_resuelto']); ?></small><?php endif; ?>
                            </div>
                            <div class="mov-que">
                                <span class="sev sev-<?php echo esc($sev); ?>"><?php echo $sev === 'alerta' ? 'ALERTA' : ($sev === 'aviso' ? 'AVISO' : 'INFO'); ?></span>
                                <strong><?php echo esc(auditEtiquetaAccion((string) $m['accion'])); ?></strong>
                                <?php if ($m['id_registro'] !== null && $m['tabla_afectada'] !== 'http'): ?>
                                    <a class="mov-registro" href="<?php echo esc($urlCon(['tabla' => $m['tabla_afectada'], 'registro' => (string) (int) $m['id_registro'], 'usuario' => '', 'accion' => '', 'modulo' => '', 'severidad' => '', 'q' => '', 'pagina' => ''])); ?>" title="Ver todo el historial de este registro">
                                        <?php echo esc(auditEtiquetaTabla((string) $m['tabla_afectada'])); ?> #<?php echo (int) $m['id_registro']; ?><?php echo $nombreRegistro !== '' ? ' · ' . esc($nombreRegistro) : ''; ?>
                                    </a>
                                <?php else: ?>
                                    <span class="grey-text mov-registro-plano"><?php echo esc(auditEtiquetaTabla((string) $m['tabla_afectada'])); ?></span>
                                <?php endif; ?>
                                <?php if (!empty($m['detalles'])): ?>
                                    <div class="mov-detalle"><?php echo esc((string) $m['detalles']); ?></div>
                                <?php endif; ?>
                                <?php if ($cambios !== ''): ?>
                                    <details class="mov-cambios-wrap"><summary>Ver antes → después</summary><?php echo $cambios; ?></details>
                                <?php endif; ?>
                            </div>
                            <div class="mov-donde">
                                <span><i class="material-icons tiny">public</i> <?php echo esc((string) $m['ip_address']); ?></span>
                                <?php if ($dispositivo !== ''): ?><small class="grey-text" title="<?php echo esc((string) $m['user_agent']); ?>"><?php echo esc($dispositivo); ?></small><?php endif; ?>
                                <?php if (!empty($m['sesion_hash'])): ?><small class="grey-text" title="Identificador de la sesión (si dos movimientos comparten sesión, salieron del mismo inicio de sesión)">sesión <?php echo esc((string) $m['sesion_hash']); ?></small><?php endif; ?>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>

            <?php if ($totalPaginas > 1): ?>
                <ul class="pagination center-align">
                    <li class="<?php echo $pagina <= 1 ? 'disabled' : 'waves-effect'; ?>"><a href="<?php echo $pagina <= 1 ? '#!' : esc($urlCon(['pagina' => (string) ($pagina - 1)])); ?>"><i class="material-icons">chevron_left</i></a></li>
                    <?php for ($p = max(1, $pagina - 3); $p <= min($totalPaginas, $pagina + 3); $p++): ?>
                        <li class="<?php echo $p === $pagina ? 'active indigo' : 'waves-effect'; ?>"><a href="<?php echo esc($urlCon(['pagina' => (string) $p])); ?>"><?php echo $p; ?></a></li>
                    <?php endfor; ?>
                    <li class="<?php echo $pagina >= $totalPaginas ? 'disabled' : 'waves-effect'; ?>"><a href="<?php echo $pagina >= $totalPaginas ? '#!' : esc($urlCon(['pagina' => (string) ($pagina + 1)])); ?>"><i class="material-icons">chevron_right</i></a></li>
                </ul>
                <p class="center-align grey-text"><small>Página <?php echo (int) $pagina; ?> de <?php echo (int) $totalPaginas; ?></small></p>
            <?php endif; ?>
        <?php endif; ?>
    <?php endif; ?>

<?php else: ?>

    <!-- Filtros de navegación -->
    <div class="card">
        <div class="card-content">
            <form method="GET" class="row" style="margin-bottom: 0;">
                <input type="hidden" name="vista" value="navegacion">
                <div class="input-field col s12 m2">
                    <select name="usuario" class="browser-default">
                        <option value="0">Todos los usuarios</option>
                        <option value="-1" <?php echo $filtro_usuario === -1 ? 'selected' : ''; ?>>Solo invitados (sin login)</option>
                        <?php foreach ($usuarios as $u): ?>
                            <option value="<?php echo $u['id_usuario']; ?>" <?php echo $filtro_usuario == $u['id_usuario'] ? 'selected' : ''; ?>>
                                <?php echo esc($u['nombre']); ?> (<?php echo esc($u['email']); ?>)
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="input-field col s12 m2">
                    <select name="tipo" class="browser-default">
                        <option value="">Todos los tipos</option>
                        <option value="visit" <?php echo $filtro_tipo == 'visit' ? 'selected' : ''; ?>>Visitas</option>
                        <option value="click" <?php echo $filtro_tipo == 'click' ? 'selected' : ''; ?>>Clics</option>
                    </select>
                </div>
                <div class="input-field col s12 m2">
                    <select name="origen" class="browser-default">
                        <option value="">Interno y externo</option>
                        <option value="externo" <?php echo $filtro_origen === 'externo' ? 'selected' : ''; ?>>Solo externo (visitantes y clientes)</option>
                        <option value="interno" <?php echo $filtro_origen === 'interno' ? 'selected' : ''; ?>>Solo interno (personal)</option>
                    </select>
                </div>
                <div class="input-field col s12 m2">
                    <select name="plataforma" class="browser-default">
                        <option value="">Todas las plataformas</option>
                        <?php foreach ($plataformas as $p): ?>
                            <option value="<?php echo esc($p); ?>" <?php echo $filtro_plataforma === $p ? 'selected' : ''; ?>><?php echo esc($p); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="input-field col s6 m1">
                    <input type="date" name="fecha_inicio" id="fecha_inicio" value="<?php echo esc((string) $fecha_inicio); ?>">
                    <label for="fecha_inicio" class="active">Desde</label>
                </div>
                <div class="input-field col s6 m1">
                    <input type="date" name="fecha_fin" id="fecha_fin" value="<?php echo esc((string) $fecha_fin); ?>">
                    <label for="fecha_fin" class="active">Hasta</label>
                </div>
                <div class="col s12 m1" style="padding-top: 15px;">
                    <button type="submit" class="btn indigo waves-effect waves-light">Filtrar</button>
                </div>
                <div class="col s12 m1 right-align" style="padding-top: 15px;">
                    <a href="?vista=navegacion" class="btn-flat grey-text">Limpiar</a>
                </div>
            </form>
        </div>
    </div>

    <!-- Visualización de Logs Agrupados -->
    <?php if (empty($groupedLogs)): ?>
        <div class="card-panel center-align grey-text">No se encontraron registros para los filtros seleccionados.</div>
    <?php else: ?>
        <?php
        $firstDay = true;
        foreach ($groupedLogs as $month => $days): ?>
            <div class="month-group">
                <h5 class="indigo-text text-darken-4" style="margin-top: 30px; font-weight: bold; border-bottom: 2px solid #e8eaf6; padding-bottom: 10px;">
                    <i class="material-icons left">calendar_month</i> <?php echo $month; ?>
                </h5>

                <ul class="collapsible z-depth-1">
                    <?php foreach ($days as $day => $dayLogs): ?>
                        <li class="<?php echo $firstDay ? 'active' : ''; ?>">
                            <div class="collapsible-header" style="display: flex; justify-content: space-between; align-items: center;">
                                <span>
                                    <i class="material-icons indigo-text">event</i>
                                    <strong><?php echo date('d \d\e F', strtotime($day)); ?></strong>
                                </span>
                                <span class="new badge blue darken-1" data-badge-caption="acciones"><?php echo count($dayLogs); ?></span>
                            </div>
                            <div class="collapsible-body white" style="padding: 0;">
                                <!-- Vista de tabla: pantallas medianas/grandes. -->
                                <div class="logs-table-wrap" style="overflow-x: auto; -webkit-overflow-scrolling: touch;">
                                    <table class="striped highlight" style="min-width: 560px;">
                                        <thead>
                                            <tr>
                                                <th>Hora</th>
                                                <th>Usuario</th>
                                                <th>Acción</th>
                                                <th>Detalle</th>
                                                <th>Plataforma</th>
                                                <th>IP</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php foreach ($dayLogs as $log): ?>
                                                <tr>
                                                    <td><?php echo date('H:i:s', strtotime($log['fecha_creacion'])); ?></td>
                                                    <td>
                                                        <span style="font-weight: 500;"><?php echo esc($log['usuario_nombre'] ?? 'Invitado'); ?></span>
                                                        <?php if (empty($log['usuario_nombre'])): ?>
                                                            <small class="grey-text" style="display:block;">sin sesión</small>
                                                        <?php endif; ?>
                                                        <?php if (!empty($log['es_interno'])): ?>
                                                            <small class="orange-text text-darken-3" style="display:block;">personal interno</small>
                                                        <?php endif; ?>
                                                    </td>
                                                    <td>
                                                        <span class="badge <?php echo $log['tipo_accion'] === 'visit' ? 'blue' : 'green'; ?> white-text" style="float: none;">
                                                            <?php echo strtoupper($log['tipo_accion']); ?>
                                                        </span>
                                                    </td>
                                                    <td>
                                                        <?php if ($log['tipo_accion'] === 'click'): ?>
                                                            <strong>"<?php echo esc($log['elemento_texto']); ?>"</strong><br>
                                                        <?php endif; ?>
                                                        <small class="grey-text"><?php echo esc(str_replace(BASE_URL, '/', $log['url'])); ?></small>
                                                    </td>
                                                    <td><small><?php echo esc($log['plataforma'] ?? ''); ?></small></td>
                                                    <td><small><?php echo esc($log['ip_address']); ?></small></td>
                                                </tr>
                                            <?php endforeach; ?>
                                        </tbody>
                                    </table>
                                </div>
                                <!-- Vista de tarjetas: pantallas de celular. Se evita el modo
                                     "responsive-table" de Materialize a proposito: en vez de
                                     apilar cada registro como tarjeta, volteaba la tabla y
                                     obligaba a hacer scroll horizontal por cada fila para ver
                                     el resto de los registros. -->
                                <div class="logs-cards-wrap">
                                    <?php foreach ($dayLogs as $log): ?>
                                        <div class="log-card">
                                            <div class="log-card-row">
                                                <span class="log-card-label">Hora</span>
                                                <span class="log-card-value"><?php echo date('H:i:s', strtotime($log['fecha_creacion'])); ?></span>
                                            </div>
                                            <div class="log-card-row">
                                                <span class="log-card-label">Usuario</span>
                                                <span class="log-card-value">
                                                    <?php echo esc($log['usuario_nombre'] ?? 'Invitado'); ?>
                                                    <?php if (empty($log['usuario_nombre'])): ?>
                                                        <small class="grey-text" style="display:block;">sin sesión</small>
                                                    <?php endif; ?>
                                                    <?php if (!empty($log['es_interno'])): ?>
                                                        <small class="orange-text text-darken-3" style="display:block;">personal interno</small>
                                                    <?php endif; ?>
                                                </span>
                                            </div>
                                            <div class="log-card-row">
                                                <span class="log-card-label">Acción</span>
                                                <span class="log-card-value">
                                                    <span class="badge <?php echo $log['tipo_accion'] === 'visit' ? 'blue' : 'green'; ?> white-text" style="float: none;">
                                                        <?php echo strtoupper($log['tipo_accion']); ?>
                                                    </span>
                                                </span>
                                            </div>
                                            <div class="log-card-row log-card-row-block">
                                                <span class="log-card-label">Detalle</span>
                                                <span class="log-card-value log-card-value-block">
                                                    <?php if ($log['tipo_accion'] === 'click'): ?>
                                                        <strong>"<?php echo esc($log['elemento_texto']); ?>"</strong><br>
                                                    <?php endif; ?>
                                                    <small class="grey-text"><?php echo esc(str_replace(BASE_URL, '/', $log['url'])); ?></small>
                                                </span>
                                            </div>
                                            <div class="log-card-row">
                                                <span class="log-card-label">Plataforma</span>
                                                <span class="log-card-value"><small><?php echo esc($log['plataforma'] ?? ''); ?></small></span>
                                            </div>
                                            <div class="log-card-row">
                                                <span class="log-card-label">IP</span>
                                                <span class="log-card-value"><small><?php echo esc($log['ip_address']); ?></small></span>
                                            </div>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                            </div>
                        </li>
                    <?php
                    $firstDay = false;
                    endforeach; ?>
                </ul>
            </div>
        <?php endforeach; ?>
    <?php endif; ?>

<?php endif; ?>
</div>

<style>
    .collapsible-header:hover { background-color: #f5f5f5; }
    .collapsible-body table { font-size: 0.9rem; }
    .badge { border-radius: 4px; min-width: 60px; font-weight: bold; }
    input[type="date"] { margin-bottom: 0 !important; }

    /* Pestañas */
    .log-tabs { display: flex; gap: 8px; flex-wrap: wrap; margin: 4px 0 14px; }
    .log-tab { display: inline-flex; align-items: center; gap: 6px; padding: 9px 16px; border-radius: 999px; background: #eceff1; color: #455a64; font-weight: 600; text-decoration: none; }
    .log-tab i { font-size: 1.1rem; }
    .log-tab.activa { background: #283593; color: #fff; }

    /* Resumen */
    .mov-resumen { display: flex; gap: 12px; flex-wrap: wrap; margin-bottom: 12px; }
    .mov-stat { flex: 1 1 140px; background: #fff; border-radius: 8px; padding: 12px 16px; box-shadow: 0 1px 3px rgba(0,0,0,.12); display: flex; flex-direction: column; }
    .mov-stat-n { font-size: 1.6rem; font-weight: 700; color: #283593; line-height: 1.1; }
    .mov-stat.alerta .mov-stat-n { color: #c62828; }
    .mov-stat-l { color: #757575; font-size: .85rem; }
    .mov-quien { display: flex; flex-wrap: wrap; gap: 8px; margin-top: 8px; }
    .mov-quien-item { display: flex; flex-direction: column; background: #f5f5f5; border-radius: 8px; padding: 8px 12px; text-decoration: none; color: #212121; min-width: 170px; }
    .mov-quien-item:hover { background: #e8eaf6; }
    .mov-quien-nombre { font-weight: 600; }
    .mov-quien-datos { font-size: .85rem; }

    /* Lista de movimientos: una fila por evento (tarjeta apilada en celular) */
    .mov-item { display: grid; grid-template-columns: 92px 170px 1fr 190px; gap: 10px 16px; padding: 12px 16px; border-bottom: 1px solid #eee; border-left: 4px solid transparent; align-items: start; }
    .mov-item:last-child { border-bottom: none; }
    .sev-borde-alerta { border-left-color: #e53935; background: #fff8f8; }
    .sev-borde-aviso { border-left-color: #fb8c00; }
    .mov-hora, .mov-actor, .mov-donde { display: flex; flex-direction: column; gap: 2px; font-size: .88rem; }
    .mov-actor-nombre { font-weight: 600; }
    .mov-que { min-width: 0; }
    .mov-detalle { margin-top: 4px; color: #424242; font-size: .9rem; word-break: break-word; }
    .mov-registro { margin-left: 6px; font-size: .85rem; color: #283593; }
    .mov-registro-plano { margin-left: 6px; font-size: .85rem; }
    .sev { display: inline-block; font-size: .68rem; font-weight: 700; padding: 2px 7px; border-radius: 4px; letter-spacing: .04em; margin-right: 4px; }
    .sev-alerta { background: #e53935; color: #fff; }
    .sev-aviso { background: #fb8c00; color: #fff; }
    .sev-info { background: #cfd8dc; color: #37474f; }
    .mov-cambios-wrap { margin-top: 6px; }
    .mov-cambios-wrap summary { cursor: pointer; color: #283593; font-size: .85rem; font-weight: 600; }
    table.mov-cambios { margin-top: 6px; font-size: .82rem; width: 100%; }
    table.mov-cambios th, table.mov-cambios td { padding: 4px 8px; text-align: left; word-break: break-word; }
    table.mov-cambios td.antes { color: #b71c1c; }
    table.mov-cambios td.despues { color: #1b5e20; font-weight: 600; }

    /* Tabla en pantallas medianas/grandes, tarjetas apiladas en celular (ver comentario
       junto al markup de .logs-cards-wrap arriba). */
    .logs-cards-wrap { display: none; }

    @media only screen and (max-width: 992px) {
        .mov-item { grid-template-columns: 1fr 1fr; }
        .mov-que { grid-column: 1 / -1; order: 3; }
        .mov-donde { grid-column: 1 / -1; order: 4; flex-direction: row; flex-wrap: wrap; gap: 4px 12px; }
    }

    @media only screen and (max-width: 600px) {
        .logs-table-wrap { display: none; }
        .logs-cards-wrap { display: block; }

        .log-card {
            padding: 12px 16px;
            border-bottom: 1px solid #eee;
        }
        .log-card:last-child { border-bottom: none; }
        .log-card-row {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            gap: 10px;
            padding: 3px 0;
            font-size: 0.85rem;
        }
        .log-card-row-block {
            flex-direction: column;
            gap: 2px;
        }
        .log-card-label {
            color: #757575;
            font-weight: 600;
            flex-shrink: 0;
        }
        .log-card-value {
            text-align: right;
            word-break: break-word;
        }
        .log-card-value-block {
            text-align: left;
        }

        /* Encabezado y filtros: botones/enlaces con area de toque completa en vez de
           quedar apretados junto al texto. */
        .collapsible-header {
            padding: 12px 16px;
        }
        .collapsible-header strong {
            font-size: 0.95rem;
        }
    }
</style>

<script>
    document.addEventListener('DOMContentLoaded', function() {
        const elems = document.querySelectorAll('.collapsible');
        if (elems.length) {
            M.Collapsible.init(elems, { accordion: false });
        }
    });
</script>

<?php include __DIR__ . '/includes/footer.php'; ?>
