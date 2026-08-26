<?php
declare(strict_types=1);

/**
 * Valida y normaliza coordenadas geograficas.
 *
 * @return array{lat: float, lng: float}|null
 */
function deliveryNormalizeCoordinates($lat, $lng): ?array
{
    if (!is_numeric($lat) || !is_numeric($lng)) {
        return null;
    }

    $latNum = (float)$lat;
    $lngNum = (float)$lng;

    if ($latNum < -90 || $latNum > 90 || $lngNum < -180 || $lngNum > 180) {
        return null;
    }

    return [
        'lat' => round($latNum, 8),
        'lng' => round($lngNum, 8),
    ];
}

/**
 * Valida la fecha de entrega requerida al asignar un pedido a un repartidor. Ningun pedido
 * puede quedar con fecha_entrega_programada vacia: sin fecha, el armador de rutas lo rechaza
 * como "fuera del dia seleccionado" (ver api/optimize_delivery_route.php).
 *
 * @return array{valid: bool, fecha: ?string, error: ?string} fecha normalizada a 'Y-m-d 00:00:00'
 */
function deliveryValidateFechaEntregaAsignacion(string $fechaRaw): array
{
    $fechaRaw = trim($fechaRaw);
    if ($fechaRaw === '') {
        return [
            'valid' => false,
            'fecha' => null,
            'error' => 'Debes seleccionar una fecha de entrega antes de asignar el pedido a un repartidor.',
        ];
    }

    $fechaParsed = DateTimeImmutable::createFromFormat('Y-m-d', $fechaRaw);
    if (!($fechaParsed instanceof DateTimeImmutable) || $fechaParsed->format('Y-m-d') !== $fechaRaw) {
        return [
            'valid' => false,
            'fecha' => null,
            'error' => 'La fecha de entrega no es valida.',
        ];
    }

    return [
        'valid' => true,
        'fecha' => $fechaParsed->format('Y-m-d 00:00:00'),
        'error' => null,
    ];
}

/**
 * Obtiene la URL final siguiendo redirecciones HTTP.
 */
function deliveryExpandUrlWithCurl(string $url): ?string
{
    $url = trim($url);
    if ($url === '' || !filter_var($url, FILTER_VALIDATE_URL)) {
        return null;
    }

    if (!function_exists('curl_init')) {
        return $url;
    }

    $ch = curl_init($url);
    if ($ch === false) {
        return $url;
    }

    curl_setopt($ch, CURLOPT_NOBODY, true);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
    curl_setopt($ch, CURLOPT_MAXREDIRS, 8);
    curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 8);
    curl_setopt($ch, CURLOPT_TIMEOUT, 12);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
    curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 2);
    curl_setopt($ch, CURLOPT_USERAGENT, 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/124.0.0.0 Safari/537.36');

    $ok = curl_exec($ch);
    $finalUrl = curl_getinfo($ch, CURLINFO_EFFECTIVE_URL);
    curl_close($ch);

    if ($ok === false || !is_string($finalUrl) || trim($finalUrl) === '') {
        return $url;
    }

    return $finalUrl;
}

/**
 * Intenta extraer coordenadas desde una URL de Google Maps.
 *
 * Soporta principalmente:
 * - .../@lat,lng,...
 * - ...!3dLAT!4dLNG
 * - .../maps?q=lat,lng...
 *
 * @return array{lat: float, lng: float}|null
 */
function deliveryExtractCoordinatesFromMapsUrl(string $url): ?array
{
    $url = trim($url);
    if ($url === '') {
        return null;
    }

    if (preg_match('/@(-?\d{1,3}(?:\.\d+)?),(-?\d{1,3}(?:\.\d+)?)/', $url, $m) === 1) {
        return deliveryNormalizeCoordinates($m[1], $m[2]);
    }

    if (preg_match('/!3d(-?\d{1,3}(?:\.\d+)?)!4d(-?\d{1,3}(?:\.\d+)?)/', $url, $m) === 1) {
        return deliveryNormalizeCoordinates($m[1], $m[2]);
    }

    if (preg_match('/[?&]q=(-?\d{1,3}(?:\.\d+)?),(-?\d{1,3}(?:\.\d+)?)/', $url, $m) === 1) {
        return deliveryNormalizeCoordinates($m[1], $m[2]);
    }

    if (preg_match('/[?&]query=(-?\d{1,3}(?:\.\d+)?),(-?\d{1,3}(?:\.\d+)?)/', $url, $m) === 1) {
        return deliveryNormalizeCoordinates($m[1], $m[2]);
    }

    return null;
}

/**
 * Intenta extraer una direccion legible desde una URL de Google Maps cuando no
 * trae coordenadas crudas sino un identificador de lugar/CID de Google. Soporta:
 * - .../maps/place/<direccion url-encoded>/data=...
 * - .../maps?q=<texto de direccion o negocio, no numerico>
 */
function deliveryExtractAddressFromMapsUrl(string $url): ?string
{
    $url = trim($url);
    if ($url === '') {
        return null;
    }

    if (preg_match('#/maps/place/([^/?]+)#', $url, $m) === 1) {
        $decoded = trim(rawurldecode(str_replace('+', ' ', $m[1])));
        if ($decoded !== '' && strpos($decoded, ' ') !== false) {
            return $decoded;
        }
    }

    // El parametro q=/query= solo es confiable como direccion dentro de un path
    // de Google Maps; en otros dominios (p.ej. resultados de busqueda) puede
    // traer IDs internos sin relacion con una direccion real.
    if (strpos($url, '/maps') === false) {
        return null;
    }

    if (preg_match('/[?&]q(?:uery)?=([^&]+)/', $url, $m) === 1) {
        $decoded = trim(rawurldecode(str_replace('+', ' ', $m[1])));
        // Si es solo coordenadas ya se habria resuelto por regex; aqui solo interesa
        // texto con forma de direccion/negocio (requiere al menos un espacio para
        // descartar slugs/IDs sin sentido).
        if ($decoded !== ''
            && strpos($decoded, ' ') !== false
            && preg_match('/^-?\d{1,3}(?:\.\d+)?,-?\d{1,3}(?:\.\d+)?$/', $decoded) !== 1
        ) {
            return $decoded;
        }
    }

    return null;
}

/**
 * Funcion requerida: expande URL corta y extrae coordenadas.
 *
 * Si la URL expandida no trae coordenadas crudas (algunos links de Google usan
 * un identificador de lugar/CID en vez de @lat,lng), intenta leer la direccion
 * legible que Google incluye en la ruta (.../maps/place/<direccion>/...) y
 * geocodificarla como respaldo.
 *
 * @return array{lat: float, lng: float}|null
 */
function obtenerCoordenadasDesdeUrl(string $urlCorta, string $apiKey = ''): ?array
{
    $urlExpandida = deliveryExpandUrlWithCurl($urlCorta);
    if ($urlExpandida === null) {
        return null;
    }

    $coords = deliveryExtractCoordinatesFromMapsUrl($urlExpandida);
    if ($coords !== null) {
        return $coords;
    }

    $address = deliveryExtractAddressFromMapsUrl($urlExpandida);
    if ($address === null) {
        return null;
    }

    return deliveryGeocodeAddress($address, $apiKey);
}

/**
 * Fallback sin llave para geocodificar texto con Nominatim (OSM).
 * Se usa solo cuando Google Geocoding no esta disponible o no responde OK.
 *
 * @return array{lat: float, lng: float}|null
 */
function deliveryGeocodeAddressFallback(string $address): ?array
{
    $address = trim($address);
    if ($address === '' || !function_exists('gsmHttpRequest')) {
        return null;
    }

    $normalized = str_replace(
        ['Jal.', 'Jal', 'México', 'mexico'],
        ['Jalisco', 'Jalisco', 'Mexico', 'Mexico'],
        $address
    );
    $normalized = preg_replace('/\s+/', ' ', (string)$normalized);
    $normalized = trim((string)$normalized);

    $withoutZip = preg_replace('/\b\d{5}\b/u', '', $normalized);
    $withoutZip = preg_replace('/\s*,\s*/', ', ', (string)$withoutZip);
    $withoutZip = preg_replace('/\s+/', ' ', (string)$withoutZip);
    $withoutZip = trim((string)$withoutZip, " ,");

    $candidates = [];
    $candidates[] = $normalized;
    if ($withoutZip !== '' && $withoutZip !== $normalized) {
        $candidates[] = $withoutZip;
    }

    $parts = array_values(array_filter(array_map('trim', explode(',', $withoutZip)), static function ($p) {
        return $p !== '';
    }));
    if (count($parts) >= 3) {
        $first = $parts[0];
        $country = $parts[count($parts) - 1];
        for ($i = 1; $i < count($parts) - 1; $i++) {
            $mid = trim((string)preg_replace('/\b\d{5}\b/u', '', $parts[$i]));
            $mid = preg_replace('/\s+/', ' ', $mid);
            $mid = trim((string)$mid, ' ,');
            if ($mid === '') {
                continue;
            }
            $candidates[] = $first . ', ' . $mid . ', ' . $country;
        }
    }

    $seen = [];
    foreach ($candidates as $candidate) {
        $candidate = trim((string)$candidate, ' ,');
        if ($candidate === '') {
            continue;
        }
        $key = function_exists('mb_strtolower') ? mb_strtolower($candidate, 'UTF-8') : strtolower($candidate);
        if (isset($seen[$key])) {
            continue;
        }
        $seen[$key] = true;

        $url = 'https://nominatim.openstreetmap.org/search?format=jsonv2&limit=1&countrycodes=mx&q='
            . rawurlencode($candidate);

        $response = gsmHttpRequest('GET', $url, '', [
            'User-Agent' => 'e-commerce-pro/route-backfill',
            'Accept' => 'application/json',
        ], 12);

        if (!$response['ok']) {
            continue;
        }

        $data = json_decode((string)($response['body'] ?? ''), true);
        if (!is_array($data) || empty($data[0]) || !is_array($data[0])) {
            continue;
        }

        $coords = deliveryNormalizeCoordinates($data[0]['lat'] ?? null, $data[0]['lon'] ?? null);
        if ($coords !== null) {
            return $coords;
        }
    }

    return null;
}

/**
 * Geocodifica una direccion de texto usando Geocoding API.
 *
 * @return array{lat: float, lng: float}|null
 */
function deliveryGeocodeAddress(string $address, string $apiKey): ?array
{
    $address = trim($address);
    $apiKey = trim($apiKey);
    if ($address === '' || !function_exists('gsmHttpRequest')) {
        return null;
    }

    // Si la direccion viene muy corta, agregar contexto para mejorar precision en MX.
    $addressForQuery = $address;
    if (strpos($addressForQuery, ',') === false) {
        $addressForQuery .= ', Guadalajara, Jalisco, Mexico';
    }

    if ($apiKey === '') {
        return deliveryGeocodeAddressFallback($addressForQuery);
    }

    $url = 'https://maps.googleapis.com/maps/api/geocode/json?address='
        . rawurlencode($addressForQuery)
        . '&components=country:MX'
        . '&region=mx&language=es'
        . '&key=' . rawurlencode($apiKey);

    $response = gsmHttpRequest('GET', $url, '', [], 12);
    if (!$response['ok']) {
        return deliveryGeocodeAddressFallback($addressForQuery);
    }

    $data = json_decode((string)($response['body'] ?? ''), true);
    if (!is_array($data)) {
        return deliveryGeocodeAddressFallback($addressForQuery);
    }

    $status = (string)($data['status'] ?? '');
    if ($status !== 'OK') {
        return deliveryGeocodeAddressFallback($addressForQuery);
    }

    $location = $data['results'][0]['geometry']['location'] ?? null;
    if (!is_array($location)) {
        return deliveryGeocodeAddressFallback($addressForQuery);
    }

    return deliveryNormalizeCoordinates($location['lat'] ?? null, $location['lng'] ?? null);
}

/**
 * Resuelve coordenadas primero por URL de Google Maps y como fallback por direccion.
 *
 * @return array{lat: float, lng: float}|null
 */
function deliveryResolveCoordinates(string $mapsLink, string $address, string $apiKey = ''): ?array
{
    $mapsLink = trim($mapsLink);
    if ($mapsLink !== '') {
        $coords = obtenerCoordenadasDesdeUrl($mapsLink, $apiKey);
        if (is_array($coords)) {
            return $coords;
        }
    }

    $address = trim($address);
    if ($address === '') {
        return null;
    }

    return deliveryGeocodeAddress($address, $apiKey);
}

/**
 * Construye URL universal de Google Maps para navegacion con circuito cerrado.
 *
 * @param array<int, array{lat: float, lng: float}> $orderedStops
 */
function deliveryBuildGoogleMapsDirectionsUrl(float $originLat, float $originLng, array $orderedStops): string
{
    $origin = number_format($originLat, 8, '.', '') . ',' . number_format($originLng, 8, '.', '');
    $waypoints = [];
    foreach ($orderedStops as $stop) {
        $waypoints[] = number_format((float)$stop['lat'], 8, '.', '') . ',' . number_format((float)$stop['lng'], 8, '.', '');
    }

    $params = [
        'api' => '1',
        'origin' => $origin,
        'destination' => $origin,
        'travelmode' => 'driving',
    ];

    if (!empty($waypoints)) {
        $params['waypoints'] = implode('|', $waypoints);
    }

    return 'https://www.google.com/maps/dir/?' . http_build_query($params);
}

/**
 * Convierte la duracion RFC3339 de Google (ej. "842s") a segundos.
 */
function deliveryDurationToSeconds(?string $duration): int
{
    if ($duration === null) {
        return 0;
    }

    if (preg_match('/^(\d+)s$/', trim($duration), $m) !== 1) {
        return 0;
    }

    return (int)$m[1];
}

/**
 * Formatea segundos como HH:MM.
 */
function deliveryFormatSecondsToHm(int $seconds): string
{
    if ($seconds < 0) {
        $seconds = 0;
    }

    $hours = intdiv($seconds, 3600);
    $minutes = intdiv($seconds % 3600, 60);

    return sprintf('%02d:%02d', $hours, $minutes);
}

/**
 * Normaliza un nombre de dia para comparar semanas de entrega.
 */
function deliveryNormalizeWeekdayKey(?string $value): ?string
{
    $raw = strtolower(trim((string) ($value ?? '')));
    if ($raw === '') {
        return null;
    }

    $map = [
        'mon' => 'lunes', 'monday' => 'lunes', 'lun' => 'lunes', 'lunes' => 'lunes',
        'tue' => 'martes', 'tuesday' => 'martes', 'mar' => 'martes', 'martes' => 'martes',
        'wed' => 'miercoles', 'wednesday' => 'miercoles', 'mie' => 'miercoles', 'miercoles' => 'miercoles', 'miércoles' => 'miercoles',
        'thu' => 'jueves', 'thursday' => 'jueves', 'jue' => 'jueves', 'jueves' => 'jueves',
        'fri' => 'viernes', 'friday' => 'viernes', 'vie' => 'viernes', 'viernes' => 'viernes',
        'sat' => 'sabado', 'saturday' => 'sabado', 'sab' => 'sabado', 'sabado' => 'sabado', 'sábado' => 'sabado',
        'sun' => 'domingo', 'sunday' => 'domingo', 'dom' => 'domingo', 'domingo' => 'domingo',
    ];

    foreach ($map as $token => $day) {
        if ($raw === $token || $raw === $day || str_starts_with($raw, $token)) {
            return $day;
        }
    }

    return $raw;
}

/**
 * Normaliza una hora manual escrita por el admin.
 * Acepta valores como 9:30, 09:30, 930, 9:30 AM o 10:15 PM.
 */
function deliveryNormalizeManualHour(?string $value): string
{
    $raw = trim((string) ($value ?? ''));
    if ($raw === '') {
        throw new InvalidArgumentException('Debes escribir una hora valida en formato HH:MM o 12 horas con AM/PM.');
    }

    $upperRaw = strtoupper($raw);
    $meridiem = null;
    if (preg_match('/\b(AM|PM)\b$/', $upperRaw)) {
        $meridiem = substr($upperRaw, -2);
        $raw = trim(substr($raw, 0, -2));
    }

    $digits = preg_replace('/[^0-9]/', '', $raw);
    if (!is_string($digits) || $digits === '') {
        throw new InvalidArgumentException('La hora debe tener formato real, por ejemplo 09:30 o 9:30 AM.');
    }

    if (strlen($digits) === 3) {
        $digits = substr($digits, 0, 1) . ':' . substr($digits, 1);
    } elseif (strlen($digits) === 4) {
        $digits = substr($digits, 0, 2) . ':' . substr($digits, 2);
    }

    if (!preg_match('/^(?:[01]?\d|2[0-3]):[0-5]\d$/', $digits)) {
        throw new InvalidArgumentException('Hora invalida: usa un valor real entre 00:00 y 23:59 o un formato de 12 horas con AM/PM.');
    }

    $parts = explode(':', $digits);
    $hour = (int) $parts[0];
    $minute = (int) $parts[1];

    if ($meridiem !== null) {
        if ($hour < 1 || $hour > 12) {
            throw new InvalidArgumentException('La hora debe estar entre 1 y 12 cuando uses AM/PM.');
        }
        if ($meridiem === 'AM' && $hour === 12) {
            $hour = 0;
        } elseif ($meridiem === 'PM' && $hour < 12) {
            $hour += 12;
        }
    }

    return sprintf('%02d:%02d', $hour, $minute);
}

/**
 * Devuelve un alias humano para una direccion cuando no hay uno definido.
 */
function deliveryFormatAddressAlias(?string $alias, int $position = 0): string
{
    $cleanAlias = trim((string) ($alias ?? ''));
    $isLegacySyntheticAlias = (bool) preg_match('/^direccion\s+\d+$/i', $cleanAlias);
    if ($cleanAlias !== '' && !$isLegacySyntheticAlias) {
        return $cleanAlias;
    }

    if ($position > 0) {
        return 'Domicilio ' . $position;
    }

    return 'Domicilio';
}

/**
 * Normaliza el dia de entrega usando nombres reales y permitiendo acentos o variantes.
 */
function deliveryNormalizeDeliveryDay(?string $value): string
{
    $normalized = deliveryNormalizeWeekdayKey($value);
    if ($normalized === null || $normalized === '') {
        throw new InvalidArgumentException('Debes elegir un dia valido para la entrega.');
    }

    $allowed = ['lunes', 'martes', 'miercoles', 'jueves', 'viernes', 'sabado', 'domingo'];
    if (!in_array($normalized, $allowed, true)) {
        throw new InvalidArgumentException('El dia de entrega no es valido.');
    }

    return $normalized === 'miercoles' ? 'miercoles' : $normalized;
}

/**
 * Normaliza una ventana de entrega a un formato estándar usado por la ruta.
 *
 * @return array{day: ?string, start: string, end: string, source: string}
 */
function deliveryNormalizeWindow(array $window, string $fallbackSource = 'default'): array
{
    $day = deliveryNormalizeWeekdayKey((string) ($window['dia'] ?? $window['day'] ?? $window['weekday'] ?? ''));
    $start = trim((string) ($window['inicio'] ?? $window['start'] ?? $window['hora_inicio'] ?? '00:00'));
    $end = trim((string) ($window['fin'] ?? $window['end'] ?? $window['hora_fin'] ?? '23:59'));

    if (!preg_match('/^\d{1,2}:\d{2}$/', $start)) {
        $start = '00:00';
    }
    if (!preg_match('/^\d{1,2}:\d{2}$/', $end)) {
        $end = '23:59';
    }

    return [
        'day' => $day,
        'start' => $start,
        'end' => $end,
        'source' => $fallbackSource,
    ];
}

/**
 * Parsea preferencias de entrega desde JSON o array.
 *
 * @param mixed $raw
 * @return array{ventanas: array<int, array{day: ?string, start: string, end: string, source: string}>, default_window: array{day: ?string, start: string, end: string, source: string}}
 */
function deliveryParseDeliveryPreferences($raw): array
{
    $decoded = $raw;
    if (is_string($raw)) {
        $trimmed = trim($raw);
        if ($trimmed === '') {
            $decoded = [];
        } else {
            $json = json_decode($trimmed, true);
            $decoded = is_array($json) ? $json : [];
        }
    }

    if (!is_array($decoded)) {
        $decoded = [];
    }

    $windows = [];
    $candidateGroups = [
        'ventanas', 'windows', 'delivery_windows', 'preferencias', 'preferences', 'horarios', 'schedules'
    ];

    foreach ($candidateGroups as $groupName) {
        if (!array_key_exists($groupName, $decoded) || !is_array($decoded[$groupName])) {
            continue;
        }

        foreach ($decoded[$groupName] as $window) {
            if (!is_array($window)) {
                continue;
            }
            $normalized = deliveryNormalizeWindow($window, $groupName);
            if ($normalized['start'] === '00:00' && $normalized['end'] === '23:59' && isset($window['dia'])) {
                $normalized['start'] = '00:00';
            }
            $windows[] = $normalized;
        }
    }

    $defaultWindow = null;
    foreach (['default_window', 'ventana_default', 'defaultWindow', 'horario_regular', 'regular_hours'] as $key) {
        if (!array_key_exists($key, $decoded) || !is_array($decoded[$key])) {
            continue;
        }
        $defaultWindow = deliveryNormalizeWindow($decoded[$key], $key);
        break;
    }

    if ($defaultWindow === null) {
        $defaultWindow = ['day' => null, 'start' => '00:00', 'end' => '23:59', 'source' => 'default'];
    }

    return [
        'ventanas' => $windows,
        'default_window' => $defaultWindow,
    ];
}

/**
 * Convierte una hora HH:MM a minutos desde medianoche.
 */
function deliveryTimeToMinutes(string $time): int
{
    if (!preg_match('/^\d{1,2}:\d{2}$/', $time)) {
        return 0;
    }

    [$hourRaw, $minuteRaw] = explode(':', $time);
    $hour = max(0, min(23, (int)$hourRaw));
    $minute = max(0, min(59, (int)$minuteRaw));
    return ($hour * 60) + $minute;
}

/**
 * Estima distancia entre dos coordenadas usando Haversine.
 */
function deliveryHaversineMeters(float $lat1, float $lng1, float $lat2, float $lng2): float
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
 * Estima tiempo de traslado en segundos con velocidad urbana promedio.
 */
function deliveryEstimateTravelSeconds(array $fromPoint, array $toPoint): int
{
    if (!isset($fromPoint['lat'], $fromPoint['lng'], $toPoint['lat'], $toPoint['lng'])) {
        return 900;
    }

    $distance = deliveryHaversineMeters(
        (float)$fromPoint['lat'],
        (float)$fromPoint['lng'],
        (float)$toPoint['lat'],
        (float)$toPoint['lng']
    );

    $metersPerSecond = 8.3333333333; // ~30 km/h urbano
    return (int)max(60, round($distance / $metersPerSecond));
}

/**
 * Extrae coordenadas de una parada y devuelve null cuando faltan.
 */
function deliveryExtractPoint(array $stop): ?array
{
    if (!isset($stop['lat'], $stop['lng'])) {
        return null;
    }

    return [
        'lat' => (float)$stop['lat'],
        'lng' => (float)$stop['lng'],
    ];
}

/**
 * Calcula el timestamp de cierre de una ventana respecto a la salida.
 */
function deliveryWindowDeadlineTimestamp(array $window, DateTimeImmutable $departure): ?int
{
    $dayKey = deliveryNormalizeWeekdayKey((string)($window['day'] ?? ''));
    if ($dayKey === null) {
        return null;
    }

    $weekdayMap = [
        'lunes' => 1,
        'martes' => 2,
        'miercoles' => 3,
        'jueves' => 4,
        'viernes' => 5,
        'sabado' => 6,
        'domingo' => 7,
    ];

    $targetDay = $weekdayMap[$dayKey] ?? null;
    if ($targetDay === null) {
        return null;
    }

    $currentDay = (int)$departure->format('N');
    $delta = (($targetDay - $currentDay) + 7) % 7;
    $targetDate = $departure->modify('+' . $delta . ' days');
    $endMinutes = deliveryTimeToMinutes((string)($window['end'] ?? '23:59'));
    $deadline = $targetDate->setTime(intdiv($endMinutes, 60), $endMinutes % 60);
    return $deadline->getTimestamp();
}

/**
 * Resuelve, para una parada, la ventana de horario con el deadline mas proximo
 * respecto a la salida. Se comparte entre el ordenamiento y la verificacion
 * posterior para que ambos midan "cumple/no cumple" de la misma forma.
 *
 * @param array<string, mixed> $stop
 * @return array{deadline: ?int, window: ?array}
 */
function deliveryResolveBestWindowDeadline(array $stop, DateTimeImmutable $departure): array
{
    $preferences = deliveryParseDeliveryPreferences($stop['delivery_preferences'] ?? $stop['preferencias_entrega'] ?? []);

    $bestDeadline = null;
    $bestWindow = null;

    foreach ($preferences['ventanas'] as $window) {
        $deadline = deliveryWindowDeadlineTimestamp($window, $departure);
        if ($deadline === null) {
            continue;
        }

        if ($bestDeadline === null || $deadline < $bestDeadline) {
            $bestDeadline = $deadline;
            $bestWindow = $window;
        }
    }

    return ['deadline' => $bestDeadline, 'window' => $bestWindow];
}

/**
 * Revisa un listado de paradas ya con ETA calculado (eta_estimada) contra su
 * ventana de horario y regresa las que llegarian tarde. Se usa despues de pedir
 * la ruta real a Google, porque el preordenamiento solo estima tiempos con
 * linea recta y puede equivocarse.
 *
 * @param array<int, array<string, mixed>> $orderedStopsWithEta
 * @return array<int, array{id_pedido: mixed, numero_pedido: mixed, eta_estimada: string, ventana_fin: string}>
 */
function deliveryFindWindowViolations(array $orderedStopsWithEta, DateTimeImmutable $departure): array
{
    $violations = [];

    foreach ($orderedStopsWithEta as $stop) {
        $resolved = deliveryResolveBestWindowDeadline($stop, $departure);
        $deadline = $resolved['deadline'];
        if ($deadline === null) {
            continue;
        }

        $etaText = (string)($stop['eta_estimada'] ?? '');
        if ($etaText === '') {
            continue;
        }

        try {
            $eta = new DateTimeImmutable($etaText);
        } catch (Throwable $e) {
            continue;
        }

        if ($eta->getTimestamp() > $deadline) {
            $violations[] = [
                'id_pedido' => $stop['id_pedido'] ?? null,
                'numero_pedido' => $stop['numero_pedido'] ?? null,
                'eta_estimada' => $etaText,
                'ventana_fin' => (string)($resolved['window']['end'] ?? ''),
            ];
        }
    }

    return $violations;
}

/**
 * Calcula ETAs reales por parada a partir de una ruta ya obtenida (de Google o del
 * fallback local) y detecta tanto incumplimientos de fecha_limite_entrega como de
 * ventana de horario. Se usa tanto para el intento inicial de optimize_delivery_route.php
 * como para el intento de correccion cuando se recalcula con orden estricto por ventana.
 *
 * @param array<int, array<string, mixed>> $orderedStops
 * @param array<string, mixed> $route
 * @return array{orderedWithEta: array<int, array<string, mixed>>, riskyStops: array<int, array<string, mixed>>, windowViolations: array<int, array<string, mixed>>}
 */
function deliveryBuildOrderedStopsWithEta(array $orderedStops, array $route, DateTimeImmutable $departure): array
{
    foreach ($orderedStops as $pos => $stop) {
        $preferences = deliveryParseDeliveryPreferences($stop['delivery_preferences'] ?? $stop['preferencias_entrega'] ?? []);
        $stop['delivery_preferences'] = $preferences;
        $orderedStops[$pos] = $stop;
    }

    $legs = is_array($route['legs'] ?? null) ? $route['legs'] : [];

    $runningSeconds = 0;
    $orderedWithEta = [];
    $riskyStops = [];

    foreach ($orderedStops as $pos => $stop) {
        $leg = $legs[$pos] ?? [];
        $legSeconds = deliveryDurationToSeconds(is_array($leg) ? (($leg['duration'] ?? null)) : null);
        $runningSeconds += $legSeconds;

        $eta = $departure->modify('+' . $runningSeconds . ' seconds');
        $etaText = $eta->format('Y-m-d H:i:s');

        $fechaLimite = $stop['fecha_limite_entrega'] ?? null;
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

        $runningSeconds += max(0, (int)($stop['tiempo_servicio_min'] ?? 0)) * 60;

        if ($enRiesgo) {
            $riskyStops[] = [
                'id_pedido' => $stop['id_pedido'] ?? null,
                'numero_pedido' => $stop['numero_pedido'] ?? null,
                'fecha_limite_entrega' => $fechaLimite,
                'eta_estimada' => $etaText,
            ];
        }
    }

    return [
        'orderedWithEta' => $orderedWithEta,
        'riskyStops' => $riskyStops,
        'windowViolations' => deliveryFindWindowViolations($orderedWithEta, $departure),
    ];
}

/**
 * Ordena paradas por urgencia de horario de entrega antes de generar la ruta.
 *
 * @param array<int, array<string, mixed>> $stops
 * @return array<int, array<string, mixed>>
 */
function deliveryOrderStopsByWindowPriority(array $stops, DateTimeImmutable $departure, ?array $origin = null): array
{
    $scheduled = [];
    $unscheduled = [];

    foreach ($stops as $index => $stop) {
        $resolved = deliveryResolveBestWindowDeadline($stop, $departure);

        $enrichedStop = [
            'index' => $index,
            'stop' => $stop,
            'window' => $resolved['window'],
            'deadline' => $resolved['deadline'],
            'priority' => (int) ($stop['prioridad_entrega'] ?? 0),
        ];

        if ($resolved['deadline'] !== null) {
            $scheduled[] = $enrichedStop;
        } else {
            $unscheduled[] = $enrichedStop;
        }
    }

    usort($scheduled, static function (array $left, array $right): int {
        if ($left['deadline'] === $right['deadline']) {
            return $right['priority'] <=> $left['priority'];
        }
        return $left['deadline'] <=> $right['deadline'];
    });

    $currentPoint = $origin !== null && isset($origin['lat'], $origin['lng'])
        ? ['lat' => (float)$origin['lat'], 'lng' => (float)$origin['lng']]
        : null;
    if ($currentPoint === null && !empty($scheduled)) {
        $currentPoint = deliveryExtractPoint($scheduled[0]['stop']) ?? null;
    } elseif ($currentPoint === null && !empty($unscheduled)) {
        $currentPoint = deliveryExtractPoint($unscheduled[0]['stop']) ?? null;
    }

    $clockTs = $departure->getTimestamp();
    $ordered = [];

    foreach ($scheduled as $scheduledIndex => $scheduledItem) {
        $scheduledStop = $scheduledItem['stop'];

        while (!empty($unscheduled) && $currentPoint !== null) {
            $directToScheduled = deliveryEstimateTravelSeconds($currentPoint, $scheduledStop);
            $arrivalIfDirect = $clockTs + $directToScheduled;
            $deadline = (int)$scheduledItem['deadline'];
            $slack = $deadline - $arrivalIfDirect;

            if ($slack <= 600) {
                break;
            }

            $bestUnscheduledKey = null;
            $bestAddedCost = null;
            foreach ($unscheduled as $key => $candidate) {
                $candidateStop = $candidate['stop'];
                $toCandidate = deliveryEstimateTravelSeconds($currentPoint, $candidateStop);
                $candidateToScheduled = deliveryEstimateTravelSeconds($candidateStop, $scheduledStop);
                $addedCost = ($toCandidate + max(0, ((int)($candidateStop['tiempo_servicio_min'] ?? 5)) * 60) + $candidateToScheduled) - $directToScheduled;

                if ($bestAddedCost === null || $addedCost < $bestAddedCost) {
                    $bestAddedCost = $addedCost;
                    $bestUnscheduledKey = $key;
                }
            }

            if ($bestUnscheduledKey === null || $bestAddedCost === null || $bestAddedCost > ($slack - 300)) {
                break;
            }

            $chosen = $unscheduled[$bestUnscheduledKey]['stop'];
            $travelToChosen = deliveryEstimateTravelSeconds($currentPoint, $chosen);
            $ordered[] = $chosen;
            $clockTs += $travelToChosen + max(0, ((int)($chosen['tiempo_servicio_min'] ?? 5)) * 60);
            $currentPoint = deliveryExtractPoint($chosen) ?? $currentPoint;
            unset($unscheduled[$bestUnscheduledKey]);
            $unscheduled = array_values($unscheduled);
        }

        if ($currentPoint !== null) {
            $clockTs += deliveryEstimateTravelSeconds($currentPoint, $scheduledStop);
        }
        $ordered[] = $scheduledStop;
        $clockTs += max(0, ((int)($scheduledStop['tiempo_servicio_min'] ?? 5)) * 60);
        $currentPoint = deliveryExtractPoint($scheduledStop) ?? $currentPoint;
    }

    while (!empty($unscheduled)) {
        if ($currentPoint === null) {
            $chosenKey = array_key_first($unscheduled);
        } else {
            $chosenKey = null;
            $chosenDistance = null;
            foreach ($unscheduled as $key => $candidate) {
                $distance = deliveryEstimateTravelSeconds($currentPoint, $candidate['stop']);
                if ($chosenDistance === null || $distance < $chosenDistance) {
                    $chosenDistance = $distance;
                    $chosenKey = $key;
                }
            }
        }

        if ($chosenKey === null) {
            break;
        }

        $stop = $unscheduled[$chosenKey]['stop'];
        $ordered[] = $stop;
        $currentPoint = deliveryExtractPoint($stop) ?? $currentPoint;
        unset($unscheduled[$chosenKey]);
        $unscheduled = array_values($unscheduled);
    }

    return $ordered;
}

/**
 * Variante estricta de deliveryOrderStopsByWindowPriority: coloca todas las
 * paradas con ventana de horario, en orden de deadline, antes que cualquier
 * parada sin horario (sin intercalar). Se usa como correccion cuando el
 * entrelazado optimista resulto en una violacion real de ventana, porque
 * garantiza llegar a las paradas con horario lo antes fisicamente posible.
 *
 * @param array<int, array<string, mixed>> $stops
 * @return array<int, array<string, mixed>>
 */
function deliveryOrderStopsStrictWindowFirst(array $stops, DateTimeImmutable $departure, ?array $origin = null): array
{
    $scheduled = [];
    $unscheduled = [];

    foreach ($stops as $index => $stop) {
        $resolved = deliveryResolveBestWindowDeadline($stop, $departure);

        $enrichedStop = [
            'index' => $index,
            'stop' => $stop,
            'deadline' => $resolved['deadline'],
            'priority' => (int) ($stop['prioridad_entrega'] ?? 0),
        ];

        if ($resolved['deadline'] !== null) {
            $scheduled[] = $enrichedStop;
        } else {
            $unscheduled[] = $enrichedStop;
        }
    }

    usort($scheduled, static function (array $left, array $right): int {
        if ($left['deadline'] === $right['deadline']) {
            return $right['priority'] <=> $left['priority'];
        }
        return $left['deadline'] <=> $right['deadline'];
    });

    $ordered = [];
    foreach ($scheduled as $scheduledItem) {
        $ordered[] = $scheduledItem['stop'];
    }

    $currentPoint = !empty($ordered)
        ? deliveryExtractPoint($ordered[count($ordered) - 1])
        : ($origin !== null && isset($origin['lat'], $origin['lng'])
            ? ['lat' => (float)$origin['lat'], 'lng' => (float)$origin['lng']]
            : null);

    while (!empty($unscheduled)) {
        if ($currentPoint === null) {
            $chosenKey = array_key_first($unscheduled);
        } else {
            $chosenKey = null;
            $chosenDistance = null;
            foreach ($unscheduled as $key => $candidate) {
                $distance = deliveryEstimateTravelSeconds($currentPoint, $candidate['stop']);
                if ($chosenDistance === null || $distance < $chosenDistance) {
                    $chosenDistance = $distance;
                    $chosenKey = $key;
                }
            }
        }

        if ($chosenKey === null) {
            break;
        }

        $stop = $unscheduled[$chosenKey]['stop'];
        $ordered[] = $stop;
        $currentPoint = deliveryExtractPoint($stop) ?? $currentPoint;
        unset($unscheduled[$chosenKey]);
        $unscheduled = array_values($unscheduled);
    }

    return $ordered;
}
