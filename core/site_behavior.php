<?php
declare(strict_types=1);

/**
 * Helpers puros para la iniciativa "Comportamiento en el Sitio": que productos/paginas
 * se ven, cuanto tiempo, y que se clickea. Todo lo que entra aqui viene del body JSON
 * publico de api/log_activity.php (no confiable) o de la URL que el propio navegador
 * mando en el evento 'visit' -- ninguna funcion debe lanzar ante entradas hostiles,
 * solo degradar a null/valor por defecto (misma filosofia que core/attribution.php:
 * el registro de actividad nunca debe romper al visitante).
 */

/**
 * Extrae el id de producto de una URL de product_detail.php (?id=123), si aplica.
 * No valida que el producto exista en la BD -- eso lo filtra el reporte via JOIN,
 * asi una URL con un id borrado/invalido simplemente no aparece en "mas vistos".
 */
function extractProductIdFromUrl(string $url): ?int
{
    $path = parse_url($url, PHP_URL_PATH);
    if (!is_string($path) || !str_ends_with(strtolower($path), 'product_detail.php')) {
        return null;
    }

    $query = parse_url($url, PHP_URL_QUERY);
    if (!is_string($query) || $query === '') {
        return null;
    }

    parse_str($query, $params);
    $id = $params['id'] ?? null;
    if (!is_scalar($id) || !preg_match('/^\d+$/', (string) $id)) {
        return null;
    }

    $intId = (int) $id;
    return $intId > 0 ? $intId : null;
}

/**
 * Valida un id_producto explicito que mande el cliente (ej. clic en "Agregar al
 * Carrito" dentro de catalogo.php, donde la URL no trae el id). Solo valida forma/rango,
 * no existencia -- ver nota arriba.
 */
function sanitizeExplicitProductId($value): ?int
{
    if (!is_scalar($value) || !preg_match('/^\d+$/', trim((string) $value))) {
        return null;
    }
    $intId = (int) $value;
    return $intId > 0 ? $intId : null;
}

/**
 * Valida el formato de un pageview_id generado por el cliente (32 hex, mismo formato
 * que visitor_id). Es la llave para que el evento 'duration' sepa a que fila de
 * logs_actividad actualizar.
 */
function sanitizePageviewId($value): ?string
{
    if (!is_scalar($value)) {
        return null;
    }
    $value = (string) $value;
    return preg_match('/^[a-f0-9]{32}$/', $value) ? $value : null;
}

/**
 * Marca si el rol de la sesion es personal interno (cualquiera que no sea 'cliente'
 * ni sesion anonima). Lo usa api/log_activity.php para sellar es_interno en la fila
 * de logs_actividad: el log guarda la actividad igual, pero los reportes de marketing
 * (Trafico y Campanas, Comportamiento en el Sitio) filtran es_interno = 0 para no
 * medir sobre la navegacion del propio equipo. Entrada tolerante: null/'' -> no
 * interno (un visitante anonimo o un cliente logueado cuenta como trafico real).
 */
function sessionRoleIsInternal(?string $rol): bool
{
    $rol = strtolower(trim((string) $rol));
    return $rol !== '' && $rol !== 'cliente';
}

/**
 * Clampea la duracion (segundos visibles en pagina) que reporta el cliente. Rechaza
 * negativos/no-numericos y limita el techo a 30 minutos: mas que eso es casi siempre
 * una pestaña olvidada abierta, no atencion real, y no queremos que un solo outlier
 * distorsione el promedio del reporte.
 */
function clampDurationSeconds($value): ?int
{
    if (!is_numeric($value)) {
        return null;
    }
    $seconds = (int) round((float) $value);
    if ($seconds <= 0) {
        return null;
    }
    return min($seconds, 1800);
}
