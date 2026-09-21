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

/*
 * Escalera de descuento por urgencia de caducidad. Se aplica sobre el precio de venta normal
 * y NUNCA baja del piso del negocio (costo + OFERTA_MARGEN_SOBRE_COSTO). Un lote "critico"
 * (menos de LOTE_RUNWAY_CRITICO dias de margen real) se liquida directo al piso, que es la regla
 * general de siempre; los demas escalones descuentan un porcentaje de precio_venta.
 *   planificar / sin_rotacion -> 15%   |   urgente -> 30%   |   critico -> piso (costo + $50)
 */
const OFERTA_ESCALERA_PCT = ['sin_rotacion' => 15, 'planificar' => 15, 'urgente' => 30];

/** Escalon inicial cuando no hay severidad conocida (producto sin lotes, o lote "vigilar"). */
const OFERTA_ESCALERA_PCT_INICIAL = 15;

/** Puntos porcentuales de precio_venta que se descuentan ademas en el precio de paquete. */
const OFERTA_PAQUETE_PUNTOS_EXTRA = 10;

/** Piezas minimas para que aplique el precio de paquete ("llevate 2"). */
const OFERTA_PAQUETE_MIN_PIEZAS = 2;

/**
 * Precio de oferta que corresponde a una severidad de caducidad (ver OFERTA_ESCALERA_PCT).
 * Nunca es mayor al precio de venta normal ni menor al piso costo + $50 (salvo que el propio
 * precio de venta ya sea menor a ese piso: entonces se queda en el precio de venta).
 */
function ofertaPrecioEscalera(float $precioVenta, float $precioCosto, ?string $severidad): float
{
    $venta = round($precioVenta, 2);
    $piso = min(ofertaPrecioSugerido($precioCosto), $venta);

    if ($severidad === 'critico') {
        return $piso;
    }

    $pct = OFERTA_ESCALERA_PCT[$severidad ?? ''] ?? OFERTA_ESCALERA_PCT_INICIAL;
    $precio = round($venta * (1 - $pct / 100), 2);

    return max($piso, min($precio, $venta));
}

/**
 * Precio unitario de "paquete" (2 o mas piezas): un escalon extra sobre el precio de oferta
 * vigente, sin bajar nunca del piso costo + $50. Regresa null si ya no hay margen para
 * descontar mas (el lote critico ya esta en el piso): no hay paquete que ofrecer.
 */
function ofertaPrecioPaquete(float $precioVenta, float $precioCosto, float $precioOferta): ?float
{
    $venta = round($precioVenta, 2);
    $oferta = round($precioOferta, 2);
    $piso = min(ofertaPrecioSugerido($precioCosto), $venta);

    // A pesos enteros ($300, no $300.10): un precio de paquete con centavos raros se lee como error.
    $candidato = max($piso, round($oferta - $venta * OFERTA_PAQUETE_PUNTOS_EXTRA / 100));

    return ($oferta - $candidato) >= 0.01 ? $candidato : null;
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

    $precioVentaRedondeado = round($precioVenta, 2);

    // Nunca cobrar MAS por estar "en oferta" que el precio de siempre: un override manual
    // mal capturado (>= precio_venta, ej. se les olvido bajarlo o el precio de venta subio
    // despues sin actualizarlo) o el costo+$50 automatico superando un precio_venta de
    // margen delgado no deben terminar cobrandole de mas al cliente en el carrito publico
    // (ver ofertaSqlPrecioEfectivoExpr(), misma regla) ni haciendo que Alex le diga "esta en
    // oferta, ahorras $0" o un ahorro negativo -- se cae al precio normal, como si el
    // producto no estuviera en oferta.
    if ($precioOferta !== null && $precioOferta !== '' && (float) $precioOferta > 0) {
        return min(round((float) $precioOferta, 2), $precioVentaRedondeado);
    }

    return min(ofertaPrecioSugerido($precioCosto), $precioVentaRedondeado);
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

    // LEAST(...) es la misma regla que el min() de ofertaPrecioEfectivo(): nunca cobrar mas
    // por estar "en oferta" que el precio de venta normal (ver docblock de esa funcion).
    return "CASE WHEN {$enOfertaExpr}"
        . " THEN LEAST({$ventaCol}, COALESCE(NULLIF({$ofertaCol}, 0), ROUND(GREATEST({$costoCol}, 0) + {$margen}, 2)))"
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
 * Aplica el precio de oferta a una lista de productos (filas con id_producto, precio_venta,
 * precio_costo y precio_oferta), para pantallas que precargan el precio a cobrar (POS, agregar
 * producto a un pedido). Si el producto esta en Ofertas Y la oferta es un descuento real, su
 * precio_venta pasa a ser el de oferta y se agregan en_oferta=true y precio_normal; si no, la fila
 * queda intacta. Antes de esto el POS precargaba siempre el precio normal y solo comparaba contra
 * la oferta para auditar: dos ventas reales de productos en oferta se cobraron a precio normal.
 *
 * @param array<int,array<string,mixed>> $productos
 * @return array<int,array<string,mixed>>
 */
function ofertaAplicarPrecioEfectivoALista(PDO $pdo, array $productos): array
{
    $enOferta = ofertaFiltrarEnOferta($pdo, array_column($productos, 'id_producto'));
    if ($enOferta === []) {
        return $productos;
    }

    foreach ($productos as &$producto) {
        if (!isset($enOferta[(int) ($producto['id_producto'] ?? 0)])) {
            continue;
        }
        $normal = round((float) ($producto['precio_venta'] ?? 0), 2);
        $efectivo = ofertaPrecioEfectivo($normal, (float) ($producto['precio_costo'] ?? 0), $producto['precio_oferta'] ?? null, true);
        if ($efectivo < $normal) {
            $producto['precio_normal'] = $normal;
            $producto['en_oferta'] = true;
            $producto['precio_venta'] = $efectivo;
        }
    }
    unset($producto);

    return $productos;
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
