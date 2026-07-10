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
            $dirActual = trim((string)($dir['direccion'] ?? ''));
            if (function_exists('piiIsEncryptedValue') && function_exists('piiDecryptValue') && piiIsEncryptedValue($dirActual)) {
                $dirActual = trim((string)piiDecryptValue($dirActual));
            }
            if ($dirActual === $direccion) {
                return; // Ya existe exactamente esa direccion, no duplicar.
            }
        }

        if (count($direcciones) >= 5) {
            return; // Se respeta el limite establecido en la app.
        }

        $maxAlias = 0;
        foreach ($direcciones as $dir) {
            $alias = trim((string)($dir['alias'] ?? ''));
            if (function_exists('piiIsEncryptedValue') && function_exists('piiDecryptValue') && piiIsEncryptedValue($alias)) {
                $alias = trim((string)piiDecryptValue($alias));
            }
            if (preg_match('/^(?:Direccion|Direcci[oó]n)\s+(\d+)$/iu', $alias, $m)) {
                $maxAlias = max($maxAlias, (int)$m[1]);
            }
        }

        $nuevoAlias = 'Direccion ' . ($maxAlias + 1);
        $esDefault = empty($direcciones) ? 1 : 0;

        $aliasStore = function_exists('piiEncryptValue') ? piiEncryptValue($nuevoAlias) : $nuevoAlias;
        $direccionStore = function_exists('piiEncryptValue') ? piiEncryptValue($direccion) : $direccion;
        $mapsStore = ($mapsLink !== '' && function_exists('piiEncryptValue')) ? piiEncryptValue($mapsLink) : $mapsLink;

        $pdo->prepare("INSERT INTO cliente_direcciones (id_cliente, alias, direccion, maps_link, es_default) VALUES (?, ?, ?, ?, ?)")
            ->execute([$idCliente, $aliasStore, $direccionStore, $mapsStore, $esDefault]);
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

// El checkout web solo esta disponible para cuentas de cliente.
if (isAuthenticated() && !isCliente()) {
    echo json_encode([
        'success' => false,
        'message' => 'Tu cuenta no tiene permisos para comprar en esta seccion. Usa una cuenta de cliente.',
    ]);
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
            $telefono = trim((string)$data['cliente']['telefono']);
            $telefonoStore = function_exists('piiEncryptValue') ? piiEncryptValue($telefono) : $telefono;
            $pdo->prepare("UPDATE clientes SET telefono = ? WHERE id_cliente = ? AND (telefono IS NULL OR telefono = '')")
                ->execute([$telefonoStore, $idCliente]);
            // Actualizar la sesión para que los futuros formularios lo lean
            $_SESSION['usuario']['telefono_cliente'] = $telefono;
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