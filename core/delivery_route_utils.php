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

    return null;
}

/**
 * Funcion requerida: expande URL corta y extrae coordenadas.
 *
 * @return array{lat: float, lng: float}|null
 */
function obtenerCoordenadasDesdeUrl(string $urlCorta): ?array
{
    $urlExpandida = deliveryExpandUrlWithCurl($urlCorta);
    if ($urlExpandida === null) {
        return null;
    }

    return deliveryExtractCoordinatesFromMapsUrl($urlExpandida);
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
        $coords = obtenerCoordenadasDesdeUrl($mapsLink);
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
 * Acepta valores como 9:30, 09:30 o 930.
 */
function deliveryNormalizeManualHour(?string $value): string
{
    $raw = trim((string) ($value ?? ''));
    if ($raw === '') {
        throw new InvalidArgumentException('Debes escribir una hora valida en formato HH:MM.');
    }

    $normalized = preg_replace('/[^0-9]/', '', $raw);
    if (!is_string($normalized) || $normalized === '') {
        throw new InvalidArgumentException('La hora debe tener formato real, por ejemplo 09:30.');
    }

    if (strlen($normalized) === 3) {
        $normalized = substr($normalized, 0, 1) . ':' . substr($normalized, 1);
    } elseif (strlen($normalized) === 4) {
        $normalized = substr($normalized, 0, 2) . ':' . substr($normalized, 2);
    }

    if (!preg_match('/^(?:[01]?\d|2[0-3]):[0-5]\d$/', $normalized)) {
        throw new InvalidArgumentException('Hora invalida: usa un valor real entre 00:00 y 23:59.');
    }

    $parts = explode(':', $normalized);
    $hour = (int) $parts[0];
    return sprintf('%02d:%02d', $hour, (int) $parts[1]);
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

    if (empty($windows)) {
        $windows[] = $defaultWindow;
    }

    return [
        'ventanas' => $windows,
        'default_window' => $defaultWindow,
    ];
}

/**
 * Ordena paradas por urgencia de horario de entrega antes de generar la ruta.
 *
 * @param array<int, array<string, mixed>> $stops
 * @return array<int, array<string, mixed>>
 */
function deliveryOrderStopsByWindowPriority(array $stops, DateTimeImmutable $departure): array
{
    $normalized = [];

    foreach ($stops as $index => $stop) {
        $preferences = deliveryParseDeliveryPreferences($stop['delivery_preferences'] ?? $stop['preferencias_entrega'] ?? []);
        $bestWindow = null;
        $bestWindowScore = PHP_INT_MAX;

        foreach ($preferences['ventanas'] as $window) {
            $dayKey = $window['day'];
            if ($dayKey === null) {
                $minuteStart = (int) substr($window['start'], 0, 2) * 60 + (int) substr($window['start'], 3, 2);
                $minuteEnd = (int) substr($window['end'], 0, 2) * 60 + (int) substr($window['end'], 3, 2);
                $score = $minuteEnd;
                if ($score < $bestWindowScore) {
                    $bestWindowScore = $score;
                    $bestWindow = $window;
                }
                continue;
            }

            $weekdayMap = [
                'lunes' => 1, 'martes' => 2, 'miercoles' => 3, 'miércoles' => 3,
                'jueves' => 4, 'viernes' => 5, 'sabado' => 6, 'sábado' => 6,
                'domingo' => 7,
            ];
            $dayNumber = $weekdayMap[$dayKey] ?? 1;
            $currentDay = (int) $departure->format('N');
            $delta = (($dayNumber - $currentDay) + 7) % 7;
            $targetDate = $departure->modify('+' . $delta . ' days');

            $startMinutes = (int) substr($window['start'], 0, 2) * 60 + (int) substr($window['start'], 3, 2);
            $endMinutes = (int) substr($window['end'], 0, 2) * 60 + (int) substr($window['end'], 3, 2);
            $deadline = $targetDate->setTime(intdiv($endMinutes, 60), $endMinutes % 60);

            $baseScore = $deadline->getTimestamp();
            if ($baseScore < $bestWindowScore) {
                $bestWindowScore = $baseScore;
                $bestWindow = $window;
            }
        }

        $normalized[] = [
            'index' => $index,
            'stop' => $stop,
            'sortKey' => $bestWindowScore,
            'priority' => (int) ($stop['prioridad_entrega'] ?? 0),
        ];
    }

    usort($normalized, static function (array $left, array $right): int {
        if ($left['sortKey'] === $right['sortKey']) {
            return $left['priority'] <=> $right['priority'];
        }
        return $left['sortKey'] <=> $right['sortKey'];
    });

    $ordered = [];
    foreach ($normalized as $item) {
        $ordered[] = $item['stop'];
    }

    return $ordered;
}
