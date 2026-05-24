<?php
declare(strict_types=1);
require_once __DIR__ . '/../core/config.php';
require_once __DIR__ . '/../core/auth.php';

header('Content-Type: application/json');
if (!isAuthenticated()) {
    echo json_encode(['success' => false, 'message' => 'No autenticado']);
    exit;
}

$pdo = getPDO();
$usuario = $_SESSION['usuario'];
$id_actual = (int)$usuario['id_usuario'];

$action = $_GET['action'] ?? '';

try {
    if ($action === 'fetch') {
        // Si es staff, necesita el ID del cliente específico. Si es cliente, usa su propio ID.
        $id_cliente = !isCliente() ? (int)($_GET['id_cliente'] ?? 0) : $id_actual;
        
        $stmt = $pdo->prepare("SELECT * FROM mensajes_soporte WHERE id_cliente = ? ORDER BY fecha_envio ASC");
        $stmt->execute([$id_cliente]);
        $mensajes = $stmt->fetchAll();
        
        // Marcar como leídos
        $columnaLeido = isCliente() ? 'leido_cliente' : 'leido_staff';
        $pdo->prepare("UPDATE mensajes_soporte SET $columnaLeido = 1 WHERE id_cliente = ?")->execute([$id_cliente]);
        
        echo json_encode(['success' => true, 'mensajes' => $mensajes]);

    } elseif ($action === 'send') {
        $data = json_decode(file_get_contents('php://input'), true);
        $mensaje = trim($data['mensaje'] ?? '');
        $tipo = $data['tipo_mensaje'] ?? 'texto';
        
        if (empty($mensaje)) throw new Exception("Mensaje vacío");

        if (isCliente()) {
            $id_cliente = $id_actual;
            $enviado_por = 'cliente';
            $id_staff = null;
        } else {
            $id_cliente = (int)($data['id_cliente'] ?? 0);
            $enviado_por = 'staff';
            $id_staff = $id_actual;
        }

        if ($id_cliente <= 0) throw new Exception("Cliente inválido");

        $sql = "INSERT INTO mensajes_soporte (id_cliente, id_staff, enviado_por, tipo_mensaje, mensaje, leido_cliente, leido_staff) 
                VALUES (?, ?, ?, ?, ?, ?, ?)";
        $leido_cliente = (isCliente() ? 1 : 0);
        $leido_staff = (!isCliente() ? 1 : 0);
        
        $stmt = $pdo->prepare($sql);
        $stmt->execute([$id_cliente, $id_staff, $enviado_por, $tipo, $mensaje, $leido_cliente, $leido_staff]);
        
        echo json_encode(['success' => true]);
    }
} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}