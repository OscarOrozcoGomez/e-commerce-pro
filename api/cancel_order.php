<?php
declare(strict_types=1);

require_once __DIR__ . '/../core/config.php';
require_once __DIR__ . '/../core/auth.php';

header('Content-Type: application/json');

if (!isAuthenticated() || !isCliente()) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'No autorizado.']);
    exit;
}

$data = json_decode((string)file_get_contents('php://input'), true);
if (!is_array($data)) {
    $data = [];
}

if (!validateCsrfToken((string)($data['csrf_token'] ?? ''))) {
    http_response_code(419);
    echo json_encode(['success' => false, 'message' => 'Token de seguridad invalido, recarga la pagina e intenta de nuevo.']);
    exit;
}

$idPedido = (int)($data['id_pedido'] ?? 0);
$idMotivo = (int)($data['id_motivo'] ?? 0);
$motivoDetalle = trim((string)($data['motivo_detalle'] ?? ''));

if ($idPedido <= 0 || $idMotivo <= 0) {
    http_response_code(422);
    echo json_encode(['success' => false, 'message' => 'Selecciona un motivo de cancelacion.']);
    exit;
}

$pdo = getPDO();

$stmtMotivo = $pdo->prepare('SELECT id_motivo, requiere_detalle FROM motivos_cancelacion_pedido WHERE id_motivo = ? AND activo = 1');
$stmtMotivo->execute([$idMotivo]);
$motivo = $stmtMotivo->fetch(PDO::FETCH_ASSOC);

if (!$motivo) {
    http_response_code(422);
    echo json_encode(['success' => false, 'message' => 'Motivo de cancelacion invalido.']);
    exit;
}

if ((int)$motivo['requiere_detalle'] === 1 && $motivoDetalle === '') {
    http_response_code(422);
    echo json_encode(['success' => false, 'message' => 'Cuentanos brevemente el motivo de tu cancelacion.']);
    exit;
}

$idUsuario = (int)($_SESSION['usuario']['id_usuario'] ?? 0);
$resultado = dbCancelOrderByCustomer($pdo, $idPedido, $idUsuario, $idMotivo, $motivoDetalle);

if (!($resultado['success'] ?? false)) {
    http_response_code(422);
}

echo json_encode($resultado);
