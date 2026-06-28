<?php
require_once __DIR__ . '/../core/config.php';
require_once __DIR__ . '/../core/auth.php';

function guardarDireccionCheckoutSiAplica(array $data): void {
    if (!isAuthenticated() || !isCliente()) {
        return;
    }

    $tipoEntrega = trim((string)($data['tipo_entrega'] ?? ''));
    if (strcasecmp($tipoEntrega, 'Domicilio') !== 0) {
        return;
    }

    $idCliente = (int)($_SESSION['usuario']['id_cliente'] ?? 0);
    $direccion = trim((string)($data['cliente']['direccion'] ?? ''));
    $mapsLink = trim((string)($data['maps_link'] ?? ''));

    if ($idCliente <= 0 || $direccion === '') {
        return;
    }

    try {
        $pdo = getPDO();
        $stmt = $pdo->prepare("SELECT alias, direccion FROM cliente_direcciones WHERE id_cliente = ? ORDER BY id_direccion ASC");
        $stmt->execute([$idCliente]);
        $direcciones = $stmt->fetchAll();

        foreach ($direcciones as $dir) {
            if (trim((string)$dir['direccion']) === $direccion) {
                return; // Ya existe exactamente esa direccion, no duplicar.
            }
        }

        if (count($direcciones) >= 5) {
            return; // Se respeta el limite establecido en la app.
        }

        $maxAlias = 0;
        foreach ($direcciones as $dir) {
            $alias = trim((string)($dir['alias'] ?? ''));
            if (preg_match('/^(?:Direccion|Direcci[oó]n)\s+(\d+)$/iu', $alias, $m)) {
                $maxAlias = max($maxAlias, (int)$m[1]);
            }
        }

        $nuevoAlias = 'Direccion ' . ($maxAlias + 1);
        $esDefault = empty($direcciones) ? 1 : 0;

        $pdo->prepare("INSERT INTO cliente_direcciones (id_cliente, alias, direccion, maps_link, es_default) VALUES (?, ?, ?, ?, ?)")
            ->execute([$idCliente, $nuevoAlias, $direccion, $mapsLink, $esDefault]);
    } catch (Throwable $e) {
        error_log('No se pudo guardar direccion de checkout: ' . $e->getMessage());
    }
}

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

// Guardar teléfono en clientes si el cliente está logueado y aún no tiene teléfono registrado
if ($result['success'] && isAuthenticated() && !empty($data['cliente']['telefono'])) {
    $idCliente = $_SESSION['usuario']['id_cliente'] ?? null;
    $telActual  = $_SESSION['usuario']['telefono_cliente'] ?? null;
    if ($idCliente && empty($telActual)) {
        try {
            $pdo = getPDO();
            $pdo->prepare("UPDATE clientes SET telefono = ? WHERE id_cliente = ? AND (telefono IS NULL OR telefono = '')")
                ->execute([trim($data['cliente']['telefono']), $idCliente]);
            // Actualizar la sesión para que los futuros formularios lo lean
            $_SESSION['usuario']['telefono_cliente'] = trim($data['cliente']['telefono']);
        } catch (PDOException $e) {
            error_log('No se pudo guardar teléfono del cliente: ' . $e->getMessage());
        }
    }
}

if ($result['success']) {
    guardarDireccionCheckoutSiAplica($data);
}

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