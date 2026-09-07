<?php

declare(strict_types=1);
require_once __DIR__ . '/../core/config.php';
require_once __DIR__ . '/../core/auth.php';
require_once __DIR__ . '/../core/stock_transfer_utils.php';

header('Content-Type: application/json');

// Refresca permisos por si se revocaron/concedieron desde el panel hace poco (no-op sin sesion).
refreshSessionPermissions();

// Fase 4: el permiso 'transferir_stock' abre este endpoint; el rol admin se mantiene como respaldo.
if (!isAuthenticated() || (!hasPermission('transferir_stock') && !isAdmin())) {
    echo json_encode(['success' => false, 'message' => 'No autorizado']);
    exit;
}

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Método no permitido.']);
    exit;
}

// CSRF: el formulario (views/transfer_stock.php) ya envía csrfInput() dentro del FormData.
if (!validateCsrfToken((string) ($_POST['csrf_token'] ?? ''))) {
    echo json_encode(['success' => false, 'message' => 'Token de seguridad inválido. Recarga la página e inténtalo de nuevo.']);
    exit;
}

try {
    $idOrigen    = (int) ($_POST['id_origen'] ?? 0);
    $idDestino   = (int) ($_POST['id_destino'] ?? 0);
    $observacion = (string) ($_POST['observacion'] ?? '');
    $userId      = (int) ($_SESSION['usuario']['id_usuario'] ?? 0);

    // La vista manda una lista de productos en `items` (JSON). Se acepta también el
    // formato antiguo de un solo producto por si hay integraciones que lo usen.
    $items = [];
    if (isset($_POST['items']) && $_POST['items'] !== '') {
        $decoded = json_decode((string) $_POST['items'], true);
        if (!is_array($decoded)) {
            throw new InvalidArgumentException('La lista de productos no es válida.');
        }
        $items = $decoded;
    } elseif (isset($_POST['id_producto'])) {
        $items = [[
            'id_producto' => $_POST['id_producto'],
            'cantidad'    => $_POST['cantidad'] ?? 0,
        ]];
    } else {
        throw new InvalidArgumentException('No se recibió ningún producto para transferir.');
    }

    $resultado = stockTransferExecuteBatch(getPDO(), $idOrigen, $idDestino, $items, $userId, $observacion);

    $mensaje = sprintf(
        '%d producto(s) transferido(s) (%d unidad(es) en total).',
        $resultado['lineas'],
        $resultado['unidades_totales']
    );
    if (($resultado['unidades_sin_lote'] ?? 0) > 0) {
        $mensaje .= sprintf(
            ' %d unidad(es) se movieron sin detalle de lote; ajústalo desde Control de Caducidades si lo necesitas.',
            $resultado['unidades_sin_lote']
        );
    }

    echo json_encode([
        'success' => true,
        'message' => $mensaje,
        'data'    => $resultado,
    ]);
} catch (InvalidArgumentException $e) {
    http_response_code(422);
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
} catch (RuntimeException $e) {
    http_response_code(409);
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
} catch (Throwable $e) {
    error_log('transfer_stock.php: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'No se pudo completar la transferencia. Inténtalo de nuevo.']);
}
