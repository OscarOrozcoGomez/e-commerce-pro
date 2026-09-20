<?php
declare(strict_types=1);

require_once __DIR__ . '/../core/config.php';
require_once __DIR__ . '/../core/auth.php';
require_once __DIR__ . '/../core/cliente_scope_utils.php';
require_once __DIR__ . '/../core/cliente_direccion_utils.php';
require_once __DIR__ . '/../core/delivery_route_utils.php';

header('Content-Type: application/json');

// Antes usaba requireAuth(), que sin sesion redirige a login.php en vez de devolver
// JSON -- rompia el fetch() que espera JSON siempre; ver ApiJsonContractNegativeTest.
if (!isAuthenticated()) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'No autenticado.']);
    exit;
}
refreshSessionPermissions();

// Permiso 'gestionar_clientes' abre este endpoint (sin respaldo por rol: el panel de Roles y Permisos manda).
if (!hasPermission('gestionar_clientes')) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'No autorizado para crear clientes.']);
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
    if (!is_string($digits)) {
        return null;
    }
    if ($digits === '') {
        return '';
    }
    if (strlen($digits) !== 10) {
        return null;
    }
    return sprintf('(%s) - %s - %s', substr($digits, 0, 3), substr($digits, 3, 3), substr($digits, 6, 4));
};

try {
    $nombre = trim((string)($_POST['nombre'] ?? ''));
    $email = trim((string)($_POST['email'] ?? ''));
    // Domicilio opcional: permite dar de alta el cliente con su direccion desde el POS sin ir a Administrar Clientes.
    $aliasDireccion = trim((string)($_POST['direccion_alias'] ?? ''));
    $direccion = trim((string)($_POST['direccion'] ?? ''));
    $mapsLink = trim((string)($_POST['maps_link'] ?? ''));
    $telefonoNormalizado = $normalizePhone((string)($_POST['telefono'] ?? ''));

    if ($nombre === '') {
        throw new Exception('El nombre del cliente es obligatorio.');
    }
    // Un cliente nunca se da de alta sin telefono (lo necesitan la ruta de entrega y los avisos por WhatsApp).
    if ($telefonoNormalizado === null || $telefonoNormalizado === '') {
        throw new Exception('El telefono es obligatorio y debe tener 10 digitos.');
    }
    if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        throw new Exception('El correo capturado no es valido.');
    }
    if ($aliasDireccion !== '' && direccionAliasExcedeLimite($aliasDireccion)) {
        throw new Exception(direccionAliasErrorLimite());
    }

    $pdo = getPDO();

    $guardarDireccion = false;
    $coords = null;
    if ($direccion !== '') {
        $stmtMeta = $pdo->prepare("SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'cliente_direcciones'");
        $stmtMeta->execute();
        $guardarDireccion = ((int)$stmtMeta->fetchColumn()) > 0;
        // Las coordenadas se resuelven antes de abrir la transaccion: puede haber una llamada de red (geocodificar).
        $coords = $guardarDireccion ? clienteResolverCoordsDireccion($mapsLink, $direccion) : null;
    }

    $pdo->beginTransaction();
    // El cliente nace en la sucursal de quien lo captura (NULL si es un admin sin
    // sucursal); de eso depende que su encargado lo vea despues -- ver cliente_scope_utils.
    $stmtInsert = $pdo->prepare("INSERT INTO clientes (nombre, email, telefono, id_almacen, estado) VALUES (?, ?, ?, ?, 'activo')");
    $stmtInsert->execute([
        $storeValue($nombre),
        $storeValue($email !== '' ? $email : null),
        $storeValue($telefonoNormalizado !== '' ? $telefonoNormalizado : null),
        clienteScopeAlmacenParaNuevo(getCurrentAlmacenId()),
    ]);
    $nuevoClienteId = (int)$pdo->lastInsertId();
    $direccionesRespuesta = [];

    if ($guardarDireccion && $direccion !== '') {
        // Sin alias se usa el mismo nombre por defecto que Administrar Clientes.
        $aliasGuardado = $aliasDireccion !== '' ? $aliasDireccion : 'Direccion 1';
        $stmtDir = $pdo->prepare('INSERT INTO cliente_direcciones (id_cliente, alias, direccion, maps_link, es_default, latitud, longitud) VALUES (?, ?, ?, ?, 1, ?, ?)');
        $stmtDir->execute([
            $nuevoClienteId,
            $storeValue($aliasGuardado),
            $storeValue($direccion),
            $storeValue($mapsLink),
            $coords['lat'] ?? null,
            $coords['lng'] ?? null,
        ]);
        // Mismo formato que las direcciones de un cliente ya existente en views/sales.php (en claro, no cifrado).
        $direccionesRespuesta[] = [
            'id_direccion' => (int)$pdo->lastInsertId(),
            'alias' => $aliasGuardado,
            'direccion' => $direccion,
            'maps_link' => $mapsLink,
            'es_default' => true,
        ];
    }

    $pdo->commit();

    // La auditoria va despues del commit, fuera de la transaccion de negocio.
    $auditDespues = auditSnapshotCliente($pdo, $nuevoClienteId);
    logAudit(
        'CLIENTE_CREADO',
        'clientes',
        $nuevoClienteId,
        'Cliente "' . $nombre . '" creado desde el POS' . (!empty($direccionesRespuesta) ? ' con dirección' : ' sin dirección'),
        null,
        auditDiff([], $auditDespues, array_keys($auditDespues))['despues']
    );

    echo json_encode([
        'success' => true,
        'cliente' => [
            'id_cliente' => $nuevoClienteId,
            'nombre' => $nombre,
            'telefono' => $telefonoNormalizado,
            'email' => $email,
            'direccion' => $direccionesRespuesta[0]['direccion'] ?? '',
            'maps_link' => $direccionesRespuesta[0]['maps_link'] ?? '',
            'direcciones' => $direccionesRespuesta,
        ],
    ]);
} catch (Throwable $e) {
    if (isset($pdo) && $pdo instanceof PDO && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
