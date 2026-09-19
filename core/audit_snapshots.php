<?php
declare(strict_types=1);

/**
 * "Fotos" de registros para la auditoria: leen una fila ANTES de modificarla y otra
 * DESPUES, para que logAuditCambios() guarde exactamente que campo cambio y de que valor
 * a que valor.
 *
 * Reglas:
 *  - Solo se devuelven los campos de la lista blanca (nunca contrasenas ni tokens).
 *  - Los datos personales cifrados en reposo (ENCv1:) se DESCIFRAN solo para poder
 *    comparar (el cifrado es no determinista: sin descifrar, "sin cambios" pareceria un
 *    cambio). Al guardar en el log se enmascaran (auditEnmascararValor), nunca se
 *    guardan en claro.
 *  - Todo es tolerante: una columna que no existe en algun entorno se omite; un error de
 *    BD devuelve [] (auditar jamas debe romper la operacion de negocio).
 */

const AUDIT_CAMPOS_PRODUCTO = [
    'nombre', 'nombre_variante', 'nombre_corto', 'sku', 'codigo_barras', 'unidad', 'id_padre',
    'precio_costo', 'precio_venta', 'precio_comparacion', 'precio_oferta', 'estado',
    'requiere_lote', 'mostrar_tabla', 'capsulas_por_envase', 'porcion_capsulas',
    'descripcion', 'ingredientes', 'modo_uso',
];

/** Campos de texto largo: se auditan como "N car. #hash" para detectar cambio sin volcar el texto. */
const AUDIT_CAMPOS_TEXTO_LARGO = [
    'descripcion', 'ingredientes', 'modo_uso', 'tabla_nutrimental', 'notas', 'observaciones',
    'extracto', 'contenido',
    // Textos de configuracion de Alex (prompts/politicas): se detecta el cambio sin volcarlos al log.
    'tono_instrucciones', 'promocion_vigente_texto', 'politica_envio_texto', 'politica_pago_texto',
    'ubicacion_texto', 'mensaje_bienvenida', 'prompt_sistema_override',
];

const AUDIT_CAMPOS_CLIENTE = ['nombre', 'email', 'telefono', 'id_almacen', 'estado', 'id_usuario'];
const AUDIT_CAMPOS_DIRECCION = ['alias', 'direccion', 'maps_link', 'es_default', 'latitud', 'longitud'];
const AUDIT_CAMPOS_USUARIO = ['nombre', 'email', 'id_rol', 'id_almacen', 'estado', 'es_superadmin', 'telefono'];
const AUDIT_CAMPOS_LOTE = [
    'id_producto', 'id_almacen', 'codigo_lote', 'fecha_caducidad', 'caducidad_aproximada',
    'cantidad_inicial', 'cantidad_restante', 'costo_unitario', 'estado', 'alerta_atendida',
    'en_oferta', 'notas_seguimiento',
];
const AUDIT_CAMPOS_ALMACEN = ['nombre', 'direccion', 'ubicacion', 'telefono', 'estado', 'tipo', 'es_sucursal', 'maps_link', 'latitud', 'longitud'];

/**
 * Fila de una tabla reducida a la lista blanca de campos, con PII descifrada para comparar.
 *
 * @param string[] $campos
 * @return array<string,mixed>
 */
function auditSnapshotFila(PDO $pdo, string $tabla, string $pk, int $id, array $campos): array
{
    if ($id <= 0 || !preg_match('/^[a-z_]+$/', $tabla) || !preg_match('/^[a-z_]+$/', $pk)) {
        return [];
    }

    try {
        $stmt = $pdo->prepare("SELECT * FROM `{$tabla}` WHERE `{$pk}` = ? LIMIT 1");
        $stmt->execute([$id]);
        $fila = $stmt->fetch(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {
        return [];
    }
    if (!is_array($fila)) {
        return [];
    }

    $salida = [];
    foreach ($campos as $campo) {
        if (!array_key_exists($campo, $fila)) {
            continue;
        }
        $valor = $fila[$campo];
        if (is_string($valor) && function_exists('piiIsEncryptedValue') && function_exists('piiDecryptValue') && piiIsEncryptedValue($valor)) {
            $valor = piiDecryptValue($valor);
        }
        $salida[$campo] = $valor;
    }

    return auditCompactarTextosLargos($salida);
}

/**
 * Reemplaza los campos de texto largo por "N car. #hash": detecta cualquier edicion sin
 * llenar el log con parrafos.
 *
 * @param array<string,mixed> $fila
 * @return array<string,mixed>
 */
function auditCompactarTextosLargos(array $fila): array
{
    foreach (AUDIT_CAMPOS_TEXTO_LARGO as $campo) {
        if (!array_key_exists($campo, $fila) || $fila[$campo] === null) {
            continue;
        }
        $texto = trim((string) $fila[$campo]);
        if ($texto === '') {
            $fila[$campo] = '';
            continue;
        }
        $fila[$campo] = mb_strlen($texto) . ' car. #' . substr(md5($texto), 0, 6);
    }
    return $fila;
}

/** @return array<string,mixed> */
function auditSnapshotProducto(PDO $pdo, int $idProducto): array
{
    return auditSnapshotFila($pdo, 'productos', 'id_producto', $idProducto, AUDIT_CAMPOS_PRODUCTO);
}

/** @return array<string,mixed> */
function auditSnapshotCliente(PDO $pdo, int $idCliente): array
{
    return auditSnapshotFila($pdo, 'clientes', 'id_cliente', $idCliente, AUDIT_CAMPOS_CLIENTE);
}

/** @return array<string,mixed> */
function auditSnapshotDireccion(PDO $pdo, int $idDireccion): array
{
    return auditSnapshotFila($pdo, 'cliente_direcciones', 'id_direccion', $idDireccion, AUDIT_CAMPOS_DIRECCION);
}

/** @return array<string,mixed> */
function auditSnapshotUsuario(PDO $pdo, int $idUsuario): array
{
    return auditSnapshotFila($pdo, 'usuarios', 'id_usuario', $idUsuario, AUDIT_CAMPOS_USUARIO);
}

/** @return array<string,mixed> */
function auditSnapshotAlmacen(PDO $pdo, int $idAlmacen): array
{
    return auditSnapshotFila($pdo, 'almacenes', 'id_almacen', $idAlmacen, AUDIT_CAMPOS_ALMACEN);
}

/**
 * Nombre legible de un registro (para "contexto" en el detalle: "Vitamina C 500mg").
 */
function auditNombreRegistro(PDO $pdo, string $tabla, string $pk, string $columnaNombre, int $id): string
{
    if ($id <= 0 || !preg_match('/^[a-z_]+$/', $tabla . $pk . $columnaNombre)) {
        return '';
    }
    try {
        $stmt = $pdo->prepare("SELECT `{$columnaNombre}` FROM `{$tabla}` WHERE `{$pk}` = ? LIMIT 1");
        $stmt->execute([$id]);
        return trim((string) $stmt->fetchColumn());
    } catch (Throwable $e) {
        return '';
    }
}

/**
 * Categorias (id => nombre) de un producto.
 *
 * @return array<int,string>
 */
function auditCategoriasDeProducto(PDO $pdo, int $idProducto): array
{
    if ($idProducto <= 0) {
        return [];
    }
    try {
        $stmt = $pdo->prepare(
            'SELECT c.id_categoria, c.nombre FROM producto_categorias pc
             JOIN categorias c ON c.id_categoria = pc.id_categoria
             WHERE pc.id_producto = ? ORDER BY c.nombre'
        );
        $stmt->execute([$idProducto]);
        $mapa = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $fila) {
            $mapa[(int) $fila['id_categoria']] = (string) $fila['nombre'];
        }
        return $mapa;
    } catch (Throwable $e) {
        return [];
    }
}

/**
 * Nombres de categorias por id.
 *
 * @param array<int|string> $ids
 * @return array<int,string>
 */
function auditNombresCategorias(PDO $pdo, array $ids): array
{
    $ids = array_values(array_unique(array_filter(array_map('intval', $ids), static fn(int $i): bool => $i > 0)));
    if ($ids === []) {
        return [];
    }
    try {
        $stmt = $pdo->prepare('SELECT id_categoria, nombre FROM categorias WHERE id_categoria IN (' . implode(',', array_fill(0, count($ids), '?')) . ')');
        $stmt->execute($ids);
        $mapa = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $fila) {
            $mapa[(int) $fila['id_categoria']] = (string) $fila['nombre'];
        }
        return $mapa;
    } catch (Throwable $e) {
        return [];
    }
}

/**
 * Registra el cambio de categorias de UN producto (antes: set anterior, despues: set nuevo).
 * Si alguna de las categorias tocadas es la de ofertas, lo marca en la severidad y el detalle:
 * es exactamente el movimiento que mas interesa poder rastrear.
 *
 * @param array<int,string> $antes   id => nombre
 * @param array<int,string> $despues id => nombre
 */
function auditRegistrarCambioCategorias(int $idProducto, string $nombreProducto, array $antes, array $despues): void
{
    $agregadas = array_diff_key($despues, $antes);
    $quitadas = array_diff_key($antes, $despues);
    if ($agregadas === [] && $quitadas === []) {
        return;
    }

    $esOferta = static function (array $mapa): bool {
        foreach ($mapa as $nombre) {
            if (in_array(mb_strtolower(trim($nombre)), ['oferta', 'ofertas'], true)) {
                return true;
            }
        }
        return false;
    };
    $tocaOferta = $esOferta($agregadas) || $esOferta($quitadas);

    $partes = [];
    if ($agregadas !== []) {
        $partes[] = 'agregó: ' . implode(', ', $agregadas);
    }
    if ($quitadas !== []) {
        $partes[] = 'quitó: ' . implode(', ', $quitadas);
    }

    logAudit(
        'CATEGORIAS_PRODUCTO_CAMBIADAS',
        'producto_categorias',
        $idProducto,
        ($nombreProducto !== '' ? $nombreProducto . ' | ' : '') . implode('; ', $partes) . ($tocaOferta ? ' (OFERTA)' : ''),
        ['categorias' => array_values($antes)],
        ['categorias' => array_values($despues)],
        ['severidad' => $tocaOferta ? 'alerta' : 'aviso']
    );
}

/**
 * Nombre de un cliente para el "contexto" de un registro de auditoria (descifrado).
 */
function auditNombreCliente(PDO $pdo, int $idCliente): string
{
    $foto = auditSnapshotCliente($pdo, $idCliente);
    return trim((string) ($foto['nombre'] ?? ''));
}
