<?php
declare(strict_types=1);

/**
 * Precio de oferta para productos proximos a caducar.
 *
 * Un producto "esta en oferta" cuando pertenece a la categoria de ofertas. El
 * nombre de esa categoria no esta sembrado por migracion: en produccion es
 * "oferta" y en algunos entornos locales "Ofertas", asi que se reconoce de forma
 * tolerante (case-insensitive, singular o plural) en vez de anclarse a un texto.
 *
 * Regla general del negocio: el precio de oferta es el costo de compra + $50. La
 * columna productos.precio_oferta guarda un override manual (para bajarlo aun
 * mas); si esta vacia, se calcula costo+50 al vuelo.
 */

/** Nombres aceptados para la categoria de ofertas (comparados en minusculas). */
const OFERTA_CATEGORIA_NOMBRES = ['oferta', 'ofertas'];

/** Margen fijo sobre el costo de compra que define el precio de oferta sugerido. */
const OFERTA_MARGEN_SOBRE_COSTO = 50.0;

/**
 * Precio de oferta sugerido a partir del costo de compra (regla general del negocio).
 */
function ofertaPrecioSugerido(float $precioCosto): float
{
    return round(max(0.0, $precioCosto) + OFERTA_MARGEN_SOBRE_COSTO, 2);
}

/**
 * Precio de venta efectivo de un producto considerando si esta en oferta.
 *
 * - No esta en oferta                -> precio_venta normal.
 * - En oferta con precio_oferta > 0  -> ese override manual.
 * - En oferta sin precio_oferta      -> costo + $50 (regla general).
 *
 * @param float      $precioVenta  productos.precio_venta
 * @param float      $precioCosto  productos.precio_costo
 * @param mixed      $precioOferta productos.precio_oferta (NULL / '' / numero)
 * @param bool       $enOferta     el producto pertenece a la categoria de ofertas
 */
function ofertaPrecioEfectivo(float $precioVenta, float $precioCosto, $precioOferta, bool $enOferta): float
{
    if (!$enOferta) {
        return round($precioVenta, 2);
    }

    if ($precioOferta !== null && $precioOferta !== '' && (float) $precioOferta > 0) {
        return round((float) $precioOferta, 2);
    }

    return ofertaPrecioSugerido($precioCosto);
}

/**
 * Expresion SQL booleana: "el producto del alias dado esta en la categoria de ofertas".
 * Usa nombres literales (no placeholders) para no introducir parametros en consultas
 * que hoy no los tienen (ver catalogo_utils.php / CatalogoUtilsTest).
 */
function ofertaSqlEnOfertaExpr(string $prodAlias = 'p'): string
{
    $nombres = implode(', ', array_map(
        static fn(string $n): string => "'" . str_replace("'", "''", $n) . "'",
        OFERTA_CATEGORIA_NOMBRES
    ));

    return "EXISTS (SELECT 1 FROM producto_categorias pc_of"
        . " JOIN categorias c_of ON c_of.id_categoria = pc_of.id_categoria"
        . " WHERE pc_of.id_producto = {$prodAlias}.id_producto"
        . " AND LOWER(c_of.nombre) IN ({$nombres}))";
}

/**
 * Expresion SQL que devuelve el precio efectivo (misma logica que ofertaPrecioEfectivo).
 *
 * @param string $ventaCol   columna/expresion de precio_venta (ej. "p.precio_venta")
 * @param string $costoCol    columna/expresion de precio_costo
 * @param string $ofertaCol   columna/expresion de precio_oferta
 * @param string $enOfertaExpr expresion booleana (ej. ofertaSqlEnOfertaExpr('p'))
 */
function ofertaSqlPrecioEfectivoExpr(string $ventaCol, string $costoCol, string $ofertaCol, string $enOfertaExpr): string
{
    $margen = (string) OFERTA_MARGEN_SOBRE_COSTO;

    return "CASE WHEN {$enOfertaExpr}"
        . " THEN COALESCE(NULLIF({$ofertaCol}, 0), ROUND(GREATEST({$costoCol}, 0) + {$margen}, 2))"
        . " ELSE {$ventaCol} END";
}

/**
 * True si el producto pertenece a la categoria de ofertas. Consulta puntual para
 * flujos que resuelven un solo producto (ficha, POS).
 */
function ofertaProductoEnOferta(PDO $pdo, int $idProducto): bool
{
    if ($idProducto <= 0) {
        return false;
    }

    $sql = 'SELECT 1 FROM producto_categorias pc'
        . ' JOIN categorias c ON c.id_categoria = pc.id_categoria'
        . ' WHERE pc.id_producto = ? AND LOWER(c.nombre) IN ('
        . implode(', ', array_fill(0, count(OFERTA_CATEGORIA_NOMBRES), '?'))
        . ') LIMIT 1';

    try {
        $stmt = $pdo->prepare($sql);
        $stmt->execute(array_merge([$idProducto], OFERTA_CATEGORIA_NOMBRES));
        return $stmt->fetchColumn() !== false;
    } catch (PDOException $e) {
        // Sin las tablas de categorias (esquemas minimos de test, DB a medio migrar)
        // nada esta "en oferta": el precio de venta normal es el fallback seguro.
        return false;
    }
}

/**
 * Devuelve el conjunto de ids (de la lista dada) que estan en la categoria de ofertas.
 * Una sola consulta para no hacer N checks al pintar variantes / resultados.
 *
 * @param array<int,int|string> $idsProducto
 * @return array<int,bool> mapa id_producto => true
 */
function ofertaFiltrarEnOferta(PDO $pdo, array $idsProducto): array
{
    $ids = array_values(array_unique(array_filter(array_map('intval', $idsProducto), static fn(int $id): bool => $id > 0)));
    if (empty($ids)) {
        return [];
    }

    $sql = 'SELECT DISTINCT pc.id_producto FROM producto_categorias pc'
        . ' JOIN categorias c ON c.id_categoria = pc.id_categoria'
        . ' WHERE pc.id_producto IN (' . implode(', ', array_fill(0, count($ids), '?')) . ')'
        . ' AND LOWER(c.nombre) IN (' . implode(', ', array_fill(0, count(OFERTA_CATEGORIA_NOMBRES), '?')) . ')';

    try {
        $stmt = $pdo->prepare($sql);
        $stmt->execute(array_merge($ids, OFERTA_CATEGORIA_NOMBRES));
    } catch (PDOException $e) {
        return [];
    }

    $mapa = [];
    foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $id) {
        $mapa[(int) $id] = true;
    }

    return $mapa;
}

/**
 * Resuelve el id de la categoria de ofertas. Si no existe y $crearSiFalta es true,
 * la crea como "Oferta" (nombre canonico) y devuelve su id nuevo.
 */
function ofertaResolverCategoriaId(PDO $pdo, bool $crearSiFalta = false): ?int
{
    $sql = 'SELECT id_categoria FROM categorias'
        . ' WHERE LOWER(nombre) IN (' . implode(', ', array_fill(0, count(OFERTA_CATEGORIA_NOMBRES), '?')) . ')'
        . " ORDER BY (estado = 'activo') DESC, id_categoria ASC LIMIT 1";

    $stmt = $pdo->prepare($sql);
    $stmt->execute(OFERTA_CATEGORIA_NOMBRES);
    $id = $stmt->fetchColumn();

    if ($id !== false) {
        return (int) $id;
    }

    if (!$crearSiFalta) {
        return null;
    }

    $pdo->prepare("INSERT INTO categorias (nombre, estado) VALUES ('Oferta', 'activo')")->execute();

    return (int) $pdo->lastInsertId();
}
