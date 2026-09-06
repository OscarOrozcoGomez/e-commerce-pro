<?php
declare(strict_types=1);

require_once __DIR__ . '/../core/config.php';
require_once __DIR__ . '/../core/auth.php';

header('Content-Type: application/json');

// Antes usaba requireAuth(), que sin sesion redirige a login.php en vez de devolver
// JSON -- rompia el fetch() que espera JSON siempre; ver ApiJsonContractNegativeTest.
if (!isAuthenticated()) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'No autenticado.']);
    exit;
}
refreshSessionPermissions();

// Permiso 'gestionar_clientes' abre este endpoint; el rol se mantiene como respaldo.
if (!hasPermission('gestionar_clientes') && !isAdmin() && !isEncargado()) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'No autorizado para editar clientes.']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Metodo no permitido.']);
    exit;
}

if (!validateCsrfToken($_POST['csrf_token'] ?? '')) {
    http_response_code(419);
    echo json_encode(['success' => false, 'message' => 'Token CSRF invalido.']);
    exit;
}

$storeValue = static function (?string $value): ?string {
    $value = $value !== null ? trim($value) : null;
    if ($value === null || $value === '') {
        return $value;
    }
    return function_exists('piiEncryptValue') ? piiEncryptValue($value) : $value;
};

$normalizePhone = static function (string $phone): ?string {
    $digits = preg_replace('/\D+/', '', $phone);
    if (!is_string($digits) || strlen($digits) !== 10) {
        return null;
    }
    return sprintf('(%s) - %s - %s', substr($digits, 0, 3), substr($digits, 3, 3), substr($digits, 6, 4));
};

try {
    $idCliente = (int)($_POST['id_cliente'] ?? 0);
    $telefonoNormalizado = $normalizePhone((string)($_POST['telefono'] ?? ''));

    if ($idCliente <= 0) {
        throw new Exception('Cliente invalido.');
    }
    if ($telefonoNormalizado === null) {
        throw new Exception('El telefono debe tener 10 digitos.');
    }

    $pdo = getPDO();
    $stmt = $pdo->prepare('SELECT id_cliente FROM clientes WHERE id_cliente = ? LIMIT 1');
    $stmt->execute([$idCliente]);
    if (!$stmt->fetchColumn()) {
        throw new Exception('El cliente ya no existe.');
    }

    $pdo->prepare('UPDATE clientes SET telefono = ? WHERE id_cliente = ?')
        ->execute([$storeValue($telefonoNormalizado), $idCliente]);

    echo json_encode([
        'success' => true,
        'cliente' => [
            'id_cliente' => $idCliente,
            'telefono' => $telefonoNormalizado,
        ],
    ]);
} catch (Throwable $e) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
