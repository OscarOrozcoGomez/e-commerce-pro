<?php
declare(strict_types=1);

require_once __DIR__ . '/../core/config.php';
require_once __DIR__ . '/../core/auth.php';
require_once __DIR__ . '/../core/google_secret_manager.php';
require_once __DIR__ . '/../core/delivery_route_utils.php';

header('Content-Type: application/json');

if (!isAuthenticated()) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'No autenticado.']);
    exit;
}

if (!isAdmin() && !isRepartidor()) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'No autorizado para optimizar rutas.']);
    exit;
}

if (strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET')) !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'Metodo no permitido.']);
    exit;
}

/**
 * @param array<string, mixed> $payload
 */
function routeJsonResponse(array $payload, int $code = 200): void
{
    http_response_code($code);
    echo json_encode($payload);
    exit;
}

/**
 * @param mixed $value
 */
function routeToFloat($value): ?float
{
    if (!is_numeric($value)) {
        return null;
    }

    return (float)$value;
}

function routeGeocodeFromAddress(string $address, string $apiKey): ?array
{
    $address = trim($address);
    if ($address === '' || $apiKey === '') {
        return null;
    }

    $url = 'https://maps.googleapis.com/maps/api/geocode/json?address='
        . rawurlencode($address)
        . '&key=' . rawurlencode($apiKey);

    $response = gsmHttpRequest('GET', $url, '', [], 12);
    if (!$response['ok']) {
        return null;
    }

    $data = json_decode((string)($response['body'] ?? ''), true);
    if (!is_array($data)) {
        return null;
    }

    $status = (string)($data['status'] ?? '');
    if ($status !== 'OK') {
        return null;
    }

    $location = $data['results'][0]['geometry']['location'] ?? null;
    if (!is_array($location)) {
        return null;
    }

    return deliveryNormalizeCoordinates($location['lat'] ?? null, $location['lng'] ?? null);
}

function routeHaversineMeters(float $lat1, float $lng1, float $lat2, float $lng2): float
{
    $earthRadius = 6371000.0;
    $dLat = deg2rad($lat2 - $lat1);
    $dLng = deg2rad($lng2 - $lng1);
    $a = sin($dLat / 2) * sin($dLat / 2)
        + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($dLng / 2) * sin($dLng / 2);
    $c = 2 * atan2(sqrt($a), sqrt(1 - $a));
    return $earthRadius * $c;
}

/**
 * @param array<int, array<string, mixed>> $stops
 * @return array{optimizedIndex: array<int, int>, legs: array<int, array{duration: string}>, distanceMeters: int, durationSeconds: int}
 */
function routeBuildLocalFallback(array $origin, array $stops): array
{
    $remaining = array_keys($stops);
    $optimizedIndex = [];
    $legs = [];
    $totalDistance = 0.0;
    $totalSeconds = 0;

    $currentLat = (float)$origin['lat'];
    $currentLng = (float)$origin['lng'];
    $metersPerSecond = 8.3333333333; // ~30 km/h urbano

    while (!empty($remaining)) {
        $closestKey = null;
        $closestDistance = null;

        foreach ($remaining as $idx) {
            $stop = $stops[$idx];
            $distance = routeHaversineMeters(
                $currentLat,
                $currentLng,
                (float)$stop['lat'],
                (float)$stop['lng']
            );

            if ($closestDistance === null || $distance < $closestDistance) {
                $closestDistance = $distance;
                $closestKey = $idx;
            }
        }

        if ($closestKey === null || $closestDistance === null) {
            break;
        }

        $optimizedIndex[] = (int)$closestKey;
        $seconds = (int)max(60, round($closestDistance / $metersPerSecond));
        $legs[] = ['duration' => $seconds . 's'];
        $totalDistance += $closestDistance;
        $totalSeconds += $seconds;

        $currentLat = (float)$stops[$closestKey]['lat'];
        $currentLng = (float)$stops[$closestKey]['lng'];

        $remaining = array_values(array_filter($remaining, static function ($value) use ($closestKey): bool {
            return (int)$value !== (int)$closestKey;
        }));
    }

    // Cerrar circuito regresando al origen para resumen de distancia/tiempo.
    if (!empty($optimizedIndex)) {
        $returnDistance = routeHaversineMeters(
            $currentLat,
            $currentLng,
            (float)$origin['lat'],
            (float)$origin['lng']
        );
        $totalDistance += $returnDistance;
        $totalSeconds += (int)max(60, round($returnDistance / $metersPerSecond));
    }

    return [
        'optimizedIndex' => $optimizedIndex,
        'legs' => $legs,
        'distanceMeters' => (int)round($totalDistance),
        'durationSeconds' => $totalSeconds,
    ];
}

$raw = json_decode((string)file_get_contents('php://input'), true);
$payload = is_array($raw) ? $raw : [];

$csrfToken = (string)($payload['csrf_token'] ?? '');
if (!validateCsrfToken($csrfToken)) {
    routeJsonResponse(['success' => false, 'error' => 'Token CSRF invalido.'], 422);
}

$originLat = routeToFloat($payload['origen']['lat'] ?? $payload['origin']['lat'] ?? null);
$originLng = routeToFloat($payload['origen']['lng'] ?? $payload['origin']['lng'] ?? null);
$origin = deliveryNormalizeCoordinates($originLat, $originLng);
if ($origin === null) {
    routeJsonResponse(['success' => false, 'error' => 'Origen invalido. Debes enviar lat y lng validos.'], 422);
}

$idsRaw = $payload['pedidosIds'] ?? $payload['pedidoIds'] ?? [];
if (!is_array($idsRaw)) {
    routeJsonResponse(['success' => false, 'error' => 'Lista de pedidos invalida.'], 422);
}

$pedidoIds = [];
foreach ($idsRaw as $idValue) {
    $id = (int)$idValue;
    if ($id > 0) {
        $pedidoIds[] = $id;
    }
}
$pedidoIds = array_values(array_unique($pedidoIds));

if (count($pedidoIds) < 2) {
    routeJsonResponse(['success' => false, 'error' => 'Selecciona al menos 2 pedidos para generar ruta.'], 422);
}

$usuario = $_SESSION['usuario'];
$usuarioId = (int)($usuario['id_usuario'] ?? 0);

$pdo = getPDO();

try {
    $hasPedidosLat = false;
    $hasPedidosLng = false;
    $hasPedidosMaps = false;
    $hasPedidosDireccionEntrega = false;
    $hasPedidosTelefonoEntrega = false;
    $hasPedidosFechaLimite = false;
    $hasPedidosPrioridad = false;
    $hasPedidosTiempoServicio = false;
    $hasClientesDireccion = false;
    $hasClientesTelefono = false;
    $hasClientesUbicacionMapa = false;
    $hasClienteDirecciones = false;

    $metaSqlColumn = "SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :table_name AND COLUMN_NAME = :column_name";
    $metaSqlTable = "SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :table_name";

    $metaStmt = $pdo->prepare($metaSqlColumn);

    $metaStmt->execute([':table_name' => 'pedidos', ':column_name' => 'latitud']);
    $hasPedidosLat = ((int)$metaStmt->fetchColumn()) > 0;

    $metaStmt->execute([':table_name' => 'pedidos', ':column_name' => 'longitud']);
    $hasPedidosLng = ((int)$metaStmt->fetchColumn()) > 0;

    $metaStmt->execute([':table_name' => 'pedidos', ':column_name' => 'maps_link_entrega']);
    $hasPedidosMaps = ((int)$metaStmt->fetchColumn()) > 0;

    $metaStmt->execute([':table_name' => 'pedidos', ':column_name' => 'direccion_entrega']);
    $hasPedidosDireccionEntrega = ((int)$metaStmt->fetchColumn()) > 0;

    $metaStmt->execute([':table_name' => 'pedidos', ':column_name' => 'telefono_entrega']);
    $hasPedidosTelefonoEntrega = ((int)$metaStmt->fetchColumn()) > 0;

    $metaStmt->execute([':table_name' => 'pedidos', ':column_name' => 'fecha_limite_entrega']);
    $hasPedidosFechaLimite = ((int)$metaStmt->fetchColumn()) > 0;

    $metaStmt->execute([':table_name' => 'pedidos', ':column_name' => 'prioridad_entrega']);
    $hasPedidosPrioridad = ((int)$metaStmt->fetchColumn()) > 0;

    $metaStmt->execute([':table_name' => 'pedidos', ':column_name' => 'tiempo_servicio_min']);
    $hasPedidosTiempoServicio = ((int)$metaStmt->fetchColumn()) > 0;

    $metaStmt->execute([':table_name' => 'clientes', ':column_name' => 'direccion']);
    $hasClientesDireccion = ((int)$metaStmt->fetchColumn()) > 0;

    $metaStmt->execute([':table_name' => 'clientes', ':column_name' => 'telefono']);
    $hasClientesTelefono = ((int)$metaStmt->fetchColumn()) > 0;

    $metaStmt->execute([':table_name' => 'clientes', ':column_name' => 'ubicacion_mapa']);
    $hasClientesUbicacionMapa = ((int)$metaStmt->fetchColumn()) > 0;

    $metaTableStmt = $pdo->prepare($metaSqlTable);
    $metaTableStmt->execute([':table_name' => 'cliente_direcciones']);
    $hasClienteDirecciones = ((int)$metaTableStmt->fetchColumn()) > 0;

    $pedidoLatExpr = $hasPedidosLat ? 'p.latitud' : 'NULL';
    $pedidoLngExpr = $hasPedidosLng ? 'p.longitud' : 'NULL';
    $fechaLimiteExpr = $hasPedidosFechaLimite ? 'p.fecha_limite_entrega' : 'NULL';
    $prioridadExpr = $hasPedidosPrioridad ? 'p.prioridad_entrega' : '0';
    $tiempoServicioExpr = $hasPedidosTiempoServicio ? 'p.tiempo_servicio_min' : '5';

    if ($hasPedidosDireccionEntrega) {
        $fallbackDireccion = $hasClientesDireccion && $hasClienteDirecciones
            ? "COALESCE(c.direccion, (SELECT cd.direccion FROM cliente_direcciones cd WHERE cd.id_cliente = c.id_cliente ORDER BY cd.es_default DESC, cd.id_direccion ASC LIMIT 1))"
            : ($hasClientesDireccion
                ? 'c.direccion'
                : ($hasClienteDirecciones
                    ? "(SELECT cd.direccion FROM cliente_direcciones cd WHERE cd.id_cliente = c.id_cliente ORDER BY cd.es_default DESC, cd.id_direccion ASC LIMIT 1)"
                    : 'NULL'));
        $direccionExpr = "COALESCE(NULLIF(TRIM(p.direccion_entrega), ''), {$fallbackDireccion})";
    } elseif ($hasClientesDireccion && $hasClienteDirecciones) {
        $direccionExpr = "COALESCE(c.direccion, (SELECT cd.direccion FROM cliente_direcciones cd WHERE cd.id_cliente = c.id_cliente ORDER BY cd.es_default DESC, cd.id_direccion ASC LIMIT 1))";
    } elseif ($hasClientesDireccion) {
        $direccionExpr = 'c.direccion';
    } elseif ($hasClienteDirecciones) {
        $direccionExpr = "(SELECT cd.direccion FROM cliente_direcciones cd WHERE cd.id_cliente = c.id_cliente ORDER BY cd.es_default DESC, cd.id_direccion ASC LIMIT 1)";
    } else {
        $direccionExpr = 'NULL';
    }

    $telefonoExpr = $hasPedidosTelefonoEntrega && $hasClientesTelefono
        ? "COALESCE(NULLIF(TRIM(p.telefono_entrega), ''), c.telefono)"
        : ($hasClientesTelefono ? 'c.telefono' : 'NULL');

    if ($hasPedidosMaps) {
        $fallbackMap = $hasClientesUbicacionMapa && $hasClienteDirecciones
            ? "COALESCE(c.ubicacion_mapa, (SELECT cd.maps_link FROM cliente_direcciones cd WHERE cd.id_cliente = c.id_cliente ORDER BY cd.es_default DESC, cd.id_direccion ASC LIMIT 1))"
            : ($hasClientesUbicacionMapa
                ? 'c.ubicacion_mapa'
                : ($hasClienteDirecciones
                    ? "(SELECT cd.maps_link FROM cliente_direcciones cd WHERE cd.id_cliente = c.id_cliente ORDER BY cd.es_default DESC, cd.id_direccion ASC LIMIT 1)"
                    : 'NULL'));
        $mapsExpr = "COALESCE(NULLIF(TRIM(p.maps_link_entrega), ''), {$fallbackMap})";
    } elseif ($hasClientesUbicacionMapa && $hasClienteDirecciones) {
        $mapsExpr = "COALESCE(c.ubicacion_mapa, (SELECT cd.maps_link FROM cliente_direcciones cd WHERE cd.id_cliente = c.id_cliente ORDER BY cd.es_default DESC, cd.id_direccion ASC LIMIT 1))";
    } elseif ($hasClientesUbicacionMapa) {
        $mapsExpr = 'c.ubicacion_mapa';
    } elseif ($hasClienteDirecciones) {
        $mapsExpr = "(SELECT cd.maps_link FROM cliente_direcciones cd WHERE cd.id_cliente = c.id_cliente ORDER BY cd.es_default DESC, cd.id_direccion ASC LIMIT 1)";
    } else {
        $mapsExpr = 'NULL';
    }

    $placeholders = implode(',', array_fill(0, count($pedidoIds), '?'));

    $sql = "SELECT p.id_pedido, p.numero_pedido, p.id_repartidor, p.estado,
                   {$pedidoLatExpr} AS latitud,
                   {$pedidoLngExpr} AS longitud,
                   {$mapsExpr} AS maps_link,
                   {$direccionExpr} AS direccion,
                   {$telefonoExpr} AS telefono,
                   {$fechaLimiteExpr} AS fecha_limite_entrega,
                   {$prioridadExpr} AS prioridad_entrega,
                   {$tiempoServicioExpr} AS tiempo_servicio_min,
                   c.nombre AS cliente
            FROM pedidos p
            LEFT JOIN clientes c ON p.id_cliente = c.id_cliente
            WHERE p.id_pedido IN ({$placeholders})
              AND p.estado IN ('pendiente_pago','pagado','en_reparto')";

    $params = $pedidoIds;
    if (isRepartidor()) {
        $sql .= ' AND p.id_repartidor = ?';
        $params[] = $usuarioId;
    }

    $sql .= ' ORDER BY p.fecha_entrega_programada ASC, p.fecha_creacion ASC';

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $pedidos = $stmt->fetchAll();

    if (count($pedidos) < 2) {
        routeJsonResponse([
            'success' => false,
            'error' => 'No hay suficientes pedidos validos para optimizar. Verifica asignacion y estado.',
        ], 422);
    }

    $repartidoresUnicos = [];
    foreach ($pedidos as $pedidoTmp) {
        $repId = (int)($pedidoTmp['id_repartidor'] ?? 0);
        if ($repId > 0) {
            $repartidoresUnicos[$repId] = true;
        }
    }

    if (count($repartidoresUnicos) > 1) {
        routeJsonResponse([
            'success' => false,
            'error' => 'Selecciona pedidos del mismo repartidor para generar una ruta coherente.',
        ], 422);
    }

    $warnings = [];
    $validStops = [];
    $routesKey = getMapsApiKey(true);

    foreach ($pedidos as $pedido) {
        $pedidoId = (int)$pedido['id_pedido'];

        $coords = deliveryNormalizeCoordinates($pedido['latitud'] ?? null, $pedido['longitud'] ?? null);
        if ($coords === null) {
            $mapsLink = trim((string)($pedido['maps_link'] ?? ''));
            if ($mapsLink !== ''
                && function_exists('piiIsEncryptedValue')
                && function_exists('piiDecryptValue')
                && piiIsEncryptedValue($mapsLink)) {
                $mapsLink = trim((string)piiDecryptValue($mapsLink));
            }
            if ($mapsLink !== '') {
                $coords = obtenerCoordenadasDesdeUrl($mapsLink);
            }
        }

        if ($coords === null) {
            $direccionPedido = trim((string)($pedido['direccion'] ?? ''));
            if ($direccionPedido !== '') {
                if (function_exists('piiIsEncryptedValue')
                    && function_exists('piiDecryptValue')
                    && piiIsEncryptedValue($direccionPedido)) {
                    $direccionPedido = trim((string)piiDecryptValue($direccionPedido));
                }
                $coords = routeGeocodeFromAddress($direccionPedido, $routesKey);
            }
        }

        if ($coords === null) {
            $warnings[] = [
                'id_pedido' => $pedidoId,
                'numero_pedido' => (string)$pedido['numero_pedido'],
                'reason' => 'No fue posible obtener coordenadas desde la URL de ubicacion.',
            ];
            continue;
        }

        if ($hasPedidosLat && $hasPedidosLng) {
            $stmtUp = $pdo->prepare('UPDATE pedidos SET latitud = ?, longitud = ? WHERE id_pedido = ?');
            $stmtUp->execute([$coords['lat'], $coords['lng'], $pedidoId]);
        }

        $telefono = (string)($pedido['telefono'] ?? '');
        if ($telefono !== '' && function_exists('piiIsEncryptedValue') && function_exists('piiDecryptValue') && piiIsEncryptedValue($telefono)) {
            $telefono = (string)piiDecryptValue($telefono);
        }

        $nombreCliente = (string)($pedido['cliente'] ?? 'Cliente');
        if ($nombreCliente !== '' && function_exists('piiIsEncryptedValue') && function_exists('piiDecryptValue') && piiIsEncryptedValue($nombreCliente)) {
            $nombreCliente = (string)piiDecryptValue($nombreCliente);
        }

        $direccion = (string)($pedido['direccion'] ?? '');
        if ($direccion !== '' && function_exists('piiIsEncryptedValue') && function_exists('piiDecryptValue') && piiIsEncryptedValue($direccion)) {
            $direccion = (string)piiDecryptValue($direccion);
        }

        $validStops[] = [
            'id_pedido' => $pedidoId,
            'numero_pedido' => (string)$pedido['numero_pedido'],
            'cliente' => $nombreCliente,
            'telefono' => $telefono,
            'direccion' => $direccion,
            'lat' => $coords['lat'],
            'lng' => $coords['lng'],
            'fecha_limite_entrega' => isset($pedido['fecha_limite_entrega']) ? (string)$pedido['fecha_limite_entrega'] : null,
            'prioridad_entrega' => (int)($pedido['prioridad_entrega'] ?? 0),
            'tiempo_servicio_min' => max(0, (int)($pedido['tiempo_servicio_min'] ?? 5)),
        ];
    }

    if (count($validStops) < 2) {
        routeJsonResponse([
            'success' => false,
            'error' => 'No hay suficientes pedidos con coordenadas validas para optimizar.',
            'hint' => 'Cada pedido seleccionado debe tener link de mapa resoluble o direccion geocodificable.',
            'warnings' => $warnings,
        ], 422);
    }

    $body = [
        'origin' => [
            'location' => [
                'latLng' => [
                    'latitude' => $origin['lat'],
                    'longitude' => $origin['lng'],
                ],
            ],
        ],
        'destination' => [
            'location' => [
                'latLng' => [
                    'latitude' => $origin['lat'],
                    'longitude' => $origin['lng'],
                ],
            ],
        ],
        'intermediates' => array_map(static function (array $stop): array {
            return [
                'location' => [
                    'latLng' => [
                        'latitude' => (float)$stop['lat'],
                        'longitude' => (float)$stop['lng'],
                    ],
                ],
            ];
        }, $validStops),
        'optimizeWaypointOrder' => true,
        'travelMode' => 'DRIVE',
    ];

    $requestBody = json_encode($body);
    if ($requestBody === false) {
        routeJsonResponse(['success' => false, 'error' => 'No se pudo serializar la solicitud de optimizacion.'], 500);
    }

    $endpoint = 'https://routes.googleapis.com/directions/v2:computeRoutes';

    $responseBody = '';
    $httpCode = 0;
    $curlErr = '';

    if (function_exists('curl_init')) {
        $ch = curl_init($endpoint);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Content-Type: application/json',
            'X-Goog-Api-Key: ' . $routesKey,
            'X-Goog-FieldMask: routes.optimizedIntermediateWaypointIndex,routes.legs,routes.distanceMeters,routes.duration',
        ]);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $requestBody);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 8);
        curl_setopt($ch, CURLOPT_TIMEOUT, 20);

        $exec = curl_exec($ch);
        $httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlErr = (string)curl_error($ch);
        curl_close($ch);

        if ($exec === false) {
            routeJsonResponse([
                'success' => false,
                'error' => 'Fallo de conexion al consultar Google Routes API.',
                'detail' => $curlErr,
            ], 502);
        }

        $responseBody = (string)$exec;
    } else {
        $res = gsmHttpRequest('POST', $endpoint, $requestBody, [
            'Content-Type' => 'application/json',
            'X-Goog-Api-Key' => $routesKey,
            'X-Goog-FieldMask' => 'routes.optimizedIntermediateWaypointIndex,routes.legs,routes.distanceMeters,routes.duration',
        ], 20);

        $httpCode = (int)($res['code'] ?? 0);
        $responseBody = (string)($res['body'] ?? '');
        if (!$res['ok']) {
            routeJsonResponse([
                'success' => false,
                'error' => 'Google Routes API rechazo la solicitud.',
                'detail' => (string)($res['error'] ?? ''),
                'http_code' => $httpCode,
            ], 502);
        }
    }

    $decoded = json_decode($responseBody, true);
    if (!is_array($decoded)) {
        routeJsonResponse([
            'success' => false,
            'error' => 'Respuesta invalida de Google Routes API.',
            'http_code' => $httpCode,
        ], 502);
    }

    $route = $decoded['routes'][0] ?? null;
    $usingFallbackRoute = false;
    $fallbackReason = '';
    if (!is_array($route)) {
        $apiErrorMsg = '';
        if (isset($decoded['error']['message']) && is_string($decoded['error']['message'])) {
            $apiErrorMsg = $decoded['error']['message'];
        }
        $fallback = routeBuildLocalFallback($origin, $validStops);
        if (count($fallback['optimizedIndex']) < count($validStops)) {
            routeJsonResponse([
                'success' => false,
                'error' => 'No se obtuvo una ruta valida desde Google.',
                'detail' => $apiErrorMsg,
                'http_code' => $httpCode,
            ], 502);
        }

        $route = [
            'optimizedIntermediateWaypointIndex' => $fallback['optimizedIndex'],
            'legs' => $fallback['legs'],
            'distanceMeters' => $fallback['distanceMeters'],
            'duration' => $fallback['durationSeconds'] . 's',
        ];
        $usingFallbackRoute = true;
        $fallbackReason = $apiErrorMsg !== '' ? $apiErrorMsg : 'Google Routes API no devolvio rutas.';
    }

    $optimizedIndex = $route['optimizedIntermediateWaypointIndex'] ?? [];
    if (!is_array($optimizedIndex) || count($optimizedIndex) !== count($validStops)) {
        $optimizedIndex = array_keys($validStops);
    }

    $orderedByGoogle = [];
    foreach ($optimizedIndex as $idx) {
        $i = (int)$idx;
        if (isset($validStops[$i])) {
            $orderedByGoogle[] = $validStops[$i];
        }
    }

    if (count($orderedByGoogle) !== count($validStops)) {
        $orderedByGoogle = $validStops;
    }

    $horaSalidaRaw = trim((string)($payload['hora_salida'] ?? ''));
    $departure = $horaSalidaRaw !== '' ? DateTimeImmutable::createFromFormat('Y-m-d\TH:i', $horaSalidaRaw) : null;
    if (!$departure instanceof DateTimeImmutable) {
        $departure = new DateTimeImmutable('now');
    }

    $legs = is_array($route['legs'] ?? null) ? $route['legs'] : [];

    $runningSeconds = 0;
    $orderedWithEta = [];
    $riskyStops = [];

    foreach ($orderedByGoogle as $pos => $stop) {
        $leg = $legs[$pos] ?? [];
        $legSeconds = deliveryDurationToSeconds(is_array($leg) ? (($leg['duration'] ?? null)) : null);
        $runningSeconds += $legSeconds;

        $eta = $departure->modify('+' . $runningSeconds . ' seconds');
        $etaText = $eta->format('Y-m-d H:i:s');

        $fechaLimite = $stop['fecha_limite_entrega'];
        $enRiesgo = false;
        if (is_string($fechaLimite) && trim($fechaLimite) !== '') {
            try {
                $deadline = new DateTimeImmutable($fechaLimite);
                $enRiesgo = $eta > $deadline;
            } catch (Throwable $e) {
                $enRiesgo = false;
            }
        }

        $stop['eta_estimada'] = $etaText;
        $stop['duracion_tramo_s'] = $legSeconds;
        $stop['en_riesgo'] = $enRiesgo;
        $orderedWithEta[] = $stop;

        $runningSeconds += max(0, (int)$stop['tiempo_servicio_min']) * 60;

        if ($enRiesgo) {
            $riskyStops[] = [
                'id_pedido' => $stop['id_pedido'],
                'numero_pedido' => $stop['numero_pedido'],
                'fecha_limite_entrega' => $stop['fecha_limite_entrega'],
                'eta_estimada' => $etaText,
            ];
        }
    }

    $mapsUrl = deliveryBuildGoogleMapsDirectionsUrl((float)$origin['lat'], (float)$origin['lng'], array_map(static function (array $stop): array {
        return [
            'lat' => (float)$stop['lat'],
            'lng' => (float)$stop['lng'],
        ];
    }, $orderedWithEta));

    $distanceMeters = (int)($route['distanceMeters'] ?? 0);
    $durationSeconds = deliveryDurationToSeconds((string)($route['duration'] ?? '0s'));

    logAudit(
        'RUTA_OPTIMIZADA',
        'pedidos',
        $orderedWithEta[0]['id_pedido'] ?? 0,
        'Ruta optimizada con ' . count($orderedWithEta) . ' paradas por usuario ID ' . $usuarioId
    );

    routeJsonResponse([
        'success' => true,
        'message' => $usingFallbackRoute
            ? 'Ruta generada con optimizacion local (respaldo sin Google Routes).'
            : 'Ruta optimizada correctamente.',
        'routing_provider' => $usingFallbackRoute ? 'local_fallback' : 'google_routes',
        'fallback_notice' => $usingFallbackRoute ? $fallbackReason : null,
        'googleMapsUrl' => $mapsUrl,
        'orderedStops' => $orderedWithEta,
        'googleOrderStops' => $orderedByGoogle,
        'optimizedIntermediateWaypointIndex' => array_values(array_map('intval', $optimizedIndex)),
        'warnings' => $warnings,
        'incumplibles_probables' => $riskyStops,
        'summary' => [
            'paradas_total' => count($orderedWithEta),
            'paradas_en_riesgo' => count($riskyStops),
            'distancia_total_m' => $distanceMeters,
            'duracion_total_s' => $durationSeconds,
            'duracion_total_hhmm' => deliveryFormatSecondsToHm($durationSeconds),
            'hora_salida' => $departure->format('Y-m-d H:i:s'),
        ],
    ]);
} catch (RuntimeException $e) {
    routeJsonResponse([
        'success' => false,
        'error' => $e->getMessage(),
    ], 500);
} catch (Throwable $e) {
    routeJsonResponse([
        'success' => false,
        'error' => 'Error interno al optimizar ruta.',
        'detail' => $e->getMessage(),
    ], 500);
}
