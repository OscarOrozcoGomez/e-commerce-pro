<?php
declare(strict_types=1);
require_once __DIR__ . '/../core/config.php';
require_once __DIR__ . '/../core/auth.php';
require_once __DIR__ . '/../core/purchase_order_utils.php';

header('Content-Type: application/json');
if (!isAuthenticated() || (!isAdmin() && !isEncargado())) {
    echo json_encode(['success' => false, 'message' => 'No autorizado']);
    exit;
}

$pdo = getPDO();
$idAlmacen = getCurrentAlmacenId();

try {
    $result = purchaseOrderFetchSuggestions($pdo, isAdmin(), $idAlmacen !== null ? (int) $idAlmacen : null);

    echo json_encode([
        'success' => true,
        'listaCompra' => $result['listaCompra'],
        'chartData' => $result['chartData']
    ]);

} catch (Throwable $e) {
    // Throwable (no solo Exception): un \Error/\TypeError sin atrapar se escaparia al
    // set_exception_handler global, que responde con un redirect HTML a views/error.php
    // -- y el fetch() del cliente lo recibe como "Unexpected token '<'" al hacer r.json().
    http_response_code(200);
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}