<?php
declare(strict_types=1);

require_once __DIR__ . '/../core/config.php';
require_once __DIR__ . '/../core/auth.php';
require_once __DIR__ . '/../core/catalogo_utils.php';

header('Content-Type: application/json; charset=UTF-8');

if (!isAuthenticated() || (!isAdmin() && !isEncargado())) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'No autorizado']);
    exit;
}

$limit = max(1, min(1000, (int) ($_GET['limit'] ?? 200)));
$doProbe = in_array(strtolower((string) ($_GET['probe_write'] ?? '0')), ['1', 'true', 'yes', 'on'], true);
$probeResult = null;

if ($doProbe) {
    $probeResult = [
        'attempted' => true,
        'ok' => catalogPerfLogEntry([
            'endpoint' => 'api/catalog_performance_report.php',
            'source' => 'probe_write',
            'note' => 'manual probe write from report endpoint',
            'timings' => [
                'request_total_ms' => 0.0,
            ],
        ]),
    ];
}

$lines = catalogPerfReadLastLines($limit);
$status = catalogPerfLogStatus();

$parsed = [];
foreach ($lines as $line) {
    $decoded = json_decode($line, true);
    if (is_array($decoded)) {
        $parsed[] = $decoded;
    }
}

$stats = [
    'count' => count($parsed),
    'avg_total_ms' => 0.0,
    'p95_total_ms' => 0.0,
    'max_total_ms' => 0.0,
];

$totals = [];
foreach ($parsed as $entry) {
    $totalMs = (float) (($entry['entry']['timings']['request_total_ms'] ?? 0));
    if ($totalMs > 0) {
        $totals[] = $totalMs;
    }
}

if ($totals !== []) {
    sort($totals);
    $stats['avg_total_ms'] = round(array_sum($totals) / count($totals), 2);
    $stats['max_total_ms'] = round((float) end($totals), 2);

    $index = (int) floor((count($totals) - 1) * 0.95);
    $index = max(0, min(count($totals) - 1, $index));
    $stats['p95_total_ms'] = round((float) $totals[$index], 2);
}

echo json_encode([
    'success' => true,
    'env' => catalogPerfAppEnv(),
    'perf_enabled_now' => catalogPerfEnabledForRequest($_GET),
    'config' => [
        'CATALOG_PERF_LOG' => (string) (getenv('CATALOG_PERF_LOG') ?: ''),
        'CATALOG_PERF_LOG_PATH' => (string) (getenv('CATALOG_PERF_LOG_PATH') ?: ''),
    ],
    'path' => catalogPerfLogPath(),
    'log_status' => $status,
    'probe' => $probeResult,
    'limit' => $limit,
    'stats' => $stats,
    'entries' => $parsed,
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
