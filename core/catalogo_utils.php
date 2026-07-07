<?php
declare(strict_types=1);

function catalogPerfNowMs(): float
{
    return hrtime(true) / 1000000;
}

function catalogPerfAppEnv(): string
{
    $rawEnv = getenv('APP_ENV');
    if ($rawEnv === false) {
        $rawEnv = $_SERVER['APP_ENV'] ?? $_ENV['APP_ENV'] ?? '';
    }

    $env = strtolower(trim((string) $rawEnv));
    return $env !== '' ? $env : 'unknown';
}

function catalogPerfEnabledForRequest(array $request = []): bool
{
    $flag = strtolower(trim((string) ($request['perf'] ?? '')));
    if (in_array($flag, ['1', 'true', 'yes', 'on'], true)) {
        return true;
    }

    $envFlag = strtolower(trim((string) (getenv('CATALOG_PERF_LOG') ?: '')));
    if (in_array($envFlag, ['1', 'true', 'yes', 'on'], true)) {
        return true;
    }

    return in_array(catalogPerfAppEnv(), ['local', 'dev', 'development', 'qa', 'test', 'testing'], true);
}

function catalogPerfLogPath(): string
{
    $override = trim((string) (getenv('CATALOG_PERF_LOG_PATH') ?: ''));
    if ($override !== '') {
        return $override;
    }

    $defaultDir = dirname(__DIR__) . '/logs';
    if (!is_dir($defaultDir)) {
        @mkdir($defaultDir, 0777, true);
    }

    if (is_dir($defaultDir) && is_writable($defaultDir)) {
        return $defaultDir . '/catalog_performance.log';
    }

    return rtrim(sys_get_temp_dir(), '/\\') . '/ecommerce_catalog_performance.log';
}

function catalogPerfLogMaxBytes(): int
{
    $raw = trim((string) (getenv('CATALOG_PERF_LOG_MAX_BYTES') ?: ''));
    $value = is_numeric($raw) ? (int) $raw : 2097152;
    return max(10240, $value);
}

/**
 * @param mixed $value
 * @return mixed
 */
function catalogPerfNormalizeValueForLog($value)
{
    if (is_float($value)) {
        return round($value, 2);
    }

    if (is_array($value)) {
        $normalized = [];
        foreach ($value as $key => $item) {
            $normalized[$key] = catalogPerfNormalizeValueForLog($item);
        }
        return $normalized;
    }

    return $value;
}

function catalogPerfRotateLogIfNeeded(string $path): void
{
    if (!is_file($path)) {
        return;
    }

    $size = (int) (@filesize($path) ?: 0);
    if ($size < catalogPerfLogMaxBytes()) {
        return;
    }

    $rotatedPath = $path . '.1';
    if (is_file($rotatedPath)) {
        @unlink($rotatedPath);
    }

    @rename($path, $rotatedPath);
    @touch($path);
}

/**
 * @return array{path:string, exists:bool, is_writable:bool, dir:string, dir_exists:bool, dir_writable:bool, size_bytes:int, max_bytes:int, rotated_path:string, rotated_exists:bool, rotated_size_bytes:int}
 */
function catalogPerfLogStatus(): array
{
    $path = catalogPerfLogPath();
    $dir = dirname($path);
    $exists = is_file($path);
    $size = $exists ? (int) @filesize($path) : 0;

    return [
        'path' => $path,
        'exists' => $exists,
        'is_writable' => $exists ? is_writable($path) : false,
        'dir' => $dir,
        'dir_exists' => is_dir($dir),
        'dir_writable' => is_dir($dir) ? is_writable($dir) : false,
        'size_bytes' => $size,
        'max_bytes' => catalogPerfLogMaxBytes(),
        'rotated_path' => $path . '.1',
        'rotated_exists' => is_file($path . '.1'),
        'rotated_size_bytes' => is_file($path . '.1') ? (int) (@filesize($path . '.1') ?: 0) : 0,
    ];
}

function catalogPerfEnsureLogFileExists(): bool
{
    $path = catalogPerfLogPath();
    if (is_file($path)) {
        return true;
    }

    $dir = dirname($path);
    if (!is_dir($dir) && !@mkdir($dir, 0777, true) && !is_dir($dir)) {
        return false;
    }

    if (!@touch($path) && !is_file($path)) {
        return false;
    }

    return true;
}

function catalogPerfLogEntry(array $entry): bool
{
    $path = catalogPerfLogPath();

    $normalized = [
        'ts' => gmdate('c'),
        'env' => catalogPerfAppEnv(),
        'entry' => catalogPerfNormalizeValueForLog($entry),
    ];

    $line = json_encode($normalized, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if (!is_string($line) || $line === '') {
        return false;
    }

    if (!catalogPerfEnsureLogFileExists()) {
        return false;
    }

    catalogPerfRotateLogIfNeeded($path);

    return @file_put_contents($path, $line . PHP_EOL, FILE_APPEND | LOCK_EX) !== false;
}

/**
 * @return array<int, string>
 */
function catalogPerfReadLastLines(int $limit = 200): array
{
    $path = catalogPerfLogPath();
    if (!is_file($path)) {
        return [];
    }

    $raw = @file($path, FILE_IGNORE_NEW_LINES);
    if (!is_array($raw)) {
        return [];
    }

    $safeLimit = max(1, min(1000, $limit));
    return array_values(array_slice($raw, -$safeLimit));
}

function catalogBindNamedParams(PDOStatement $stmt, array $params): void
{
    foreach ($params as $key => $value) {
        $stmt->bindValue($key, $value, is_int($value) ? PDO::PARAM_INT : PDO::PARAM_STR);
    }
}

function catalogGroupKey(string $name): string
{
    $trimmed = trim($name);
    if ($trimmed === '') {
        return '';
    }

    if (function_exists('mb_strtolower')) {
        return mb_strtolower($trimmed, 'UTF-8');
    }

    return strtolower($trimmed);
}

function catalogHasUsableImage(?string $img): bool
{
    $value = trim((string) $img);
    if ($value === '') {
        return false;
    }

    return stripos($value, 'default-product.svg') === false;
}

function catalogCollapseProducts(array $products): array
{
    $grouped = [];

    foreach ($products as $p) {
        $name = (string) ($p['nombre'] ?? '');
        $key = catalogGroupKey($name);
        if ($key === '') {
            $key = 'id:' . (string) ($p['id_producto'] ?? uniqid('', true));
        }

        $id = (int) ($p['id_producto'] ?? 0);
        $precioDesde = (float) ($p['precio_desde'] ?? $p['precio_venta'] ?? 0);
        $precioVenta = (float) ($p['precio_venta'] ?? $precioDesde);
        $precioComparacionDesde = (float) ($p['precio_comparacion_desde'] ?? $p['precio_comparacion'] ?? 0);

        if (!isset($grouped[$key])) {
            $p['precio_desde'] = $precioDesde;
            $p['precio_venta'] = $precioVenta;
            $p['precio_comparacion_desde'] = $precioComparacionDesde;
            $p['_variant_ids'] = $id > 0 ? [$id => true] : [];
            $p['total_variantes'] = max(1, (int) ($p['total_variantes'] ?? 1));
            $grouped[$key] = $p;
            continue;
        }

        $grouped[$key]['_variant_ids'][$id] = true;
        $variantCount = count($grouped[$key]['_variant_ids']);
        $grouped[$key]['total_variantes'] = max((int) $grouped[$key]['total_variantes'], $variantCount);

        $currentDesde = (float) ($grouped[$key]['precio_desde'] ?? 0);
        if ($currentDesde <= 0 || ($precioDesde > 0 && $precioDesde < $currentDesde)) {
            $grouped[$key]['precio_desde'] = $precioDesde;
        }

        $currentVenta = (float) ($grouped[$key]['precio_venta'] ?? 0);
        if ($currentVenta <= 0 || ($precioVenta > 0 && $precioVenta < $currentVenta)) {
            $grouped[$key]['precio_venta'] = $precioVenta;
        }

        $currentComparacion = (float) ($grouped[$key]['precio_comparacion_desde'] ?? 0);
        if ($currentComparacion <= 0 || ($precioComparacionDesde > 0 && $precioComparacionDesde < $currentComparacion)) {
            $grouped[$key]['precio_comparacion_desde'] = $precioComparacionDesde;
        }

        if (!catalogHasUsableImage((string) ($grouped[$key]['imagen'] ?? '')) && catalogHasUsableImage((string) ($p['imagen'] ?? ''))) {
            $grouped[$key]['imagen'] = $p['imagen'];
        }

        $existingDescription = trim((string) ($grouped[$key]['descripcion'] ?? ''));
        $incomingDescription = trim((string) ($p['descripcion'] ?? ''));
        if ($existingDescription === '' && $incomingDescription !== '') {
            $grouped[$key]['descripcion'] = $p['descripcion'];
        }
    }

    foreach ($grouped as &$item) {
        unset($item['_variant_ids']);
    }
    unset($item);

    return array_values($grouped);
}

/**
 * @return array{total_products:int, items_per_page:int, current_page:int, total_pages:int, has_more:bool}
 */
function catalogBuildPaginationMeta(int $totalProducts, int $itemsPerPage, int $currentPage): array
{
    $safePerPage = max(1, $itemsPerPage);
    $safePage = max(1, $currentPage);
    $totalPages = (int) ceil($totalProducts / $safePerPage);

    return [
        'total_products' => $totalProducts,
        'items_per_page' => $safePerPage,
        'current_page' => $safePage,
        'total_pages' => $totalPages,
        'has_more' => $safePage < $totalPages,
    ];
}

function catalogRenderProductCard(array $p): string
{
    $groupKey = catalogGroupKey((string) ($p['nombre'] ?? ''));
    $precioActual = (float) ($p['precio_desde'] ?? 0);
    $precioComparacion = (float) ($p['precio_comparacion_desde'] ?? $p['precio_comparacion'] ?? 0);
    $showPrecioComparacion = $precioComparacion > $precioActual && $precioActual > 0;

    ob_start();
    ?>
    <div class="col s12 m6 l4 product-card-container" data-group-key="<?php echo esc($groupKey); ?>" data-name="<?php echo esc(strtolower((string) ($p['nombre'] ?? ''))); ?>" data-sku="<?php echo esc(strtolower((string) ($p['sku'] ?? ''))); ?>">
        <a href="<?php echo BASE_URL; ?>product_detail.php?id=<?php echo (int) ($p['id_producto'] ?? 0); ?>" class="card-link">
            <div class="card hoverable border-radius-8" style="height: 360px; display: flex; flex-direction: column;">
                <div class="card-image waves-effect waves-block waves-light" style="height: 200px; background: #f9f9f9; display: flex; align-items: center; justify-content: center;">
                    <?php $imgSrc = getProductImageUrl((string) ($p['imagen'] ?? ''), (int) ($p['id_producto'] ?? 0)); ?>
                    <img src="<?php echo $imgSrc; ?>" loading="lazy" onerror="this.onerror=null;this.src='<?php echo getDefaultProductImageUrl(); ?>';" style="max-height: 100%; width: auto; object-fit: contain;">
                </div>
                <div class="card-content" style="flex-grow: 1;">
                    <span class="card-title grey-text text-darken-4 truncate" style="font-size: 1rem; font-weight: bold;" title="<?php echo esc((string) ($p['nombre'] ?? '')); ?>">
                        <?php echo esc((string) ($p['nombre'] ?? '')); ?>
                    </span>
                    <?php if ($showPrecioComparacion): ?>
                        <p class="grey-text text-darken-1" style="font-size: 0.95rem; margin: 8px 0 0;">
                            Precio de lista:
                            <span style="text-decoration: line-through;">$<?php echo number_format($precioComparacion, 2); ?></span>
                        </p>
                    <?php endif; ?>
                    <p class="blue-text text-darken-4" style="font-size: 1.3rem; margin: 10px 0;">
                        <?php if ((int) ($p['total_variantes'] ?? 0) > 1): ?>Desde <?php endif; ?>
                        $<?php echo number_format($precioActual, 2); ?>
                        <?php if ((int) ($p['total_variantes'] ?? 0) > 1): ?>
                            <span style="font-size: 0.8rem; display: block; color: #757575;">
                                (<?php echo (int) $p['total_variantes']; ?> opciones)
                            </span>
                        <?php endif; ?>
                    </p>
                </div>
                <div class="card-action center-align" style="border-top: 1px solid #eee;">
                    <button class="btn blue darken-4 waves-effect waves-light"
                            onclick="handleAddToCart(event, <?php echo (int) ($p['id_producto'] ?? 0); ?>, '<?php echo addslashes(esc((string) ($p['nombre'] ?? ''))); ?>', <?php echo (float) ($p['precio_venta'] ?? 0); ?>)">
                        <i class="material-icons">add_shopping_cart</i>
                    </button>
                </div>
            </div>
        </a>
    </div>
    <?php

    return (string) ob_get_clean();
}

/**
 * @return array{sql_main:string, sql_count:string, params:array<string,mixed>}
 */
function catalogBuildQueries(string $categoriaSeleccionada, string $busqueda): array
{
    $sqlMain = "SELECT p.*, COALESCE(NULLIF((SELECT pi.ruta_archivo FROM producto_imagenes pi INNER JOIN productos p_img ON pi.id_producto = p_img.id_producto WHERE (p_img.id_producto = p.id_producto OR p_img.id_padre = p.id_producto OR (TRIM(p_img.nombre) = TRIM(p.nombre) AND p_img.estado = 'activo')) ORDER BY (p_img.id_producto = p.id_producto) DESC, (p_img.id_padre = p.id_producto) DESC, pi.orden ASC LIMIT 1), ''), NULLIF(TRIM(p.imagen), ''), NULLIF(TRIM(p.imagen_url), '')) AS imagen,
        (SELECT MIN(precio_venta) FROM productos p3 WHERE (p3.id_producto = p.id_producto OR p3.id_padre = p.id_producto) AND p3.estado = 'activo') AS precio_desde,
        (SELECT MIN(precio_comparacion) FROM productos p4 WHERE (p4.id_producto = p.id_producto OR p4.id_padre = p.id_producto) AND p4.estado = 'activo' AND p4.precio_comparacion > 0) AS precio_comparacion_desde,
        (SELECT COUNT(*) FROM productos p2 WHERE (p2.id_producto = p.id_producto OR p2.id_padre = p.id_producto) AND p2.estado = 'activo') AS total_variantes
        FROM productos p";

    $sqlCount = 'SELECT COUNT(DISTINCT TRIM(p.nombre)) FROM productos p';
    $params = [];
    $whereClauses = [
        "(p.id_padre IS NULL OR p.id_padre = 0)",
        "(p.estado = 'activo' OR EXISTS (SELECT 1 FROM productos p_child WHERE p_child.id_padre = p.id_producto AND p_child.estado = 'activo'))",
    ];

    if ($categoriaSeleccionada !== '') {
        $joins = ' JOIN producto_categorias pc ON p.id_producto = pc.id_producto JOIN categorias c ON pc.id_categoria = c.id_categoria ';
        $sqlMain .= $joins;
        $sqlCount .= $joins;
        $whereClauses[] = 'c.nombre = :cat';
        $params[':cat'] = $categoriaSeleccionada;
    }

    if ($busqueda !== '') {
        $whereClauses[] = "(p.nombre LIKE :search_name OR p.codigo_barras LIKE :search_code OR p.nombre_variante LIKE :search_variant OR EXISTS (
            SELECT 1 FROM productos p_v
            WHERE p_v.id_padre = p.id_producto
              AND (p_v.nombre LIKE :search_ex OR p_v.codigo_barras LIKE :search_ex_code OR p_v.nombre_variante LIKE :search_ex_variant)
        ))";

        $term = '%' . $busqueda . '%';
        $params[':search_name'] = $term;
        $params[':search_code'] = $term;
        $params[':search_variant'] = $term;
        $params[':search_ex'] = $term;
        $params[':search_ex_code'] = $term;
        $params[':search_ex_variant'] = $term;
    }

    $where = ' WHERE ' . implode(' AND ', $whereClauses);
    $sqlMain .= $where;
    $sqlCount .= $where;

    return [
        'sql_main' => $sqlMain,
        'sql_count' => $sqlCount,
        'params' => $params,
    ];
}

/**
 * @return array{productos:array<int,array<string,mixed>>, total:int, timings:array{count_ms:float, query_ms:float, collapse_ms:float, total_ms:float}}
 */
function catalogFetchProductsPage(PDO $pdo, string $categoriaSeleccionada, string $busqueda, int $page, int $itemsPerPage, bool $accumulated = false): array
{
    $startMs = catalogPerfNowMs();
    $safePage = max(1, $page);
    $safePerPage = max(1, $itemsPerPage);

    $limit = $accumulated ? ($safePage * $safePerPage) : $safePerPage;
    $offset = $accumulated ? 0 : (($safePage - 1) * $safePerPage);

    $parts = catalogBuildQueries($categoriaSeleccionada, $busqueda);

    $countStartMs = catalogPerfNowMs();
    $stmtCount = $pdo->prepare($parts['sql_count']);
    catalogBindNamedParams($stmtCount, $parts['params']);
    $stmtCount->execute();
    $total = (int) $stmtCount->fetchColumn();
    $countMs = round(catalogPerfNowMs() - $countStartMs, 2);

    $queryStartMs = catalogPerfNowMs();
    $sqlMain = $parts['sql_main'] . ' ORDER BY p.nombre ASC LIMIT :limit OFFSET :offset';
    $stmtMain = $pdo->prepare($sqlMain);
    catalogBindNamedParams($stmtMain, $parts['params']);
    $stmtMain->bindValue(':limit', $limit, PDO::PARAM_INT);
    $stmtMain->bindValue(':offset', $offset, PDO::PARAM_INT);
    $stmtMain->execute();
    $rawRows = (array) $stmtMain->fetchAll(PDO::FETCH_ASSOC);
    $queryMs = round(catalogPerfNowMs() - $queryStartMs, 2);

    $collapseStartMs = catalogPerfNowMs();
    $productos = catalogCollapseProducts($rawRows);
    $collapseMs = round(catalogPerfNowMs() - $collapseStartMs, 2);

    $totalMs = round(catalogPerfNowMs() - $startMs, 2);

    return [
        'productos' => $productos,
        'total' => $total,
        'timings' => [
            'count_ms' => $countMs,
            'query_ms' => $queryMs,
            'collapse_ms' => $collapseMs,
            'total_ms' => $totalMs,
        ],
    ];
}
