<?php
require_once __DIR__ . '/../core/config.php';
require_once __DIR__ . '/../core/auth.php';
require_once __DIR__ . '/../core/phone_utils.php';

function normalizePhoneDigitsCheckout(string $value): string {
    return normalizePhoneDigitsMx($value) ?? '';
}

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
        $occupiedAliases = [];
        foreach ($direcciones as $dir) {
            $alias = trim((string)($dir['alias'] ?? ''));
            if (function_exists('piiIsEncryptedValue') && function_exists('piiDecryptValue') && piiIsEncryptedValue($alias)) {
                $alias = trim((string)piiDecryptValue($alias));
            }
            if ($alias !== '') {
                $occupiedAliases[$alias] = true;
            }
            if (preg_match('/^(?:Direccion|Direcci[oó]n)\s+(\d+)$/iu', $alias, $m)) {
                $maxAlias = max($maxAlias, (int)$m[1]);
            }
        }

        $nuevoAlias = 'Direccion ' . ($maxAlias + 1);
        $baseAlias = $nuevoAlias;
        $counter = $maxAlias + 1;
        while (isset($occupiedAliases[$nuevoAlias])) {
            $counter++;
            $nuevoAlias = 'Direccion ' . $counter;
        }
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

$telefonoMatch = null;
$telefonoEntrada = trim((string)($data['cliente']['telefono'] ?? ''));
if ($telefonoEntrada !== '') {
    try {
        $pdo = getPDO();
        $telefonoMatch = findClienteByPhone($pdo, $telefonoEntrada, isAuthenticated() && !empty($data['id_cliente']) ? (int) $data['id_cliente'] : null);
    } catch (Throwable $e) {
        error_log('No se pudo verificar teléfono del checkout: ' . $e->getMessage());
    }
}

$result = dbCreatePublicOrder($data);

// Guardar o actualizar teléfono del cliente desde checkout cuando cambie.
if ($result['success'] && isAuthenticated() && !empty($data['cliente']['telefono'])) {
    $idCliente = $_SESSION['usuario']['id_cliente'] ?? null;
    $telActual  = $_SESSION['usuario']['telefono_cliente'] ?? null;
    if ($idCliente) {
        try {
            $pdo = getPDO();
            $telefonoNuevo = trim((string)$data['cliente']['telefono']);
            $digitsNuevo = normalizePhoneDigitsCheckout($telefonoNuevo);

            $telActualPlain = trim((string)$telActual);
            if ($telActualPlain !== '' && function_exists('piiIsEncryptedValue') && function_exists('piiDecryptValue') && piiIsEncryptedValue($telActualPlain)) {
                $telActualPlain = trim((string)piiDecryptValue($telActualPlain));
            }
            $digitsActual = normalizePhoneDigitsCheckout($telActualPlain);

            if ($digitsNuevo !== '' && $digitsNuevo !== $digitsActual) {
                if ($telefonoMatch !== null && isset($telefonoMatch['id_cliente']) && (int)$telefonoMatch['id_cliente'] !== (int)$idCliente) {
                    // Mantener el pedido, pero no sobrescribir el teléfono de otra cuenta.
                    $_SESSION['checkout_phone_warning'] = 'Ese teléfono ya existe en otra cuenta.';
                } else {
                $telefonoStore = function_exists('piiEncryptValue') ? piiEncryptValue($telefonoNuevo) : $telefonoNuevo;
                $pdo->prepare("UPDATE clientes SET telefono = ? WHERE id_cliente = ?")
                    ->execute([$telefonoStore, $idCliente]);
                // Actualizar la sesión para futuros formularios.
                $_SESSION['usuario']['telefono_cliente'] = $telefonoNuevo;
                }
            }
        } catch (PDOException $e) {
            error_log('No se pudo guardar teléfono del cliente: ' . $e->getMessage());
        }
    }
}

if ($result['success']) {
    guardarDireccionCheckoutSiAplica($data);
}

if ($result['success']) {
    $payload = [
        'success' => true,
        'pedido' => $result['pedido'],
        'id_pedido' => $result['id_pedido'] ?? null,
        'message' => "Gracias {$data['cliente']['nombre']}, tu pedido {$result['pedido']} registrado.",
    ];

    if ($telefonoMatch !== null) {
        $payload['telefono_detectado'] = true;
        $payload['telefono_warning'] = 'Ese teléfono ya está registrado. Completa tu cuenta o inicia sesión para tener tu perfil listo.';
    }

    if (!empty($_SESSION['checkout_phone_warning'])) {
        $payload['telefono_warning'] = (string) $_SESSION['checkout_phone_warning'];
        unset($_SESSION['checkout_phone_warning']);
    }

    echo json_encode($payload);
} else {
    echo json_encode(['success' => false, 'message' => $result['message']]);
}