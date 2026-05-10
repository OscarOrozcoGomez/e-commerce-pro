<?php
require_once __DIR__ . '/../core/config.php';
require_once __DIR__ . '/../core/auth.php';

header('Content-Type: application/json');
$data = json_decode(file_get_contents('php://input'), true);

if (!$data || empty($data['items'])) {
    echo json_encode(['success' => false, 'message' => 'Datos inválidos']);
    exit;
}

$result = dbCreatePublicOrder($data);

if ($result['success']) {
    echo json_encode(['success' => true, 'message' => "Gracias {$data['cliente']['nombre']}, tu pedido {$result['pedido']} registrado."]);
} else {
    echo json_encode(['success' => false, 'message' => $result['message']]);
}