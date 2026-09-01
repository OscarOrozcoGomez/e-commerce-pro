<?php
declare(strict_types=1);
require_once __DIR__ . '/../core/config.php';
require_once __DIR__ . '/../core/auth.php';
require_once __DIR__ . '/../core/delivery_route_utils.php';
require_once __DIR__ . '/../core/whatsapp_link_utils.php';
require_once __DIR__ . '/../core/cliente_direccion_utils.php';
require_once __DIR__ . '/../core/cliente_loyalty_utils.php';
requireAuth();
// Permiso 'gestionar_clientes' abre esta vista; el rol se mantiene como respaldo.
if (!hasPermission('gestionar_clientes') && !isAdmin() && !isEncargado()) { header('Location: dashboard.php'); exit; }

$pdo = getPDO();
$error = '';
$success = '';
$sessionFlashKey = 'manage_customers_flash';

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

if (isset($_SESSION[$sessionFlashKey]) && is_array($_SESSION[$sessionFlashKey])) {
    $flash = $_SESSION[$sessionFlashKey];
    unset($_SESSION[$sessionFlashKey]);
    $error = isset($flash['type']) && $flash['type'] === 'error' ? (string)($flash['message'] ?? '') : $error;
    $success = isset($flash['type']) && $flash['type'] === 'success' ? (string)($flash['message'] ?? '') : $success;
}

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

/**
 * Resuelve lat/lng para un domicilio a partir de su link de Google Maps o,
 * si eso falla, geocodificando el texto de la direccion. El buscador de
 * direcciones (Google Places Autocomplete) ya arma un maps_link con las
 * coordenadas crudas (.../maps/search/?api=1&query=lat,lng), asi que en ese
 * caso se resuelve al instante sin llamadas de red adicionales.
 *
 * @return array{lat: float, lng: float}|null
 */
$resolveDireccionCoords = static function (string $mapsLink, string $direccion): ?array {
    static $apiKey = null;
    if ($apiKey === null) {
        $apiKey = getMapsApiKey(false);
    }

    $mapsLink = trim($mapsLink);
    if ($mapsLink !== '') {
        $coords = deliveryExtractCoordinatesFromMapsUrl($mapsLink);
        if ($coords !== null) {
            return $coords;
        }
        $coords = obtenerCoordenadasDesdeUrl($mapsLink, $apiKey);
        if ($coords !== null) {
            return $coords;
        }
    }

    $direccion = trim($direccion);
    return $direccion !== '' ? deliveryGeocodeAddress($direccion, $apiKey) : null;
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
                $pdo->beginTransaction();
                try {
                    if ($idUsuarioVinculado > 0) {
                        $pdo->prepare("UPDATE usuarios SET estado = 'inactivo' WHERE id_usuario = ?")->execute([$idUsuarioVinculado]);
                        $pdo->prepare('UPDATE clientes SET id_usuario = NULL WHERE id_cliente = ?')->execute([$idCliente]);
                    }
                    $pdo->prepare('DELETE FROM cliente_direcciones WHERE id_cliente = ?')->execute([$idCliente]);
                    $pdo->prepare('DELETE FROM cliente_horarios_entrega WHERE id_cliente = ?')->execute([$idCliente]);
                    $pdo->prepare('DELETE FROM clientes WHERE id_cliente = ?')->execute([$idCliente]);
                    $pdo->commit();
                } catch (Throwable $e) {
                    $pdo->rollBack();
                    throw $e;
                }

                $nombreEliminado = $safeDisplayValue((string)($clienteDelete['nombre'] ?? ''), 'Cliente');
                $success = 'Cliente eliminado correctamente: ' . $nombreEliminado . '. La cuenta web vinculada fue desactivada y desconectada.';
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
                    $coords = $resolveDireccionCoords($mapsLink, $direccion);
                    $stmtDir = $pdo->prepare('INSERT INTO cliente_direcciones (id_cliente, alias, direccion, maps_link, es_default, latitud, longitud) VALUES (?, ?, ?, ?, 1, ?, ?)');
                    $stmtDir->execute([
                        $nuevoClienteId,
                        $storeValue($aliasDireccion !== '' ? $aliasDireccion : 'Direccion 1'),
                        $storeValue($direccion),
                        $storeValue($mapsLink),
                        $coords['lat'] ?? null,
                        $coords['lng'] ?? null,
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

                $coords = $resolveDireccionCoords($mapsLink, $direccion);

                if ($accion === 'agregar_direccion') {
                    $stmtCount = $pdo->prepare('SELECT COUNT(*) FROM cliente_direcciones WHERE id_cliente = ?');
                    $stmtCount->execute([$idCliente]);
                    if ((int)$stmtCount->fetchColumn() >= 5) {
                        throw new Exception('Limite de 5 direcciones alcanzado para este cliente.');
                    }

                    $stmtInsertDir = $pdo->prepare('INSERT INTO cliente_direcciones (id_cliente, alias, direccion, maps_link, es_default, latitud, longitud) VALUES (?, ?, ?, ?, ?, ?, ?)');
                    $stmtInsertDir->execute([
                        $idCliente,
                        $storeValue($alias),
                        $storeValue($direccion),
                        $storeValue($mapsLink),
                        $setDefault ? 1 : 0,
                        $coords['lat'] ?? null,
                        $coords['lng'] ?? null,
                    ]);
                } else {
                    if ($idDireccion <= 0) {
                        throw new Exception('Direccion invalida para editar.');
                    }
                    // Cambiar la direccion invalida cualquier confirmacion previa del cliente:
                    // si se movio/corrigio, hay que volver a confirmarla por WhatsApp.
                    $stmtUpdateDir = $pdo->prepare('UPDATE cliente_direcciones SET alias = ?, direccion = ?, maps_link = ?, es_default = ?, latitud = ?, longitud = ?, confirmada_cliente = 0, confirmada_en = NULL, confirmada_por = NULL WHERE id_direccion = ? AND id_cliente = ?');
                    $stmtUpdateDir->execute([
                        $storeValue($alias),
                        $storeValue($direccion),
                        $storeValue($mapsLink),
                        $setDefault ? 1 : 0,
                        $coords['lat'] ?? null,
                        $coords['lng'] ?? null,
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
            } elseif ($accion === 'marcar_direccion_confirmada') {
                $idDireccion = (int)($_POST['id_direccion'] ?? 0);
                if (!$hasClienteDireccionesTable) {
                    throw new Exception('No se pudo confirmar la direccion.');
                }
                $idUsuarioActual = (int)($_SESSION['usuario']['id_usuario'] ?? 0);
                $resultadoConfirmar = dbConfirmarDireccionCliente($pdo, $idCliente, $idDireccion, $idUsuarioActual);
                if (!$resultadoConfirmar['success']) {
                    throw new Exception($resultadoConfirmar['message']);
                }
                $success = $resultadoConfirmar['message'];
            } elseif ($accion === 'guardar_horario_direccion') {
                if (!$hasClienteDireccionesTable) {
                    throw new Exception('La tabla de direcciones no esta disponible para horarios.');
                }

                $idDireccion = (int)($_POST['id_direccion'] ?? 0);
                $idHorario = (int)($_POST['id_horario'] ?? 0);
                $diaSemana = deliveryNormalizeDeliveryDay((string)($_POST['dia_semana'] ?? ''));
                $horaInicioInput = trim((string)($_POST['hora_inicio'] ?? '')) . ' ' . trim((string)($_POST['hora_inicio_meridiem'] ?? ''));
                $horaFinInput = trim((string)($_POST['hora_fin'] ?? '')) . ' ' . trim((string)($_POST['hora_fin_meridiem'] ?? ''));
                $horaInicio = deliveryNormalizeManualHour($horaInicioInput);
                $horaFin = deliveryNormalizeManualHour($horaFinInput);
                $activo = ((int)($_POST['activo'] ?? 1)) === 1 ? 1 : 0;
                $nota = trim((string)($_POST['nota'] ?? ''));

                if ($idCliente <= 0) {
                    throw new Exception('Cliente invalido para guardar el horario.');
                }
                if ($idDireccion <= 0) {
                    throw new Exception('Debes seleccionar un domicilio valido.');
                }
                if ($horaFin <= $horaInicio) {
                    throw new Exception('La hora final debe ser mayor que la hora de inicio.');
                }
                if (mb_strlen($nota) > 255) {
                    throw new Exception('La nota no puede exceder 255 caracteres.');
                }

                $existingStmt = $pdo->prepare('SELECT id_horario FROM cliente_horarios_entrega WHERE id_cliente = ? AND id_direccion = ? ORDER BY id_horario ASC LIMIT 1');
                $existingStmt->execute([$idCliente, $idDireccion]);
                $existingHorarioId = (int)($existingStmt->fetchColumn() ?? 0);

                if ($idHorario > 0) {
                    $existingHorarioId = $idHorario;
                } elseif ($existingHorarioId > 0) {
                    $existingHorarioId = $existingHorarioId;
                }

                if ($existingHorarioId > 0) {
                    $pdo->prepare('UPDATE cliente_horarios_entrega SET dia_semana = ?, hora_inicio = ?, hora_fin = ?, activo = ?, nota = ? WHERE id_horario = ? AND id_cliente = ?')
                        ->execute([$diaSemana, $horaInicio, $horaFin, $activo, $nota !== '' ? $nota : null, $existingHorarioId, $idCliente]);
                    $pdo->prepare('DELETE FROM cliente_horarios_entrega WHERE id_cliente = ? AND id_direccion = ? AND id_horario <> ?')->execute([$idCliente, $idDireccion, $existingHorarioId]);
                    $success = 'Horario de entrega actualizado.';
                } else {
                    $pdo->beginTransaction();
                    $pdo->prepare('INSERT INTO cliente_horarios_entrega (id_cliente, id_direccion, dia_semana, hora_inicio, hora_fin, activo, nota) VALUES (?, ?, ?, ?, ?, ?, ?)')
                        ->execute([$idCliente, $idDireccion, $diaSemana, $horaInicio, $horaFin, $activo, $nota !== '' ? $nota : null]);
                    $lastInsertedId = (int)$pdo->lastInsertId();
                    $pdo->prepare('DELETE FROM cliente_horarios_entrega WHERE id_cliente = ? AND id_direccion = ? AND id_horario <> ?')->execute([$idCliente, $idDireccion, $lastInsertedId]);
                    $pdo->commit();
                    $success = 'Horario de entrega guardado.';
                }
            }
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            $error = $e->getMessage();
        }

        if ($success !== '' || $error !== '') {
            $_SESSION[$sessionFlashKey] = [
                'type' => $error !== '' ? 'error' : 'success',
                'message' => $error !== '' ? $error : $success,
            ];
            header('Location: ' . basename($_SERVER['PHP_SELF']));
            exit;
        }
    }
}

$clientesFrecuentesIds = clienteFrecuenteGetIds($pdo);

$clientes = $pdo->query("SELECT c.*, u.id_usuario, u.estado AS estado_usuario, u.contrasena, COALESCE(u.estado, c.estado, 'activo') AS estado_visible, CASE WHEN u.id_usuario IS NULL THEN 'sucursal' ELSE 'sitio_web' END AS origen_registro, CASE WHEN u.id_usuario IS NOT NULL AND u.contrasena IS NOT NULL AND TRIM(u.contrasena) <> '' THEN 1 ELSE 0 END AS tiene_acceso_web, (SELECT a.nombre FROM pedidos p0 INNER JOIN almacenes a ON a.id_almacen = p0.id_almacen WHERE p0.id_cliente = c.id_cliente ORDER BY p0.id_pedido ASC LIMIT 1) AS sucursal_origen FROM clientes c LEFT JOIN usuarios u ON c.id_usuario = u.id_usuario ORDER BY c.nombre ASC")->fetchAll(PDO::FETCH_ASSOC);

$horariosPorCliente = [];
try {
    $stmtHorarios = $pdo->prepare("SELECT id_horario, id_cliente, id_direccion, dia_semana, hora_inicio, hora_fin, activo, nota FROM cliente_horarios_entrega ORDER BY id_cliente ASC, id_direccion ASC, dia_semana ASC");
    $stmtHorarios->execute();
    foreach ($stmtHorarios->fetchAll(PDO::FETCH_ASSOC) as $horario) {
        $clienteId = (int)($horario['id_cliente'] ?? 0);
        $horariosPorCliente[$clienteId][] = $horario;
    }
} catch (Throwable $e) {
    $horariosPorCliente = [];
}

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
        $addressCountersByClient = [];
        $placeholders = implode(', ', array_fill(0, count($idsClientes), '?'));
        $stmtDirecciones = $pdo->prepare("SELECT id_direccion, id_cliente, alias, direccion, maps_link, es_default, confirmada_cliente, confirmada_en FROM cliente_direcciones WHERE id_cliente IN ({$placeholders}) ORDER BY id_cliente ASC, es_default DESC, id_direccion ASC");
        $stmtDirecciones->execute($idsClientes);
        $direccionesRaw = $stmtDirecciones->fetchAll(PDO::FETCH_ASSOC);
        foreach ($direccionesRaw as $dir) {
            $clienteDireccionId = (int)($dir['id_cliente'] ?? 0);
            if (!isset($addressCountersByClient[$clienteDireccionId])) {
                $addressCountersByClient[$clienteDireccionId] = 0;
            }
            $addressCountersByClient[$clienteDireccionId]++;
            $dir['alias'] = $safeDisplayValue((string)($dir['alias'] ?? ''), '');
            $dir['alias'] = deliveryFormatAddressAlias($dir['alias'], (int)$addressCountersByClient[$clienteDireccionId]);
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

// El ORDER BY del query ordena el texto cifrado (no el nombre real) cuando el
// cifrado de PII esta activo, asi que el orden alfabetico real se aplica aqui,
// ya con los nombres descifrados en memoria.
$normalizeForSort = static function (string $value): string {
    $ascii = @iconv('UTF-8', 'ASCII//TRANSLIT', $value);
    if (!is_string($ascii) || trim($ascii) === '') {
        $ascii = $value;
    }
    return mb_strtoupper(trim($ascii), 'UTF-8');
};

usort($clientes, static function (array $a, array $b) use ($normalizeForSort): int {
    return strcmp(
        $normalizeForSort((string)($a['nombre'] ?? '')),
        $normalizeForSort((string)($b['nombre'] ?? ''))
    );
});

// Indice alfabetico: para cada letra, el id_cliente del primer cliente (ya
// ordenado) cuyo nombre comienza con esa letra; sirve de ancla para el
// scroll al hacer click en la barra A-Z.
$alphabetFirstClientId = [];
foreach ($clientes as $clienteAlpha) {
    $nombreNormalizado = $normalizeForSort((string)($clienteAlpha['nombre'] ?? ''));
    $primeraLetra = ($nombreNormalizado !== '' && preg_match('/^[A-Z]/', $nombreNormalizado) === 1)
        ? $nombreNormalizado[0]
        : '#';
    if (!isset($alphabetFirstClientId[$primeraLetra])) {
        $alphabetFirstClientId[$primeraLetra] = (int)($clienteAlpha['id_cliente'] ?? 0);
    }
}
$alphabetLetterByFirstClientId = array_flip($alphabetFirstClientId);

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

    .manage-customers-phone {
        display: inline-block;
        white-space: nowrap;
        min-width: 132px;
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

    .manage-customers-toolbar {
        display: flex;
        flex-wrap: wrap;
        gap: 16px;
        align-items: flex-end;
        margin-bottom: 18px;
    }

    .manage-customers-toolbar-item {
        min-width: 180px;
        flex: 1 1 180px;
    }

    .manage-customers-toolbar-item label {
        display: block;
        font-size: 0.9rem;
        color: #546e7a;
        margin-bottom: 6px;
    }

    .manage-customers-toolbar-item select {
        width: 100%;
    }

    .manage-customers-col-hidden {
        display: none;
    }

    /* Tabla clasica: visible en escritorio/tablet, con scroll horizontal solo como respaldo */
    .manage-customers-table-wrap {
        overflow-x: auto;
        -webkit-overflow-scrolling: touch;
    }

    /* Tarjetas moviles: ocultas por defecto, se muestran solo en pantallas angostas */
    .manage-customers-cards {
        display: none;
    }

    @media (max-width: 992px) {
        .manage-customers-table-wrap {
            display: none;
        }

        .manage-customers-cards {
            display: block;
        }
    }

    .manage-customers-card {
        background: #fff;
        border: 1px solid #e0e0e0;
        border-radius: 8px;
        padding: 14px 16px;
        margin-bottom: 12px;
        box-shadow: 0 1px 3px rgba(0, 0, 0, 0.08);
    }

    .manage-customers-card-header {
        display: flex;
        justify-content: space-between;
        align-items: flex-start;
        gap: 10px;
        margin-bottom: 8px;
    }

    .manage-customers-card-name {
        font-size: 1.05rem;
        font-weight: 600;
        color: #212121;
        word-break: break-word;
    }

    .manage-customers-card-field {
        display: flex;
        justify-content: space-between;
        align-items: flex-start;
        gap: 12px;
        padding: 7px 0;
        border-top: 1px solid #f0f0f0;
        font-size: 0.9rem;
    }

    .manage-customers-card-field-label {
        color: #78909c;
        font-weight: 500;
        flex: 0 0 auto;
    }

    .manage-customers-card-field-value {
        text-align: right;
        color: #37474f;
        word-break: break-word;
        overflow-wrap: anywhere;
    }

    .manage-customers-card-field-value .badge,
    .manage-customers-card-field-value .new.badge {
        float: none !important;
        margin: 0;
    }

    .manage-customers-card-actions {
        display: flex;
        gap: 8px;
        flex-wrap: wrap;
        margin-top: 12px;
        padding-top: 12px;
        border-top: 1px solid #f0f0f0;
    }

    .manage-customers-card-actions form {
        display: flex;
        flex: 1 1 auto;
    }

    .manage-customers-card-actions .btn-small {
        width: 100%;
        display: inline-flex;
        align-items: center;
        justify-content: center;
    }

    .manage-customers-card-actions a.btn-small {
        flex: 1 1 auto;
        min-width: 44px;
    }

    .modal {
        max-width: 960px !important;
        width: 92% !important;
    }

    .modal-content {
        max-height: 82vh;
        overflow-y: auto;
    }

    /* Modal de direcciones: pantalla completa en moviles para facilitar el uso tactil */
    @media (max-width: 600px) {
        .manage-customers-direcciones-modal {
            width: 100% !important;
            max-width: 100% !important;
            height: 100% !important;
            max-height: 100% !important;
            top: 0 !important;
            margin: 0 !important;
            border-radius: 0 !important;
        }

        .manage-customers-direcciones-modal .modal-content {
            max-height: calc(100% - 56px);
        }

        .manage-customers-direcciones-modal .modal-footer {
            padding: 10px 16px;
        }

        .manage-customers-direcciones-modal .card-panel {
            padding: 16px 14px;
        }

        .manage-customers-direcciones-modal .card-panel .btn,
        .manage-customers-direcciones-modal .card-panel .btn-small,
        .manage-customers-direcciones-modal .card-panel .btn-flat {
            min-height: 42px;
        }
    }

    /* Buscador de direccion tipo cart.php dentro del modal */
    .pac-container {
        z-index: 3000 !important;
        border-radius: 4px;
    }

    .manual-time-input {
        font-size: 1.1rem;
        letter-spacing: 0.05em;
    }

    .alpha-index-nav {
        position: fixed;
        top: 50%;
        right: 6px;
        transform: translateY(-50%);
        z-index: 998;
        display: flex;
        flex-direction: column;
        align-items: center;
        gap: 0;
        background: rgba(255, 255, 255, 0.92);
        border: 1px solid #e0e0e0;
        border-radius: 14px;
        padding: 6px 2px;
        box-shadow: 0 1px 5px rgba(0, 0, 0, 0.15);
        max-height: 80vh;
        overflow-y: auto;
    }

    .alpha-index-letter {
        display: block;
        width: 18px;
        text-align: center;
        font-size: 0.66rem;
        line-height: 1.5;
        font-weight: 700;
        color: #1565c0;
        cursor: pointer;
        user-select: none;
        border-radius: 3px;
    }

    .alpha-index-letter:hover {
        background: #e3f2fd;
    }

    .alpha-index-letter-disabled {
        color: #cfd8dc;
        cursor: default;
        pointer-events: none;
    }

    @media (max-width: 480px) {
        .alpha-index-nav {
            right: 2px;
            padding: 4px 1px;
        }

        .alpha-index-letter {
            width: 15px;
            font-size: 0.58rem;
        }
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

    <div class="alpha-index-nav" id="alpha-index-nav">
        <?php foreach (array_merge(range('A', 'Z'), ['#']) as $letraIndice): ?>
            <?php $tieneClientes = isset($alphabetFirstClientId[$letraIndice]); ?>
            <a href="#!" class="alpha-index-letter<?php echo $tieneClientes ? '' : ' alpha-index-letter-disabled'; ?>" data-letter="<?php echo esc($letraIndice); ?>"><?php echo esc($letraIndice); ?></a>
        <?php endforeach; ?>
    </div>

    <div class="card">
        <div class="card-content">
            <div style="display:flex; justify-content:space-between; align-items:flex-start; gap:16px; flex-wrap:wrap; margin-bottom:16px;">
                <div>
                    <span class="card-title" style="font-size: 1.2rem; margin-bottom: 4px;">Clientes registrados</span>
                    <span class="grey-text text-darken-1">Mostrando <strong id="clientes-visibles"><?php echo count($clientes); ?></strong> de <strong id="clientes-total"><?php echo count($clientes); ?></strong> clientes.</span>
                </div>
            </div>
            <div class="input-field" style="margin-top:0; margin-bottom:20px;">
                <i class="material-icons prefix">search</i>
                <input type="text" id="buscar-cliente" autocomplete="off">
                <label for="buscar-cliente">Buscar cliente por nombre</label>
            </div>
            <div class="manage-customers-toolbar">
                <div class="manage-customers-toolbar-item">
                    <label for="filtro-origen">Origen</label>
                    <select id="filtro-origen" class="browser-default">
                        <option value="todos">Todos</option>
                        <option value="sitio_web">Sitio Web</option>
                        <option value="sucursal">Sucursal</option>
                    </select>
                </div>
                <div class="manage-customers-toolbar-item">
                    <label for="filtro-acceso">Acceso web</label>
                    <select id="filtro-acceso" class="browser-default">
                        <option value="todos">Todos</option>
                        <option value="acceso_si">Con acceso</option>
                        <option value="acceso_no">Sin acceso</option>
                    </select>
                </div>
                <div class="manage-customers-toolbar-item">
                    <label for="filtro-estado">Estado</label>
                    <select id="filtro-estado" class="browser-default">
                        <option value="todos">Todos</option>
                        <option value="activo">Activos</option>
                        <option value="inactivo">Inactivos</option>
                    </select>
                </div>
                <div class="manage-customers-toolbar-item">
                    <label for="filtro-sucursal">Sucursal origen</label>
                    <select id="filtro-sucursal" class="browser-default">
                        <option value="__todas__">Todas</option>
                        <option value="__sin_sucursal__">Sin sucursal detectada</option>
                        <?php foreach ($sucursalesOrigen as $suc): ?>
                            <option value="<?php echo esc(strtolower($suc)); ?>"><?php echo esc($suc); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="manage-customers-toolbar-item">
                    <label for="filtro-columnas">Vista de columnas</label>
                    <select id="filtro-columnas" class="browser-default">
                        <option value="operativa">Operativa</option>
                        <option value="compacta">Compacta</option>
                        <option value="completa">Completa</option>
                    </select>
                </div>
            </div>
            <div class="manage-customers-table-wrap">
            <table class="striped manage-customers-table">
                <thead>
                    <tr>
                        <th>Nombre</th>
                        <th>Telefono</th>
                        <th>Email</th>
                        <th class="js-col-direcciones">Direcciones</th>
                        <th class="js-col-origen">Origen</th>
                        <th class="js-col-sucursal manage-customers-col-hidden">Sucursal Origen</th>
                        <th class="js-col-acceso">Acceso Web</th>
                        <th>Estado</th>
                        <th class="center-align">Acciones</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($clientes as $c): ?>
                    <?php
                        $origenRegistro = (string)($c['origen_registro'] ?? 'sucursal');
                        $sucursalOrigen = trim((string)($c['sucursal_origen'] ?? ''));
                        $sucursalFiltro = ($origenRegistro === 'sucursal' && $sucursalOrigen !== '')
                            ? strtolower($sucursalOrigen)
                            : '__sin_sucursal__';
                        $accesoWebFiltro = ((int)($c['tiene_acceso_web'] ?? 0) === 1) ? 'si' : 'no';
                        $estadoVisible = (string)($c['estado_visible'] ?? 'activo');
                        $direccionesCliente = $c['direcciones'] ?? [];
                        $resumenDirecciones = count($direccionesCliente);
                        $letraAncla = $alphabetLetterByFirstClientId[(int)$c['id_cliente']] ?? null;
                    ?>
                    <tr<?php echo $letraAncla !== null ? ' id="cust-letter-row-' . esc($letraAncla) . '"' : ''; ?> data-client-id="<?php echo (int)$c['id_cliente']; ?>" data-nombre="<?php echo esc(mb_strtolower((string)$c['nombre'])); ?>" data-origen="<?php echo esc($origenRegistro); ?>" data-acceso-web="<?php echo esc($accesoWebFiltro); ?>" data-estado="<?php echo esc($estadoVisible); ?>" data-sucursal="<?php echo esc($sucursalFiltro); ?>">
                        <td><strong class="manage-customers-name"><?php echo esc((string)$c['nombre']); ?></strong><?php if (in_array((int)$c['id_cliente'], $clientesFrecuentesIds, true)): ?><?php echo clienteFrecuenteBadgeHtml(); ?><?php endif; ?></td>
                        <td><span class="manage-customers-phone"><?php echo esc((string)($c['telefono'] ?: 'N/A')); ?></span></td>
                        <td><?php echo esc((string)($c['email'] ?: 'N/A')); ?></td>
                        <td class="manage-customers-badge-cell js-col-direcciones">
                            <?php if ($resumenDirecciones > 0): ?>
                                <span class="badge blue white-text" style="float:none;"><?php echo $resumenDirecciones; ?></span>
                            <?php else: ?>
                                <span class="grey-text text-darken-1">Sin direcciones</span>
                            <?php endif; ?>
                        </td>
                        <td class="manage-customers-badge-cell js-col-origen">
                            <?php if ($origenRegistro === 'sitio_web'): ?>
                                <span class="new badge teal" data-badge-caption="Sitio Web"></span>
                            <?php else: ?>
                                <span class="new badge indigo" data-badge-caption="Sucursal"></span>
                            <?php endif; ?>
                        </td>
                        <td class="js-col-sucursal manage-customers-col-hidden">
                            <?php if ($origenRegistro === 'sitio_web'): ?>
                                <span class="grey-text text-darken-1">N/A</span>
                            <?php elseif ($sucursalOrigen !== ''): ?>
                                <span><?php echo esc($sucursalOrigen); ?></span>
                            <?php else: ?>
                                <span class="grey-text text-darken-1">Sin sucursal detectada</span>
                            <?php endif; ?>
                        </td>
                        <td class="manage-customers-badge-cell js-col-acceso">
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
                    <?php endforeach; ?>
                </tbody>
            </table>
            </div>

            <div class="manage-customers-cards">
                <?php foreach ($clientes as $c): ?>
                <?php
                    $origenRegistro = (string)($c['origen_registro'] ?? 'sucursal');
                    $sucursalOrigen = trim((string)($c['sucursal_origen'] ?? ''));
                    $sucursalFiltro = ($origenRegistro === 'sucursal' && $sucursalOrigen !== '')
                        ? strtolower($sucursalOrigen)
                        : '__sin_sucursal__';
                    $accesoWebFiltro = ((int)($c['tiene_acceso_web'] ?? 0) === 1) ? 'si' : 'no';
                    $estadoVisible = (string)($c['estado_visible'] ?? 'activo');
                    $direccionesCliente = $c['direcciones'] ?? [];
                    $resumenDirecciones = count($direccionesCliente);
                    $letraAncla = $alphabetLetterByFirstClientId[(int)$c['id_cliente']] ?? null;
                ?>
                <div<?php echo $letraAncla !== null ? ' id="cust-letter-card-' . esc($letraAncla) . '"' : ''; ?> class="manage-customers-card" data-client-id="<?php echo (int)$c['id_cliente']; ?>" data-nombre="<?php echo esc(mb_strtolower((string)$c['nombre'])); ?>" data-origen="<?php echo esc($origenRegistro); ?>" data-acceso-web="<?php echo esc($accesoWebFiltro); ?>" data-estado="<?php echo esc($estadoVisible); ?>" data-sucursal="<?php echo esc($sucursalFiltro); ?>">
                    <div class="manage-customers-card-header">
                        <span class="manage-customers-card-name"><?php echo esc((string)$c['nombre']); ?></span><?php if (in_array((int)$c['id_cliente'], $clientesFrecuentesIds, true)): ?><?php echo clienteFrecuenteBadgeHtml(); ?><?php endif; ?>
                        <span class="badge <?php echo $estadoVisible === 'activo' ? 'green' : 'red'; ?> white-text" style="float:none; flex-shrink:0;">
                            <?php echo strtoupper($estadoVisible); ?>
                        </span>
                    </div>

                    <div class="manage-customers-card-field">
                        <span class="manage-customers-card-field-label">Telefono</span>
                        <span class="manage-customers-card-field-value"><?php echo esc((string)($c['telefono'] ?: 'N/A')); ?></span>
                    </div>
                    <div class="manage-customers-card-field">
                        <span class="manage-customers-card-field-label">Email</span>
                        <span class="manage-customers-card-field-value"><?php echo esc((string)($c['email'] ?: 'N/A')); ?></span>
                    </div>
                    <div class="manage-customers-card-field js-col-direcciones">
                        <span class="manage-customers-card-field-label">Direcciones</span>
                        <span class="manage-customers-card-field-value">
                            <?php if ($resumenDirecciones > 0): ?>
                                <span class="badge blue white-text"><?php echo $resumenDirecciones; ?></span>
                            <?php else: ?>
                                <span class="grey-text text-darken-1">Sin direcciones</span>
                            <?php endif; ?>
                        </span>
                    </div>
                    <div class="manage-customers-card-field js-col-origen">
                        <span class="manage-customers-card-field-label">Origen</span>
                        <span class="manage-customers-card-field-value">
                            <?php if ($origenRegistro === 'sitio_web'): ?>
                                <span class="new badge teal" data-badge-caption="Sitio Web"></span>
                            <?php else: ?>
                                <span class="new badge indigo" data-badge-caption="Sucursal"></span>
                            <?php endif; ?>
                        </span>
                    </div>
                    <div class="manage-customers-card-field js-col-sucursal manage-customers-col-hidden">
                        <span class="manage-customers-card-field-label">Sucursal Origen</span>
                        <span class="manage-customers-card-field-value">
                            <?php if ($origenRegistro === 'sitio_web'): ?>
                                <span class="grey-text text-darken-1">N/A</span>
                            <?php elseif ($sucursalOrigen !== ''): ?>
                                <?php echo esc($sucursalOrigen); ?>
                            <?php else: ?>
                                <span class="grey-text text-darken-1">Sin sucursal detectada</span>
                            <?php endif; ?>
                        </span>
                    </div>
                    <div class="manage-customers-card-field js-col-acceso">
                        <span class="manage-customers-card-field-label">Acceso Web</span>
                        <span class="manage-customers-card-field-value">
                            <?php if ((int)$c['tiene_acceso_web'] === 1): ?>
                                <span class="new badge green" data-badge-caption="Si"></span>
                            <?php else: ?>
                                <span class="new badge grey" data-badge-caption="No"></span>
                            <?php endif; ?>
                        </span>
                    </div>

                    <div class="manage-customers-card-actions">
                        <a href="#modal-editar-cliente-<?php echo (int)$c['id_cliente']; ?>" class="btn-small amber darken-2 waves-effect waves-light modal-trigger" title="Editar cliente">
                            <i class="material-icons left" style="margin-right:4px;">edit</i>Editar
                        </a>
                        <a href="#modal-dir-<?php echo (int)$c['id_cliente']; ?>" class="btn-small blue waves-effect waves-light modal-trigger" title="Ver Direcciones">
                            <i class="material-icons left" style="margin-right:4px;">place</i>Direcciones
                        </a>
                        <form method="POST">
                            <?php echo csrfInput(); ?>
                            <input type="hidden" name="id_usuario" value="<?php echo (int)($c['id_usuario'] ?? 0); ?>">
                            <input type="hidden" name="id_cliente" value="<?php echo (int)$c['id_cliente']; ?>">
                            <input type="hidden" name="accion" value="<?php echo $estadoVisible === 'activo' ? 'desactivar' : 'activar'; ?>">
                            <button type="submit" class="btn-small <?php echo $estadoVisible === 'activo' ? 'orange' : 'green'; ?> waves-effect waves-light" title="<?php echo $estadoVisible === 'activo' ? 'Bloquear' : 'Activar'; ?>">
                                <i class="material-icons left" style="margin-right:4px;"><?php echo $estadoVisible === 'activo' ? 'block' : 'check'; ?></i><?php echo $estadoVisible === 'activo' ? 'Bloquear' : 'Activar'; ?>
                            </button>
                        </form>
                        <form method="POST">
                            <?php echo csrfInput(); ?>
                            <input type="hidden" name="id_usuario" value="<?php echo (int)($c['id_usuario'] ?? 0); ?>">
                            <input type="hidden" name="id_cliente" value="<?php echo (int)$c['id_cliente']; ?>">
                            <input type="hidden" name="accion" value="eliminar_cliente">
                            <button type="submit" class="btn-small red darken-2 waves-effect waves-light" title="Eliminar cliente" onclick="return confirm('¿Eliminar este cliente? Sus pedidos quedaran sin cliente asignado y sus direcciones se borraran.');">
                                <i class="material-icons left" style="margin-right:4px;">delete_forever</i>Eliminar
                            </button>
                        </form>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>

            <?php foreach ($clientes as $c): ?>
                <?php
                    $origenRegistro = (string)($c['origen_registro'] ?? 'sucursal');
                    $direccionesCliente = $c['direcciones'] ?? [];
                    $horariosCliente = $horariosPorCliente[(int)$c['id_cliente']] ?? [];
                    $waPhoneCliente = waBuildBusinessLinkPhone((string)($c['telefono'] ?? ''));
                ?>
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

                <div id="modal-dir-<?php echo (int)$c['id_cliente']; ?>" class="modal modal-fixed-footer manage-customers-direcciones-modal" data-cliente-id="<?php echo (int)$c['id_cliente']; ?>" style="max-width: 760px;">
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
                                            <?php
                                                $confirmadaCliente = ((int)($d['confirmada_cliente'] ?? 0)) === 1;
                                                $mensajeWaDireccion = 'Hola ' . (string)$c['nombre'] . ', para asegurarnos de tener bien tu direccion de entrega, nos confirmas que esta es correcta? '
                                                    . (string)$d['direccion']
                                                    . (trim((string)($d['maps_link'] ?? '')) !== '' ? (' Ubicacion en el mapa: ' . trim((string)$d['maps_link'])) : '')
                                                    . ' Responde SI para confirmar o dinos que corregir. Gracias!';
                                            ?>
                                            <li class="collection-item">
                                                <strong><?php echo esc((string)$d['alias']); ?></strong>
                                                <?php if ((int)($d['es_default'] ?? 0) === 1): ?><span class="new badge blue" data-badge-caption="Predeterminada"></span><?php endif; ?>
                                                <?php if ($confirmadaCliente): ?>
                                                    <span class="new badge green" data-badge-caption="Confirmada por cliente"></span>
                                                <?php else: ?>
                                                    <span class="new badge orange darken-1" data-badge-caption="Pendiente de confirmar"></span>
                                                <?php endif; ?>
                                                <br>
                                                <span class="grey-text text-darken-1"><?php echo esc((string)$d['direccion']); ?></span>
                                                <?php if ($confirmadaCliente && !empty($d['confirmada_en'])): ?>
                                                    <div class="green-text text-darken-2" style="font-size:0.78rem; margin-top:2px;">
                                                        <i class="material-icons tiny">check_circle</i> Confirmada el <?php echo date('d/m/Y H:i', strtotime((string)$d['confirmada_en'])); ?>
                                                    </div>
                                                <?php endif; ?>
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
                                                <?php if (!$confirmadaCliente): ?>
                                                    <div style="margin-top:8px; display:flex; gap:8px; flex-wrap:wrap;">
                                                        <?php if ($waPhoneCliente !== ''): ?>
                                                            <a href="https://wa.me/<?php echo esc($waPhoneCliente); ?>?text=<?php echo rawurlencode($mensajeWaDireccion); ?>" target="_blank" class="btn-small green darken-1 waves-effect waves-light whatsapp-business-link" data-wa-phone="<?php echo esc($waPhoneCliente); ?>" data-wa-text="<?php echo esc($mensajeWaDireccion); ?>">
                                                                <i class="material-icons tiny">chat</i> Confirmar por WhatsApp
                                                            </a>
                                                        <?php else: ?>
                                                            <span class="grey-text" style="font-size:0.78rem; align-self:center;">Sin telefono registrado para enviar WhatsApp.</span>
                                                        <?php endif; ?>
                                                        <form method="POST" style="display:inline;">
                                                            <?php echo csrfInput(); ?>
                                                            <input type="hidden" name="accion" value="marcar_direccion_confirmada">
                                                            <input type="hidden" name="id_cliente" value="<?php echo (int)$c['id_cliente']; ?>">
                                                            <input type="hidden" name="id_direccion" value="<?php echo (int)$d['id_direccion']; ?>">
                                                            <button type="submit" class="btn-small teal waves-effect waves-light" onclick="return confirm('¿El cliente ya confirmo que esta direccion es correcta?')">
                                                                <i class="material-icons tiny">check</i> Marcar confirmada
                                                            </button>
                                                        </form>
                                                    </div>
                                                <?php endif; ?>
                                            </li>
                                        <?php endforeach; ?>
                                    <?php endif; ?>
                                </ul>
                            </div>
                            <div class="col s12 m6">
                                <div class="card-panel blue lighten-5" style="margin-top:0;">
                                    <strong id="dir-form-title-<?php echo (int)$c['id_cliente']; ?>">Agregar direccion</strong>
                                    <div class="input-field" style="margin-top:14px;">
                                        <i class="material-icons prefix blue-text">search</i>
                                        <input type="text" id="dir-autocomplete-<?php echo (int)$c['id_cliente']; ?>" placeholder="Escribe la calle y numero...">
                                        <span class="helper-text">Selecciona una opcion sugerida para mayor precision</span>
                                    </div>
                                    <div id="dir-map-preview-<?php echo (int)$c['id_cliente']; ?>" class="z-depth-1" style="height:200px; width:100%; margin-bottom:20px; border-radius:4px; display:none; border:1px solid #ddd;"></div>
                                    <form method="POST" id="dir-form-<?php echo (int)$c['id_cliente']; ?>">
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
                                            <label class="active">Direccion exacta (incluye numero de casa)</label>
                                        </div>
                                        <input type="hidden" name="maps_link" class="dir-maps-link">
                                        <p class="grey-text text-small" id="dir-maps-link-status-<?php echo (int)$c['id_cliente']; ?>" style="margin:-6px 0 14px;">Sin ubicacion en mapa seleccionada aun.</p>
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
                                        <div style="margin-top:8px;">
                                            <button type="button" class="btn amber darken-2 waves-effect waves-light" style="width:100%;" onclick="enviarDireccionPorWhatsApp('<?php echo (int)$c['id_cliente']; ?>', '<?php echo esc($waPhoneCliente); ?>', <?php echo json_encode((string)$c['nombre'], JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP); ?>)" <?php echo $waPhoneCliente === '' ? 'disabled title="Cliente sin telefono registrado"' : ''; ?>>
                                                <i class="material-icons left">chat</i> Enviar por WhatsApp para confirmar
                                            </button>
                                            <p class="grey-text text-small" style="margin:4px 0 0;">Manda la direccion de arriba (aun sin guardar) al cliente para que la confirme antes de guardarla.</p>
                                        </div>
                                    </form>
                                </div>

                                <div class="card-panel amber lighten-5" style="margin-top:18px;">
                                    <strong>Preferencias de entrega por horario</strong>
                                    <p class="grey-text" style="margin:8px 0 0;">Registra ventanas por dia para este cliente y domicilio. La ruta los tomara en cuenta al ordenar las paradas.</p>

                                    <?php if (!empty($horariosCliente)): ?>
                                        <ul class="collection" style="margin-top:12px;">
                                            <?php foreach ($horariosCliente as $horario): ?>
                                                <?php $dirAlias = 'General'; ?>
                                                <?php foreach ($c['direcciones'] as $dir): ?>
                                                    <?php if ((int)($dir['id_direccion'] ?? 0) === (int)($horario['id_direccion'] ?? 0)): ?>
                                                        <?php $dirAlias = (string)($dir['alias'] ?? 'General'); ?>
                                                    <?php endif; ?>
                                                <?php endforeach; ?>
                                                <li class="collection-item">
                                                    <div><strong><?php echo esc((string)$horario['dia_semana']); ?></strong> · <?php echo esc((string)$dirAlias); ?></div>
                                                    <div class="grey-text text-darken-1"><?php echo esc((string)$horario['hora_inicio']); ?> - <?php echo esc((string)$horario['hora_fin']); ?></div>
                                                    <?php if (trim((string)($horario['nota'] ?? '')) !== ''): ?><div class="grey-text text-darken-1"><?php echo esc((string)$horario['nota']); ?></div><?php endif; ?>
                                                    <div style="margin-top:8px; display:flex; gap:8px; flex-wrap:wrap;">
                                                        <button type="button" class="btn-small amber darken-2 waves-effect waves-light" onclick='cargarEdicionHorario(<?php echo (int)$c['id_cliente']; ?>, <?php echo json_encode([
                                                            'id_horario' => (int)($horario['id_horario'] ?? 0),
                                                            'id_direccion' => (int)($horario['id_direccion'] ?? 0),
                                                            'dia_semana' => (string)($horario['dia_semana'] ?? ''),
                                                            'hora_inicio' => (string)($horario['hora_inicio'] ?? ''),
                                                            'hora_fin' => (string)($horario['hora_fin'] ?? ''),
                                                            'activo' => ((int)($horario['activo'] ?? 1)) === 1,
                                                            'nota' => (string)($horario['nota'] ?? ''),
                                                        ], JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP); ?>)'>Editar</button>
                                                    </div>
                                                </li>
                                            <?php endforeach; ?>
                                        </ul>
                                    <?php else: ?>
                                        <p class="grey-text" style="margin:12px 0 0;">Todavia no hay horarios registrados para este cliente.</p>
                                    <?php endif; ?>

                                    <form method="POST" style="margin-top:16px;" data-schedule-form id="schedule-form-<?php echo (int)$c['id_cliente']; ?>">
                                        <?php echo csrfInput(); ?>
                                        <input type="hidden" name="accion" value="guardar_horario_direccion">
                                        <input type="hidden" name="id_cliente" value="<?php echo (int)$c['id_cliente']; ?>">
                                        <input type="hidden" name="id_horario" value="0" class="schedule-id">
                                        <div class="row" style="margin-bottom:0;">
                                            <div class="input-field col s12 m4">
                                                <select name="id_direccion" class="browser-default schedule-direccion" required>
                                                    <option value="">Selecciona domicilio</option>
                                                    <?php foreach ($c['direcciones'] as $dir): ?>
                                                        <option value="<?php echo (int)$dir['id_direccion']; ?>"><?php echo esc((string)$dir['alias']); ?></option>
                                                    <?php endforeach; ?>
                                                </select>
                                                <label class="active">Domicilio</label>
                                            </div>
                                            <div class="input-field col s12 m3">
                                                <select name="dia_semana" class="browser-default schedule-dia" required>
                                                    <option value="">Dia</option>
                                                    <option value="lunes">Lunes</option>
                                                    <option value="martes">Martes</option>
                                                    <option value="miercoles">Miercoles</option>
                                                    <option value="jueves">Jueves</option>
                                                    <option value="viernes">Viernes</option>
                                                    <option value="sabado">Sabado</option>
                                                    <option value="domingo">Domingo</option>
                                                </select>
                                            </div>
                                            <div class="input-field col s12 m1">
                                                <label>
                                                    <input type="checkbox" name="activo" value="1" checked class="filled-in schedule-activo">
                                                    <span></span>
                                                </label>
                                            </div>
                                        </div>
                                        <div class="row" style="margin-bottom:8px;">
                                            <div class="input-field col s12 m6">
                                                <label class="active">Inicio</label>
                                                <div class="row" style="margin-bottom:0;">
                                                    <div class="col s8" style="padding-right:4px;">
                                                        <input type="text" name="hora_inicio" class="schedule-hora-inicio" placeholder="09:30" required inputmode="numeric" pattern="^(?:[0-9]|0[0-9]|1[0-2]):[0-5][0-9]$">
                                                    </div>
                                                    <div class="col s4" style="padding-left:4px;">
                                                        <select name="hora_inicio_meridiem" class="browser-default schedule-hora-inicio-meridiem" required>
                                                            <option value="AM">AM</option>
                                                            <option value="PM">PM</option>
                                                        </select>
                                                    </div>
                                                </div>
                                            </div>
                                            <div class="input-field col s12 m6">
                                                <label class="active">Fin</label>
                                                <div class="row" style="margin-bottom:0;">
                                                    <div class="col s8" style="padding-right:4px;">
                                                        <input type="text" name="hora_fin" class="schedule-hora-fin" placeholder="06:45" required inputmode="numeric" pattern="^(?:[0-9]|0[0-9]|1[0-2]):[0-5][0-9]$">
                                                    </div>
                                                    <div class="col s4" style="padding-left:4px;">
                                                        <select name="hora_fin_meridiem" class="browser-default schedule-hora-fin-meridiem" required>
                                                            <option value="AM">AM</option>
                                                            <option value="PM">PM</option>
                                                        </select>
                                                    </div>
                                                </div>
                                            </div>
                                        </div>
                                        <div class="input-field">
                                            <input type="text" name="nota" maxlength="255" placeholder="Ej: Entrega solo antes de la comida" class="schedule-nota">
                                            <label class="active">Nota opcional</label>
                                        </div>
                                        <div style="display:flex; gap:8px; flex-wrap:wrap; align-items:center;">
                                            <button type="submit" class="btn amber darken-3 waves-effect waves-light schedule-submit-btn">Guardar horario</button>
                                            <button type="button" class="btn-flat waves-effect" onclick='resetHorarioForm(<?php echo (int)$c['id_cliente']; ?>)'>Cancelar edicion</button>
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
        </div>
    </div>
</div>

<div id="modal-crear-cliente" class="modal manage-customers-direcciones-modal" data-cliente-id="crear" style="max-width: 720px;">
    <div class="modal-content">
        <h5>Nuevo cliente</h5>
        <form method="POST" id="dir-form-crear">
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
                <div class="input-field col s12 m6">
                    <input type="text" name="direccion_alias" maxlength="50" placeholder="Ej: Casa, Oficina">
                    <label class="active">Alias de direccion (opcional)</label>
                </div>
            </div>

            <div class="card-panel blue lighten-5" style="margin-top:8px;">
                <strong>Domicilio principal (opcional)</strong>
                <p class="grey-text text-small" style="margin:4px 0 0;">Busca la direccion en Google para capturar el punto exacto en el mapa.</p>
                <div class="input-field" style="margin-top:14px;">
                    <i class="material-icons prefix blue-text">search</i>
                    <input type="text" id="dir-autocomplete-crear" placeholder="Escribe la calle y numero...">
                    <span class="helper-text">Selecciona una opcion sugerida para mayor precision</span>
                </div>
                <div id="dir-map-preview-crear" class="z-depth-1" style="height:200px; width:100%; margin-bottom:20px; border-radius:4px; display:none; border:1px solid #ddd;"></div>
                <div class="input-field">
                    <textarea name="direccion" class="materialize-textarea dir-direccion"></textarea>
                    <label>Direccion exacta (incluye numero de casa)</label>
                </div>
                <input type="hidden" name="maps_link" class="dir-maps-link">
                <p class="grey-text text-small" id="dir-maps-link-status-crear" style="margin:-6px 0 0;">Sin ubicacion en mapa seleccionada aun.</p>
                <button type="button" class="btn amber darken-2 waves-effect waves-light" style="width:100%; margin-top:10px;" onclick="enviarDireccionCrearPorWhatsApp()">
                    <i class="material-icons left">chat</i> Enviar por WhatsApp para confirmar
                </button>
                <p class="grey-text text-small" style="margin:4px 0 0;">Usa el telefono capturado arriba. Confirma con el cliente antes de guardar.</p>
            </div>

            <div class="modal-footer" style="padding:0; background:transparent;">
                <a href="#!" class="modal-close waves-effect btn-flat">Cancelar</a>
                <button type="submit" class="btn blue darken-2 waves-effect waves-light">Crear cliente</button>
            </div>
        </form>
    </div>
</div>

<script src="https://maps.googleapis.com/maps/api/js?key=<?php echo GOOGLE_MAPS_API_KEY; ?>&libraries=places&callback=initAutocompleteManageCustomers" async defer></script>

<script>
const dirMapState = {};

function initAutocompleteManageCustomers() {
    window.googleMapsReadyManageCustomers = true;
    const openModal = document.querySelector('.manage-customers-direcciones-modal.open');
    if (openModal) {
        ensureDireccionMapInit(openModal.dataset.clienteId);
    }
}

function tryInitDireccionMap(idCliente, attemptsLeft) {
    if (typeof google !== 'undefined' && google.maps && google.maps.places) {
        ensureDireccionMapInit(idCliente);
        return;
    }
    if (attemptsLeft <= 0) {
        console.warn('Google Maps no cargo a tiempo para el buscador de direcciones del cliente ' + idCliente);
        return;
    }
    setTimeout(() => tryInitDireccionMap(idCliente, attemptsLeft - 1), 250);
}

document.addEventListener('click', function(event) {
    const trigger = event.target.closest('a.modal-trigger[href^="#modal-dir-"], a.modal-trigger[href="#modal-crear-cliente"]');
    if (!trigger) return;
    const href = trigger.getAttribute('href');
    const idCliente = href === '#modal-crear-cliente' ? 'crear' : href.replace('#modal-dir-', '');
    // Se espera a que termine la animacion de apertura del modal (Materialize la anima)
    // antes de crear el widget de Google, porque si el campo esta oculto (display:none)
    // en el momento de crearlo, el listado de sugerencias queda mal posicionado para siempre.
    // Nota: no basta con onOpenEnd del M.Modal.init de esta pagina, porque M.AutoInit()
    // en footer.php corre despues y reemplaza esa instancia sin el callback; este listener
    // de click es independiente de esa instancia y por eso es el que realmente dispara la carga.
    setTimeout(() => tryInitDireccionMap(idCliente, 20), 400);
});

function ensureDireccionMapInit(idCliente) {
    if (!idCliente || typeof google === 'undefined' || (dirMapState[idCliente] && dirMapState[idCliente].initialized)) return;

    const input = document.getElementById(`dir-autocomplete-${idCliente}`);
    const mapEl = document.getElementById(`dir-map-preview-${idCliente}`);
    if (!input || !mapEl) return;

    const autocomplete = new google.maps.places.Autocomplete(input, {
        types: ['address'],
        componentRestrictions: { country: 'mx' }
    });

    const map = new google.maps.Map(mapEl, {
        center: { lat: 23.6345, lng: -102.5528 },
        zoom: 5,
        disableDefaultUI: true,
        zoomControl: true
    });
    const marker = new google.maps.Marker({ map: map });

    autocomplete.addListener('place_changed', function() {
        const place = autocomplete.getPlace();
        if (!place.geometry) return;

        mapEl.style.display = 'block';
        const form = document.getElementById(`dir-form-${idCliente}`);
        const direccionEl = form ? form.querySelector('.dir-direccion') : null;
        const mapsLinkEl = form ? form.querySelector('.dir-maps-link') : null;
        const statusEl = document.getElementById(`dir-maps-link-status-${idCliente}`);

        if (direccionEl) {
            direccionEl.value = place.formatted_address || direccionEl.value;
            M.textareaAutoResize(direccionEl);
        }
        if (mapsLinkEl) {
            mapsLinkEl.value = `https://www.google.com/maps/search/?api=1&query=${place.geometry.location.lat()},${place.geometry.location.lng()}`;
        }
        if (statusEl) {
            statusEl.textContent = 'Ubicacion en mapa capturada correctamente.';
            statusEl.classList.add('green-text');
        }

        map.setCenter(place.geometry.location);
        map.setZoom(17);
        marker.setPosition(place.geometry.location);
        M.updateTextFields();
    });

    dirMapState[idCliente] = { map: map, marker: marker, autocomplete: autocomplete, initialized: true };

    setTimeout(() => google.maps.event.trigger(map, 'resize'), 150);

    const existingLink = document.getElementById(`dir-form-${idCliente}`)?.querySelector('.dir-maps-link')?.value || '';
    if (existingLink.includes('query=')) {
        actualizarMapaDireccionDesdeCoords(idCliente, existingLink.split('query=')[1]);
    }
}

function actualizarMapaDireccionDesdeCoords(idCliente, coords) {
    const state = dirMapState[idCliente];
    const mapEl = document.getElementById(`dir-map-preview-${idCliente}`);
    if (!state || !mapEl) return;
    const [lat, lng] = String(coords || '').split(',').map(Number);
    if (!isNaN(lat) && !isNaN(lng)) {
        mapEl.style.display = 'block';
        const pos = { lat, lng };
        state.map.setCenter(pos);
        state.map.setZoom(17);
        state.marker.setPosition(pos);
        setTimeout(() => google.maps.event.trigger(state.map, 'resize'), 100);
    }
}

document.addEventListener('DOMContentLoaded', () => {
    M.Modal.init(document.querySelectorAll('.modal:not(.manage-customers-direcciones-modal)'));
    M.Modal.init(document.querySelectorAll('.manage-customers-direcciones-modal'), {
        onOpenEnd: function(modalEl) {
            const idCliente = modalEl.dataset.clienteId;
            tryInitDireccionMap(idCliente, 20);
        }
    });
    M.updateTextFields();

    const tableRows = Array.from(document.querySelectorAll('table.manage-customers-table tbody tr[data-origen]'));
    const cardsByClientId = new Map();
    document.querySelectorAll('.manage-customers-cards .manage-customers-card[data-client-id]').forEach((card) => {
        cardsByClientId.set(card.getAttribute('data-client-id'), card);
    });
    const buscarInput = document.getElementById('buscar-cliente');
    const origenSelect = document.getElementById('filtro-origen');
    const accesoSelect = document.getElementById('filtro-acceso');
    const estadoSelect = document.getElementById('filtro-estado');
    const sucursalSelect = document.getElementById('filtro-sucursal');
    const columnasSelect = document.getElementById('filtro-columnas');
    const visibleEl = document.getElementById('clientes-visibles');
    const totalEl = document.getElementById('clientes-total');
    const total = tableRows.length;
    const columnStorageKey = 'manageCustomersColumnPreset';

    function readColumnPreset() {
        try {
            const raw = window.localStorage.getItem(columnStorageKey);
            return raw || 'operativa';
        } catch (error) {
            return 'operativa';
        }
    }

    function writeColumnPreset(value) {
        try {
            window.localStorage.setItem(columnStorageKey, value);
        } catch (error) {
            // Ignora bloqueos de storage sin romper la vista.
        }
    }

    function formatTimeInputValue(value) {
        if (!value) return '';
        const digits = String(value).replace(/[^0-9]/g, '').slice(0, 4);
        if (digits.length <= 2) {
            return digits;
        }
        return `${digits.slice(0, 2)}:${digits.slice(2)}`;
    }

    function normalizeTimeInput(input) {
        const formatted = formatTimeInputValue(input.value || '');
        input.value = formatted;
        return /^(?:[0-9]|0[0-9]|1[0-2]):[0-5][0-9]$/.test(formatted);
    }

    document.querySelectorAll('.schedule-hora-inicio, .schedule-hora-fin').forEach((input) => {
        input.addEventListener('input', () => {
            input.value = formatTimeInputValue(input.value || '');
        });

        input.addEventListener('blur', () => {
            const formatted = formatTimeInputValue(input.value || '');
            input.value = formatted.includes(':') ? formatted : `${formatted.slice(0, 2)}:${formatted.slice(2)}`;
            normalizeTimeInput(input);
        });
    });

    document.querySelectorAll('[data-schedule-form]').forEach((form) => {
        form.addEventListener('submit', (event) => {
            const startTime = form.querySelector('.schedule-hora-inicio');
            const startMeridiem = form.querySelector('.schedule-hora-inicio-meridiem');
            const endTime = form.querySelector('.schedule-hora-fin');
            const endMeridiem = form.querySelector('.schedule-hora-fin-meridiem');
            let ok = true;

            [startTime, startMeridiem, endTime, endMeridiem].forEach((field) => {
                if (field && !field.value) {
                    ok = false;
                }
            });

            if (startTime && !normalizeTimeInput(startTime)) {
                ok = false;
            }

            if (endTime && !normalizeTimeInput(endTime)) {
                ok = false;
            }

            if (!ok) {
                event.preventDefault();
                const invalid = form.querySelector(':invalid');
                if (invalid) {
                    invalid.focus();
                }
            }
        });
    });

    function applyColumnVisibility(columnName, visible) {
        document.querySelectorAll(`.js-col-${columnName}`).forEach((cell) => {
            cell.classList.toggle('manage-customers-col-hidden', !visible);
        });
    }

    if (totalEl) totalEl.textContent = String(total);

    function applyColumnPreset(preset) {
        const visibilityByPreset = {
            compacta: {
                direcciones: false,
                origen: false,
                sucursal: false,
                acceso: false,
            },
            operativa: {
                direcciones: true,
                origen: true,
                sucursal: false,
                acceso: true,
            },
            completa: {
                direcciones: true,
                origen: true,
                sucursal: true,
                acceso: true,
            },
        };

        const selectedPreset = visibilityByPreset[preset] ? preset : 'operativa';
        const columns = visibilityByPreset[selectedPreset];
        Object.keys(columns).forEach((columnName) => {
            applyColumnVisibility(columnName, !!columns[columnName]);
        });
        if (columnasSelect) {
            columnasSelect.value = selectedPreset;
        }
        writeColumnPreset(selectedPreset);
    }

    applyColumnPreset(readColumnPreset());

    function matchesOrigen(row, filter) {
        const origen = row.getAttribute('data-origen') || '';

        if (filter === 'todos') return true;
        if (filter === 'sitio_web') return origen === 'sitio_web';
        if (filter === 'sucursal') return origen === 'sucursal';
        return true;
    }

    function matchesAcceso(row, filter) {
        const acceso = row.getAttribute('data-acceso-web') || '';

        if (filter === 'todos') return true;
        if (filter === 'acceso_si') return acceso === 'si';
        if (filter === 'acceso_no') return acceso === 'no';
        return true;
    }

    function matchesEstado(row, filter) {
        const estado = row.getAttribute('data-estado') || '';

        if (filter === 'todos') return true;
        if (filter === 'activo') return estado === 'activo';
        if (filter === 'inactivo') return estado === 'inactivo';
        return true;
    }

    function matchesSucursal(row, sucursalValue) {
        const sucursal = row.getAttribute('data-sucursal') || '';
        if (sucursalValue === '__todas__') return true;
        return sucursal === sucursalValue;
    }

    function matchesSearch(row, searchValue) {
        if (!searchValue) return true;
        const nombre = row.getAttribute('data-nombre') || '';
        return nombre.includes(searchValue);
    }

    function applyFilters() {
        const searchValue = (buscarInput?.value || '').trim().toLowerCase();
        const sucursalValue = (sucursalSelect?.value || '__todas__').toLowerCase();
        const origenValue = origenSelect?.value || 'todos';
        const accesoValue = accesoSelect?.value || 'todos';
        const estadoValue = estadoSelect?.value || 'todos';
        let visibles = 0;

        tableRows.forEach((row) => {
            const ok = matchesSearch(row, searchValue)
                && matchesOrigen(row, origenValue)
                && matchesAcceso(row, accesoValue)
                && matchesEstado(row, estadoValue)
                && matchesSucursal(row, sucursalValue);
            row.style.display = ok ? '' : 'none';

            const card = cardsByClientId.get(row.getAttribute('data-client-id'));
            if (card) {
                card.style.display = ok ? '' : 'none';
            }

            if (ok) visibles++;
        });

        if (visibleEl) visibleEl.textContent = String(visibles);
    }

    if (buscarInput) {
        buscarInput.addEventListener('input', applyFilters);
    }

    [origenSelect, accesoSelect, estadoSelect, sucursalSelect].forEach((select) => {
        if (select) {
            select.addEventListener('change', applyFilters);
        }
    });

    if (columnasSelect) {
        columnasSelect.addEventListener('change', () => {
            applyColumnPreset(columnasSelect.value || 'operativa');
        });
    }

    applyFilters();

    const cardsContainer = document.querySelector('.manage-customers-cards');
    document.querySelectorAll('.alpha-index-letter[data-letter]').forEach((letterEl) => {
        letterEl.addEventListener('click', (event) => {
            event.preventDefault();
            const letter = letterEl.getAttribute('data-letter');
            if (!letter) return;

            const usingCards = cardsContainer && window.getComputedStyle(cardsContainer).display !== 'none';
            const targetId = (usingCards ? 'cust-letter-card-' : 'cust-letter-row-') + letter;
            const target = document.getElementById(targetId);
            if (target) {
                target.scrollIntoView({ behavior: 'smooth', block: 'start' });
            }
        });
    });
});

function resetHorarioForm(idCliente) {
    const form = document.getElementById(`schedule-form-${idCliente}`);
    if (!form) return;
    form.querySelector('.schedule-id').value = '0';
    form.querySelector('.schedule-direccion').value = '';
    form.querySelector('.schedule-dia').value = '';
    form.querySelector('.schedule-hora-inicio').value = '09:00';
    form.querySelector('.schedule-hora-inicio-meridiem').value = 'AM';
    form.querySelector('.schedule-hora-fin').value = '06:00';
    form.querySelector('.schedule-hora-fin-meridiem').value = 'PM';
    form.querySelector('.schedule-nota').value = '';
    const activo = form.querySelector('.schedule-activo');
    if (activo) activo.checked = true;
    const submitBtn = form.querySelector('.schedule-submit-btn');
    if (submitBtn) submitBtn.textContent = 'Guardar horario';
    M.updateTextFields();
    form.reset();
}

function cargarEdicionHorario(idCliente, data) {
    const form = document.getElementById(`schedule-form-${idCliente}`);
    if (!form || !data) return;
    form.querySelector('.schedule-id').value = String(data.id_horario || '0');
    form.querySelector('.schedule-direccion').value = String(data.id_direccion || '');
    form.querySelector('.schedule-dia').value = String(data.dia_semana || '');
    const startValue = String(data.hora_inicio || '');
    const endValue = String(data.hora_fin || '');
    const parseTimeValue = (value) => {
        if (!value) {
            return { time: '09:00', meridiem: 'AM' };
        }
        const [timePart, meridiemPart] = String(value).split(' ');
        const [hourRaw, minuteRaw] = (timePart || '00:00').split(':');
        let hour = parseInt(hourRaw || '0', 10);
        if (Number.isNaN(hour)) {
            hour = 0;
        }
        let meridiem = 'AM';
        if (meridiemPart && (meridiemPart === 'PM' || meridiemPart === 'AM')) {
            meridiem = meridiemPart;
        } else if (hour >= 12) {
            meridiem = 'PM';
        }
        const hourString = String(hour % 12 || 12).padStart(2, '0');
        const minuteString = String(parseInt(minuteRaw || '0', 10)).padStart(2, '0');
        return { time: `${hourString}:${minuteString}`, meridiem };
    };

    const startParts = parseTimeValue(startValue);
    const endParts = parseTimeValue(endValue);
    form.querySelector('.schedule-hora-inicio').value = startParts.time;
    form.querySelector('.schedule-hora-inicio-meridiem').value = startParts.meridiem;
    form.querySelector('.schedule-hora-fin').value = endParts.time;
    form.querySelector('.schedule-hora-fin-meridiem').value = endParts.meridiem;
    form.querySelector('.schedule-nota').value = String(data.nota || '');
    const activo = form.querySelector('.schedule-activo');
    if (activo) activo.checked = !!data.activo;
    const submitBtn = form.querySelector('.schedule-submit-btn');
    if (submitBtn) submitBtn.textContent = 'Actualizar horario';
    M.updateTextFields();
    form.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
}

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

    const searchEl = document.getElementById(`dir-autocomplete-${idCliente}`);
    if (searchEl) searchEl.value = '';
    const mapEl = document.getElementById(`dir-map-preview-${idCliente}`);
    if (mapEl) mapEl.style.display = 'none';
    const statusEl = document.getElementById(`dir-maps-link-status-${idCliente}`);
    if (statusEl) {
        statusEl.textContent = 'Sin ubicacion en mapa seleccionada aun.';
        statusEl.classList.remove('green-text');
    }

    M.updateTextFields();
    M.textareaAutoResize(form.querySelector('.dir-direccion'));
}

// Manda la direccion que se esta buscando/editando (aun sin guardar) al cliente por
// WhatsApp para que la confirme antes de darla de alta, porque a veces ni el mapa
// encuentra bien el lugar. Lee los campos del formulario en vivo, no lo que ya esta
// guardado en la base, para que sirva igual con una direccion nueva que con una edicion.
function enviarDireccionPorWhatsApp(idCliente, phoneDigits, nombreCliente) {
    const form = document.getElementById(`dir-form-${idCliente}`);
    const direccionTexto = (form ? form.querySelector('.dir-direccion').value : '').trim();
    const mapsLink = (form ? form.querySelector('.dir-maps-link').value : '').trim();

    if (!direccionTexto) {
        if (typeof M !== 'undefined' && M.toast) {
            M.toast({ html: 'Escribe o busca la direccion antes de enviarla por WhatsApp.', classes: 'orange darken-2' });
        } else {
            alert('Escribe o busca la direccion antes de enviarla por WhatsApp.');
        }
        return;
    }
    if (!phoneDigits) {
        if (typeof M !== 'undefined' && M.toast) {
            M.toast({ html: 'Este cliente no tiene telefono registrado.', classes: 'red darken-2' });
        } else {
            alert('Este cliente no tiene telefono registrado.');
        }
        return;
    }

    let mensaje = `Hola ${nombreCliente}, para asegurarnos de tener bien tu direccion de entrega, nos confirmas que esta es correcta? ${direccionTexto}`;
    if (mapsLink) {
        mensaje += ` Ubicacion en el mapa: ${mapsLink}`;
    }
    mensaje += ' Responde SI para confirmar o dinos que corregir. Gracias!';

    // Se crea el link al vuelo (en vez de un <a> fijo) porque el texto depende de lo que
    // haya en el formulario en ese momento. waApplyBusinessLinks lo reescribe a intent://
    // en Android para forzar WhatsApp Business en vez del WhatsApp personal.
    const link = document.createElement('a');
    link.href = `https://wa.me/${phoneDigits}?text=${encodeURIComponent(mensaje)}`;
    link.target = '_blank';
    link.rel = 'noopener noreferrer';
    link.className = 'whatsapp-business-link';
    link.style.display = 'none';
    link.setAttribute('data-wa-phone', phoneDigits);
    link.setAttribute('data-wa-text', mensaje);
    document.body.appendChild(link);
    if (typeof window.waApplyBusinessLinks === 'function') {
        window.waApplyBusinessLinks(link.parentElement);
    }
    link.click();
    link.remove();
}

// Variante para el modal de "Nuevo cliente": ahi el cliente aun no existe en la base, asi
// que el nombre/telefono se leen del propio formulario en vez de venir ya renderizados
// desde PHP.
function enviarDireccionCrearPorWhatsApp() {
    const form = document.getElementById('dir-form-crear');
    if (!form) return;
    const nombreEl = form.querySelector('[name="nombre"]');
    const telefonoEl = form.querySelector('[name="telefono"]');
    const nombre = ((nombreEl ? nombreEl.value : '') || '').trim() || 'cliente';
    const phoneDigits = ((telefonoEl ? telefonoEl.value : '') || '').replace(/\D/g, '');
    const phoneConLada = phoneDigits ? ('52' + phoneDigits) : '';
    enviarDireccionPorWhatsApp('crear', phoneConLada, nombre);
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

    const searchEl = document.getElementById(`dir-autocomplete-${idCliente}`);
    if (searchEl) searchEl.value = '';
    const statusEl = document.getElementById(`dir-maps-link-status-${idCliente}`);
    const mapsLink = String(data.maps_link || '');

    ensureDireccionMapInit(idCliente);

    if (mapsLink.includes('query=')) {
        actualizarMapaDireccionDesdeCoords(idCliente, mapsLink.split('query=')[1]);
        if (statusEl) {
            statusEl.textContent = 'Ubicacion guardada disponible en el mapa.';
            statusEl.classList.add('green-text');
        }
    } else {
        const mapEl = document.getElementById(`dir-map-preview-${idCliente}`);
        if (mapEl) mapEl.style.display = 'none';
        if (statusEl) {
            statusEl.textContent = mapsLink !== '' ? 'Link guardado sin coordenadas detectables.' : 'Sin ubicacion en mapa seleccionada aun.';
            statusEl.classList.remove('green-text');
        }
    }

    M.updateTextFields();
    M.textareaAutoResize(form.querySelector('.dir-direccion'));
}
</script>

<?php include __DIR__ . '/includes/footer.php'; ?>
