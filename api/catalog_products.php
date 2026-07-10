<?php
declare(strict_types=1);

require_once __DIR__ . '/../core/config.php';
require_once __DIR__ . '/../core/auth.php';
require_once __DIR__ . '/../core/catalogo_utils.php';

header('Content-Type: application/json; charset=UTF-8');

if (session_status() === PHP_SESSION_ACTIVE) {
    session_write_close();
}

$requestId = bin2hex(random_bytes(8));
header('X-Catalog-Perf-Request-Id: ' . $requestId);

$categoriaSeleccionada = trim((string) ($_GET['categoria'] ?? ''));
$busqueda = trim((string) ($_GET['search'] ?? ''));
$page = max(1, (int) ($_GET['page'] ?? 1));
$itemsPerPage = max(1, min(30, (int) ($_GET['items_per_page'] ?? 9)));
$accumulated = (($_GET['accumulated'] ?? '') === '1');
$source = trim((string) ($_GET['source'] ?? 'unknown'));
$perfEnabled = catalogPerfEnabledForRequest($_GET);
$requestStartMs = catalogPerfNowMs();

try {
    $pdo = getPDO();
    $result = catalogFetchProductsPage($pdo, $categoriaSeleccionada, $busqueda, $page, $itemsPerPage, $accumulated);
    $meta = catalogBuildPaginationMeta((int) $result['total'], $itemsPerPage, $page);

    $renderStartMs = catalogPerfNowMs();
    $itemsHtml = '';
    foreach ($result['productos'] as $producto) {
        $itemsHtml .= catalogRenderProductCard($producto);
    }
    $renderMs = round(catalogPerfNowMs() - $renderStartMs, 2);
    $requestMs = round(catalogPerfNowMs() - $requestStartMs, 2);

    if ($perfEnabled) {
        catalogPerfLogEntry([
            'request_id' => $requestId,
            'endpoint' => 'api/catalog_products.php',
            'source' => $source,
            'method' => (string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'),
            'uri' => (string) ($_SERVER['REQUEST_URI'] ?? ''),
            'ip' => (string) ($_SERVER['REMOTE_ADDR'] ?? ''),
            'user_agent' => (string) ($_SERVER['HTTP_USER_AGENT'] ?? ''),
            'page' => $page,
            'items_per_page' => $itemsPerPage,
            'accumulated' => $accumulated,
            'search_len' => strlen($busqueda),
            'categoria' => $categoriaSeleccionada,
            'returned_items' => count((array) $result['productos']),
            'total_products' => (int) $result['total'],
            'has_more' => (bool) ($meta['has_more'] ?? false),
            'timings' => [
                'count_ms' => (float) ($result['timings']['count_ms'] ?? 0.0),
                'query_ms' => (float) ($result['timings']['query_ms'] ?? 0.0),
                'collapse_ms' => (float) ($result['timings']['collapse_ms'] ?? 0.0),
                'fetch_total_ms' => (float) ($result['timings']['total_ms'] ?? 0.0),
                'render_ms' => $renderMs,
                'request_total_ms' => $requestMs,
            ],
            'memory_mb' => round(memory_get_peak_usage(true) / 1048576, 2),
        ]);
    }

    echo json_encode([
        'success' => true,
        'request_id' => $requestId,
        'items_html' => $itemsHtml,
        'meta' => $meta,
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
} catch (Throwable $e) {
    if ($perfEnabled) {
        catalogPerfLogEntry([
            'request_id' => $requestId,
            'endpoint' => 'api/catalog_products.php',
            'source' => $source,
            'page' => $page,
            'items_per_page' => $itemsPerPage,
            'accumulated' => $accumulated,
            'search_len' => strlen($busqueda),
            'categoria' => $categoriaSeleccionada,
            'error' => $e->getMessage(),
            'timings' => [
                'request_total_ms' => round(catalogPerfNowMs() - $requestStartMs, 2),
            ],
        ]);
    }

    http_response_code(500);
    echo json_encode([
        'success' => false,
        'request_id' => $requestId,
        'message' => 'Error al cargar productos del catálogo',
        'error' => $e->getMessage(),
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
}
