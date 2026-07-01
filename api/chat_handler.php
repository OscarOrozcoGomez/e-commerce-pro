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

// Función auxiliar para enviar notificaciones Push ruidosas al celular vía Telegram
function enviarNotificacionTelegram(string $nombreCliente, string $textoMensaje): void {
    $enabledRaw = strtolower((string) (getEnvVar('TELEGRAM_NOTIFICATIONS_ENABLED', '1') ?? '1'));
    $notificationsEnabled = in_array($enabledRaw, ['1', 'true', 'yes', 'on'], true);
    if (!$notificationsEnabled) {
        return;
    }

    $botToken = getEnvVar('TELEGRAM_BOT_TOKEN');
    $chatId = getEnvVar('TELEGRAM_CHAT_ID');
    if ($botToken === null || $chatId === null) {
        // No interrumpir el flujo del chat si faltan credenciales de Telegram.
        return;
    }

    $textoAlerta = "💬 *¡Nuevo mensaje en bLife!*\n";
    $textoAlerta .= "👤 *Cliente:* " . $nombreCliente . "\n";
    $textoAlerta .= "📝 *Mensaje:* " . $textoMensaje;

    $url = "https://api.telegram.org/bot" . $botToken . "/sendMessage";
    $postData = [
        'chat_id' => $chatId,
        'text' => $textoAlerta
    ];

    if (!function_exists('curl_init')) {
        error_log('WARNING: curl no disponible; no se pudo enviar notificacion a Telegram.');
        return;
    }

    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($postData));
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 5);
    curl_setopt($ch, CURLOPT_TIMEOUT, 10);
    $response = curl_exec($ch);
    if ($response === false) {
        error_log('WARNING: fallo al enviar notificacion a Telegram.');
    }
    curl_close($ch);
}

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
            } elseif ($asignado_a !== $id_actual && !isAdmin()) {
                // Si ya lo tiene otro compañero, bloqueamos la vista (excepto para Admins)
                echo json_encode([
                    'success' => false, 
                    'message' => 'Este chat ya está siendo atendido por otro compañero.'
                ]);
                exit;
            }
        }
        
        $sqlFetch = "SELECT * FROM mensajes_soporte WHERE id_cliente = ?";
        // Los clientes no deben ver mensajes de auditoría técnica o seguridad
        if ($soyCliente) {
            $sqlFetch .= " AND tipo_mensaje != 'seguridad'";
        }
        $sqlFetch .= " ORDER BY fecha_envio ASC";
        
        $stmt = $pdo->prepare($sqlFetch);
        $stmt->execute([$id_cliente]);
        $mensajes = $stmt->fetchAll();
        
        // Marcar como leídos
        $columnaLeido = $soyCliente ? 'leido_cliente' : 'leido_staff';
        $pdo->prepare("UPDATE mensajes_soporte SET $columnaLeido = 1 WHERE id_cliente = ?")->execute([$id_cliente]);
        
        // Verificar si la contraparte está escribiendo (hace menos de 6 segundos)
        $isTyping = false;
        if ($soyCliente) {
            // El cliente solo ver si SU agente asignado está escribiendo
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

    } elseif ($action === 'fetch_clients') {
        if ($soyCliente) throw new Exception("No autorizado");

        $sqlList = "SELECT DISTINCT u.id_usuario, u.nombre, u.email, u.asignado_a,
            (SELECT COUNT(*) FROM mensajes_soporte m2 WHERE m2.id_cliente = u.id_usuario AND m2.leido_staff = 0) as pendientes,
            (SELECT COUNT(*) FROM mensajes_soporte m4 WHERE m4.id_cliente = u.id_usuario AND m4.leido_staff = 0 AND m4.tipo_mensaje = 'seguridad') as alertas_sistema
            FROM usuarios u
            JOIN mensajes_soporte m ON u.id_usuario = m.id_cliente
            WHERE u.soporte_activo = 1";

        $params = [];
        if (!isAdmin()) {
            $sqlList .= " AND (u.asignado_a IS NULL OR u.asignado_a = :id_actual)";
            $params[':id_actual'] = $id_actual;
        }
        $sqlList .= " ORDER BY alertas_sistema DESC, pendientes DESC, u.nombre ASC";

        $stmtList = $pdo->prepare($sqlList);
        $stmtList->execute($params);
        echo json_encode(['success' => true, 'clientes' => $stmtList->fetchAll()]);

    } elseif ($action === 'start' || $action === 'close') {
        $id_cliente = $soyStaff ? (int)($_GET['id_cliente'] ?? 0) : $id_actual;
        
        if ($id_cliente <= 0) throw new Exception("ID de usuario no válido.");

        dbUpdateChatStatus($id_cliente, $action);

        // Insertar marcador de sistema si se inicia un nuevo chat
        if ($action === 'start') {
            $fecha = date('d/m/Y H:i');
            $msgLabel = "--- Sesión iniciada: $fecha ---";
            $pdo->prepare("INSERT INTO mensajes_soporte (id_cliente, enviado_por, tipo_mensaje, mensaje) VALUES (?, 'staff', 'sistema', ?)")
                ->execute([$id_cliente, $msgLabel]);
        }

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
            $nombre_cliente = $usuario['nombre'] ?? 'Cliente bLife';
        } else {
            $id_cliente = (int)($data['id_cliente'] ?? 0);
            $enviado_por = 'staff';
            $id_staff = $id_actual;
            $nombre_cliente = '';
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
        
        // 🔥 SI EL MENSAJE PROVIENE DE UN CLIENTE, DISPARAMOS LA ALERTA A TU CELULAR
        if ($soyCliente) {
            // Si el tipo es un objeto JSON de producto, mandamos solo el nombre del producto en la notificación
            $textoNotificacion = $mensaje;
            if ($tipo === 'producto') {
                $pData = json_decode($mensaje, true);
                $textoNotificacion = "📦 Envió una tarjeta de producto: " . ($pData['nombre'] ?? 'Ver en chat');
            }
            enviarNotificacionTelegram($nombre_cliente, $textoNotificacion);
        }

        echo json_encode(['success' => true]);
    }
} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}