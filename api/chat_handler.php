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
$soyCliente = isCliente();
$soyStaff = !isCliente();

$action = $_GET['action'] ?? '';

try {
    if ($action === 'fetch') {
        // Si es staff, necesita el ID del cliente específico. Si es cliente, usa su propio ID.
        $id_cliente = $soyStaff ? (int)($_GET['id_cliente'] ?? 0) : $id_actual;

        // Obtener estado y asignación actual del cliente
        $stmtStatus = $pdo->prepare("SELECT soporte_activo, asignado_a FROM usuarios WHERE id_usuario = ?");
        $stmtStatus->execute([$id_cliente]);
        $statusData = $stmtStatus->fetch();
        
        if (!$statusData) throw new Exception("Usuario no encontrado");

        $soporte_activo = (bool)$statusData['soporte_activo'];
        $asignado_a = $statusData['asignado_a'] !== null ? (int)$statusData['asignado_a'] : null;

        // Regla de Asignación Automática para Staff
        if ($soyStaff) {
            if ($asignado_a === null && $soporte_activo && $id_cliente > 0) {
                // Si nadie lo atiende, el primero que lo "vea" se lo queda
                $pdo->prepare("UPDATE usuarios SET asignado_a = ? WHERE id_usuario = ?")->execute([$id_actual, $id_cliente]);
                $asignado_a = $id_actual;
            } elseif ($asignado_a !== $id_actual) {
                // Si ya lo tiene otro compañero, bloqueamos la vista según tu requerimiento
                echo json_encode(['success' => false, 'message' => 'Este chat ya está siendo atendido por otro compañero.']);
                exit;
            }
        }
        
        $stmt = $pdo->prepare("SELECT * FROM mensajes_soporte WHERE id_cliente = ? ORDER BY fecha_envio ASC");
        $stmt->execute([$id_cliente]);
        $mensajes = $stmt->fetchAll();
        
        // Marcar como leídos
        $columnaLeido = $soyCliente ? 'leido_cliente' : 'leido_staff';
        $pdo->prepare("UPDATE mensajes_soporte SET $columnaLeido = 1 WHERE id_cliente = ?")->execute([$id_cliente]);
        
        // Verificar si la contraparte está escribiendo (hace menos de 6 segundos)
        $isTyping = false;
        if ($soyCliente) {
            // El cliente solo ve si SU agente asignado está escribiendo
            $stmtT = $pdo->prepare("SELECT COUNT(*) FROM usuarios WHERE id_usuario = ? AND tecleando_para = ? AND ultimo_tecleo > (NOW() - INTERVAL 6 SECOND)");
            $stmtT->execute([$asignado_a, $id_actual]);
            $isTyping = $stmtT->fetchColumn() > 0;
        } else {
            // El staff busca si el cliente específico está escribiendo (tecleando_para = 0 o nulo significa "a soporte")
            $stmtT = $pdo->prepare("SELECT COUNT(*) FROM usuarios WHERE id_usuario = ? AND ultimo_tecleo > (NOW() - INTERVAL 6 SECOND)");
            $stmtT->execute([$id_cliente]);
            $isTyping = $stmtT->fetchColumn() > 0;
        }
        
        echo json_encode([
            'success' => true, 
            'mensajes' => $mensajes, 
            'is_typing' => $isTyping,
            'soporte_activo' => $soporte_activo,
            'asignado_a' => $asignado_a
        ]);

    } elseif ($action === 'start' || $action === 'close') {
        $id_cliente = $soyStaff ? (int)($_GET['id_cliente'] ?? 0) : $id_actual;
        $nuevo_estado = ($action === 'start') ? 1 : 0;
        
        if ($id_cliente <= 0) throw new Exception("ID de usuario no válido.");

        dbUpdateChatStatus($id_cliente, $action);

        // Insertar marcador de sistema si se inicia un nuevo chat
        if ($action === 'start') {
            $fecha = date('d/m/Y H:i');
            $msgLabel = "--- Sesión iniciada: $fecha ---";
            $pdo->prepare("INSERT INTO mensajes_soporte (id_cliente, enviado_por, tipo_mensaje, mensaje) VALUES (?, 'staff', 'sistema', ?)")
                ->execute([$id_cliente, $msgLabel]);
        }

        $pdo->commit();
        echo json_encode(['success' => true]);

    } elseif ($action === 'transfer') {
        if ($soyCliente) throw new Exception("No autorizado");
        $id_cliente = (int)($_GET['id_cliente'] ?? 0);
        $id_destino = (int)($_GET['id_destino'] ?? 0);
        
        if ($id_cliente <= 0 || $id_destino <= 0) throw new Exception("Datos de transferencia incompletos.");

        $pdo->beginTransaction();

        // Obtener nombre del nuevo agente para el mensaje de sistema
        $stmtN = $pdo->prepare("SELECT nombre FROM usuarios WHERE id_usuario = ?");
        $stmtN->execute([$id_destino]);
        $nombreDestino = $stmtN->fetchColumn();
        if (!$nombreDestino) throw new Exception("El agente de destino no existe.");

        $stmt = $pdo->prepare("UPDATE usuarios SET asignado_a = ?, soporte_activo = 1 WHERE id_usuario = ?");
        $stmt->execute([$id_destino, $id_cliente]);

        // Insertar mensaje de sistema para dejar constancia en el historial
        $msgTransfer = "--- Conversación transferida a: $nombreDestino ---";
        $pdo->prepare("INSERT INTO mensajes_soporte (id_cliente, enviado_por, tipo_mensaje, mensaje) VALUES (?, 'staff', 'sistema', ?)")
            ->execute([$id_cliente, $msgTransfer]);

        $pdo->commit();
        echo json_encode(['success' => true]);

    } elseif ($action === 'get_staff') {
        // Obtener lista de staff para el dropdown de transferencia
        $stmt = $pdo->query("SELECT id_usuario, nombre FROM usuarios WHERE id_rol IN (1,2,3) AND estado = 'activo'");
        echo json_encode(['success' => true, 'staff' => $stmt->fetchAll()]);

    } elseif ($action === 'fetch_quick') {
        $stmt = $pdo->prepare("SELECT * FROM respuestas_rapidas WHERE id_usuario = ? ORDER BY titulo ASC");
        $stmt->execute([$id_actual]);
        echo json_encode(['success' => true, 'responses' => $stmt->fetchAll()]);

    } elseif ($action === 'save_quick') {
        $data = json_decode(file_get_contents('php://input'), true);
        $titulo = trim($data['titulo'] ?? '');
        $msg = trim($data['mensaje'] ?? '');
        $id_resp = (int)($data['id_respuesta'] ?? 0);

        if (empty($titulo) || empty($msg)) throw new Exception("Título y mensaje son obligatorios.");

        if ($id_resp > 0) {
            $stmt = $pdo->prepare("UPDATE respuestas_rapidas SET titulo = ?, mensaje = ? WHERE id_respuesta = ? AND id_usuario = ?");
            $stmt->execute([$titulo, $msg, $id_resp, $id_actual]);
        } else {
            $stmt = $pdo->prepare("INSERT INTO respuestas_rapidas (id_usuario, titulo, mensaje) VALUES (?, ?, ?)");
            $stmt->execute([$id_actual, $titulo, $msg]);
        }
        echo json_encode(['success' => true]);

    } elseif ($action === 'delete_quick') {
        $id_resp = (int)($_GET['id_respuesta'] ?? 0);
        if ($id_resp <= 0) throw new Exception("ID inválido.");

        $stmt = $pdo->prepare("DELETE FROM respuestas_rapidas WHERE id_respuesta = ? AND id_usuario = ?");
        $stmt->execute([$id_resp, $id_actual]);
        echo json_encode(['success' => true]);

    } elseif ($action === 'typing') {
        $target = $soyStaff ? (int)($_GET['id_cliente'] ?? 0) : 0; // 0 para clientes escribiendo a soporte
        $stmt = $pdo->prepare("UPDATE usuarios SET ultimo_tecleo = NOW(), tecleando_para = ? WHERE id_usuario = ?");
        $stmt->execute([$target, $id_actual]);
        echo json_encode(['success' => true]);

    } elseif ($action === 'send') {
        $data = json_decode(file_get_contents('php://input'), true);
        $mensaje = trim($data['mensaje'] ?? '');
        $tipo = $data['tipo_mensaje'] ?? 'texto';
        
        if (empty($mensaje)) throw new Exception("Mensaje vacío");

        if ($soyCliente) {
            $id_cliente = $id_actual;
            $enviado_por = 'cliente';
            $id_staff = null;
        } else {
            $id_cliente = (int)($data['id_cliente'] ?? 0);
            $enviado_por = 'staff';
            $id_staff = $id_actual;
        }

        if ($id_cliente <= 0) throw new Exception("Cliente inválido");

        // Al enviar un mensaje, forzamos que el soporte esté activo
        $pdo->prepare("UPDATE usuarios SET soporte_activo = 1 WHERE id_usuario = ?")->execute([$id_cliente]);

        $sql = "INSERT INTO mensajes_soporte (id_cliente, id_staff, enviado_por, tipo_mensaje, mensaje, leido_cliente, leido_staff) 
                VALUES (?, ?, ?, ?, ?, ?, ?)";
        $leido_cliente = ($soyCliente ? 1 : 0);
        $leido_staff = (!$soyCliente ? 1 : 0);
        
        $stmt = $pdo->prepare($sql);
        $stmt->execute([$id_cliente, $id_staff, $enviado_por, $tipo, $mensaje, $leido_cliente, $leido_staff]);
        
        echo json_encode(['success' => true]);
    }
} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}