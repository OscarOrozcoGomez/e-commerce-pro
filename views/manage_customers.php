<?php
declare(strict_types=1);
require_once __DIR__ . '/../core/config.php';
require_once __DIR__ . '/../core/auth.php';
requireAuth();
if (!isAdmin()) { header('Location: dashboard.php'); exit; }

$pdo = getPDO();
$error = '';
$success = '';

$hasClienteDireccionesTable = false;
try {
    $stmtMeta = $pdo->prepare("SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'cliente_direcciones'");
    $stmtMeta->execute();
    $hasClienteDireccionesTable = ((int)$stmtMeta->fetchColumn()) > 0;
} catch (Throwable $e) {
    $hasClienteDireccionesTable = false;
}

$decryptValue = static function (?string $value): string {
    $value = trim((string)$value);
    if ($value !== '' && function_exists('piiIsEncryptedValue') && function_exists('piiDecryptValue') && piiIsEncryptedValue($value)) {
        return trim((string)piiDecryptValue($value));
    }
    return $value;
};

$safeDisplayValue = static function (?string $value, string $fallback = '') use ($decryptValue): string {
    $raw = trim((string)$value);
    if ($raw === '') {
        return $fallback;
    }

    $decrypted = $decryptValue($raw);
    if (
        $decrypted === $raw
        && function_exists('piiIsEncryptedValue')
        && piiIsEncryptedValue($raw)
    ) {
        return $fallback;
    }

    return $decrypted;
};

$storeValue = static function (?string $value): ?string {
    $value = $value !== null ? trim($value) : null;
    if ($value === null || $value === '') {
        return $value;
    }
    return function_exists('piiEncryptValue') ? piiEncryptValue($value) : $value;
};

$normalizePhone = static function (string $phone): ?string {
    $digits = preg_replace('/\D+/', '', $phone);
    if (!is_string($digits)) {
        return null;
    }
    if ($digits === '') {
        return '';
    }
    if (strlen($digits) !== 10) {
        return null;
    }
    return sprintf('(%s) - %s - %s', substr($digits, 0, 3), substr($digits, 3, 3), substr($digits, 6, 4));
};

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['accion'])) {
    if (!validateCsrfToken($_POST['csrf_token'] ?? '')) {
        $error = 'Token CSRF invalido.';
    } else {
        $accion = trim((string)($_POST['accion'] ?? ''));
        $idUsuario = (int)($_POST['id_usuario'] ?? 0);
        $idCliente = (int)($_POST['id_cliente'] ?? 0);

        try {
            if ($accion === 'activar') {
                if ($idUsuario > 0) {
                    $pdo->prepare("UPDATE usuarios SET estado = 'activo' WHERE id_usuario = ?")->execute([$idUsuario]);
                }
                if ($idCliente > 0) {
                    $pdo->prepare("UPDATE clientes SET estado = 'activo' WHERE id_cliente = ?")->execute([$idCliente]);
                }
                $success = 'Cliente activado.';
            } elseif ($accion === 'desactivar') {
                if ($idUsuario > 0) {
                    $pdo->prepare("UPDATE usuarios SET estado = 'inactivo' WHERE id_usuario = ?")->execute([$idUsuario]);
                }
                if ($idCliente > 0) {
                    $pdo->prepare("UPDATE clientes SET estado = 'inactivo' WHERE id_cliente = ?")->execute([$idCliente]);
                }
                $success = 'Cliente bloqueado.';
            } elseif ($accion === 'eliminar_cliente') {
                if ($idCliente <= 0) {
                    throw new Exception('Cliente invalido para eliminar.');
                }

                $stmtClienteDelete = $pdo->prepare('SELECT id_cliente, id_usuario, nombre FROM clientes WHERE id_cliente = ? LIMIT 1');
                $stmtClienteDelete->execute([$idCliente]);
                $clienteDelete = $stmtClienteDelete->fetch(PDO::FETCH_ASSOC) ?: null;
                if (!$clienteDelete) {
                    throw new Exception('El cliente ya no existe o fue eliminado previamente.');
                }

                $idUsuarioVinculado = (int)($clienteDelete['id_usuario'] ?? 0);
                if ($idUsuarioVinculado > 0 || $idUsuario > 0) {
                    throw new Exception('No se puede eliminar un cliente con acceso web vinculado. Bloquealo o revisalo manualmente.');
                }

                $pdo->beginTransaction();
                $pdo->prepare('DELETE FROM clientes WHERE id_cliente = ?')->execute([$idCliente]);
                $pdo->commit();

                $nombreEliminado = $safeDisplayValue((string)($clienteDelete['nombre'] ?? ''), 'Cliente');
                $success = 'Cliente eliminado correctamente: ' . $nombreEliminado . '.';
            } elseif ($accion === 'crear_cliente') {
                $nombre = trim((string)($_POST['nombre'] ?? ''));
                $email = trim((string)($_POST['email'] ?? ''));
                $telefonoNormalizado = $normalizePhone((string)($_POST['telefono'] ?? ''));
                $aliasDireccion = trim((string)($_POST['direccion_alias'] ?? ''));
                $direccion = trim((string)($_POST['direccion'] ?? ''));
                $mapsLink = trim((string)($_POST['maps_link'] ?? ''));

                if ($nombre === '') {
                    throw new Exception('El nombre del cliente es obligatorio.');
                }
                if ($telefonoNormalizado === null) {
                    throw new Exception('Si capturas telefono, debe tener 10 digitos.');
                }
                if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
                    throw new Exception('El correo capturado no es valido.');
                }
                if ($aliasDireccion !== '' && mb_strlen($aliasDireccion) > 50) {
                    throw new Exception('El alias de la direccion no puede exceder 50 caracteres.');
                }

                $pdo->beginTransaction();
                $stmtInsert = $pdo->prepare("INSERT INTO clientes (nombre, email, telefono, estado) VALUES (?, ?, ?, 'activo')");
                $stmtInsert->execute([
                    $storeValue($nombre),
                    $storeValue($email !== '' ? $email : null),
                    $storeValue($telefonoNormalizado),
                ]);
                $nuevoClienteId = (int)$pdo->lastInsertId();

                if ($hasClienteDireccionesTable && $direccion !== '') {
                    $stmtDir = $pdo->prepare('INSERT INTO cliente_direcciones (id_cliente, alias, direccion, maps_link, es_default) VALUES (?, ?, ?, ?, 1)');
                    $stmtDir->execute([
                        $nuevoClienteId,
                        $storeValue($aliasDireccion !== '' ? $aliasDireccion : 'Direccion 1'),
                        $storeValue($direccion),
                        $storeValue($mapsLink),
                    ]);
                }

                $pdo->commit();
                $success = 'Cliente creado correctamente.';
            } elseif ($accion === 'editar_cliente') {
                $nombre = trim((string)($_POST['nombre'] ?? ''));
                $email = trim((string)($_POST['email'] ?? ''));
                $telefonoNormalizado = $normalizePhone((string)($_POST['telefono'] ?? ''));

                if ($idCliente <= 0) {
                    throw new Exception('Cliente invalido para editar.');
                }
                if ($nombre === '') {
                    throw new Exception('El nombre del cliente es obligatorio.');
                }
                if ($telefonoNormalizado === null) {
                    throw new Exception('Si capturas telefono, debe tener 10 digitos.');
                }
                if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
                    throw new Exception('El correo capturado no es valido.');
                }

                $stmtUpdate = $pdo->prepare('UPDATE clientes SET nombre = ?, email = ?, telefono = ? WHERE id_cliente = ?');
                $stmtUpdate->execute([
                    $storeValue($nombre),
                    $storeValue($email !== '' ? $email : null),
                    $storeValue($telefonoNormalizado),
                    $idCliente,
                ]);
                $success = 'Cliente actualizado correctamente.';
            } elseif ($accion === 'agregar_direccion' || $accion === 'editar_direccion') {
                if (!$hasClienteDireccionesTable) {
                    throw new Exception('La tabla de direcciones no esta disponible.');
                }

                $idDireccion = (int)($_POST['id_direccion'] ?? 0);
                $alias = trim((string)($_POST['alias'] ?? ''));
                $direccion = trim((string)($_POST['direccion'] ?? ''));
                $mapsLink = trim((string)($_POST['maps_link'] ?? ''));
                $setDefault = ((int)($_POST['es_default'] ?? 0)) === 1;

                if ($idCliente <= 0) {
                    throw new Exception('Cliente invalido para administrar direcciones.');
                }
                if ($alias === '' || $direccion === '') {
                    throw new Exception('Alias y direccion son obligatorios.');
                }
                if (mb_strlen($alias) > 50) {
                    throw new Exception('El alias de la direccion no puede exceder 50 caracteres.');
                }

                $pdo->beginTransaction();
                if ($setDefault) {
                    $pdo->prepare('UPDATE cliente_direcciones SET es_default = 0 WHERE id_cliente = ?')->execute([$idCliente]);
                }

                if ($accion === 'agregar_direccion') {
                    $stmtCount = $pdo->prepare('SELECT COUNT(*) FROM cliente_direcciones WHERE id_cliente = ?');
                    $stmtCount->execute([$idCliente]);
                    if ((int)$stmtCount->fetchColumn() >= 5) {
                        throw new Exception('Limite de 5 direcciones alcanzado para este cliente.');
                    }

                    $stmtInsertDir = $pdo->prepare('INSERT INTO cliente_direcciones (id_cliente, alias, direccion, maps_link, es_default) VALUES (?, ?, ?, ?, ?)');
                    $stmtInsertDir->execute([
                        $idCliente,
                        $storeValue($alias),
                        $storeValue($direccion),
                        $storeValue($mapsLink),
                        $setDefault ? 1 : 0,
                    ]);
                } else {
                    if ($idDireccion <= 0) {
                        throw new Exception('Direccion invalida para editar.');
                    }
                    $stmtUpdateDir = $pdo->prepare('UPDATE cliente_direcciones SET alias = ?, direccion = ?, maps_link = ?, es_default = ? WHERE id_direccion = ? AND id_cliente = ?');
                    $stmtUpdateDir->execute([
                        $storeValue($alias),
                        $storeValue($direccion),
                        $storeValue($mapsLink),
                        $setDefault ? 1 : 0,
                        $idDireccion,
                        $idCliente,
                    ]);
                }

                $stmtHasDefault = $pdo->prepare('SELECT COUNT(*) FROM cliente_direcciones WHERE id_cliente = ? AND es_default = 1');
                $stmtHasDefault->execute([$idCliente]);
                if ((int)$stmtHasDefault->fetchColumn() === 0) {
                    $pdo->prepare('UPDATE cliente_direcciones SET es_default = 1 WHERE id_cliente = ? ORDER BY id_direccion ASC LIMIT 1')->execute([$idCliente]);
                }

                $pdo->commit();
                $success = $accion === 'agregar_direccion' ? 'Direccion agregada correctamente.' : 'Direccion actualizada correctamente.';
            } elseif ($accion === 'set_default_direccion') {
                $idDireccion = (int)($_POST['id_direccion'] ?? 0);
                if (!$hasClienteDireccionesTable || $idCliente <= 0 || $idDireccion <= 0) {
                    throw new Exception('No se pudo definir la direccion predeterminada.');
                }
                $pdo->beginTransaction();
                $pdo->prepare('UPDATE cliente_direcciones SET es_default = 0 WHERE id_cliente = ?')->execute([$idCliente]);
                $pdo->prepare('UPDATE cliente_direcciones SET es_default = 1 WHERE id_direccion = ? AND id_cliente = ?')->execute([$idDireccion, $idCliente]);
                $pdo->commit();
                $success = 'Direccion predeterminada actualizada.';
            } elseif ($accion === 'eliminar_direccion') {
                $idDireccion = (int)($_POST['id_direccion'] ?? 0);
                if (!$hasClienteDireccionesTable || $idCliente <= 0 || $idDireccion <= 0) {
                    throw new Exception('No se pudo eliminar la direccion.');
                }
                $pdo->beginTransaction();
                $stmtWasDefault = $pdo->prepare('SELECT es_default FROM cliente_direcciones WHERE id_direccion = ? AND id_cliente = ? LIMIT 1');
                $stmtWasDefault->execute([$idDireccion, $idCliente]);
                $wasDefault = ((int)$stmtWasDefault->fetchColumn()) === 1;
                $pdo->prepare('DELETE FROM cliente_direcciones WHERE id_direccion = ? AND id_cliente = ?')->execute([$idDireccion, $idCliente]);
                if ($wasDefault) {
                    $pdo->prepare('UPDATE cliente_direcciones SET es_default = 1 WHERE id_cliente = ? ORDER BY id_direccion ASC LIMIT 1')->execute([$idCliente]);
                }
                $pdo->commit();
                $success = 'Direccion eliminada.';
            }
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            $error = $e->getMessage();
        }
    }
}

$clientes = $pdo->query("SELECT c.*, u.id_usuario, u.estado AS estado_usuario, u.contrasena, COALESCE(u.estado, c.estado, 'activo') AS estado_visible, CASE WHEN u.id_usuario IS NULL THEN 'sucursal' ELSE 'sitio_web' END AS origen_registro, CASE WHEN u.id_usuario IS NOT NULL AND u.contrasena IS NOT NULL AND TRIM(u.contrasena) <> '' THEN 1 ELSE 0 END AS tiene_acceso_web, (SELECT a.nombre FROM pedidos p0 INNER JOIN almacenes a ON a.id_almacen = p0.id_almacen WHERE p0.id_cliente = c.id_cliente ORDER BY p0.id_pedido ASC LIMIT 1) AS sucursal_origen FROM clientes c LEFT JOIN usuarios u ON c.id_usuario = u.id_usuario ORDER BY c.nombre ASC")->fetchAll(PDO::FETCH_ASSOC);

$idsClientes = [];
foreach ($clientes as &$cliente) {
    $cliente['nombre'] = $safeDisplayValue((string)($cliente['nombre'] ?? ''), 'Cliente protegido');
    $cliente['email'] = $safeDisplayValue((string)($cliente['email'] ?? ''), '');
    $cliente['telefono'] = $safeDisplayValue((string)($cliente['telefono'] ?? ''), '');
    $cliente['direcciones'] = [];
    $idsClientes[] = (int)($cliente['id_cliente'] ?? 0);
}
unset($cliente);

$direccionesPorCliente = [];
if ($hasClienteDireccionesTable && !empty($idsClientes)) {
    $idsClientes = array_values(array_filter(array_unique($idsClientes), static fn(int $id): bool => $id > 0));
    if (!empty($idsClientes)) {
        $placeholders = implode(', ', array_fill(0, count($idsClientes), '?'));
        $stmtDirecciones = $pdo->prepare("SELECT id_direccion, id_cliente, alias, direccion, maps_link, es_default FROM cliente_direcciones WHERE id_cliente IN ({$placeholders}) ORDER BY id_cliente ASC, es_default DESC, id_direccion ASC");
        $stmtDirecciones->execute($idsClientes);
        $direccionesRaw = $stmtDirecciones->fetchAll(PDO::FETCH_ASSOC);
        foreach ($direccionesRaw as $dir) {
            $dir['alias'] = $safeDisplayValue((string)($dir['alias'] ?? ''), 'Direccion ' . (string)($dir['id_direccion'] ?? ''));
            $dir['direccion'] = $safeDisplayValue((string)($dir['direccion'] ?? ''), 'Direccion protegida');
            $dir['maps_link'] = $safeDisplayValue((string)($dir['maps_link'] ?? ''), '');
            $direccionesPorCliente[(int)$dir['id_cliente']][] = $dir;
        }
    }
}

foreach ($clientes as &$cliente) {
    $cliente['direcciones'] = $direccionesPorCliente[(int)$cliente['id_cliente']] ?? [];
}
unset($cliente);

$sucursalesOrigen = [];
foreach ($clientes as $cl) {
    $suc = trim((string)($cl['sucursal_origen'] ?? ''));
    if ($suc !== '') {
        $sucursalesOrigen[$suc] = true;
    }
}
$sucursalesOrigen = array_keys($sucursalesOrigen);
natcasesort($sucursalesOrigen);

$pageTitle = 'Administrar Clientes';
include __DIR__ . '/includes/header.php';
?>
<style>
    .manage-customers-table td,
    .manage-customers-table th {
        vertical-align: top;
    }

    .manage-customers-table td {
        word-break: break-word;
        overflow-wrap: anywhere;
    }

    .manage-customers-actions {
        white-space: nowrap;
    }

    .manage-customers-badge-cell .badge,
    .manage-customers-badge-cell .new.badge {
        float: none !important;
        margin: 0;
    }
    
    .manage-customers-name {
        display: inline-block;
        max-width: 220px;
    }
</style>
<div class="container">
    <div class="row">
        <div class="col s12">
            <h4><i class="material-icons left blue-text">people</i> Gestion de Clientes</h4>
            <p class="grey-text">Aqui registras clientes nuevos, editas sus datos y administras uno o varios domicilios por alias.</p>
        </div>
    </div>

    <?php if ($success): ?><div class="card-panel green lighten-4 green-text"><?php echo esc($success); ?></div><?php endif; ?>
    <?php if ($error): ?><div class="card-panel red lighten-4 red-text"><?php echo esc($error); ?></div><?php endif; ?>

    <div class="card">
        <div class="card-content">
            <div style="display:flex; justify-content:space-between; align-items:center; gap:12px; flex-wrap:wrap;">
                <div>
                    <span class="card-title" style="margin-bottom: 4px;">Altas y cambios</span>
                    <p class="grey-text" style="margin:0;">Usa esta vista para crear clientes y administrar sus direcciones antes de agendar pedidos.</p>
                </div>
                <a href="#modal-crear-cliente" class="btn waves-effect waves-light blue darken-2 modal-trigger">
                    <i class="material-icons left">person_add</i>Nuevo cliente
                </a>
            </div>
        </div>
    </div>

    <div class="card">
        <div class="card-content" style="padding-bottom: 10px;">
            <span class="card-title" style="font-size: 1.2rem; margin-bottom: 10px;">Filtros rapidos</span>
            <div class="row" style="margin-bottom: 0;">
                <div class="col s12 m8 l9" style="display:flex; flex-wrap:wrap; gap:8px; align-items:center;">
                    <button type="button" class="btn-small blue waves-effect waves-light js-filter-btn" data-filter="todos">Todos</button>
                    <button type="button" class="btn-small teal waves-effect waves-light js-filter-btn" data-filter="sitio_web">Sitio Web</button>
                    <button type="button" class="btn-small indigo waves-effect waves-light js-filter-btn" data-filter="sucursal">Sucursal</button>
                    <button type="button" class="btn-small green waves-effect waves-light js-filter-btn" data-filter="acceso_si">Con acceso</button>
                    <button type="button" class="btn-small grey darken-1 waves-effect waves-light js-filter-btn" data-filter="acceso_no">Sin acceso</button>
                    <button type="button" class="btn-small light-green darken-2 waves-effect waves-light js-filter-btn" data-filter="activo">Activos</button>
                    <button type="button" class="btn-small orange darken-2 waves-effect waves-light js-filter-btn" data-filter="inactivo">Inactivos</button>
                </div>
                <div class="col s12 m4 l3">
                    <label for="filtro-sucursal" class="active">Sucursal origen</label>
                    <select id="filtro-sucursal" class="browser-default" style="margin-top: 4px;">
                        <option value="__todas__">Todas</option>
                        <option value="__sin_sucursal__">Sin sucursal detectada</option>
                        <?php foreach ($sucursalesOrigen as $suc): ?>
                            <option value="<?php echo esc(strtolower($suc)); ?>"><?php echo esc($suc); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>
            <div class="row" style="margin-top: 10px; margin-bottom: 0;">
                <div class="col s12">
                    <span class="grey-text text-darken-1">Mostrando <strong id="clientes-visibles"><?php echo count($clientes); ?></strong> de <strong id="clientes-total"><?php echo count($clientes); ?></strong> clientes.</span>
                </div>
            </div>
        </div>
    </div>

    <div class="card">
        <div class="card-content">
            <table class="striped responsive-table manage-customers-table">
                <thead>
                    <tr>
                        <th>Nombre</th>
                        <th>Telefono</th>
                        <th>Email</th>
                        <th>Direcciones</th>
                        <th>Origen</th>
                        <th>Sucursal Origen</th>
                        <th>Acceso Web</th>
                        <th>Estado</th>
                        <th class="center-align">Acciones</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($clientes as $c): ?>
                    <?php
                        $origenRegistro = (string)($c['origen_registro'] ?? 'sucursal');
                        $sucursalOrigen = trim((string)($c['sucursal_origen'] ?? ''));
                        $sucursalFiltro = $sucursalOrigen !== '' ? strtolower($sucursalOrigen) : '__sin_sucursal__';
                        $accesoWebFiltro = ((int)($c['tiene_acceso_web'] ?? 0) === 1) ? 'si' : 'no';
                        $estadoVisible = (string)($c['estado_visible'] ?? 'activo');
                        $direccionesCliente = $c['direcciones'] ?? [];
                        $resumenDirecciones = count($direccionesCliente);
                    ?>
                    <tr data-origen="<?php echo esc($origenRegistro); ?>" data-acceso-web="<?php echo esc($accesoWebFiltro); ?>" data-estado="<?php echo esc($estadoVisible); ?>" data-sucursal="<?php echo esc($sucursalFiltro); ?>">
                        <td><strong class="manage-customers-name"><?php echo esc((string)$c['nombre']); ?></strong></td>
                        <td><?php echo esc((string)($c['telefono'] ?: 'N/A')); ?></td>
                        <td><?php echo esc((string)($c['email'] ?: 'N/A')); ?></td>
                        <td class="manage-customers-badge-cell">
                            <?php if ($resumenDirecciones > 0): ?>
                                <span class="badge blue white-text" style="float:none;"><?php echo $resumenDirecciones; ?></span>
                            <?php else: ?>
                                <span class="grey-text text-darken-1">Sin direcciones</span>
                            <?php endif; ?>
                        </td>
                        <td class="manage-customers-badge-cell">
                            <?php if ($origenRegistro === 'sitio_web'): ?>
                                <span class="new badge teal" data-badge-caption="Sitio Web"></span>
                            <?php else: ?>
                                <span class="new badge indigo" data-badge-caption="Sucursal"></span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <?php if ($origenRegistro === 'sitio_web'): ?>
                                <span class="grey-text text-darken-1">N/A</span>
                            <?php elseif ($sucursalOrigen !== ''): ?>
                                <span><?php echo esc($sucursalOrigen); ?></span>
                            <?php else: ?>
                                <span class="grey-text text-darken-1">Sin sucursal detectada</span>
                            <?php endif; ?>
                        </td>
                        <td class="manage-customers-badge-cell">
                            <?php if ((int)$c['tiene_acceso_web'] === 1): ?>
                                <span class="new badge green" data-badge-caption="Si"></span>
                            <?php else: ?>
                                <span class="new badge grey" data-badge-caption="No"></span>
                            <?php endif; ?>
                        </td>
                        <td class="manage-customers-badge-cell">
                            <span class="badge <?php echo $estadoVisible === 'activo' ? 'green' : 'red'; ?> white-text" style="float:none;">
                                <?php echo strtoupper($estadoVisible); ?>
                            </span>
                        </td>
                        <td class="center-align manage-customers-actions">
                            <a href="#modal-editar-cliente-<?php echo (int)$c['id_cliente']; ?>" class="btn-small amber darken-2 waves-effect waves-light modal-trigger" title="Editar cliente">
                                <i class="material-icons">edit</i>
                            </a>
                            <a href="#modal-dir-<?php echo (int)$c['id_cliente']; ?>" class="btn-small blue waves-effect waves-light modal-trigger" title="Ver Direcciones">
                                <i class="material-icons">place</i>
                            </a>
                            <form method="POST" style="display:inline;">
                                <?php echo csrfInput(); ?>
                                <input type="hidden" name="id_usuario" value="<?php echo (int)($c['id_usuario'] ?? 0); ?>">
                                <input type="hidden" name="id_cliente" value="<?php echo (int)$c['id_cliente']; ?>">
                                <input type="hidden" name="accion" value="<?php echo $estadoVisible === 'activo' ? 'desactivar' : 'activar'; ?>">
                                <button type="submit" class="btn-small <?php echo $estadoVisible === 'activo' ? 'orange' : 'green'; ?> waves-effect waves-light" title="<?php echo $estadoVisible === 'activo' ? 'Bloquear' : 'Activar'; ?>">
                                    <i class="material-icons"><?php echo $estadoVisible === 'activo' ? 'block' : 'check'; ?></i>
                                </button>
                            </form>
                            <form method="POST" style="display:inline;">
                                <?php echo csrfInput(); ?>
                                <input type="hidden" name="id_usuario" value="<?php echo (int)($c['id_usuario'] ?? 0); ?>">
                                <input type="hidden" name="id_cliente" value="<?php echo (int)$c['id_cliente']; ?>">
                                <input type="hidden" name="accion" value="eliminar_cliente">
                                <button type="submit" class="btn-small red darken-2 waves-effect waves-light" title="Eliminar cliente" onclick="return confirm('¿Eliminar este cliente? Sus pedidos quedaran sin cliente asignado y sus direcciones se borraran.');">
                                    <i class="material-icons">delete_forever</i>
                                </button>
                            </form>
                        </td>
                    </tr>

                    <div id="modal-editar-cliente-<?php echo (int)$c['id_cliente']; ?>" class="modal" style="max-width: 640px;">
                        <div class="modal-content">
                            <h5>Editar cliente</h5>
                            <form method="POST">
                                <?php echo csrfInput(); ?>
                                <input type="hidden" name="accion" value="editar_cliente">
                                <input type="hidden" name="id_cliente" value="<?php echo (int)$c['id_cliente']; ?>">
                                <div class="row">
                                    <div class="input-field col s12 m6">
                                        <input type="text" name="nombre" required value="<?php echo esc((string)$c['nombre']); ?>">
                                        <label class="active">Nombre</label>
                                    </div>
                                    <div class="input-field col s12 m6">
                                        <input type="email" name="email" value="<?php echo esc((string)($c['email'] ?? '')); ?>">
                                        <label class="active">Email</label>
                                    </div>
                                </div>
                                <div class="row">
                                    <div class="input-field col s12 m6">
                                        <input type="tel" name="telefono" maxlength="19" inputmode="numeric" autocomplete="tel-national" value="<?php echo esc((string)($c['telefono'] ?? '')); ?>">
                                        <label class="active">Telefono</label>
                                    </div>
                                    <div class="col s12 m6" style="display:flex; align-items:center; min-height:72px; color:#546e7a;">
                                        Administra direcciones en el boton de ubicacion para agregar una o varias con alias.
                                    </div>
                                </div>
                                <div class="modal-footer" style="padding:0; background:transparent;">
                                    <a href="#!" class="modal-close waves-effect btn-flat">Cancelar</a>
                                    <button type="submit" class="btn blue darken-2 waves-effect waves-light">Guardar cambios</button>
                                </div>
                            </form>
                        </div>
                    </div>

                    <div id="modal-dir-<?php echo (int)$c['id_cliente']; ?>" class="modal modal-fixed-footer" style="max-width: 760px;">
                        <div class="modal-content">
                            <h5>Direcciones de <?php echo esc((string)$c['nombre']); ?></h5>
                            <p class="grey-text" style="margin-top:0;">Agrega una o varias direcciones con alias para que ventas y reparto puedan elegir correctamente el domicilio.</p>
                            <div class="divider"></div>
                            <div class="row" style="margin-top:20px;">
                                <div class="col s12 m6">
                                    <ul class="collection">
                                        <?php if (empty($direccionesCliente)): ?>
                                            <li class="collection-item grey-text center">Sin direcciones registradas.</li>
                                        <?php else: ?>
                                            <?php foreach ($direccionesCliente as $d): ?>
                                                <li class="collection-item">
                                                    <strong><?php echo esc((string)$d['alias']); ?></strong>
                                                    <?php if ((int)($d['es_default'] ?? 0) === 1): ?><span class="new badge blue" data-badge-caption="Predeterminada"></span><?php endif; ?><br>
                                                    <span class="grey-text text-darken-1"><?php echo esc((string)$d['direccion']); ?></span>
                                                    <?php if (trim((string)($d['maps_link'] ?? '')) !== ''): ?>
                                                        <div style="margin-top:6px;">
                                                            <a href="<?php echo esc((string)$d['maps_link']); ?>" target="_blank" rel="noopener noreferrer" class="blue-text">
                                                                <i class="material-icons tiny">map</i> Abrir mapa
                                                            </a>
                                                        </div>
                                                    <?php endif; ?>
                                                    <div style="margin-top:10px; display:flex; gap:8px; flex-wrap:wrap;">
                                                        <button type="button" class="btn-small amber darken-2 waves-effect waves-light" onclick='cargarEdicionDireccion(<?php echo (int)$c['id_cliente']; ?>, <?php echo json_encode([
                                                            'id_direccion' => (int)$d['id_direccion'],
                                                            'alias' => (string)$d['alias'],
                                                            'direccion' => (string)$d['direccion'],
                                                            'maps_link' => (string)($d['maps_link'] ?? ''),
                                                            'es_default' => ((int)($d['es_default'] ?? 0)) === 1,
                                                        ], JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP); ?>)'>Editar</button>
                                                        <?php if ((int)($d['es_default'] ?? 0) !== 1): ?>
                                                            <form method="POST" style="display:inline;">
                                                                <?php echo csrfInput(); ?>
                                                                <input type="hidden" name="accion" value="set_default_direccion">
                                                                <input type="hidden" name="id_cliente" value="<?php echo (int)$c['id_cliente']; ?>">
                                                                <input type="hidden" name="id_direccion" value="<?php echo (int)$d['id_direccion']; ?>">
                                                                <button type="submit" class="btn-small blue lighten-1 waves-effect waves-light">Predeterminada</button>
                                                            </form>
                                                        <?php endif; ?>
                                                        <form method="POST" style="display:inline;">
                                                            <?php echo csrfInput(); ?>
                                                            <input type="hidden" name="accion" value="eliminar_direccion">
                                                            <input type="hidden" name="id_cliente" value="<?php echo (int)$c['id_cliente']; ?>">
                                                            <input type="hidden" name="id_direccion" value="<?php echo (int)$d['id_direccion']; ?>">
                                                            <button type="submit" class="btn-small red waves-effect waves-light" onclick="return confirm('¿Eliminar esta direccion?')">Eliminar</button>
                                                        </form>
                                                    </div>
                                                </li>
                                            <?php endforeach; ?>
                                        <?php endif; ?>
                                    </ul>
                                </div>
                                <div class="col s12 m6">
                                    <div class="card-panel blue lighten-5" style="margin-top:0;">
                                        <strong id="dir-form-title-<?php echo (int)$c['id_cliente']; ?>">Agregar direccion</strong>
                                        <form method="POST" id="dir-form-<?php echo (int)$c['id_cliente']; ?>" style="margin-top:14px;">
                                            <?php echo csrfInput(); ?>
                                            <input type="hidden" name="accion" value="agregar_direccion" class="dir-accion">
                                            <input type="hidden" name="id_cliente" value="<?php echo (int)$c['id_cliente']; ?>">
                                            <input type="hidden" name="id_direccion" value="0" class="dir-id">
                                            <div class="input-field">
                                                <input type="text" name="alias" class="dir-alias" maxlength="50" required>
                                                <label class="active">Alias</label>
                                            </div>
                                            <div class="input-field">
                                                <textarea name="direccion" class="materialize-textarea dir-direccion" required></textarea>
                                                <label class="active">Direccion</label>
                                            </div>
                                            <div class="input-field">
                                                <input type="url" name="maps_link" class="dir-maps-link">
                                                <label class="active">Link de Google Maps</label>
                                            </div>
                                            <p>
                                                <label>
                                                    <input type="checkbox" name="es_default" value="1" class="filled-in dir-default">
                                                    <span>Marcar como predeterminada</span>
                                                </label>
                                            </p>
                                            <div style="display:flex; gap:8px; flex-wrap:wrap; margin-top:16px;">
                                                <button type="submit" class="btn blue darken-2 waves-effect waves-light">Guardar direccion</button>
                                                <button type="button" class="btn-flat waves-effect" onclick="resetDireccionForm(<?php echo (int)$c['id_cliente']; ?>)">Cancelar edicion</button>
                                            </div>
                                        </form>
                                    </div>
                                </div>
                            </div>
                        </div>
                        <div class="modal-footer">
                            <a href="#!" class="modal-close waves-effect waves-green btn-flat">Cerrar</a>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<div id="modal-crear-cliente" class="modal" style="max-width: 720px;">
    <div class="modal-content">
        <h5>Nuevo cliente</h5>
        <form method="POST">
            <?php echo csrfInput(); ?>
            <input type="hidden" name="accion" value="crear_cliente">
            <div class="row">
                <div class="input-field col s12 m6">
                    <input type="text" name="nombre" required>
                    <label>Nombre</label>
                </div>
                <div class="input-field col s12 m6">
                    <input type="email" name="email">
                    <label>Email</label>
                </div>
            </div>
            <div class="row">
                <div class="input-field col s12 m6">
                    <input type="tel" name="telefono" maxlength="19" inputmode="numeric" autocomplete="tel-national">
                    <label>Telefono</label>
                </div>
                <div class="col s12 m6" style="display:flex; align-items:center; min-height:72px; color:#546e7a;">
                    Si ya conoces su domicilio principal, tambien puedes capturarlo desde aqui.
                </div>
            </div>
            <div class="row">
                <div class="input-field col s12 m4">
                    <input type="text" name="direccion_alias" maxlength="50" placeholder="Ej: Casa, Oficina">
                    <label class="active">Alias de direccion</label>
                </div>
                <div class="input-field col s12 m8">
                    <textarea name="direccion" class="materialize-textarea"></textarea>
                    <label>Direccion principal</label>
                </div>
            </div>
            <div class="row">
                <div class="input-field col s12">
                    <input type="url" name="maps_link">
                    <label>Link de Google Maps</label>
                </div>
            </div>
            <div class="modal-footer" style="padding:0; background:transparent;">
                <a href="#!" class="modal-close waves-effect btn-flat">Cancelar</a>
                <button type="submit" class="btn blue darken-2 waves-effect waves-light">Crear cliente</button>
            </div>
        </form>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', () => {
    M.Modal.init(document.querySelectorAll('.modal'));
    M.updateTextFields();

    const tableRows = Array.from(document.querySelectorAll('table tbody tr[data-origen]'));
    const btns = Array.from(document.querySelectorAll('.js-filter-btn'));
    const sucursalSelect = document.getElementById('filtro-sucursal');
    const visibleEl = document.getElementById('clientes-visibles');
    const totalEl = document.getElementById('clientes-total');
    const total = tableRows.length;
    let activeFilter = 'todos';

    if (totalEl) totalEl.textContent = String(total);

    function matchesQuickFilter(row, filter) {
        const origen = row.getAttribute('data-origen') || '';
        const acceso = row.getAttribute('data-acceso-web') || '';
        const estado = row.getAttribute('data-estado') || '';

        if (filter === 'todos') return true;
        if (filter === 'sitio_web') return origen === 'sitio_web';
        if (filter === 'sucursal') return origen === 'sucursal';
        if (filter === 'acceso_si') return acceso === 'si';
        if (filter === 'acceso_no') return acceso === 'no';
        if (filter === 'activo') return estado === 'activo';
        if (filter === 'inactivo') return estado === 'inactivo';
        return true;
    }

    function matchesSucursal(row, sucursalValue) {
        const sucursal = row.getAttribute('data-sucursal') || '';
        if (sucursalValue === '__todas__') return true;
        return sucursal === sucursalValue;
    }

    function applyFilters() {
        const sucursalValue = (sucursalSelect?.value || '__todas__').toLowerCase();
        let visibles = 0;

        tableRows.forEach((row) => {
            const ok = matchesQuickFilter(row, activeFilter) && matchesSucursal(row, sucursalValue);
            row.style.display = ok ? '' : 'none';
            if (ok) visibles++;
        });

        if (visibleEl) visibleEl.textContent = String(visibles);
    }

    btns.forEach((btn) => {
        btn.addEventListener('click', () => {
            activeFilter = btn.getAttribute('data-filter') || 'todos';
            btns.forEach((b) => {
                b.classList.remove('darken-4');
                b.classList.add('lighten-1');
            });
            btn.classList.remove('lighten-1');
            btn.classList.add('darken-4');
            applyFilters();
        });
    });

    if (sucursalSelect) {
        sucursalSelect.addEventListener('change', applyFilters);
    }

    const defaultBtn = document.querySelector('.js-filter-btn[data-filter="todos"]');
    if (defaultBtn) {
        defaultBtn.classList.add('darken-4');
    }
    applyFilters();
});

function resetDireccionForm(idCliente) {
    const form = document.getElementById(`dir-form-${idCliente}`);
    if (!form) return;
    form.querySelector('.dir-accion').value = 'agregar_direccion';
    form.querySelector('.dir-id').value = '0';
    form.querySelector('.dir-alias').value = '';
    form.querySelector('.dir-direccion').value = '';
    form.querySelector('.dir-maps-link').value = '';
    form.querySelector('.dir-default').checked = false;
    const title = document.getElementById(`dir-form-title-${idCliente}`);
    if (title) title.textContent = 'Agregar direccion';
    M.updateTextFields();
    M.textareaAutoResize(form.querySelector('.dir-direccion'));
}

function cargarEdicionDireccion(idCliente, data) {
    const form = document.getElementById(`dir-form-${idCliente}`);
    if (!form || !data) return;
    form.querySelector('.dir-accion').value = 'editar_direccion';
    form.querySelector('.dir-id').value = String(data.id_direccion || 0);
    form.querySelector('.dir-alias').value = String(data.alias || '');
    form.querySelector('.dir-direccion').value = String(data.direccion || '');
    form.querySelector('.dir-maps-link').value = String(data.maps_link || '');
    form.querySelector('.dir-default').checked = !!data.es_default;
    const title = document.getElementById(`dir-form-title-${idCliente}`);
    if (title) title.textContent = 'Editar direccion';
    M.updateTextFields();
    M.textareaAutoResize(form.querySelector('.dir-direccion'));
}
</script>

<?php include __DIR__ . '/includes/footer.php'; ?>
