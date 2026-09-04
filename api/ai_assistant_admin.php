<?php
declare(strict_types=1);

require_once __DIR__ . '/../core/config.php';
require_once __DIR__ . '/../core/auth.php';
require_once __DIR__ . '/../core/ai_assistant.php';

header('Content-Type: application/json');

// Permiso 'gestionar_asistente_ia' abre este endpoint; el admin entra siempre (short-circuit).
if (!isAuthenticated() || (!hasPermission('gestionar_asistente_ia') && !isAdmin())) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'No autorizado.']);
    exit;
}

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Metodo no permitido.']);
    exit;
}

$data = json_decode((string)file_get_contents('php://input'), true);
if (!is_array($data)) {
    $data = $_POST;
}

if (!validateCsrfToken((string)($data['csrf_token'] ?? ''))) {
    http_response_code(419);
    echo json_encode(['success' => false, 'message' => 'Token de seguridad invalido, recarga la pagina e intenta de nuevo.']);
    exit;
}

$action = (string)($data['action'] ?? '');
$pdo = getPDO();

// Todas las acciones quedan detras de este try/catch: un fallo inesperado (ej. un valor
// demasiado largo para una columna VARCHAR bajo sql_mode estricto) nunca debe propagarse
// como excepcion no capturada ni exponer detalles internos en la respuesta.
try {
    aiAssistantAdminDispatch($action, $data, $pdo);
} catch (Throwable $e) {
    error_log('ERROR en ai_assistant_admin (accion=' . $action . '): ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Ocurrio un error inesperado.']);
}
exit;

function aiAssistantAdminDispatch(string $action, array $data, PDO $pdo): void
{
    if ($action === 'toggle_bot_global') {
        $activo = !empty($data['activo']);

        aiSetGlobalActive($pdo, $activo);
        logAudit('AI_ASISTENTE_GLOBAL_TOGGLE', 'ai_asistente_config', 1, $activo ? 'Alex activado globalmente' : 'Alex desactivado globalmente');

        echo json_encode(['success' => true, 'activo' => $activo]);
        exit;
    }

    if ($action === 'reactivate_bot') {
        $idConversacion = (int)($data['id_conversacion'] ?? 0);
        if ($idConversacion <= 0) {
            http_response_code(422);
            echo json_encode(['success' => false, 'message' => 'Conversacion invalida.']);
            exit;
        }

        $stmt = $pdo->prepare('SELECT wa_id FROM whatsapp_conversaciones WHERE id_conversacion = ?');
        $stmt->execute([$idConversacion]);
        $waId = $stmt->fetchColumn();

        if ($waId === false) {
            http_response_code(404);
            echo json_encode(['success' => false, 'message' => 'No se encontro esa conversacion.']);
            exit;
        }

        aiSetConversationState($pdo, $idConversacion, 'activo', null);
        logAudit('AI_ASISTENTE_BOT_REACTIVADO', 'whatsapp_conversaciones', $idConversacion, 'Bot reactivado para WhatsApp ' . (string)$waId);

        echo json_encode(['success' => true, 'message' => 'Bot reactivado para esta conversacion.']);
        exit;
    }

    if ($action === 'add_tag') {
        $idConversacion = (int)($data['id_conversacion'] ?? 0);
        $nombreEtiqueta = trim((string)($data['nombre_etiqueta'] ?? ''));

        if ($idConversacion <= 0 || $nombreEtiqueta === '') {
            http_response_code(422);
            echo json_encode(['success' => false, 'message' => 'Conversacion o etiqueta invalida.']);
            exit;
        }
        // whatsapp_etiquetas.nombre es VARCHAR(60); se valida en vez de dejar que el INSERT
        // truene bajo sql_mode estricto (el try/catch de arriba lo cubriria de todos modos,
        // pero asi el admin recibe un mensaje claro en vez de un 500 generico).
        if (mb_strlen($nombreEtiqueta) > 60) {
            http_response_code(422);
            echo json_encode(['success' => false, 'message' => 'El nombre de la etiqueta no puede pasar de 60 caracteres.']);
            exit;
        }

        $ok = aiAssignTag($pdo, $idConversacion, $nombreEtiqueta);
        if ($ok) {
            logAudit('AI_ASISTENTE_ETIQUETA_ASIGNADA', 'whatsapp_conversaciones', $idConversacion, 'Etiqueta "' . $nombreEtiqueta . '" asignada');
        }

        echo json_encode(['success' => $ok, 'tags' => aiGetConversationTags($pdo, $idConversacion)]);
        exit;
    }

    if ($action === 'remove_tag') {
        $idConversacion = (int)($data['id_conversacion'] ?? 0);
        $idEtiqueta = (int)($data['id_etiqueta'] ?? 0);

        if ($idConversacion <= 0 || $idEtiqueta <= 0) {
            http_response_code(422);
            echo json_encode(['success' => false, 'message' => 'Conversacion o etiqueta invalida.']);
            exit;
        }

        aiRemoveTag($pdo, $idConversacion, $idEtiqueta);
        logAudit('AI_ASISTENTE_ETIQUETA_QUITADA', 'whatsapp_conversaciones', $idConversacion, 'Etiqueta id ' . $idEtiqueta . ' quitada');

        echo json_encode(['success' => true, 'tags' => aiGetConversationTags($pdo, $idConversacion)]);
        exit;
    }

    if ($action === 'resolve_diagnostic_error') {
        $idError = (int)($data['id_error'] ?? 0);
        if ($idError <= 0) {
            http_response_code(422);
            echo json_encode(['success' => false, 'message' => 'Error de diagnostico invalido.']);
            exit;
        }

        $ok = aiMarkDiagnosticErrorResolved($pdo, $idError);
        if ($ok) {
            logAudit('AI_ASISTENTE_DIAGNOSTICO_RESUELTO', 'ai_errores_diagnostico', $idError, 'Marcado como revisado/corregido');
        }

        echo json_encode(['success' => $ok]);
        exit;
    }

    if ($action === 'create_learning_rule') {
        $idError = (int)($data['id_error'] ?? 0);
        $contexto = trim((string)($data['contexto_o_pregunta'] ?? ''));
        $respuesta = trim((string)($data['respuesta_o_accion_esperada'] ?? ''));
        $etiquetaSugerida = trim((string)($data['etiqueta_sugerida'] ?? ''));

        if ($contexto === '' || $respuesta === '') {
            http_response_code(422);
            echo json_encode(['success' => false, 'message' => 'Falta el contexto o la respuesta esperada.']);
            exit;
        }
        if (mb_strlen($etiquetaSugerida) > 60) {
            http_response_code(422);
            echo json_encode(['success' => false, 'message' => 'La etiqueta sugerida no puede pasar de 60 caracteres.']);
            exit;
        }

        $idRegla = aiCreateLearningRule($pdo, $contexto, $respuesta, $etiquetaSugerida !== '' ? $etiquetaSugerida : null);
        logAudit('AI_ASISTENTE_REGLA_APRENDIZAJE_CREADA', 'ai_reglas_aprendizaje', $idRegla, 'Regla creada desde correccion de diagnostico id_error=' . $idError);

        if ($idError > 0) {
            aiMarkDiagnosticErrorResolved($pdo, $idError);
        }

        echo json_encode(['success' => true, 'id_regla' => $idRegla]);
        exit;
    }

    if ($action === 'toggle_learning_rule') {
        $idRegla = (int)($data['id_regla'] ?? 0);
        $activa = !empty($data['activa']);

        if ($idRegla <= 0) {
            http_response_code(422);
            echo json_encode(['success' => false, 'message' => 'Regla invalida.']);
            exit;
        }

        $ok = aiSetLearningRuleActive($pdo, $idRegla, $activa);
        if ($ok) {
            logAudit('AI_ASISTENTE_REGLA_APRENDIZAJE_TOGGLE', 'ai_reglas_aprendizaje', $idRegla, $activa ? 'Activada' : 'Desactivada');
        }

        echo json_encode(['success' => $ok]);
        exit;
    }

    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Accion desconocida.']);
}
