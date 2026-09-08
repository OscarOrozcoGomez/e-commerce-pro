<?php
declare(strict_types=1);

/**
 * Clasificacion de zona de entrega y calculo del cargo de envio foraneo.
 *
 * Unifica en un solo lugar la logica que antes solo vivia en core/ai_assistant.php
 * (aiClasificarZonaEntrega / aiCalcularCargoEnvio) para que los TRES flujos que dan
 * de alta un pedido apliquen exactamente el mismo criterio:
 *
 *   - Checkout web            core/auth.php :: dbCreatePublicOrder()
 *   - Panel de vendedor       api/ventas.php
 *   - Bot de WhatsApp (Alex)  core/ai_assistant.php :: aiToolAgendarVenta()
 *
 * Todo aqui es PURO y testeable: sin PDO, sin red, sin sesion, sin estado global.
 *
 * Estrategia hibrida (ver DeliveryZoneUtilsTest):
 *   1. Si el pedido trae coordenadas validas -> se decide por geometria:
 *      punto-en-poligono si se pasa un poligono, si no, radio (haversine) desde la
 *      sucursal de despacho. Las coordenadas MANDAN: nunca cae en 'indeterminado'.
 *   2. Sin coordenadas -> heuristica de texto: colonias/fraccionamientos perifericos
 *      conocidos (cobran aunque el municipio sea de la ZMG) -> municipio de la ZMG
 *      -> mencion de Jalisco -> 'indeterminado' (para revision manual, nunca se
 *      asume ni local ni foraneo por falta de dato).
 */

// Cargo fijo (MXN) para entregas fuera de la periferia de Guadalajara cuando el
// pedido tiene menos de DELIVERY_ZONE_FREE_SHIP_ITEMS productos DISTINTOS.
const DELIVERY_ZONE_FORANEO_FEE = 40.00;

// Promocion vigente: 2 o mas productos distintos => envio gratis en cualquier zona.
const DELIVERY_ZONE_FREE_SHIP_ITEMS = 2;

// Punto de despacho: sucursal "Tabachin 248, Bosques de Tonala, 45400 Tonala, Jal.".
// Aproximado; ajustalo si mueves el punto de salida real del reparto.
const DELIVERY_ZONE_STORE_LAT = 20.60500;
const DELIVERY_ZONE_STORE_LNG = -103.24000;

// Radio (km) alrededor de la sucursal que se considera "dentro de periferia" cuando
// hay coordenadas pero no se pasa un poligono explicito. Valor conservador: cubre la
// mancha urbana de la ZMG sin llegar a los desarrollos del sur de Tlajomulco / El Salto.
const DELIVERY_ZONE_RADIUS_KM = 18.0;

// Municipios de la Zona Metropolitana de Guadalajara con reparto sin costo. Sin
// acentos y en minusculas: deliveryZoneClassifyByText() normaliza antes de comparar.
const DELIVERY_ZONE_ZMG_MUNICIPIOS = [
    'guadalajara', 'zapopan', 'tlaquepaque', 'tonala', 'tlajomulco',
    'el salto', 'ixtlahuacan de los membrillos', 'juanacatlan',
];

// Colonias / fraccionamientos que por nombre caen en un municipio de la ZMG pero que
// en la practica estan fuera de la periferia con reparto sin costo (sobre todo el sur
// de Tlajomulco). Si el texto de la direccion menciona uno de estos, el cargo foraneo
// aplica AUNQUE tambien mencione "Tlajomulco", "El Salto", etc.
//
// LISTA INICIAL -- ampliala conforme aparezcan casos reales en ai_diagnostics.php.
// La precision fina la da la clasificacion por coordenadas; esto es solo el respaldo
// para pedidos capturados como texto suelto (bot / mostrador sin mapa).
const DELIVERY_ZONE_PERIFERIA_TOKENS = [
    'arvento',
    'hacienda santa fe',
    'chulavista',
    'chula vista',
    'lomas del sur',
    'la tijera',
    'cajititlan',
    'san miguel cuyutlan',
    'zapote del valle',
];

/**
 * Minusculas + sin acentos. Mismo criterio ya usado en el proyecto para busqueda de
 * inventario y deteccion de temas (coincidencia de texto simple, no interpretacion IA).
 */
function deliveryZoneStripAccentsLower(string $texto): string
{
    $texto = mb_strtolower(trim($texto));

    return strtr($texto, [
        'a' => 'a', 'e' => 'e', 'i' => 'i', 'o' => 'o', 'u' => 'u',
        'á' => 'a', 'é' => 'e', 'í' => 'i', 'ó' => 'o', 'ú' => 'u',
        'ñ' => 'n', 'ü' => 'u',
        'Á' => 'a', 'É' => 'e', 'Í' => 'i', 'Ó' => 'o', 'Ú' => 'u',
    ]);
}

/**
 * true solo si el par es numerico, esta dentro de rango geografico y no es el
 * "null island" (0,0), que es el valor tipico de un geocode que fallo.
 */
function deliveryZoneValidCoord($lat, $lng): bool
{
    if (!is_numeric($lat) || !is_numeric($lng)) {
        return false;
    }

    $latNum = (float) $lat;
    $lngNum = (float) $lng;

    if (is_nan($latNum) || is_nan($lngNum) || is_infinite($latNum) || is_infinite($lngNum)) {
        return false;
    }

    if ($latNum < -90.0 || $latNum > 90.0 || $lngNum < -180.0 || $lngNum > 180.0) {
        return false;
    }

    // (0,0) exacto: casi siempre "no se pudo geocodificar", no una direccion real.
    if (abs($latNum) < 1e-9 && abs($lngNum) < 1e-9) {
        return false;
    }

    return true;
}

/**
 * Distancia en kilometros entre dos puntos (formula del haversine, radio 6371 km).
 */
function deliveryZoneHaversineKm(float $lat1, float $lng1, float $lat2, float $lng2): float
{
    $radioTierraKm = 6371.0;

    $dLat = deg2rad($lat2 - $lat1);
    $dLng = deg2rad($lng2 - $lng1);

    $a = sin($dLat / 2) ** 2
        + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($dLng / 2) ** 2;

    return $radioTierraKm * 2 * atan2(sqrt($a), sqrt(max(0.0, 1 - $a)));
}

/**
 * Ray casting: true si el punto cae dentro del poligono. Cada vertice puede venir
 * como [lat, lng] o como ['lat' => , 'lng' => ]. Menos de 3 vertices => false.
 * El comportamiento sobre el borde/vertice exacto no esta garantizado (caso limite).
 *
 * @param array<int, array{0?: float, 1?: float, lat?: float, lng?: float}> $polygon
 */
function deliveryZonePointInPolygon(float $lat, float $lng, array $polygon): bool
{
    $pts = [];
    foreach ($polygon as $vertice) {
        if (!is_array($vertice)) {
            continue;
        }
        if (array_key_exists('lat', $vertice) && array_key_exists('lng', $vertice)) {
            $pts[] = [(float) $vertice['lat'], (float) $vertice['lng']];
        } elseif (array_key_exists(0, $vertice) && array_key_exists(1, $vertice)) {
            $pts[] = [(float) $vertice[0], (float) $vertice[1]];
        }
    }

    $n = count($pts);
    if ($n < 3) {
        return false;
    }

    $dentro = false;
    for ($i = 0, $j = $n - 1; $i < $n; $j = $i++) {
        [$latI, $lngI] = $pts[$i];
        [$latJ, $lngJ] = $pts[$j];

        $cruza = (($lngI > $lng) !== ($lngJ > $lng))
            && ($lat < ($latJ - $latI) * ($lng - $lngI) / (($lngJ - $lngI) ?: 1e-12) + $latI);

        if ($cruza) {
            $dentro = !$dentro;
        }
    }

    return $dentro;
}

/**
 * Clasifica SOLO por texto: 'local' | 'foraneo' | 'indeterminado'.
 * Direccion vacia o sin ninguna pista de municipio/estado => 'indeterminado'
 * (nunca se asume local ni foraneo por falta de dato).
 */
function deliveryZoneClassifyByText(string $direccion): string
{
    $n = deliveryZoneStripAccentsLower($direccion);
    if ($n === '') {
        return 'indeterminado';
    }

    // 1. Colonia/fraccionamiento periferico conocido: cobra aunque diga "Tlajomulco".
    foreach (DELIVERY_ZONE_PERIFERIA_TOKENS as $token) {
        if ($token !== '' && mb_strpos($n, $token) !== false) {
            return 'foraneo';
        }
    }

    // 2. Municipio de la ZMG mencionado en el texto.
    foreach (DELIVERY_ZONE_ZMG_MUNICIPIOS as $municipio) {
        if (mb_strpos($n, $municipio) !== false) {
            return 'local';
        }
    }

    // 3. Esta en Jalisco pero en ningun municipio de la ZMG -> foraneo del estado.
    if (mb_strpos($n, 'jalisco') !== false) {
        return 'foraneo';
    }

    return 'indeterminado';
}

/**
 * Clasificacion hibrida. Con coordenadas validas decide por geometria (poligono si se
 * pasa, si no radio desde la sucursal) y nunca regresa 'indeterminado'. Sin coordenadas
 * validas cae a deliveryZoneClassifyByText($direccion).
 *
 * @param array<int, mixed>|null $polygon Vertices [lat,lng] o ['lat'=>,'lng'=>]; null/[] => usar radio.
 */
function deliveryZoneClassify($lat, $lng, string $direccion = '', ?array $polygon = null): string
{
    if (deliveryZoneValidCoord($lat, $lng)) {
        $latNum = (float) $lat;
        $lngNum = (float) $lng;

        if (is_array($polygon) && count($polygon) >= 3) {
            return deliveryZonePointInPolygon($latNum, $lngNum, $polygon) ? 'local' : 'foraneo';
        }

        $km = deliveryZoneHaversineKm($latNum, $lngNum, DELIVERY_ZONE_STORE_LAT, DELIVERY_ZONE_STORE_LNG);

        return $km <= DELIVERY_ZONE_RADIUS_KM ? 'local' : 'foraneo';
    }

    return deliveryZoneClassifyByText($direccion);
}

/**
 * Cargo de envio segun zona y cantidad de productos DISTINTOS (no piezas totales).
 * Solo 'foraneo' con menos de $freeThreshold productos distintos genera cargo.
 */
function deliveryZoneShippingFee(
    string $zona,
    int $productosDistintos,
    float $fee = DELIVERY_ZONE_FORANEO_FEE,
    int $freeThreshold = DELIVERY_ZONE_FREE_SHIP_ITEMS
): float {
    if ($zona !== 'foraneo') {
        return 0.0;
    }

    if ($productosDistintos >= $freeThreshold) {
        return 0.0;
    }

    return round(max(0.0, $fee), 2);
}

/**
 * Resuelve zona + cargo para un pedido concreto en un solo llamado. Pensada para los
 * tres flujos de alta: le pasas lo que ya tienes (coords si hay, texto de la direccion,
 * tipo de entrega y numero de productos distintos) y te regresa que guardar.
 *
 * Entregas que no son a domicilio (Sucursal / pickup) nunca llevan cargo: zona 'local'.
 *
 * @return array{zona: string, costo_envio: float, productos_distintos: int}
 */
function deliveryZoneResolveForOrder(
    ?float $lat,
    ?float $lng,
    string $direccion,
    string $tipoEntrega,
    int $productosDistintos,
    ?array $polygon = null
): array {
    if (strcasecmp(trim($tipoEntrega), 'Domicilio') !== 0) {
        return ['zona' => 'local', 'costo_envio' => 0.0, 'productos_distintos' => max(0, $productosDistintos)];
    }

    $distintos = max(0, $productosDistintos);
    $zona = deliveryZoneClassify($lat, $lng, $direccion, $polygon);
    $costo = deliveryZoneShippingFee($zona, $distintos);

    return ['zona' => $zona, 'costo_envio' => $costo, 'productos_distintos' => $distintos];
}
