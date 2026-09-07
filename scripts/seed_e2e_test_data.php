<?php
declare(strict_types=1);

require_once __DIR__ . '/../core/config.php';
require_once __DIR__ . '/../core/auth.php'; // isPublicPickupWarehouseName()

if (!in_array(PHP_SAPI, ['cli', 'phpdbg'], true)) {
    http_response_code(403);
    echo "Este script solo se puede ejecutar por CLI o phpdbg.\n";
    exit(1);
}

// Datos fijos que los specs de tests/e2e/ referencian por nombre/codigo_barras.
// Idempotente: correr varias veces (local o CI) no duplica filas ni acumula stock,
// siempre lo deja en el valor fijo de abajo.
const E2E_PRODUCT_NAME = 'Playwright E2E Test Product';
const E2E_PRODUCT_BARCODE = 'E2E-PLAYWRIGHT-TEST-0001';

// Producto con stock deliberadamente bajo, para los tests negativos de
// "stock insuficiente" en checkout (ver tests/e2e/checkout-edge-cases.*.spec.ts).
const E2E_LOW_STOCK_PRODUCT_NAME = 'Playwright E2E Low Stock Product';
const E2E_LOW_STOCK_PRODUCT_BARCODE = 'E2E-PLAYWRIGHT-TEST-0002';

// Producto sin existencia en la sucursal por defecto, para el test negativo de
// "producto agotado" en views/sales.php (ver tests/e2e/sales-agendar-pedido-edge-cases.staff.spec.ts).
const E2E_OUT_OF_STOCK_PRODUCT_NAME = 'Playwright E2E Out Of Stock Product';
const E2E_OUT_OF_STOCK_PRODUCT_BARCODE = 'E2E-PLAYWRIGHT-TEST-0003';

// Producto de uso exclusivo del test de "posponer" en purchase_orders.php (ver
// tests/e2e/purchase-orders.staff.spec.ts). Se reactiva con el boton "Devolver" de la
// pestana Pospuestos (api/postpone_reactivate.php), que no toca el inventario -- por
// eso el stock NO se resetea en cada corrida (ver mas abajo), a diferencia de los dos
// productos del ciclo real de Ordenes de Compra que siguen. Se le da un stock_minimo
// generoso para que siga calificando para la lista de resurtido aunque no se resiembre.
// Es un producto aparte de E2E_OUT_OF_STOCK_PRODUCT_NAME a proposito: ese otro debe
// quedarse SIEMPRE en 0.
const E2E_PURCHASE_ORDER_PRODUCT_NAME = 'Playwright E2E Purchase Order Product';
const E2E_PURCHASE_ORDER_PRODUCT_BARCODE = 'E2E-PLAYWRIGHT-TEST-0004';
const E2E_PURCHASE_ORDER_PRODUCT_STOCK_MINIMO = 1000;

// Dos productos de uso exclusivo del ciclo REAL de Ordenes de Compra (generar orden ->
// pestana "Ordenes Abiertas" -> surtir/cancelar), a diferencia del de arriba que solo
// prueba "posponer". Estos SI mutan ordenes_compra/detalle_orden_compra de verdad, asi
// que cantidad_actual se resetea a 0 en cada corrida (ver mas abajo) para que el test de
// "surtir" -que sube el inventario real- sea repetible sin resembrar ni acumular stock.
const E2E_PO_SURTIR_PRODUCT_NAME = 'Playwright E2E PO Surtir Product';
const E2E_PO_SURTIR_PRODUCT_BARCODE = 'E2E-PLAYWRIGHT-TEST-0005';
const E2E_PO_CANCEL_PRODUCT_NAME = 'Playwright E2E PO Cancel Product';
const E2E_PO_CANCEL_PRODUCT_BARCODE = 'E2E-PLAYWRIGHT-TEST-0006';
const E2E_PO_LIFECYCLE_STOCK_MINIMO = 5;
const E2E_PO_LIFECYCLE_STOCK_MAXIMO = 10;

// Producto de uso exclusivo de tests/e2e/productos-incompletos.staff.spec.ts (views/
// productos_incompletos.php): a proposito sin precio_venta, sin precio_costo, sin sku,
// sin codigo_barras y sin fila en inventario_almacen -- las 5 banderas de "falta" a la
// vez. Se busca por nombre (no por codigo_barras, que aqui es NULL a proposito) para
// mantenerlo idempotente sin resembrar duplicados.
const E2E_PRODUCTO_INCOMPLETO_NOMBRE = 'Playwright E2E Producto Incompleto';

$productsToSeed = [
    ['nombre' => E2E_PRODUCT_NAME, 'codigo_barras' => E2E_PRODUCT_BARCODE, 'precio' => 99.99, 'stock' => 9999],
    ['nombre' => E2E_LOW_STOCK_PRODUCT_NAME, 'codigo_barras' => E2E_LOW_STOCK_PRODUCT_BARCODE, 'precio' => 49.99, 'stock' => 1],
    ['nombre' => E2E_OUT_OF_STOCK_PRODUCT_NAME, 'codigo_barras' => E2E_OUT_OF_STOCK_PRODUCT_BARCODE, 'precio' => 29.99, 'stock' => 0],
];

// Cliente fijo (no autoregistrado) con un domicilio guardado real, para que
// tests/e2e/sales-agendar-pedido.staff.spec.ts pueda elegirlo por nombre en el
// autocomplete de views/sales.php sin depender del autocompletado de Google Maps
// (que los tests bloquean a proposito, ver tests/e2e/fixtures.ts). El nombre es
// deliberadamente distintivo para no chocar con clientes reales ni con las
// cuentas "Playwright QA" que registran los demas specs.
const E2E_SALES_CLIENTE_NOMBRE = 'Playwright E2E Sales Cliente';
const E2E_SALES_CLIENTE_TELEFONO = '3320001122';
const E2E_SALES_CLIENTE_DIRECCION = 'Av. Vallarta 1500, Guadalajara, Jal.';

// resolvePickupWarehouseId() (core/auth.php) solo reconoce como sucursal publica de
// pickup un almacen cuyo nombre contenga "papeler" o "liz" -- ninguno de los que
// siembra database.sql ("Almacen Central", "Sucursal 1") califica. En un ambiente
// nuevo (CI, o un deploy limpio) el flujo de "Recoger en Sucursal" fallaria por
// completo con "No se pudo resolver sucursal pickup". Sembramos un almacen de
// prueba con un nombre que sí califica en vez de tocar esa logica de negocio.
const E2E_PICKUP_ALMACEN_NOMBRE = 'Papelería E2E Playwright';

$pdo = getPDO();

try {
    $pdo->beginTransaction();

    // Housekeeping: practicamente cada spec de tests/e2e/ crea una cuenta/cliente
    // "Playwright ..." desechable (registerAndLogin crea "Playwright QA";
    // admin-customers.staff.spec.ts crea "Playwright Cliente Admin/Editar/Estado/
    // Buscar Unico ..."; sales-agendar-pedido*.staff.spec.ts crea "Playwright Sales
    // ..."; register*.spec.ts crea "Playwright Registro ..."; etc.) y salvo el caso
    // puntual que se borra a si mismo via la UI durante su propio test, ninguno se
    // limpia despues. En una BD local que se reusa entre muchas corridas manuales
    // esto se acumula sin limite -- llegamos a tener 880+ clientes activos (410 solo
    // de "Playwright QA", verificado por muestreo), lo que hizo que views/sales.php
    // (que limita su lista de clientes a LIMIT 500 ordenados por nombre) dejara de
    // encontrar nuestro cliente fijo de prueba, y que views/manage_customers.php se
    // volviera tan pesado que sus botones de accion dejaron de responder a tiempo en
    // los tests (timeouts de click pese a que Playwright reportaba el elemento
    // visible y estable -- la pagina misma tardaba en procesar el click con esa
    // cantidad de filas).
    // Se borran TODOS los clientes "Playwright *" (no solo los sin pedidos): a
    // diferencia de categorias/sucursales/blogs, clientes SI tiene un pedido
    // asociado en varios flujos (checkout, sales.php), pero fk_pedidos_cliente es
    // "ON DELETE SET NULL" (ver database.sql) -- exactamente el mismo efecto que
    // produce el propio boton "Eliminar cliente" de manage_customers.php ("Sus
    // pedidos quedaran sin cliente asignado"), asi que no es mas destructivo que la
    // funcionalidad ya expuesta en la UI. Se excluye por nombre exacto el cliente
    // fijo E2E_SALES_CLIENTE_NOMBRE, que varios specs SI reutilizan intencionalmente
    // entre corridas (no es desechable). No aplica en CI (base de datos efímera,
    // nunca acumula) -- es higiene solo para desarrollo local repetido.
    // clientes.nombre esta cifrado (piiEncryptValue, no determinista), asi que no
    // se puede filtrar por WHERE nombre LIKE '...' en SQL -- hay que descifrar en PHP.
    $todosClientes = $pdo->query('SELECT id_cliente, nombre FROM clientes')->fetchAll(PDO::FETCH_ASSOC);

    $idsDesechables = [];
    foreach ($todosClientes as $c) {
        $nombreRaw = trim((string) ($c['nombre'] ?? ''));
        $nombre = (function_exists('piiIsEncryptedValue') && piiIsEncryptedValue($nombreRaw))
            ? trim((string) piiDecryptValue($nombreRaw))
            : $nombreRaw;
        if ($nombre !== E2E_SALES_CLIENTE_NOMBRE && strpos($nombre, 'Playwright ') === 0) {
            $idsDesechables[] = (int) $c['id_cliente'];
        }
    }

    if (!empty($idsDesechables)) {
        $placeholders = implode(', ', array_fill(0, count($idsDesechables), '?'));
        $pdo->prepare("DELETE FROM cliente_direcciones WHERE id_cliente IN ({$placeholders})")->execute($idsDesechables);
        $stmtDel = $pdo->prepare("DELETE FROM clientes WHERE id_cliente IN ({$placeholders})");
        $stmtDel->execute($idsDesechables);
        echo 'Limpieza: ' . count($idsDesechables) . " clientes 'Playwright *' desechables eliminados (sus pedidos, si tenian, quedan sin cliente asignado).\n";
    }

    // Housekeeping: tests/e2e/bulk-assign-category.staff.spec.ts crea una categoria nueva
    // ("Playwright BAC Categoria ..." / "Playwright BAC Encargado Categoria ...") en cada
    // corrida via la pestaña "Nueva" de views/bulk_assign_category.php, y nada las borraba
    // despues -- en una BD local que se reusa entre muchas corridas, el <select> de
    // categorias termina con cientos de opciones desechables, al grado de romper el layout
    // de esa vista y hacer que el checkbox de producto (Playwright) ya no sea clickeable en
    // su posicion esperada. A diferencia de clientes, aqui si se puede filtrar por nombre en
    // SQL directamente porque categorias.nombre no esta cifrado.
    $idsCategoriasDesechables = $pdo->query(
        "SELECT id_categoria FROM categorias WHERE nombre LIKE 'Playwright BAC%'"
    )->fetchAll(PDO::FETCH_COLUMN);

    if (!empty($idsCategoriasDesechables)) {
        $placeholdersCat = implode(', ', array_fill(0, count($idsCategoriasDesechables), '?'));
        $pdo->prepare("DELETE FROM producto_categorias WHERE id_categoria IN ({$placeholdersCat})")->execute($idsCategoriasDesechables);
        $pdo->prepare("DELETE FROM categorias WHERE id_categoria IN ({$placeholdersCat})")->execute($idsCategoriasDesechables);
        echo 'Limpieza: ' . count($idsCategoriasDesechables) . " categorias 'Playwright BAC*' desechables eliminadas.\n";
    }

    // Housekeeping: tests/e2e/admin-branches.staff.spec.ts crea una sucursal nueva
    // ("Playwright Sucursal ...") en cada corrida (alta, editar, cambiar estado) y nada las
    // borraba despues -- mismo patron que las categorias de arriba. Solo se borran las que
    // nadie mas referencia (sin inventario, sin usuarios ni pedidos asignados), que es
    // siempre el caso para estas de prueba.
    $idsSucursalesDesechables = $pdo->query(
        "SELECT id_almacen FROM almacenes
         WHERE nombre LIKE 'Playwright Sucursal%'
           AND id_almacen NOT IN (SELECT DISTINCT id_almacen FROM inventario_almacen WHERE id_almacen IS NOT NULL)
           AND id_almacen NOT IN (SELECT DISTINCT id_almacen FROM usuarios WHERE id_almacen IS NOT NULL)
           AND id_almacen NOT IN (SELECT DISTINCT id_almacen FROM pedidos WHERE id_almacen IS NOT NULL)"
    )->fetchAll(PDO::FETCH_COLUMN);

    if (!empty($idsSucursalesDesechables)) {
        $placeholdersSuc = implode(', ', array_fill(0, count($idsSucursalesDesechables), '?'));
        $pdo->prepare("DELETE FROM almacenes WHERE id_almacen IN ({$placeholdersSuc})")->execute($idsSucursalesDesechables);
        echo 'Limpieza: ' . count($idsSucursalesDesechables) . " sucursales 'Playwright Sucursal*' desechables eliminadas.\n";
    }

    // Housekeeping: tests/e2e/manage-blogs.staff.spec.ts crea articulos nuevos
    // ("Playwright Blog ...") en cada corrida y nada los borraba despues -- mismo patron que
    // arriba. blogs no tiene FKs entrantes (solo id_usuario, que es el autor), asi que se
    // pueden borrar directo por nombre.
    $idsBlogsDesechables = $pdo->query(
        "SELECT id_blog FROM blogs WHERE titulo LIKE 'Playwright Blog%'"
    )->fetchAll(PDO::FETCH_COLUMN);

    if (!empty($idsBlogsDesechables)) {
        $placeholdersBlog = implode(', ', array_fill(0, count($idsBlogsDesechables), '?'));
        $pdo->prepare("DELETE FROM blogs WHERE id_blog IN ({$placeholdersBlog})")->execute($idsBlogsDesechables);
        echo 'Limpieza: ' . count($idsBlogsDesechables) . " articulos 'Playwright Blog*' desechables eliminados.\n";
    }

    $idAlmacen = (int) $pdo->query(
        "SELECT id_almacen FROM almacenes WHERE estado = 'activo' ORDER BY id_almacen ASC LIMIT 1"
    )->fetchColumn();

    if ($idAlmacen <= 0) {
        throw new RuntimeException('No se pudo resolver id_almacen. ¿Se importó database.sql?');
    }

    // Busca un almacen existente cuyo nombre ya califique como sucursal publica de
    // pickup (p.ej. si el ambiente ya tiene uno real como "Papelería Liz"); si
    // ninguno califica, siembra el de prueba.
    $idAlmacenPickup = 0;
    $stmt = $pdo->query("SELECT id_almacen, nombre FROM almacenes WHERE estado = 'activo' ORDER BY id_almacen ASC");
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $almacen) {
        if (isPublicPickupWarehouseName((string) $almacen['nombre'])) {
            $idAlmacenPickup = (int) $almacen['id_almacen'];
            break;
        }
    }

    if ($idAlmacenPickup <= 0) {
        $stmt = $pdo->prepare('SELECT id_almacen FROM almacenes WHERE nombre = :nombre LIMIT 1');
        $stmt->execute(['nombre' => E2E_PICKUP_ALMACEN_NOMBRE]);
        $idAlmacenPickup = (int) $stmt->fetchColumn();

        if ($idAlmacenPickup <= 0) {
            $stmt = $pdo->prepare(
                'INSERT INTO almacenes (nombre, ubicacion, estado) VALUES (:nombre, :ubicacion, "activo")'
            );
            $stmt->execute([
                'nombre' => E2E_PICKUP_ALMACEN_NOMBRE,
                'ubicacion' => 'Almacen de prueba para tests/e2e/*.pickup.spec.ts',
            ]);
            $idAlmacenPickup = (int) $pdo->lastInsertId();
        }
    }

    echo 'Seed OK: sucursal pickup -> id_almacen=' . $idAlmacenPickup . "\n";

    $idProductoPrincipal = 0;
    $idProductoLowStock = 0;
    $idProductoOutOfStock = 0;

    foreach ($productsToSeed as $p) {
        $stmt = $pdo->prepare(
            'INSERT INTO productos (nombre, codigo_barras, precio_venta, estado)
             VALUES (:nombre, :codigo_barras, :precio, "activo")
             ON DUPLICATE KEY UPDATE nombre = VALUES(nombre), precio_venta = VALUES(precio_venta), estado = "activo"'
        );
        $stmt->execute([
            'nombre' => $p['nombre'],
            'codigo_barras' => $p['codigo_barras'],
            'precio' => $p['precio'],
        ]);

        $stmt = $pdo->prepare('SELECT id_producto FROM productos WHERE codigo_barras = :codigo_barras');
        $stmt->execute(['codigo_barras' => $p['codigo_barras']]);
        $idProducto = (int) $stmt->fetchColumn();

        if ($idProducto <= 0) {
            throw new RuntimeException("No se pudo resolver id_producto para {$p['codigo_barras']}.");
        }

        $stmt = $pdo->prepare(
            'INSERT INTO inventario_almacen (id_producto, id_almacen, cantidad_actual)
             VALUES (:id_producto, :id_almacen, :cantidad)
             ON DUPLICATE KEY UPDATE cantidad_actual = VALUES(cantidad_actual)'
        );
        $stmt->execute([
            'id_producto' => $idProducto,
            'id_almacen' => $idAlmacen,
            'cantidad' => $p['stock'],
        ]);

        echo "Seed OK: {$p['nombre']} -> id_producto={$idProducto}, id_almacen={$idAlmacen}, stock={$p['stock']}\n";

        if ($p['nombre'] === E2E_PRODUCT_NAME) {
            $idProductoPrincipal = $idProducto;
        } elseif ($p['nombre'] === E2E_LOW_STOCK_PRODUCT_NAME) {
            $idProductoLowStock = $idProducto;
        } elseif ($p['nombre'] === E2E_OUT_OF_STOCK_PRODUCT_NAME) {
            $idProductoOutOfStock = $idProducto;
        }
    }

    // El producto con stock alto tambien se siembra en la sucursal de pickup, para
    // el caso feliz de "Recoger en Sucursal" (status 'ok'). El producto de stock
    // bajo se deja SIN existencia ahi a proposito: sirve para probar 'transferible'
    // (pidiendo 1, se cubre transfiriendo desde el almacen principal) y 'sin_stock'
    // (pidiendo mas de lo que existe en total) en tests/e2e/*.pickup.spec.ts.
    if ($idProductoPrincipal > 0) {
        $stmt = $pdo->prepare(
            'INSERT INTO inventario_almacen (id_producto, id_almacen, cantidad_actual)
             VALUES (:id_producto, :id_almacen, 9999)
             ON DUPLICATE KEY UPDATE cantidad_actual = 9999'
        );
        $stmt->execute(['id_producto' => $idProductoPrincipal, 'id_almacen' => $idAlmacenPickup]);
        echo 'Seed OK: ' . E2E_PRODUCT_NAME . " -> stock=9999 tambien en la sucursal de pickup (id_almacen={$idAlmacenPickup})\n";
    }

    // El bucle de arriba solo escribe cantidad_actual en $idAlmacen (el almacen principal) --
    // "se deja SIN existencia ahi a proposito" (comentario de arriba) describe la intencion,
    // pero nunca se hacia cumplir: nada evitaba que otro proceso le metiera stock a estos
    // productos en OTRO almacen. Eso paso de verdad: dbCancelarPedidoCompleto() (limpieza de
    // pedidos huerfanos de prueba) le devolvio 1 unidad a Low Stock Product en el almacen de
    // pickup porque algun pedido viejo lo habia pedido desde ahi, rompiendo la garantia de
    // "1 unidad en TODO el sistema" de la que dependen los tests de
    // checkout-sucursal.authenticated.spec.ts -- silencioso hasta que esos tests fallaron sin
    // razon aparente. Se pone a 0 en cualquier otro almacen en cada corrida para que sea
    // realmente idempotente/autocorregible sin importar que otro proceso le haya hecho al
    // inventario mientras tanto.
    foreach ([$idProductoLowStock => E2E_LOW_STOCK_PRODUCT_NAME, $idProductoOutOfStock => E2E_OUT_OF_STOCK_PRODUCT_NAME] as $idProductoCero => $nombreProductoCero) {
        if ($idProductoCero <= 0) {
            continue;
        }
        $stmt = $pdo->prepare(
            'UPDATE inventario_almacen SET cantidad_actual = 0 WHERE id_producto = :id_producto AND id_almacen != :id_almacen'
        );
        $filas = $stmt->execute(['id_producto' => $idProductoCero, 'id_almacen' => $idAlmacen]) ? $stmt->rowCount() : 0;
        if ($filas > 0) {
            echo "Correccion: {$nombreProductoCero} tenia stock fuera de su almacen principal ({$filas} almacen(es)) -- puesto en 0.\n";
        }
    }

    $stmt = $pdo->prepare(
        'INSERT INTO productos (nombre, codigo_barras, precio_venta, estado)
         VALUES (:nombre, :codigo_barras, :precio, "activo")
         ON DUPLICATE KEY UPDATE nombre = VALUES(nombre), precio_venta = VALUES(precio_venta), estado = "activo"'
    );
    $stmt->execute([
        'nombre' => E2E_PURCHASE_ORDER_PRODUCT_NAME,
        'codigo_barras' => E2E_PURCHASE_ORDER_PRODUCT_BARCODE,
        'precio' => 19.99,
    ]);
    $stmt = $pdo->prepare('SELECT id_producto FROM productos WHERE codigo_barras = :codigo_barras');
    $stmt->execute(['codigo_barras' => E2E_PURCHASE_ORDER_PRODUCT_BARCODE]);
    $idProductoPO = (int) $stmt->fetchColumn();
    if ($idProductoPO <= 0) {
        throw new RuntimeException('No se pudo resolver id_producto para ' . E2E_PURCHASE_ORDER_PRODUCT_BARCODE . '.');
    }
    // cantidad_actual NO se resetea en cada corrida a proposito (ver comentario junto a la
    // constante arriba): solo se fija en 1 la primera vez que se crea esta fila. stock_minimo/
    // maximo si se garantizan siempre, para que el margen siga siendo generoso aunque este
    // script no se vuelva a correr en un rato.
    $stmt = $pdo->prepare(
        'INSERT INTO inventario_almacen (id_producto, id_almacen, cantidad_actual, stock_minimo, stock_maximo)
         VALUES (:id_producto, :id_almacen, 1, :stock_minimo, :stock_maximo)
         ON DUPLICATE KEY UPDATE stock_minimo = VALUES(stock_minimo), stock_maximo = VALUES(stock_maximo)'
    );
    $stmt->execute([
        'id_producto' => $idProductoPO,
        'id_almacen' => $idAlmacen,
        'stock_minimo' => E2E_PURCHASE_ORDER_PRODUCT_STOCK_MINIMO,
        'stock_maximo' => E2E_PURCHASE_ORDER_PRODUCT_STOCK_MINIMO + 5,
    ]);
    echo 'Seed OK: ' . E2E_PURCHASE_ORDER_PRODUCT_NAME . " -> id_producto={$idProductoPO}, id_almacen={$idAlmacen}, stock_minimo=" . E2E_PURCHASE_ORDER_PRODUCT_STOCK_MINIMO . "\n";

    // Los dos productos del ciclo real de Ordenes de Compra: a diferencia del de arriba,
    // cantidad_actual SI se resetea a 0 en cada corrida, y se cancela cualquier orden de
    // compra que haya quedado abierta de una corrida anterior interrumpida a medias -- si
    // no, el producto quedaria excluido de la Lista de Compra para siempre
    // (purchaseOrderFetchSuggestions oculta productos con una orden 'borrador'/'enviada'/
    // 'parcial' sin cerrar).
    foreach ([
        E2E_PO_SURTIR_PRODUCT_BARCODE => E2E_PO_SURTIR_PRODUCT_NAME,
        E2E_PO_CANCEL_PRODUCT_BARCODE => E2E_PO_CANCEL_PRODUCT_NAME,
    ] as $codigoBarrasLifecycle => $nombreLifecycle) {
        $stmt = $pdo->prepare(
            'INSERT INTO productos (nombre, codigo_barras, precio_venta, precio_costo, estado)
             VALUES (:nombre, :codigo_barras, 19.99, 10.00, "activo")
             ON DUPLICATE KEY UPDATE nombre = VALUES(nombre), precio_venta = VALUES(precio_venta), precio_costo = VALUES(precio_costo), estado = "activo"'
        );
        $stmt->execute(['nombre' => $nombreLifecycle, 'codigo_barras' => $codigoBarrasLifecycle]);

        $stmt = $pdo->prepare('SELECT id_producto FROM productos WHERE codigo_barras = :codigo_barras');
        $stmt->execute(['codigo_barras' => $codigoBarrasLifecycle]);
        $idProductoLifecycle = (int) $stmt->fetchColumn();
        if ($idProductoLifecycle <= 0) {
            throw new RuntimeException("No se pudo resolver id_producto para {$codigoBarrasLifecycle}.");
        }

        $stmt = $pdo->prepare(
            'INSERT INTO inventario_almacen (id_producto, id_almacen, cantidad_actual, stock_minimo, stock_maximo)
             VALUES (:id_producto, :id_almacen, 0, :stock_minimo, :stock_maximo)
             ON DUPLICATE KEY UPDATE cantidad_actual = 0, stock_minimo = VALUES(stock_minimo), stock_maximo = VALUES(stock_maximo)'
        );
        $stmt->execute([
            'id_producto' => $idProductoLifecycle,
            'id_almacen' => $idAlmacen,
            'stock_minimo' => E2E_PO_LIFECYCLE_STOCK_MINIMO,
            'stock_maximo' => E2E_PO_LIFECYCLE_STOCK_MAXIMO,
        ]);

        $stmtCancelStale = $pdo->prepare(
            "UPDATE ordenes_compra oc
             JOIN detalle_orden_compra doc ON doc.id_orden_compra = oc.id_orden_compra
             SET oc.estado = 'cancelada'
             WHERE doc.id_producto = :id_producto
               AND oc.estado IN ('borrador','enviada','parcial')"
        );
        $stmtCancelStale->execute(['id_producto' => $idProductoLifecycle]);
        if ($stmtCancelStale->rowCount() > 0) {
            echo "Correccion: {$nombreLifecycle} tenia {$stmtCancelStale->rowCount()} orden(es) de compra abierta(s) de una corrida anterior -- canceladas.\n";
        }

        echo "Seed OK: {$nombreLifecycle} -> id_producto={$idProductoLifecycle}, id_almacen={$idAlmacen}, stock_minimo=" . E2E_PO_LIFECYCLE_STOCK_MINIMO . "\n";
    }

    // Producto deliberadamente incompleto (sin precio_venta/precio_costo/sku/codigo_barras
    // ni fila en inventario_almacen). Se busca por nombre porque no tiene codigo_barras.
    $stmt = $pdo->prepare('SELECT id_producto FROM productos WHERE nombre = :nombre AND id_padre IS NULL LIMIT 1');
    $stmt->execute(['nombre' => E2E_PRODUCTO_INCOMPLETO_NOMBRE]);
    $idProductoIncompleto = (int) $stmt->fetchColumn();

    if ($idProductoIncompleto <= 0) {
        $stmt = $pdo->prepare(
            "INSERT INTO productos (nombre, precio_venta, precio_costo, estado)
             VALUES (:nombre, 0, 0, 'activo')"
        );
        $stmt->execute(['nombre' => E2E_PRODUCTO_INCOMPLETO_NOMBRE]);
        $idProductoIncompleto = (int) $pdo->lastInsertId();
    } else {
        // Por si un test anterior le agrego algo -- se deja siempre incompleto de verdad.
        $pdo->prepare(
            "UPDATE productos SET precio_venta = 0, precio_costo = 0, sku = NULL, codigo_barras = NULL, estado = 'activo' WHERE id_producto = ?"
        )->execute([$idProductoIncompleto]);
        $pdo->prepare('DELETE FROM inventario_almacen WHERE id_producto = ?')->execute([$idProductoIncompleto]);
    }
    echo 'Seed OK: ' . E2E_PRODUCTO_INCOMPLETO_NOMBRE . " -> id_producto={$idProductoIncompleto} (sin precio/sku/codigo_barras/inventario)\n";

    // La tabla clientes no tiene una llave unica sobre nombre, asi que la
    // idempotencia se resuelve buscando primero en vez de ON DUPLICATE KEY.
    $stmt = $pdo->prepare('SELECT id_cliente FROM clientes WHERE nombre = :nombre LIMIT 1');
    $stmt->execute(['nombre' => E2E_SALES_CLIENTE_NOMBRE]);
    $idCliente = (int) $stmt->fetchColumn();

    if ($idCliente <= 0) {
        $stmt = $pdo->prepare(
            'INSERT INTO clientes (nombre, telefono, estado) VALUES (:nombre, :telefono, "activo")'
        );
        $stmt->execute([
            'nombre' => E2E_SALES_CLIENTE_NOMBRE,
            'telefono' => E2E_SALES_CLIENTE_TELEFONO,
        ]);
        $idCliente = (int) $pdo->lastInsertId();
    } else {
        $stmt = $pdo->prepare('UPDATE clientes SET telefono = :telefono, estado = "activo" WHERE id_cliente = :id_cliente');
        $stmt->execute(['telefono' => E2E_SALES_CLIENTE_TELEFONO, 'id_cliente' => $idCliente]);
    }

    $stmt = $pdo->prepare('SELECT id_direccion FROM cliente_direcciones WHERE id_cliente = :id_cliente LIMIT 1');
    $stmt->execute(['id_cliente' => $idCliente]);
    $idDireccion = (int) $stmt->fetchColumn();

    if ($idDireccion <= 0) {
        $stmt = $pdo->prepare(
            'INSERT INTO cliente_direcciones (id_cliente, alias, direccion, es_default) VALUES (:id_cliente, "Casa", :direccion, 1)'
        );
        $stmt->execute(['id_cliente' => $idCliente, 'direccion' => E2E_SALES_CLIENTE_DIRECCION]);
    } else {
        $stmt = $pdo->prepare('UPDATE cliente_direcciones SET direccion = :direccion WHERE id_direccion = :id_direccion');
        $stmt->execute(['direccion' => E2E_SALES_CLIENTE_DIRECCION, 'id_direccion' => $idDireccion]);
    }

    echo "Seed OK: " . E2E_SALES_CLIENTE_NOMBRE . " -> id_cliente={$idCliente}, con domicilio 'Casa'\n";

    $pdo->commit();
    exit(0);
} catch (Throwable $e) {
    $pdo->rollBack();
    fwrite(STDERR, 'Seed error: ' . $e->getMessage() . "\n");
    exit(1);
}
