<?php
declare(strict_types=1);

/**
 * Cotizacion en vivo del cargo de envio foraneo para el carrito, ANTES de confirmar.
 *
 * Usa exactamente el mismo criterio que dbCreatePublicOrder() (core/delivery_zone_utils.php
 * + resolucion de coordenadas), asi que lo que se muestra aqui es lo que se va a cobrar.
 * Es solo lectura: no crea nada, no requiere sesion (el carrito se usa sin login).
 */

require_once __DIR__ . '/../core/config.php';
require_once __DIR__ . '/../core/auth.php'; // arrastra delivery_zone_utils.php y delivery_route_utils.php

header('Content-Type: application/json');

$data = json_decode(file_get_contents('php://input'), true);
if (!is_array($data)) {
    echo json_encode(['success' => false, 'message' => 'Datos invalidos']);
    exit;
}

$tipoEntrega = trim((string) ($data['tipo_entrega'] ?? ''));
$direccion   = trim((string) ($data['direccion'] ?? ''));
$mapsLink    = trim((string) ($data['maps_link'] ?? ''));
$items       = is_array($data['items'] ?? null) ? $data['items'] : [];

$productosDistintos = count(array_unique(array_filter(array_map(
    static fn($i) => (int) ($i['id_producto'] ?? $i['id'] ?? 0),
    $items
), static fn($id) => $id > 0)));

// Resolucion de coordenadas identica a dbCreatePublicOrder(): solo para domicilio, y el
// maps_link con "query=lat,lng" (cuando el cliente eligio una sugerencia de Google) se
// parsea sin llamada HTTP.
$coords = null;
if (strcasecmp($tipoEntrega, 'Domicilio') === 0 && ($mapsLink !== '' || $direccion !== '')) {
    try {
        $coords = deliveryResolveCoordinates($mapsLink, $direccion, getMapsApiKey(false));
    } catch (Throwable $e) {
        $coords = null;
    }
}

$quote = deliveryZoneResolveForOrder(
    is_array($coords) ? (float) $coords['lat'] : null,
    is_array($coords) ? (float) $coords['lng'] : null,
    $direccion,
    $tipoEntrega,
    $productosDistintos
);

echo json_encode([
    'success'             => true,
    'zona'                => $quote['zona'],
    'costo_envio'         => (float) $quote['costo_envio'],
    'productos_distintos' => $quote['productos_distintos'],
    'free_ship_items'     => DELIVERY_ZONE_FREE_SHIP_ITEMS,
    'foraneo_fee'         => (float) DELIVERY_ZONE_FORANEO_FEE,
    'tiene_coordenadas'   => is_array($coords),
]);
