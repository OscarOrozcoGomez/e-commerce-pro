<?php
require_once __DIR__ . '/../core/config.php';
require_once __DIR__ . '/../core/auth.php';

header('Content-Type: application/json');
$data = json_decode(file_get_contents('php://input'), true);

if (!$data || empty($data['items'])) {
    echo json_encode(['success' => false, 'message' => 'Datos inválidos']);
    exit;
}

// Si el usuario está logueado, vinculamos el pedido a su cuenta automáticamente
if (isAuthenticated()) {
    $usuario = $_SESSION['usuario'];
    $data['id_usuario'] = $usuario['id_usuario'];
    $data['id_cliente'] = $usuario['id_cliente'] ?? null;
}

$result = dbCreatePublicOrder($data);

if ($result['success']) {
    echo json_encode([
        'success' => true,
        'pedido' => $result['pedido'],
        'id_pedido' => $result['id_pedido'] ?? null,
        'message' => "Gracias {$data['cliente']['nombre']}, tu pedido {$result['pedido']} registrado.",
    ]);
} else {
    echo json_encode(['success' => false, 'message' => $result['message']]);
}