<?php
declare(strict_types=1);

require_once __DIR__ . '/../core/config.php';
require_once __DIR__ . '/../core/auth.php';
require_once __DIR__ . '/../core/cliente_loyalty_utils.php';
require_once __DIR__ . '/../core/cliente_scope_utils.php';
require_once __DIR__ . '/../core/oferta_pricing.php';

requireAuth();
// El permiso 'realizar_ventas' abre esta vista (sin respaldo por rol: el panel de Roles y Permisos manda).
if (!hasPermission('realizar_ventas')) {
    header('Location: ' . BASE_URL . 'views/dashboard.php');
    exit;
}

// Agendar pedidos a domicilio exige el permiso 'asignar_entregas' (sin respaldo por rol).
// Quien no lo tiene (p. ej. un vendedor) solo puede registrar ventas de mostrador.
$puedeAgendarDomicilio = hasPermission('asignar_entregas');
$pageTitle = $puedeAgendarDomicilio ? 'Registrar Venta / Pedido' : 'Registrar Venta en Sucursal';
$pdo = getPDO();
$error = '';
$canManageCustomers = hasPermission('gestionar_clientes');

// URL absoluta (con dominio) para el boton "Compartir" -- WhatsApp/Facebook/correo la usan
// tal cual, no pueden resolver una ruta relativa como BASE_URL.
$compartirScheme = ((!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
    || (isset($_SERVER['HTTP_X_FORWARDED_PROTO']) && strtolower((string)$_SERVER['HTTP_X_FORWARDED_PROTO']) === 'https'))
    ? 'https' : 'http';
$productoPublicoBaseUrl = $compartirScheme . '://' . ($_SERVER['HTTP_HOST'] ?? 'localhost') . BASE_URL . 'views/producto_publico.php?id=';

$id_almacen_actual = resolveSalesWarehouseId($pdo);
$almacenActualNombre = '';

if ($id_almacen_actual > 0) {
    $stmtSucursal = $pdo->prepare('SELECT nombre FROM almacenes WHERE id_almacen = ? LIMIT 1');
    $stmtSucursal->execute([$id_almacen_actual]);
    $almacenActualNombre = (string)($stmtSucursal->fetchColumn() ?: '');
}

if (!$id_almacen_actual) {
    $error = 'Error: No tienes una sucursal asignada o no se seleccionó ninguna.';
}

try {
    $sql = "SELECT p.*, ia.cantidad_actual,
            COALESCE(
                (SELECT pi.ruta_archivo
                 FROM producto_imagenes pi
                 INNER JOIN productos p_img ON pi.id_producto = p_img.id_producto
                 WHERE (p_img.id_producto = p.id_producto OR p_img.id_padre = p.id_producto)
                 ORDER BY (p_img.id_producto = p.id_producto) DESC, pi.orden ASC
                 LIMIT 1),
                p.imagen,
                p.imagen_url
            ) as imagen_fuente
            FROM productos p
            LEFT JOIN inventario_almacen ia ON p.id_producto = ia.id_producto AND ia.id_almacen = :almacen
            WHERE p.estado = 'activo'
            ORDER BY p.nombre ASC, p.nombre_variante ASC";
    $stmt = $pdo->prepare($sql);
    $stmt->execute([':almacen' => $id_almacen_actual]);
    $productos = $stmt->fetchAll();

    // Los productos en Ofertas se precargan al precio de oferta (no al normal): ver ofertaAplicarPrecioEfectivoALista().
    $productos = ofertaAplicarPrecioEfectivoALista($pdo, $productos);

    foreach ($productos as &$producto) {
        $producto['imagen_resuelta'] = getProductImageUrl((string)($producto['imagen_fuente'] ?? ''), (int)($producto['id_producto'] ?? 0));
    }
    unset($producto);
} catch (PDOException $e) {
    $error = 'Error al obtener productos: ' . $e->getMessage();
    $productos = [];
}

// Si el descifrado falla (llave distinta, dato corrupto, etc.) NUNCA debemos
// mostrar el texto cifrado crudo (ENCv1:...) al usuario; se usa un fallback seguro.
$safeDecryptValue = static function (?string $value, string $fallback = ''): string {
    $raw = trim((string)$value);
    if ($raw === '') {
        return $fallback;
    }
    if (!function_exists('piiIsEncryptedValue') || !function_exists('piiDecryptValue') || !piiIsEncryptedValue($raw)) {
        return $raw;
    }
    $decrypted = trim((string)piiDecryptValue($raw));
    if ($decrypted === $raw || piiIsEncryptedValue($decrypted)) {
        // El descifrado no funcionó (misma llave requerida no disponible o coincide con el crudo).
        return $fallback;
    }
    return $decrypted;
};

try {
    $stmtMeta = $pdo->prepare("SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'cliente_direcciones'");
    $stmtMeta->execute();
    $hasClienteDireccionesTable = ((int)$stmtMeta->fetchColumn()) > 0;

    // Sin ORDER BY/LIMIT aqui a proposito: c.nombre esta cifrado (ENCv1:...) en la BD, y
    // ordenar/limitar en SQL sobre el texto cifrado da un orden esencialmente arbitrario
    // (no alfabetico) -- con suficientes clientes, eso deja clientes reales fuera del
    // LIMIT de forma impredecible aunque su nombre real empiece con "A". Se ordena y se
    // recorta a 500 mas abajo, ya con el nombre descifrado.
    //
    // Alcance por sucursal: un encargado/vendedor solo puede buscar y venderle a
    // clientes de SU sucursal (un admin ve todos). Mismo criterio que manage_customers.php.
    $clienteScope = clienteScopeSqlFilter(getCurrentAlmacenId(), isAdmin(), 'c');
    $sql = "SELECT c.id_cliente, c.nombre, COALESCE(c.telefono, '') AS telefono
            FROM clientes c
            WHERE c.estado = 'activo' AND {$clienteScope['sql']}";
    $stmt = $pdo->prepare($sql);
    $stmt->execute($clienteScope['params']);
    $clientesActivos = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $clientesFrecuentesIds = clienteFrecuenteGetIds($pdo);

    foreach ($clientesActivos as &$cliente) {
        $cliente['direccion'] = '';
        $cliente['maps_link'] = '';
        $cliente['direcciones'] = [];
        $cliente['nombre'] = $safeDecryptValue($cliente['nombre'] ?? '', 'Cliente protegido');
        $cliente['telefono'] = $safeDecryptValue($cliente['telefono'] ?? '', '');
        $cliente['es_frecuente'] = in_array((int)($cliente['id_cliente'] ?? 0), $clientesFrecuentesIds, true);
    }
    unset($cliente);

    usort($clientesActivos, static function (array $a, array $b): int {
        return strcasecmp((string)($a['nombre'] ?? ''), (string)($b['nombre'] ?? ''));
    });
    $clientesActivos = array_slice($clientesActivos, 0, 500);

    if ($hasClienteDireccionesTable && !empty($clientesActivos)) {
        $idsCliente = array_values(array_filter(array_map(static function (array $cliente): int {
            return (int)($cliente['id_cliente'] ?? 0);
        }, $clientesActivos), static function (int $idCliente): bool {
            return $idCliente > 0;
        }));

        if (!empty($idsCliente)) {
            $placeholders = implode(', ', array_fill(0, count($idsCliente), '?'));
            $stmtDir = $pdo->prepare("SELECT id_direccion, id_cliente, alias, direccion, maps_link, es_default FROM cliente_direcciones WHERE id_cliente IN ({$placeholders}) ORDER BY id_cliente ASC, es_default DESC, id_direccion ASC");
            $stmtDir->execute($idsCliente);
            $direccionesRaw = $stmtDir->fetchAll(PDO::FETCH_ASSOC);

            $direccionesPorCliente = [];
            foreach ($direccionesRaw as $direccion) {
                $idClienteDireccion = (int)($direccion['id_cliente'] ?? 0);
                if ($idClienteDireccion <= 0) {
                    continue;
                }

                $direccionDescifrada = $safeDecryptValue($direccion['direccion'] ?? '', '');
                if ($direccionDescifrada === '') {
                    // Direccion ilegible (no se pudo descifrar): no la ofrecemos como opcion de entrega.
                    continue;
                }

                $direccionesPorCliente[$idClienteDireccion][] = [
                    'id_direccion' => (int)($direccion['id_direccion'] ?? 0),
                    'alias' => $safeDecryptValue($direccion['alias'] ?? '', ''),
                    'direccion' => $direccionDescifrada,
                    'maps_link' => $safeDecryptValue($direccion['maps_link'] ?? '', ''),
                    'es_default' => ((int)($direccion['es_default'] ?? 0)) === 1,
                ];
            }

            foreach ($clientesActivos as &$cliente) {
                $idCliente = (int)($cliente['id_cliente'] ?? 0);
                $direccionesCliente = $direccionesPorCliente[$idCliente] ?? [];
                $cliente['direcciones'] = $direccionesCliente;
                if (!empty($direccionesCliente)) {
                    $cliente['direccion'] = (string)($direccionesCliente[0]['direccion'] ?? '');
                    $cliente['maps_link'] = (string)($direccionesCliente[0]['maps_link'] ?? '');
                }
            }
            unset($cliente);
        }
    }
} catch (PDOException $e) {
    $clientesActivos = [];
}

include __DIR__ . '/includes/header.php';
?>

<div class="container">
    <!-- Pedidos a domicilio recien agendados: se quedan aqui (aun tras recargar) hasta que los tocas y te llevan a asignarlos -->
    <div id="ventas-por-asignar" style="margin-top:12px;"></div>
    <div class="row">
        <div class="col s12">
            <div class="sales-toolbar" style="display: flex; align-items: center; justify-content: space-between; margin-top: 15px; border-bottom: 2px solid #e0e0e0; padding-bottom: 5px;">
                <div class="chip blue lighten-5 blue-text text-darken-4 sales-warehouse-chip" style="margin: 0 10px 0 0;">
                    <span>Sucursal operativa:</span>
                    <strong class="sales-warehouse-name"><?php echo esc($almacenActualNombre !== '' ? $almacenActualNombre : 'Sin asignar'); ?></strong>
                </div>
                <ul id="ventas-tabs" class="tabs sales-tabs" style="background: transparent; height: 45px; overflow-x: auto; overflow-y: hidden;"></ul>
                <button type="button" onclick="nuevaVenta()" class="btn-floating btn-small waves-effect waves-light indigo sales-new-tab-btn" title="Atender otro cliente" style="margin-left: 10px;">
                    <i class="material-icons">add</i>
                </button>
                <a href="<?php echo BASE_URL; ?>views/dashboard.php" class="btn waves-effect waves-light blue darken-3 z-depth-1 sales-back-dashboard-btn" title="Volver al dashboard" style="margin-left: 10px;">
                    <i class="material-icons left">dashboard</i>Volver al Dashboard
                </a>
            </div>
        </div>
    </div>

    <?php if ($error !== ''): ?>
        <div class="card-panel red lighten-4 red-text text-darken-4"><?php echo esc($error); ?></div>
    <?php endif; ?>

    <div id="ventas-containers"></div>
</div>

<div id="modal-cerrar-venta" class="modal" style="max-width: 520px;">
    <div class="modal-content">
        <h5 style="margin-top: 0;">Cerrar pestaña de pedido</h5>
        <p>Esta pestaña tiene datos del cliente, entrega o productos.</p>
        <p class="grey-text text-darken-1" style="margin-bottom: 0;">Si la cierras, perderás esta información no guardada.</p>
    </div>
    <div class="modal-footer">
        <a href="#!" class="modal-close waves-effect waves-grey btn-flat">Cancelar</a>
        <a href="#!" id="btn-confirmar-cerrar-venta" class="waves-effect waves-light btn red darken-2">Sí, cerrar pestaña</a>
    </div>
</div>

<?php if ($canManageCustomers): ?>
<div id="modal-nuevo-cliente" class="modal" style="max-width: 560px;">
    <div class="modal-content">
        <h5 style="margin-top: 0;">Nuevo cliente</h5>
        <form id="form-nuevo-cliente">
            <?php echo csrfInput(); ?>
            <div class="input-field">
                <input type="text" id="nuevo-cliente-nombre" name="nombre" required>
                <label for="nuevo-cliente-nombre">Nombre completo</label>
            </div>
            <div class="input-field">
                <input type="tel" id="nuevo-cliente-telefono" name="telefono" required maxlength="19" inputmode="numeric" autocomplete="tel-national" placeholder="Ej: (331) - 863 - 5185">
                <label for="nuevo-cliente-telefono">Telefono</label>
            </div>
            <div class="input-field">
                <input type="email" id="nuevo-cliente-email" name="email">
                <label for="nuevo-cliente-email">Email (opcional)</label>
            </div>
            <div class="nuevo-cliente-domicilio">
                <strong>Domicilio <span class="grey-text" style="font-weight:normal;">(opcional)</span></strong>
                <p class="grey-text" style="margin:4px 0 0;">Agregalo ahora para no tener que ir a Administrar Clientes.</p>
                <?php if (defined('GOOGLE_MAPS_API_KEY') && GOOGLE_MAPS_API_KEY !== ''): ?>
                <div class="input-field" style="margin-top:14px;">
                    <i class="material-icons prefix blue-text">search</i>
                    <input type="text" id="nuevo-cliente-dir-buscar" autocomplete="off" placeholder="Escribe la calle y numero...">
                    <span class="helper-text">Selecciona una opcion sugerida para mayor precision</span>
                </div>
                <?php endif; ?>
                <div class="input-field">
                    <input type="text" id="nuevo-cliente-dir-alias" maxlength="50" placeholder="Ej: Casa, Trabajo, Mama">
                    <label for="nuevo-cliente-dir-alias">Alias del domicilio</label>
                </div>
                <div class="input-field">
                    <textarea id="nuevo-cliente-direccion" class="materialize-textarea"></textarea>
                    <label for="nuevo-cliente-direccion">Direccion exacta (incluye numero de casa)</label>
                </div>
                <input type="hidden" id="nuevo-cliente-maps-link" value="">
                <p id="nuevo-cliente-maps-status" class="grey-text" style="margin:-6px 0 8px;">Sin ubicacion en mapa seleccionada aun.</p>
            </div>
            <div id="nuevo-cliente-error" class="red-text" style="display:none; margin-top:-8px; margin-bottom:12px;"></div>
        </form>
    </div>
    <div class="modal-footer">
        <a href="#!" class="modal-close waves-effect waves-grey btn-flat">Cancelar</a>
        <a href="#!" id="btn-guardar-nuevo-cliente" class="waves-effect waves-light btn blue darken-2">Crear cliente</a>
    </div>
</div>

<div id="modal-agregar-telefono" class="modal" style="max-width: 420px;">
    <div class="modal-content">
        <h5 style="margin-top: 0;">Agregar telefono</h5>
        <p class="grey-text" id="agregar-telefono-cliente-nombre" style="margin-top:-10px;"></p>
        <form id="form-agregar-telefono">
            <?php echo csrfInput(); ?>
            <div class="input-field">
                <input type="tel" id="agregar-telefono-input" maxlength="19" inputmode="numeric" autocomplete="tel-national" placeholder="Ej: (331) - 863 - 5185">
                <label for="agregar-telefono-input">Telefono</label>
            </div>
            <div id="agregar-telefono-error" class="red-text" style="display:none; margin-top:-8px; margin-bottom:12px;"></div>
        </form>
    </div>
    <div class="modal-footer">
        <a href="#!" id="btn-cancelar-agregar-telefono" class="modal-close waves-effect waves-grey btn-flat">Cancelar</a>
        <a href="#!" id="btn-guardar-agregar-telefono" class="waves-effect waves-light btn blue darken-2">Guardar</a>
    </div>
</div>
<?php endif; ?>

<div id="modal-verificar-lotes" class="modal" style="max-width: 640px;">
    <div class="modal-content">
        <h5 style="margin-top: 0;"><i class="material-icons left">fact_check</i>Verifica el lote antes de cobrar</h5>
        <p class="grey-text text-darken-1" style="margin-top:-8px;">Estos productos tienen lote registrado. Confirma que separaste/entregaste cada uno del lote indicado antes de continuar.</p>
        <div id="verificar-lotes-lista"></div>
    </div>
    <div class="modal-footer">
        <a href="#!" class="modal-close waves-effect waves-grey btn-flat" id="btn-cancelar-verificar-lotes">Cancelar</a>
        <a href="#!" id="btn-confirmar-lotes" class="waves-effect waves-light btn green darken-2 disabled">Confirmo, cobrar</a>
    </div>
</div>

<div id="modal-ver-beneficios" class="modal" style="max-width: 520px;">
    <div class="modal-content">
        <h5 style="margin-top: 0;"><i class="material-icons left">info</i>Beneficios del producto</h5>
        <p class="grey-text text-darken-1 beneficios-producto-nombre" style="margin-top: -8px; font-weight: 600;"></p>
        <p class="grey-text" style="font-size: 0.85rem;">Referencia interna para contestarle al cliente -- no se muestra en el catálogo ni se le lee tal cual.</p>
        <div class="beneficios-producto-lista" style="margin: 10px 0;"></div>
        <div class="beneficios-producto-perfil" style="margin-top: 14px; display: none;">
            <strong style="font-size: 0.9rem;">Perfil recomendado</strong>
            <p class="grey-text text-darken-2 beneficios-producto-perfil-texto" style="margin: 4px 0 0; font-size: 0.9rem; line-height: 1.5;"></p>
        </div>
        <div class="beneficios-producto-vacio grey-text" style="display: none;">Este producto todavía no tiene beneficios ni perfil recomendado capturados.</div>
    </div>
    <div class="modal-footer">
        <a href="#!" class="modal-close waves-effect waves-grey btn-flat">Cerrar</a>
    </div>
</div>

<div id="modal-compartir-producto" class="modal" style="max-width: 480px;">
    <div class="modal-content">
        <h5 style="margin-top: 0;"><i class="material-icons left">share</i>Compartir producto</h5>
        <p class="grey-text text-darken-1 compartir-producto-nombre" style="margin-top: -8px; font-weight: 600;"></p>
        <p class="grey-text" style="font-size: 0.85rem;">El mensaje se precarga tal cual se ve abajo -- revísalo o edítalo antes de enviarlo, ningún canal lo manda solo.</p>
        <div class="compartir-producto-preview" style="white-space: pre-wrap; background: #f5f5f5; border-radius: 6px; padding: 10px 12px; font-size: 0.85rem; color: #333; max-height: 160px; overflow-y: auto;"></div>
    </div>
    <div class="modal-footer" style="display: flex; gap: 8px; justify-content: flex-end;">
        <a href="#!" class="modal-close waves-effect waves-grey btn-flat">Cerrar</a>
        <a href="#" target="_blank" rel="noopener noreferrer" class="btn waves-effect waves-light blue darken-2 compartir-link-facebook"><i class="fa-brands fa-facebook left"></i>Facebook</a>
        <a href="#" target="_blank" rel="noopener noreferrer" class="btn waves-effect waves-light grey darken-1 compartir-link-correo"><i class="material-icons left">email</i>Correo</a>
        <a href="#" target="_blank" rel="noopener noreferrer" class="btn waves-effect waves-light green darken-1 compartir-link-whatsapp"><i class="fa-brands fa-whatsapp left"></i>WhatsApp</a>
    </div>
</div>

<template id="venta-template">
    <div id="venta-{{id}}" class="row animated fadeIn venta-context" style="margin-top: 20px;">
        <div class="col s12 m8">
            <div class="card">
                <div class="card-content">
                    <form class="formulario-venta" method="POST" action="<?php echo BASE_URL; ?>api/ventas.php">
                        <?php echo csrfInput(); ?>

                        <div class="row tipo-entrega-row" style="margin-bottom: 8px;">
                            <div class="col s12">
                                <div class="tipo-entrega-switch" style="display:flex; flex-wrap:wrap; gap:6px 22px; padding:10px 14px; border:1px solid #cfd8dc; border-radius:6px; background:#fafafa;">
<?php if ($puedeAgendarDomicilio): ?>
                                    <span style="font-weight:600; color:#37474f; margin-right:4px;">Tipo de venta:</span>
                                    <label style="margin:0;">
                                        <input name="tipo_entrega" type="radio" value="Domicilio" class="tipo-entrega-radio" checked>
                                        <span>A domicilio</span>
                                    </label>
                                    <label style="margin:0;">
                                        <input name="tipo_entrega" type="radio" value="Sucursal" class="tipo-entrega-radio">
                                        <span>En sucursal (cliente presente)</span>
                                    </label>
<?php else: ?>
                                    <input name="tipo_entrega" type="radio" value="Sucursal" class="tipo-entrega-radio" checked hidden>
                                    <span style="font-weight:600; color:#37474f;">
                                        <i class="material-icons tiny" style="vertical-align:middle;">storefront</i>
                                        Venta en sucursal (mostrador)
                                    </span>
<?php endif; ?>
                                </div>
                                <span class="helper-text tipo-entrega-hint" style="display:block; margin-top:4px;"></span>
                            </div>
                        </div>

                        <div class="row">
                            <div class="input-field col s12">
                                <i class="material-icons prefix">person_outline</i>
                                <input type="hidden" class="cliente_id" name="id_cliente" value="">
                                <div class="sales-customer-input-wrap">
                                    <input type="text" class="cliente_nombre" name="cliente_nombre" placeholder="Busca un cliente existente" oninput="actualizarTituloTab('{{id}}', this.value)" autocomplete="off">
                                    <div class="selected-customer-chip-wrap"></div>
                                </div>
                                <label class="active">Nombre de cliente</label>
                                <?php if ($canManageCustomers): ?>
                                    <span class="helper-text"><a href="#!" class="btn-nuevo-cliente-trigger blue-text">+ Crear cliente nuevo</a></span>
                                <?php else: ?>
                                    <span class="helper-text">Si no aparece, contacta al administrador.</span>
                                <?php endif; ?>
                                <div class="selected-client-status grey-text text-darken-1" style="font-size:0.85rem; margin-top:4px;"></div>
                            </div>
                        </div>

                        <div class="row">
                            <div class="input-field col s12">
                                <i class="material-icons prefix">phone</i>
                                <input type="tel" class="cliente_telefono" name="cliente_telefono" placeholder="Telefono del cliente seleccionado" maxlength="19" inputmode="numeric" autocomplete="tel-national">
                                <label class="active">Telefono</label>
                                <span class="helper-text telefono-helper-text">Obligatorio para la entrega a domicilio.</span>
                                <div class="cliente-sin-telefono-alert" style="display:none; margin-top:6px; font-size:0.85rem;">
                                    <span class="orange-text text-darken-3">Este cliente no tiene telefono registrado.</span>
                                    <a href="#!" class="cliente-editar-telefono-link blue-text" style="margin-left:4px;">Agregarlo ahora</a>
                                </div>
                            </div>
                        </div>

                        <div class="row delivery-only-row">
                            <div class="col s12 customer-address-block" style="display:none; margin-bottom: 10px;">
                                <label style="display:block; margin-bottom:8px; font-weight:600; color:#37474f;">Domicilios guardados del cliente</label>
                                <select class="browser-default customer-address-select" name="customer_address_id" style="border: 1px solid #cfd8dc; border-radius: 4px; padding: 10px; height: auto; width: 100%;">
                                    <option value="">-- Selecciona un domicilio --</option>
                                </select>
                                <span class="helper-text">Usa el alias para identificar Casa, Trabajo, Mama u otras direcciones guardadas.</span>
                            </div>
                        </div>

                        <div class="row delivery-only-row">
                            <div class="col s12">
                                <input type="hidden" class="direccion_entrega" name="direccion_entrega" value="">
                                <div class="delivery-address-preview" style="display:none;">
                                    <i class="material-icons tiny">place</i><span class="delivery-address-preview-text"></span>
                                </div>
                                <div class="delivery-map-link" style="display:none; margin-top:8px;">
                                    <a href="#" target="_blank" rel="noopener noreferrer" class="btn-small blue darken-2 waves-effect waves-light delivery-map-link-anchor">
                                        <i class="material-icons left">map</i><span class="delivery-map-link-text">Abrir ubicación</span>
                                    </a>
                                </div>
                            </div>
                        </div>

                        <div class="row delivery-only-row">
                            <div class="input-field col s12">
                                <i class="material-icons prefix">map</i>
                                <input type="url" class="maps_link_entrega" name="maps_link_entrega" placeholder="Link guardado del domicilio" autocomplete="off">
                                <label class="active">Link de Google Maps</label>
                                <span class="helper-text">Se llena automáticamente cuando eliges direccion guardada.</span>
                            </div>
                        </div>

                        <div class="row delivery-only-row">
                            <div class="col s12">
                                <!-- Mapa (izquierda) y vista de la calle / fachada de Google Street View (derecha) -->
                                <div class="sales-map-row">
                                    <div class="sales-map-preview z-depth-1"></div>
                                    <div class="sales-streetview-cell z-depth-1">
                                        <div class="sales-streetview-preview"></div>
                                        <div class="sales-streetview-msg grey-text text-darken-1">Buscando vista de la calle...</div>
                                        <!-- Solo en pantallas tactiles: evita que el dedo quede atrapado en el panorama al hacer scroll -->
                                        <button type="button" class="sales-streetview-shield" aria-label="Activar la vista de la calle"><span>Toca para explorar</span></button>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <div class="row" style="background: #f9f9f9; padding: 20px; border-radius: 8px; border: 1px dashed #ccc; margin-bottom: 20px;">
                            <div class="input-field col s12 producto-dropdown-wrap">
                                <i class="material-icons prefix">search</i>
                                <input type="text" class="buscador-producto" placeholder="Escribe el nombre o escanea código de barras..." autocomplete="off">
                                <label class="active">Buscar Producto</label>
                                <span class="helper-text">Presiona Enter para agregar por código de barras</span>
                                <div class="producto-dropdown"></div>
                            </div>
                        </div>

                        <div class="carrito-items"></div>

                        <div class="sin-productos center-align grey-text" style="padding: 20px;">
                            <i class="material-icons style-large">shopping_basket</i>
                            <p>No hay productos en el pedido. Usa el buscador de arriba para agregar.</p>
                        </div>

                        <hr>

                        <div class="row">
                            <div class="input-field col s12 m6">
                                <select name="id_metodo_pago">
                                    <option value="" selected>Se define al entregar</option>
                                    <option value="1">Efectivo</option>
                                    <option value="2">Transferencia Bancaria</option>
                                </select>
                                <label>Método de Pago Estimado (Opcional)</label>
                            </div>
                            <div class="col s12 m6" style="display:flex; align-items:center; min-height:70px;">
                                <div class="card-panel teal lighten-5 teal-text text-darken-4" style="margin:0; width:100%; padding:12px 16px;">
                                    No se aplican incentivos automáticos de sucursal. Los descuentos se capturan manualmente por producto.
                                </div>
                            </div>
                        </div>

                        <div class="input-field">
                            <textarea name="observaciones" class="materialize-textarea observaciones"></textarea>
                            <label>Observaciones</label>
                        </div>

                        <button type="submit" class="btn waves-effect waves-light green btn-large w-100 btn-enviar-venta">
                            Agendar Pedido <i class="material-icons right">local_shipping</i>
                        </button>
                    </form>
                </div>
            </div>
        </div>

        <div class="col s12 m4">
            <div class="card blue-grey darken-1">
                <div class="card-content white-text">
                    <span class="card-title resumen-titulo">Resumen del Pedido</span>
                    <div style="margin-top: 20px;">
                        <div class="row" style="margin-bottom: 5px;">
                            <div class="col s6">Subtotal:</div>
                            <div class="col s6 right-align">$<span class="subtotal-val">0.00</span></div>
                        </div>
                        <div class="row" style="margin-bottom: 5px;">
                            <div class="col s6">Descuento manual:</div>
                            <div class="col s6 right-align text-red">-$<span class="descuento-total-val">0.00</span></div>
                        </div>
                        <div class="divider" style="background: rgba(255,255,255,0.2); margin: 10px 0;"></div>
                        <div class="row" style="font-size: 1.8rem; font-weight: bold;">
                            <div class="col s4">Total:</div>
                            <div class="col s8 right-align">$<span class="total-venta-val">0.00</span></div>
                        </div>
                        <div class="divider" style="background: rgba(255,255,255,0.2); margin: 10px 0;"></div>
                        <div class="row" style="margin-bottom: 0; color: #c5e1a5;">
                            <div class="col s12 resumen-cobro-text">
                                El cobro se realizará al momento de la entrega por el repartidor asignado.
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</template>

<style>
    /* Sugerencias de Google Places: por encima de los modales de Materialize (z-index ~1003) */
    .pac-container { z-index: 3000 !important; }
    .nuevo-cliente-domicilio { margin-top: 8px; padding-top: 12px; border-top: 1px solid #e0e0e0; }
    /* Mapa + Street View: mitad y mitad; en movil se apilan para que cada uno tenga ancho util */
    .sales-map-row { display: none; gap: 8px; }
    .sales-map-row.is-visible { display: flex; }
    .sales-map-preview,
    .sales-streetview-cell { flex: 1 1 0; min-width: 0; height: 180px; border-radius: 4px; border: 1px solid #ddd; overflow: hidden; position: relative; }
    .sales-streetview-preview { position: absolute; top: 0; right: 0; bottom: 0; left: 0; }
    .sales-streetview-msg { position: absolute; top: 0; right: 0; bottom: 0; left: 0; display: flex; align-items: center; justify-content: center; text-align: center; padding: 12px; background: #f5f5f5; font-size: 0.85rem; }
    .sales-streetview-msg.is-hidden { display: none; }
    /* En tactil el panorama captura el arrastre del dedo y traba el scroll de la pagina: se tapa con
       una capa transparente hasta que se toca "Toca para explorar". */
    .sales-streetview-shield { display: none; position: absolute; top: 0; right: 0; bottom: 0; left: 0; z-index: 2; width: 100%; margin: 0; padding: 0; border: 0; background: transparent; cursor: pointer; align-items: flex-end; justify-content: center; }
    .sales-streetview-shield span { margin-bottom: 10px; padding: 6px 14px; border-radius: 16px; background: rgba(0, 0, 0, 0.6); color: #fff; font-size: 0.85rem; }
    @media (hover: none) and (pointer: coarse) {
        .sales-streetview-shield { display: flex; }
        .sales-streetview-shield.is-hidden { display: none; }
    }
    @media (max-width: 600px) {
        .sales-map-row.is-visible { flex-direction: column; }
        .sales-map-preview, .sales-streetview-cell { flex: none; width: 100%; }
    }
    /* Con el domicilio el modal es alto: en movil se aprovecha casi toda la pantalla y hace scroll */
    @media (max-width: 600px) {
        #modal-nuevo-cliente { width: 94% !important; max-height: 92% !important; top: 4% !important; }
    }
    .sales-toolbar { flex-wrap: wrap; gap: 8px; }
    .sales-warehouse-chip { display: inline-flex; align-items: center; gap: 6px; white-space: nowrap; flex: 0 0 auto; }
    .sales-warehouse-name { white-space: nowrap; }
    .sales-tabs { flex: 1 1 260px; min-width: 220px; }
    .sales-new-tab-btn { flex: 0 0 auto; }
    .tabs .tab a { display: flex; align-items: center; padding: 0 15px; text-transform: none; font-weight: 500; }
    .tabs .tab a i.close-tab { margin-left: 10px; font-size: 16px; cursor: pointer; color: #9e9e9e; }
    .tabs .tab a i.close-tab:hover { color: #f44336; }
    .tab-color-0 .active { border-bottom: 3px solid #2196f3 !important; color: #2196f3 !important; }
    .tab-color-1 .active { border-bottom: 3px solid #4caf50 !important; color: #4caf50 !important; }
    .tab-color-2 .active { border-bottom: 3px solid #9c27b0 !important; color: #9c27b0 !important; }
    .tab-color-3 .active { border-bottom: 3px solid #ff9800 !important; color: #ff9800 !important; }
    .producto-item {
        background: #fff;
        transition: all 0.3s;
        border-left: 4px solid #4caf50;
        padding: 15px;
        margin: 10px 0;
        border-radius: 4px;
        box-shadow: 0 2px 5px rgba(0,0,0,0.1);
    }
    .producto-item:hover { background: #f5f5f5; }
    /* Cada renglon (imagen+nombre, luego cantidad/precio/descuento cada uno en su propia
       fila, luego total+eliminar) se acomoda con flexbox en vez del grid de 12 columnas de
       Materialize -- la suma de columnas que usaba antes (imagen+nombre+cant+precio+desc+
       total+eliminar) superaba 12, lo que desbordaba el grid y encimaba los labels con los
       inputs a ciertos anchos. Flexbox con flex-wrap se acomoda solo sin tener que cuadrar
       esa aritmetica.  */
    .producto-item-layout { display: flex; flex-wrap: wrap; align-items: flex-start; gap: 16px; }
    /* Ancho y alto fijos en px (no %): Materialbox necesita un tamaño definido para calcular
       la miniatura -- con flexbox (sin la columna fija de Materialize que habia antes) un
       tamaño en porcentaje queda ambiguo y termina mostrando la foto a su resolucion real. */
    .producto-item-media { flex: 0 0 80px; width: 80px; }
    .producto-item-media img { display: block; width: 80px; height: 80px; object-fit: cover; }
    .producto-item-info { flex: 1 1 200px; min-width: 180px; }
    .producto-item-fields { flex: 0 0 auto; display: flex; flex-direction: column; gap: 10px; min-width: 210px; }
    .producto-item-field-row { display: flex; align-items: center; gap: 10px; }
    .producto-item-field-label { flex: 0 0 90px; color: #607d8b; font-size: 0.85rem; }
    .producto-item-field-input {
        height: 38px;
        width: 110px;
        margin: 0;
        padding: 0 8px;
        border: 1px solid #b0bec5;
        border-radius: 4px;
        color: #2e7d32;
        font-weight: bold;
        box-sizing: border-box;
    }
    .producto-item-field-input--desc { color: #c62828; }
    .producto-item-totals { flex: 0 0 auto; display: flex; align-items: center; gap: 14px; margin-left: auto; }
    .producto-item-actions { display: inline-flex; align-items: center; gap: 6px; margin-left: 8px; vertical-align: middle; }
    .producto-item-action {
        display: inline-flex;
        align-items: center;
        justify-content: center;
        width: 44px;
        height: 44px;
        padding: 0;
        border: 1px solid transparent;
        border-radius: 50%;
        background: transparent;
        cursor: pointer;
        line-height: 1;
        touch-action: manipulation;
        transition: background-color 0.15s ease, border-color 0.15s ease, transform 0.15s ease;
    }
    .producto-item-action:hover { transform: translateY(-1px); }
    .producto-item-action:focus-visible { outline: 3px solid rgba(33, 150, 243, 0.4); outline-offset: 2px; }
    .producto-item-action i { font-size: 23px; }
    .producto-item-action--beneficios { color: #1565c0; border-color: #bbdefb; background: #e3f2fd; }
    .producto-item-action--beneficios:hover { background: #bbdefb; }
    .producto-item-action--compartir { color: #2e7d32; border-color: #c8e6c9; background: #e8f5e9; }
    .producto-item-action--compartir:hover { background: #c8e6c9; }
    .w-100 { width: 100%; }
    /* Buscador de productos: dropdown propio (reemplaza M.Autocomplete de Materialize) para
       poder ocultar el codigo de barras del texto visible y agrandar la miniatura, que es lo
       que el equipo de sucursal pidio para identificar productos mas por la vista. */
    .producto-dropdown-wrap { position: relative; }
    .producto-dropdown {
        display: none;
        position: absolute;
        left: 0;
        right: 0;
        top: 100%;
        z-index: 1200;
        background: #fff;
        border: 1px solid #dde3e8;
        border-top: none;
        border-radius: 0 0 6px 6px;
        box-shadow: 0 6px 16px rgba(0,0,0,0.12);
        max-height: 420px;
        overflow-y: auto;
    }
    .producto-dropdown.abierto {
        display: grid;
        grid-template-columns: repeat(auto-fill, minmax(112px, 1fr));
        gap: 10px;
        padding: 12px;
    }
    .producto-dropdown .item {
        cursor: pointer;
        border: 1px solid #eceff1;
        border-radius: 8px;
        overflow: hidden;
        background: #fafbfc;
        text-align: center;
        transition: border-color 0.15s, background 0.15s;
    }
    .producto-dropdown .item:hover,
    .producto-dropdown .item.resaltado { border-color: #3949ab; background: #e8eaf9; }
    .producto-dropdown .item.sin-stock { opacity: 0.55; }
    .producto-dropdown .thumb {
        width: 100%;
        height: 88px;
        object-fit: cover;
        display: block;
        background: #f5f5f5;
    }
    .producto-dropdown .info { padding: 6px 8px 9px; }
    .producto-dropdown .nombre {
        font-size: 0.8rem;
        font-weight: 500;
        color: #263238;
        line-height: 1.25;
        display: -webkit-box;
        -webkit-line-clamp: 2;
        -webkit-box-orient: vertical;
        overflow: hidden;
    }
    .producto-dropdown .sub {
        font-size: 0.7rem;
        color: #78909c;
        margin-top: 2px;
        white-space: nowrap;
        overflow: hidden;
        text-overflow: ellipsis;
    }
    .producto-dropdown .vacio {
        grid-column: 1 / -1;
        padding: 14px;
        color: #90a4ae;
        font-size: 0.88rem;
        text-align: center;
    }
    .style-large { font-size: 4rem; opacity: 0.2; margin-top: 20px; }
    .animated { animation-duration: 0.5s; }
    @keyframes fadeIn { from { opacity: 0; transform: translateY(10px); } to { opacity: 1; transform: translateY(0); } }
    .fadeIn { animation-name: fadeIn; }
    .sales-qty-control { display: flex; align-items: center; gap: 8px; }
    .sales-qty-control input {
        flex: 0 0 48px;
        width: 48px;
        height: 44px !important;
        margin: 0 !important;
        padding: 0 4px;
        border: 1px solid #b0bec5 !important;
        border-radius: 4px;
        background: #fff;
        color: #263238;
        font-size: 1.05rem;
        font-weight: 700;
        line-height: 44px;
        text-align: center;
        box-sizing: border-box;
    }
    .sales-qty-control input:focus { border-color: #1976d2 !important; box-shadow: 0 0 0 2px rgba(25, 118, 210, 0.16) !important; }
    .sales-line-total { font-size: 1.15rem; font-weight: 700; color: #2e7d32; padding-top: 18px; }
    .delivery-address-preview {
        display: flex;
        align-items: flex-start;
        gap: 6px;
        margin-top: 8px;
        padding: 10px 12px;
        background: #f5f7fa;
        border: 1px solid #dde3e8;
        border-radius: 4px;
        color: #37474f;
        font-size: 0.92rem;
        line-height: 1.35;
    }
    .delivery-address-preview i { color: #607d8b; margin-top: 1px; }
    .delivery-address-preview.delivery-address-preview-missing { color: #b71c1c; background: #fdecea; border-color: #f5c6c3; }
    .delivery-address-preview.delivery-address-preview-missing i { color: #c62828; }
    .sales-customer-input-wrap {
        position: relative;
        min-height: 3rem;
        margin-left: 3rem;
        width: calc(100% - 3rem);
    }
    .sales-customer-input-wrap .selected-customer-chip-wrap {
        position: absolute;
        left: 4px;
        right: 4px;
        top: 50%;
        transform: translateY(-50%);
        z-index: 2;
        pointer-events: none;
    }
    .sales-customer-input-wrap.has-selection .cliente_nombre {
        color: transparent;
        caret-color: transparent;
    }
    .sales-customer-input-wrap.has-selection .cliente_nombre::placeholder {
        color: transparent;
    }
    .selected-customer-chip {
        display: inline-flex;
        align-items: center;
        gap: 8px;
        background: #e3f2fd;
        color: #0d47a1;
        border: 1px solid #90caf9;
        border-radius: 16px;
        padding: 4px 10px;
        font-size: 0.9rem;
        font-weight: 500;
        max-width: 100%;
        pointer-events: auto;
    }
    .selected-customer-chip > span,
    .selected-customer-chip-name {
        overflow: hidden;
        text-overflow: ellipsis;
        white-space: nowrap;
    }
    .selected-customer-chip-name {
        color: inherit;
        text-decoration: none;
        cursor: pointer;
    }
    .selected-customer-chip-name:hover,
    .selected-customer-chip-name:focus {
        text-decoration: underline;
    }
    .selected-customer-chip-remove {
        border: none;
        background: transparent;
        color: #0d47a1;
        cursor: pointer;
        line-height: 1;
        font-size: 1rem;
        font-weight: 700;
        padding: 0;
    }
</style>

<script>
    const productosDisponibles = <?php echo json_encode($productos, JSON_UNESCAPED_UNICODE); ?>;
    const PRODUCTO_PUBLICO_BASE_URL = <?php echo json_encode($productoPublicoBaseUrl, JSON_UNESCAPED_SLASHES); ?>;
    const clientesActivos = <?php echo json_encode($clientesActivos, JSON_UNESCAPED_UNICODE); ?>;
    const ID_ALMACEN_VENTA = <?php echo (int) $id_almacen_actual; ?>;
    const SALES_TABS_STORAGE_KEY = 'sales_tabs_draft_v4';
    let tabCount = 0;
    let productoIndex = 0;
    const customerMap = {};
    const customerAutocompleteData = {};
    const customerSearchIndex = [];
    const CAN_MANAGE_CUSTOMERS = <?php echo $canManageCustomers ? 'true' : 'false'; ?>;
    const MANAGE_CUSTOMERS_URL = '<?php echo BASE_URL; ?>views/manage_customers.php';
    const CUSTOMER_SUPPORT_HTML = CAN_MANAGE_CUSTOMERS
        ? `Busca un cliente existente. Si necesitas darlo de alta, hazlo en <a href="${MANAGE_CUSTOMERS_URL}" target="_blank" rel="noopener noreferrer">Administrar Clientes</a>.`
        : 'Busca un cliente existente. Si no aparece, solicita al administrador darlo de alta en Administrar Clientes.';
    const CUSTOMER_NO_ADDRESS_HTML = (customerId) => CAN_MANAGE_CUSTOMERS
        ? `Cliente existente seleccionado: #${customerId}. Este cliente no tiene direcciones guardadas. Agregalas en <a href="${MANAGE_CUSTOMERS_URL}" target="_blank" rel="noopener noreferrer">Administrar Clientes</a>.`
        : `Cliente existente seleccionado: #${customerId}. Este cliente no tiene direcciones guardadas. Solicita al administrador registrar sus direcciones.`;
    let salesDraftSaveTimer = null;
    let isRestoringDrafts = false;
    let pendingCloseVentaId = null;
    let modalLotesQueue = [];
    let modalLotesBusy = false;
    let resolverModalLotesActual = null;
    let googlePlacesReadySales = false;
    let pendingNewClienteContext = null;
    let pendingPhoneContext = null;
    let pendingPhoneClienteId = null;

    // footer.php llama M.AutoInit() en su propio DOMContentLoaded (que corre despues del de
    // esta vista) y reinicializa CUALQUIER .modal que ya se haya inicializado aqui, creando una
    // segunda instancia de M.Modal desincronizada sobre el mismo elemento -- el sintoma es que
    // cerrar/cancelar el modal deja de funcionar porque la instancia que este script sigue
    // usando ya no es la que el DOM tiene registrada. En vez de cachear la instancia una sola
    // vez en DOMContentLoaded, se resuelve con M.Modal.getInstance() al momento de usarla, que
    // para entonces ya corrio tanto este init como el AutoInit del footer y siempre apunta a la
    // instancia final vigente.
    function getModalInstance(elementId) {
        const node = document.getElementById(elementId);
        return node ? M.Modal.getInstance(node) : null;
    }

    function resolveProductImageSrc(rawImage) {
        if (!rawImage) return '../assets/img/no-product.png';
        const value = String(rawImage).trim();
        if (value === '') return '../assets/img/no-product.png';

        // Some environments don't render inline SVG placeholders consistently in autocomplete lists.
        if (value.startsWith('data:image/svg+xml')) {
            return '../assets/img/no-product.png';
        }

        if (value.startsWith('data:') || value.startsWith('http://') || value.startsWith('https://') || value.startsWith('/') || value.startsWith('../') || value.startsWith('./')) {
            return value;
        }

        const normalized = value.replace(/\\/g, '/');

        // Common case: DB stores file names or relative image paths.
        if (/\.(png|jpe?g|webp|gif|avif|svg)$/i.test(normalized)) {
            if (normalized.startsWith('assets/')) return `../${normalized}`;
            if (normalized.startsWith('uploads/')) return `../${normalized}`;
            if (normalized.startsWith('img/')) return `../assets/${normalized}`;
            if (normalized.startsWith('products/')) return `../assets/img/${normalized}`;
            return `../assets/img/products/${normalized}`;
        }

        // Only treat as base64 when it really looks like encoded binary content.
        const maybeBase64 = /^[A-Za-z0-9+/=\r\n]+$/.test(value) && value.length > 80;
        if (maybeBase64) {
            return `data:image/jpeg;base64,${value}`;
        }

        return '../assets/img/no-product.png';
    }

    // Buscador de productos: dropdown propio en cuadricula (reemplaza M.Autocomplete de
    // Materialize). El codigo de barras ya no se muestra en el texto -solo vive adentro para
    // el escaneo/busqueda- y la imagen es el elemento mas grande de cada tarjeta, que es lo
    // que pidio el equipo de sucursal para identificar productos mas por la vista.
    function initProductoDropdown(context, tabId, buscador) {
        const dropdownEl = context.querySelector('.producto-dropdown');
        if (!dropdownEl) return;

        let items = []; // [{ el, producto }] actualmente renderizados
        let highlightIndex = -1;

        function cerrar() {
            dropdownEl.classList.remove('abierto');
            dropdownEl.innerHTML = '';
            items = [];
            highlightIndex = -1;
        }

        function resaltar(index) {
            items.forEach((it) => it.el.classList.remove('resaltado'));
            if (index >= 0 && index < items.length) {
                items[index].el.classList.add('resaltado');
                items[index].el.scrollIntoView({ block: 'nearest' });
            }
            highlightIndex = index;
        }

        function seleccionar(producto) {
            agregarProductoALista(tabId, producto);
            buscador.value = '';
            cerrar();
            setTimeout(() => buscador.focus(), 100);
        }

        function render(query) {
            const normalizado = normalizeSearchTerm(query);
            if (normalizado === '') {
                cerrar();
                return;
            }

            const coincidencias = productosDisponibles.filter((p) => {
                const texto = normalizeSearchTerm(`${p.nombre} ${p.nombre_variante || ''} ${p.nombre_corto || ''} ${p.codigo_barras || ''}`);
                return texto.includes(normalizado);
            }).slice(0, 12);

            dropdownEl.innerHTML = '';
            highlightIndex = -1;

            if (coincidencias.length === 0) {
                dropdownEl.innerHTML = '<div class="vacio">Sin resultados</div>';
                dropdownEl.classList.add('abierto');
                items = [];
                return;
            }

            items = coincidencias.map((p) => {
                const imgSrc = resolveProductImageSrc(p.imagen_resuelta || p.imagen_fuente || p.imagen || p.imagen_url);
                const stockDisponible = parseInt(p.cantidad_actual || 0, 10) || 0;
                const el = document.createElement('div');
                el.className = 'item' + (stockDisponible <= 0 ? ' sin-stock' : '');
                el.title = p.nombre_variante ? `${p.nombre} - ${p.nombre_variante}` : p.nombre;
                el.innerHTML = `
                    <img class="thumb" src="${imgSrc}" alt="" loading="lazy">
                    <div class="info">
                        <div class="nombre">${escapeHtml(p.nombre)}</div>
                        ${p.nombre_corto ? `<div class="sub" style="font-weight:600;">${escapeHtml(p.nombre_corto)}</div>` : ''}
                        ${p.nombre_variante ? `<div class="sub">${escapeHtml(p.nombre_variante)}</div>` : ''}
                    </div>
                `;
                // mousedown (no click) para que dispare antes que el blur del input y la
                // seleccion se registre aunque el dropdown se este por cerrar.
                el.addEventListener('mousedown', (e) => {
                    e.preventDefault();
                    seleccionar(p);
                });
                dropdownEl.appendChild(el);
                return { el, producto: p };
            });

            dropdownEl.classList.add('abierto');
        }

        buscador.addEventListener('input', () => render(buscador.value));
        buscador.addEventListener('focus', () => {
            if (buscador.value.trim() !== '') render(buscador.value);
        });
        buscador.addEventListener('blur', () => {
            // Retraso corto: si el blur vino de un click en una tarjeta, el mousedown de arriba
            // ya disparo primero y la seleccion ya se registro antes de cerrar el dropdown.
            setTimeout(cerrar, 150);
        });

        buscador.addEventListener('keydown', function (e) {
            if (e.key === 'ArrowDown') {
                if (items.length === 0) return;
                e.preventDefault();
                resaltar(Math.min(highlightIndex + 1, items.length - 1));
                return;
            }
            if (e.key === 'ArrowUp') {
                if (items.length === 0) return;
                e.preventDefault();
                resaltar(Math.max(highlightIndex - 1, 0));
                return;
            }
            if (e.key === 'Escape') {
                cerrar();
                return;
            }
            if (e.key !== 'Enter' && e.key !== 'Tab') return;

            if (highlightIndex >= 0 && items[highlightIndex]) {
                e.preventDefault();
                seleccionar(items[highlightIndex].producto);
                return;
            }

            const value = this.value.trim();
            if (value === '') return;
            const valueLower = value.toLowerCase();

            // Coincidencia exacta por codigo de barras (lectura de escaner) o por nombre
            // completo tal cual se muestra hoy en la tarjeta.
            let prod = productosDisponibles.find((p) => p.codigo_barras && p.codigo_barras.toLowerCase() === valueLower);
            if (!prod) {
                prod = productosDisponibles.find((p) => {
                    const nombreCompleto = p.nombre_variante ? `${p.nombre} ${p.nombre_variante}` : p.nombre;
                    return nombreCompleto.toLowerCase() === valueLower;
                });
            }

            if (prod) {
                e.preventDefault();
                seleccionar(prod);
            } else if (e.key === 'Enter') {
                if (items.length > 0) {
                    e.preventDefault();
                    seleccionar(items[0].producto);
                } else {
                    M.toast({ html: 'Producto no encontrado', classes: 'orange' });
                }
            }
        });
    }

    function normalizeSearchTerm(value) {
        return String(value || '')
            .normalize('NFD')
            .replace(/[\u0300-\u036f]/g, '')
            .toLowerCase()
            .trim();
    }

    const ADDRESS_PLACEHOLDER_TEXTS = [
        'por confirmar', 'pendiente', 'pendiente de confirmar', 'sin direccion',
        'sin especificar', 'direccion pendiente', 'n/a', 'na', 'tbd', 'por definir',
    ];

    function isPlaceholderAddressText(value) {
        const normalized = normalizeSearchTerm(value);
        if (normalized === '') return false;
        return ADDRESS_PLACEHOLDER_TEXTS.includes(normalized);
    }

    function normalizePhoneDigits(value) {
        return String(value || '').replace(/\D+/g, '');
    }

    function registerCustomerOption(label, customerRecord) {
        const key = String(label || '').trim();
        if (key === '') return;
        customerAutocompleteData[key] = null;
        customerMap[key.toLowerCase()] = customerRecord;
    }

    function registerCustomerLookupAlias(label, customerRecord) {
        const key = String(label || '').trim();
        if (key === '') return;
        customerMap[key.toLowerCase()] = customerRecord;
    }

    function registerCustomerRecord(c) {
        const idCliente = parseInt(c.id_cliente || 0, 10) || 0;
        const nombre = String(c.nombre || '').trim();
        const telefono = String(c.telefono || '').trim();
        const direccion = String(c.direccion || '').trim();
        const mapsLink = String(c.maps_link || '').trim();
        const direcciones = Array.isArray(c.direcciones) ? c.direcciones : [];
        if (nombre === '') return null;

        // El buscador de Materialize solo muestra texto plano por item, asi que la marca de
        // "frecuente" se antepone al nombre en vez de un badge HTML.
        const nombreEtiquetado = c.es_frecuente ? `★ ${nombre}` : nombre;
        const label = telefono !== '' ? `${nombreEtiquetado} (${telefono})` : nombreEtiquetado;
        const customerRecord = { id_cliente: idCliente, nombre, telefono, direccion, maps_link: mapsLink, direcciones, label };
        registerCustomerOption(label, customerRecord);
        registerCustomerLookupAlias(nombre, customerRecord);
        if (telefono !== '') {
            registerCustomerLookupAlias(`${telefono} ${nombre}`, customerRecord);
            registerCustomerLookupAlias(`${nombre} ${telefono}`, customerRecord);
            const phoneDigits = normalizePhoneDigits(telefono);
            if (phoneDigits !== '') {
                registerCustomerLookupAlias(`${phoneDigits} ${nombre}`, customerRecord);
                registerCustomerLookupAlias(`${nombre} ${phoneDigits}`, customerRecord);
            }
        }

        customerSearchIndex.push({
            normalizedLabel: normalizeSearchTerm(label),
            normalizedName: normalizeSearchTerm(nombre),
            phoneDigits: normalizePhoneDigits(telefono),
            customer: customerRecord,
        });

        return customerRecord;
    }

    function findCustomerByInput(value) {
        const raw = String(value || '').trim();
        if (raw === '') return null;

        const key = raw.toLowerCase();
        if (customerMap[key]) {
            return customerMap[key];
        }

        const normalized = normalizeSearchTerm(raw);
        const digits = normalizePhoneDigits(raw);

        const exact = customerSearchIndex.filter((entry) => {
            return entry.normalizedLabel === normalized
                || entry.normalizedName === normalized
                || (digits !== '' && entry.phoneDigits === digits);
        });
        if (exact.length === 1) return exact[0].customer;
        if (exact.length > 1) return null;

        const partial = customerSearchIndex.filter((entry) => {
            return entry.normalizedLabel.includes(normalized)
                || entry.normalizedName.includes(normalized)
                || (digits !== '' && entry.phoneDigits.includes(digits));
        });

        return partial.length === 1 ? partial[0].customer : null;
    }

    function hasVentaData(context) {
        if (!context) return false;
        const hasItems = context.querySelectorAll('.producto-item').length > 0;
        const clienteNombre = (context.querySelector('.cliente_nombre')?.value || '').trim();
        const clienteTelefono = (context.querySelector('.cliente_telefono')?.value || '').trim();
        const direccionEntrega = (context.querySelector('.direccion_entrega')?.value || '').trim();
        const observaciones = (context.querySelector('.observaciones')?.value || '').trim();
        const hasDiscount = Array.from(context.querySelectorAll('.descuento-linea')).some((input) => (parseFloat(input.value || '0') || 0) > 0);
        return hasItems || clienteNombre !== '' || clienteTelefono !== '' || direccionEntrega !== '' || observaciones !== '' || hasDiscount;
    }

    function getCurrentSalesDraft() {
        const tabs = [];
        const contexts = Array.from(document.querySelectorAll('.venta-context'));
        contexts.forEach((context) => {
            const id = String(context.id || '').replace('venta-', '');
            if (!id) return;
            const productos = Array.from(context.querySelectorAll('.producto-item')).map((item) => ({
                id_producto: parseInt(item.dataset.id || '0', 10) || 0,
                cantidad: parseInt(item.querySelector('.cantidad')?.value || '0', 10) || 0,
                precio_unitario: parseFloat(item.querySelector('.precio-unitario')?.value || '0') || 0,
                descuento_linea: parseFloat(item.querySelector('.descuento-linea')?.value || '0') || 0,
            })).filter((p) => p.id_producto > 0 && p.cantidad > 0);

            tabs.push({
                id,
                tipo_entrega: getTipoEntrega(context),
                id_cliente: String(context.querySelector('.cliente_id')?.value || ''),
                cliente_nombre: (context.querySelector('.cliente_nombre')?.value || '').trim(),
                cliente_telefono: (context.querySelector('.cliente_telefono')?.value || '').trim(),
                customer_address_id: String(context.querySelector('.customer-address-select')?.value || ''),
                direccion_entrega: (context.querySelector('.direccion_entrega')?.value || '').trim(),
                maps_link_entrega: (context.querySelector('.maps_link_entrega')?.value || '').trim(),
                id_metodo_pago: String(context.querySelector('select[name="id_metodo_pago"]')?.value || ''),
                observaciones: (context.querySelector('.observaciones')?.value || '').trim(),
                productos,
            });
        });

        const activeTab = document.querySelector('#ventas-tabs .tab a.active');
        const activeHref = activeTab?.getAttribute('href') || '';
        const activeTabId = activeHref.startsWith('#venta-') ? activeHref.replace('#venta-', '') : null;

        return { version: 4, saved_at: Date.now(), active_tab_id: activeTabId, tabs };
    }

    function saveSalesDraftNow() {
        if (isRestoringDrafts) return;
        try {
            const draft = getCurrentSalesDraft();
            if (!draft.tabs || draft.tabs.length === 0) {
                localStorage.removeItem(SALES_TABS_STORAGE_KEY);
                return;
            }
            localStorage.setItem(SALES_TABS_STORAGE_KEY, JSON.stringify(draft));
        } catch (err) {
            console.warn('No se pudo guardar borrador de ventas:', err);
        }
    }

    function scheduleSalesDraftSave() {
        if (isRestoringDrafts) return;
        if (salesDraftSaveTimer) clearTimeout(salesDraftSaveTimer);
        salesDraftSaveTimer = setTimeout(saveSalesDraftNow, 250);
    }

    function clearSalesDraftIfEmpty() {
        if (document.querySelectorAll('.venta-context').length === 0) {
            try {
                localStorage.removeItem(SALES_TABS_STORAGE_KEY);
            } catch (err) {
                console.warn('No se pudo limpiar borrador de ventas:', err);
            }
        }
    }

    function restoreSalesDrafts() {
        let parsed;
        try {
            const raw = localStorage.getItem(SALES_TABS_STORAGE_KEY);
            if (!raw) return false;
            parsed = JSON.parse(raw);
        } catch (err) {
            console.warn('No se pudo leer borrador de ventas:', err);
            return false;
        }

        if (!parsed || !Array.isArray(parsed.tabs) || parsed.tabs.length === 0) {
            return false;
        }

        isRestoringDrafts = true;
        parsed.tabs.forEach((draftTab) => nuevaVenta(draftTab));
        isRestoringDrafts = false;

        const activeTabId = String(parsed.active_tab_id || '').trim();
        if (activeTabId !== '') {
            const tabsUl = document.getElementById('ventas-tabs');
            const tabsInstance = M.Tabs.getInstance(tabsUl);
            if (tabsInstance && document.getElementById(`venta-${activeTabId}`)) {
                tabsInstance.select(`venta-${activeTabId}`);
            }
        }

        scheduleSalesDraftSave();
        return true;
    }

    function openNuevoClienteModal(context) {
        const modalInstance = getModalInstance('modal-nuevo-cliente');
        if (!modalInstance) return;
        pendingNewClienteContext = context;

        const form = document.getElementById('form-nuevo-cliente');
        if (form) form.reset();
        const errorBox = document.getElementById('nuevo-cliente-error');
        if (errorBox) {
            errorBox.style.display = 'none';
            errorBox.textContent = '';
        }

        resetNuevoClienteDomicilio();

        modalInstance.open();
        M.updateTextFields();
        setTimeout(() => document.getElementById('nuevo-cliente-nombre')?.focus(), 200);
        // Google crea la lista de sugerencias mal posicionada si el campo aun esta oculto
        // (Materialize anima la apertura), asi que se espera a que el modal termine de abrir.
        setTimeout(() => intentarIniciarAutocompleteNuevoCliente(20), 400);
    }

    function resetNuevoClienteDomicilio() {
        const status = document.getElementById('nuevo-cliente-maps-status');
        if (status) {
            status.textContent = 'Sin ubicacion en mapa seleccionada aun.';
            status.classList.remove('green-text');
        }
        const mapsLink = document.getElementById('nuevo-cliente-maps-link');
        if (mapsLink) mapsLink.value = '';
        const direccion = document.getElementById('nuevo-cliente-direccion');
        if (direccion) M.textareaAutoResize(direccion);
    }

    let autocompleteNuevoClienteListo = false;

    // Buscador de direccion (Google Places) del modal "Nuevo cliente". Al elegir una sugerencia
    // llena la direccion exacta y guarda el link con las coordenadas, igual que Administrar Clientes.
    function intentarIniciarAutocompleteNuevoCliente(intentosRestantes) {
        if (autocompleteNuevoClienteListo) return;
        const input = document.getElementById('nuevo-cliente-dir-buscar');
        if (!input) return; // sin API key de Google no se dibuja el buscador; queda la captura manual
        if (typeof google === 'undefined' || !google.maps || !google.maps.places) {
            if (intentosRestantes > 0) setTimeout(() => intentarIniciarAutocompleteNuevoCliente(intentosRestantes - 1), 250);
            return;
        }

        const autocomplete = new google.maps.places.Autocomplete(input, {
            types: ['address'],
            componentRestrictions: { country: 'mx' },
        });
        autocomplete.addListener('place_changed', () => {
            const place = autocomplete.getPlace();
            if (!place || !place.geometry) return;

            const direccion = document.getElementById('nuevo-cliente-direccion');
            const mapsLink = document.getElementById('nuevo-cliente-maps-link');
            const status = document.getElementById('nuevo-cliente-maps-status');
            if (direccion) {
                direccion.value = place.formatted_address || direccion.value;
                M.textareaAutoResize(direccion);
            }
            if (mapsLink) {
                mapsLink.value = `https://www.google.com/maps/search/?api=1&query=${place.geometry.location.lat()},${place.geometry.location.lng()}`;
            }
            if (status) {
                status.textContent = 'Ubicacion en mapa capturada correctamente.';
                status.classList.add('green-text');
            }
            M.updateTextFields();
        });
        autocompleteNuevoClienteListo = true;
    }

    async function guardarNuevoCliente() {
        const btn = document.getElementById('btn-guardar-nuevo-cliente');
        const errorBox = document.getElementById('nuevo-cliente-error');
        const nombreInput = document.getElementById('nuevo-cliente-nombre');
        const telefonoInput = document.getElementById('nuevo-cliente-telefono');
        const emailInput = document.getElementById('nuevo-cliente-email');
        const csrfTokenInput = document.querySelector('#form-nuevo-cliente input[name="csrf_token"]');

        const nombre = (nombreInput?.value || '').trim();
        if (nombre === '') {
            if (errorBox) {
                errorBox.textContent = 'El nombre es obligatorio.';
                errorBox.style.display = 'block';
            }
            nombreInput?.focus();
            return;
        }

        // Un cliente nunca se da de alta sin telefono (10 digitos); el servidor tambien lo valida.
        if (((telefonoInput?.value || '').replace(/\D+/g, '')).length !== 10) {
            if (errorBox) {
                errorBox.textContent = 'El telefono es obligatorio y debe tener 10 digitos.';
                errorBox.style.display = 'block';
            }
            telefonoInput?.focus();
            return;
        }

        if (errorBox) errorBox.style.display = 'none';
        if (btn) {
            btn.classList.add('disabled');
            btn.textContent = 'Guardando...';
        }

        const formData = new FormData();
        formData.append('csrf_token', csrfTokenInput?.value || '');
        formData.append('nombre', nombre);
        formData.append('telefono', telefonoInput?.value || '');
        formData.append('email', emailInput?.value || '');
        // Domicilio opcional: el servidor lo guarda como direccion predeterminada del cliente nuevo.
        const direccionNueva = (document.getElementById('nuevo-cliente-direccion')?.value || '').trim();
        formData.append('direccion', direccionNueva);
        formData.append('direccion_alias', (document.getElementById('nuevo-cliente-dir-alias')?.value || '').trim());
        formData.append('maps_link', direccionNueva !== '' ? (document.getElementById('nuevo-cliente-maps-link')?.value || '') : '');

        try {
            const response = await fetch('<?php echo BASE_URL; ?>api/create_customer.php', { method: 'POST', body: formData });
            const data = await response.json();
            if (!data.success) {
                throw new Error(data.message || 'No se pudo crear el cliente.');
            }

            const customerRecord = registerCustomerRecord(data.cliente);
            clientesActivos.push(data.cliente);

            const context = pendingNewClienteContext;
            pendingNewClienteContext = null;
            getModalInstance('modal-nuevo-cliente')?.close();

            if (context && customerRecord) {
                const clienteTelefonoInput = context.querySelector('.cliente_telefono');
                const direccionEntregaInput = context.querySelector('.direccion_entrega');
                setSelectedCustomer(context, customerRecord);
                if (clienteTelefonoInput) clienteTelefonoInput.value = customerRecord.telefono || clienteTelefonoInput.value || '';
                if (direccionEntregaInput && (!direccionEntregaInput.value || direccionEntregaInput.value.trim() === '')) {
                    direccionEntregaInput.value = customerRecord.direccion || '';
                }
                renderCustomerAddressOptions(context, customerRecord);
                const tabId = String(context.id || '').replace('venta-', '');
                actualizarTituloTab(tabId, customerRecord.nombre);
                M.updateTextFields();
            }

            M.toast({
                html: direccionNueva !== '' ? 'Cliente creado con su domicilio y seleccionado.' : 'Cliente creado y seleccionado.',
                classes: 'green darken-1',
            });
        } catch (err) {
            if (errorBox) {
                errorBox.textContent = err.message || 'No se pudo crear el cliente.';
                errorBox.style.display = 'block';
            }
        } finally {
            if (btn) {
                btn.classList.remove('disabled');
                btn.textContent = 'Crear cliente';
            }
        }
    }

    function openAgregarTelefonoModal(context, clienteId, clienteNombre) {
        const modalInstance = getModalInstance('modal-agregar-telefono');
        if (!modalInstance || !clienteId) return;
        pendingPhoneContext = context;
        pendingPhoneClienteId = clienteId;

        const nombreEl = document.getElementById('agregar-telefono-cliente-nombre');
        if (nombreEl) nombreEl.textContent = clienteNombre ? `Cliente: ${clienteNombre}` : '';
        const input = document.getElementById('agregar-telefono-input');
        if (input) input.value = '';
        const errorBox = document.getElementById('agregar-telefono-error');
        if (errorBox) {
            errorBox.style.display = 'none';
            errorBox.textContent = '';
        }

        modalInstance.open();
        M.updateTextFields();
        setTimeout(() => document.getElementById('agregar-telefono-input')?.focus(), 200);
    }

    async function guardarTelefonoCliente() {
        const btn = document.getElementById('btn-guardar-agregar-telefono');
        const errorBox = document.getElementById('agregar-telefono-error');
        const input = document.getElementById('agregar-telefono-input');
        const csrfTokenInput = document.querySelector('#form-agregar-telefono input[name="csrf_token"]');

        if (!pendingPhoneClienteId) return;

        const telefono = (input?.value || '').trim();
        if (telefono === '') {
            if (errorBox) {
                errorBox.textContent = 'Captura el telefono.';
                errorBox.style.display = 'block';
            }
            input?.focus();
            return;
        }

        if (errorBox) errorBox.style.display = 'none';
        if (btn) {
            btn.classList.add('disabled');
            btn.textContent = 'Guardando...';
        }

        const formData = new FormData();
        formData.append('csrf_token', csrfTokenInput?.value || '');
        formData.append('id_cliente', pendingPhoneClienteId);
        formData.append('telefono', telefono);

        try {
            const response = await fetch('<?php echo BASE_URL; ?>api/update_customer_phone.php', { method: 'POST', body: formData });
            const data = await response.json();
            if (!data.success) {
                throw new Error(data.message || 'No se pudo guardar el telefono.');
            }

            const idx = clientesActivos.findIndex((c) => String(c.id_cliente) === String(data.cliente.id_cliente));
            if (idx >= 0) {
                clientesActivos[idx].telefono = data.cliente.telefono;
            }
            const customerRecord = registerCustomerRecord(idx >= 0 ? clientesActivos[idx] : data.cliente);

            const context = pendingPhoneContext;
            pendingPhoneContext = null;
            pendingPhoneClienteId = null;
            getModalInstance('modal-agregar-telefono')?.close();

            if (context && customerRecord) {
                setSelectedCustomer(context, customerRecord);
                M.updateTextFields();
            }

            M.toast({ html: 'Telefono guardado.', classes: 'green darken-1' });
        } catch (err) {
            if (errorBox) {
                errorBox.textContent = err.message || 'No se pudo guardar el telefono.';
                errorBox.style.display = 'block';
            }
        } finally {
            if (btn) {
                btn.classList.remove('disabled');
                btn.textContent = 'Guardar';
            }
        }
    }

    function setSelectedCustomer(context, cliente, overrideFields = true) {
        if (!context || !cliente) return;
        const clienteIdInput = context.querySelector('.cliente_id');
        const clienteNombreInput = context.querySelector('.cliente_nombre');
        const clienteTelefonoInput = context.querySelector('.cliente_telefono');
        const direccionEntregaInput = context.querySelector('.direccion_entrega');
        const mapsLinkEntregaInput = context.querySelector('.maps_link_entrega');

        if (clienteIdInput) clienteIdInput.value = String(cliente.id_cliente || '');
        if (clienteNombreInput) {
            if (overrideFields) clienteNombreInput.value = String(cliente.nombre || '');
            clienteNombreInput.dataset.selectedClientName = String(cliente.nombre || '');
            clienteNombreInput.readOnly = true;
        }
        if (clienteTelefonoInput && overrideFields) clienteTelefonoInput.value = String(cliente.telefono || '');
        if (direccionEntregaInput && overrideFields && (!direccionEntregaInput.value || direccionEntregaInput.value.trim() === '')) {
            direccionEntregaInput.value = String(cliente.direccion || '');
        }
        if (mapsLinkEntregaInput && overrideFields && (!mapsLinkEntregaInput.value || mapsLinkEntregaInput.value.trim() === '')) {
            mapsLinkEntregaInput.value = String(cliente.maps_link || '');
        }
        context.dataset.customerMapsLink = String(cliente.maps_link || '');
        context.dataset.customerAddress = String(cliente.direccion || '');
        context.dataset.selectedCustomerId = String(cliente.id_cliente || '');
        renderSelectedCustomerChip(context, cliente);
        renderCustomerAddressOptions(context, cliente);
        updateDeliveryMapLink(context);
        updateMissingPhoneAlert(context, cliente);
    }

    function updateMissingPhoneAlert(context, cliente) {
        if (!context) return;
        const alertBox = context.querySelector('.cliente-sin-telefono-alert');
        if (!alertBox) return;
        const clienteTelefonoInput = context.querySelector('.cliente_telefono');
        const clienteId = cliente && cliente.id_cliente ? String(cliente.id_cliente) : '';
        const hasPhone = !!(clienteTelefonoInput && clienteTelefonoInput.value.trim() !== '');

        if (hasPhone || clienteId === '') {
            alertBox.style.display = 'none';
            return;
        }

        const link = alertBox.querySelector('.cliente-editar-telefono-link');
        if (link) {
            link.style.display = CAN_MANAGE_CUSTOMERS ? '' : 'none';
        }
        alertBox.style.display = 'block';
    }

    function clearSelectedCustomer(context, wipeFields = false) {
        if (!context) return;
        const clienteIdInput = context.querySelector('.cliente_id');
        const clienteNombreInput = context.querySelector('.cliente_nombre');
        const clienteTelefonoInput = context.querySelector('.cliente_telefono');
        const direccionEntregaInput = context.querySelector('.direccion_entrega');
        const mapsLinkEntregaInput = context.querySelector('.maps_link_entrega');
        const statusNode = context.querySelector('.selected-client-status');

        if (clienteIdInput) clienteIdInput.value = '';
        if (clienteNombreInput) {
            clienteNombreInput.dataset.selectedClientName = '';
            clienteNombreInput.readOnly = false;
            if (wipeFields) clienteNombreInput.value = '';
        }
        if (clienteTelefonoInput && wipeFields) clienteTelefonoInput.value = '';
        if (direccionEntregaInput && wipeFields) direccionEntregaInput.value = '';
        if (mapsLinkEntregaInput && wipeFields) mapsLinkEntregaInput.value = '';
        context.dataset.customerMapsLink = '';
        context.dataset.customerAddress = '';
        context.dataset.selectedCustomerId = '';
        if (statusNode) statusNode.textContent = '';
        renderSelectedCustomerChip(context, null);
        renderCustomerAddressOptions(context, null);
        updateDeliveryMapLink(context);
        updateMissingPhoneAlert(context, null);
    }

    function renderSelectedCustomerChip(context, cliente) {
        if (!context) return;
        const wrap = context.querySelector('.selected-customer-chip-wrap');
        const fieldWrap = context.querySelector('.sales-customer-input-wrap');
        if (!wrap) return;

        if (!cliente || !cliente.id_cliente) {
            wrap.innerHTML = '';
            if (fieldWrap) fieldWrap.classList.remove('has-selection');
            return;
        }

        const chipLabel = escapeHtml(`${cliente.nombre || 'Cliente'}${cliente.telefono ? ` (${cliente.telefono})` : ''}`);
        const idCliente = parseInt(cliente.id_cliente, 10) || 0;
        const nameHtml = (CAN_MANAGE_CUSTOMERS && idCliente > 0)
            ? `<a href="${MANAGE_CUSTOMERS_URL}?id_cliente=${idCliente}" target="_blank" rel="noopener noreferrer" class="selected-customer-chip-name" title="Editar este cliente en Administrar Clientes">${chipLabel}</a>`
            : `<span>${chipLabel}</span>`;
        wrap.innerHTML = `
            <span class="selected-customer-chip" title="Cliente seleccionado">
                ${nameHtml}
                <button type="button" class="selected-customer-chip-remove" aria-label="Quitar cliente seleccionado">&times;</button>
            </span>
        `;

        const removeBtn = wrap.querySelector('.selected-customer-chip-remove');
        removeBtn?.addEventListener('click', () => {
            clearSelectedCustomer(context, true);
            const clienteInput = context.querySelector('.cliente_nombre');
            if (clienteInput) {
                clienteInput.focus();
            }
        });

        if (fieldWrap) fieldWrap.classList.add('has-selection');
    }

    function parseCoordsFromMapsLink(rawLink) {
        const value = String(rawLink || '').trim();
        if (value === '') return null;

        const queryMatch = value.match(/[?&]query=(-?\d+(?:\.\d+)?),(-?\d+(?:\.\d+)?)/i);
        if (queryMatch) {
            const lat = Number(queryMatch[1]);
            const lng = Number(queryMatch[2]);
            if (!Number.isNaN(lat) && !Number.isNaN(lng)) return { lat, lng };
        }

        const atMatch = value.match(/@(-?\d+(?:\.\d+)?),(-?\d+(?:\.\d+)?)/i);
        if (atMatch) {
            const lat = Number(atMatch[1]);
            const lng = Number(atMatch[2]);
            if (!Number.isNaN(lat) && !Number.isNaN(lng)) return { lat, lng };
        }

        return null;
    }

    // Rumbo (0-360) para que la camara del Street View mire desde la calle hacia la casa.
    function headingEntrePuntos(desde, hacia) {
        const rad = (grados) => grados * Math.PI / 180;
        const dLng = rad(hacia.lng - desde.lng);
        const y = Math.sin(dLng) * Math.cos(rad(hacia.lat));
        const x = Math.cos(rad(desde.lat)) * Math.sin(rad(hacia.lat)) - Math.sin(rad(desde.lat)) * Math.cos(rad(hacia.lat)) * Math.cos(dLng);
        return (Math.atan2(y, x) * 180 / Math.PI + 360) % 360;
    }

    // Vista real de la calle/fachada (Google Street View) del punto del domicilio. Se consulta solo
    // cuando cambian las coordenadas: updateDeliveryMapLink corre con cada tecla que se escribe en
    // la direccion y cada consulta de Street View se factura.
    function updateSalesStreetView(context, coords) {
        const svEl = context.querySelector('.sales-streetview-preview');
        const msgEl = context.querySelector('.sales-streetview-msg');
        if (!svEl || !msgEl || typeof google === 'undefined' || !google.maps) return;

        const key = coords.lat + ',' + coords.lng;
        if (context.__salesStreetViewKey === key) {
            if (context.__salesPanorama) google.maps.event.trigger(context.__salesPanorama, 'resize');
            return;
        }
        context.__salesStreetViewKey = key;
        // Domicilio nuevo: en tactil se vuelve a tapar el panorama para no atrapar el scroll.
        context.querySelector('.sales-streetview-shield')?.classList.remove('is-hidden');
        const consulta =(context.__salesStreetViewQuery || 0) + 1;
        context.__salesStreetViewQuery = consulta;

        const mostrarMensaje = (texto) => {
            msgEl.textContent = texto;
            msgEl.classList.remove('is-hidden');
        };
        mostrarMensaje('Buscando vista de la calle...');

        if (!context.__salesStreetViewService) context.__salesStreetViewService = new google.maps.StreetViewService();
        context.__salesStreetViewService.getPanorama({
            location: coords,
            radius: 60, // metros a la redonda: el pin cae en la casa y el panorama esta sobre la calle
            source: google.maps.StreetViewSource.OUTDOOR,
        }, (data, status) => {
            if (consulta !== context.__salesStreetViewQuery) return; // llego tarde: ya se pidio otro punto
            if (status !== google.maps.StreetViewStatus.OK || !data || !data.location || !data.location.latLng) {
                mostrarMensaje('Google no tiene vista de la calle para este punto.');
                return;
            }

            if (!context.__salesPanorama) {
                context.__salesPanorama = new google.maps.StreetViewPanorama(svEl, {
                    addressControl: false,
                    linksControl: false,
                    panControl: false,
                    enableCloseButton: false,
                    fullscreenControl: true,
                    zoomControl: true,
                });
            }
            const panorama = context.__salesPanorama;
            const desde = { lat: data.location.latLng.lat(), lng: data.location.latLng.lng() };
            panorama.setPano(data.location.pano);
            panorama.setPov({ heading: headingEntrePuntos(desde, coords), pitch: 5 });
            panorama.setVisible(true);
            msgEl.classList.add('is-hidden');
            setTimeout(() => google.maps.event.trigger(panorama, 'resize'), 80);
        });
    }

    function updateSalesMapPreviewFromMapsLink(context, mapsLink) {
        if (!context) return;
        const mapEl = context.querySelector('.sales-map-preview');
        const rowEl = context.querySelector('.sales-map-row');
        if (!mapEl || !rowEl) return;

        const coords = parseCoordsFromMapsLink(mapsLink);
        if (!coords) {
            rowEl.classList.remove('is-visible');
            return;
        }

        if (typeof google === 'undefined' || !google.maps) return;

        // La fila se muestra antes de crear el mapa y el panorama: con display:none miden 0 de alto.
        rowEl.classList.add('is-visible');

        if (!context.__salesMapInstance) {
            context.__salesMapInstance = new google.maps.Map(mapEl, {
                center: coords,
                zoom: 15,
                disableDefaultUI: true,
                zoomControl: true,
            });
            context.__salesMapMarker = new google.maps.Marker({ map: context.__salesMapInstance, position: coords });
        } else {
            context.__salesMapInstance.setCenter(coords);
            context.__salesMapInstance.setZoom(15);
            if (context.__salesMapMarker) context.__salesMapMarker.setPosition(coords);
        }

        setTimeout(() => {
            if (context.__salesMapInstance && typeof google !== 'undefined' && google.maps && google.maps.event) {
                google.maps.event.trigger(context.__salesMapInstance, 'resize');
                context.__salesMapInstance.setCenter(coords);
            }
        }, 80);

        updateSalesStreetView(context, coords);
    }

    // Un toque en la capa transparente activa el panorama (la fila vive dentro de un <template>
    // que se clona por pestana, por eso el listener es delegado).
    document.addEventListener('click', (event) => {
        const shield = event.target.closest('.sales-streetview-shield');
        if (shield) shield.classList.add('is-hidden');
    });

    function initAutocompleteSales() {
        googlePlacesReadySales = typeof google !== 'undefined' && !!google.maps;
        if (!googlePlacesReadySales) {
            console.error('Google Maps no pudo cargarse para ventas. Revisa la API key.');
            return;
        }

        document.querySelectorAll('.venta-context').forEach((context) => {
            const mapsInput = context.querySelector('.maps_link_entrega');
            if (mapsInput && mapsInput.value.trim() !== '') {
                updateSalesMapPreviewFromMapsLink(context, mapsInput.value);
            }
        });
    }

    function escapeHtml(value) {
        return String(value || '')
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;')
            .replace(/'/g, '&#39;');
    }

    function getCustomerSavedAddresses(cliente) {
        if (!cliente || !Array.isArray(cliente.direcciones)) return [];
        return cliente.direcciones.map((direccion) => ({
            id_direccion: parseInt(direccion.id_direccion || 0, 10) || 0,
            alias: String(direccion.alias || '').trim(),
            direccion: String(direccion.direccion || '').trim(),
            maps_link: String(direccion.maps_link || '').trim(),
            es_default: !!direccion.es_default,
        })).filter((direccion) => direccion.id_direccion > 0 && direccion.direccion !== '');
    }

    function renderCustomerAddressOptions(context, cliente, preferredValue = '') {
        if (!context) return;
        const block = context.querySelector('.customer-address-block');
        const select = context.querySelector('.customer-address-select');
        if (!block || !select) return;

        const addresses = getCustomerSavedAddresses(cliente);
        context.__customerAddresses = addresses;

        if (!cliente || !cliente.id_cliente || addresses.length === 0) {
            block.style.display = cliente && cliente.id_cliente ? 'block' : 'none';
            select.innerHTML = '<option value="">-- Sin direcciones guardadas --</option>';
            const direccionInput = context.querySelector('.direccion_entrega');
            const mapsInput = context.querySelector('.maps_link_entrega');
            if (direccionInput && (!cliente || !cliente.id_cliente)) direccionInput.value = '';
            if (mapsInput && (!cliente || !cliente.id_cliente)) mapsInput.value = '';
            context.dataset.addressMode = 'none';
            const statusNode = context.querySelector('.selected-client-status');
            if (statusNode && cliente && cliente.id_cliente) {
                statusNode.innerHTML = CUSTOMER_NO_ADDRESS_HTML(cliente.id_cliente);
            }
            updateDeliveryMapLink(context);
            return;
        }

        const options = ['<option value="">-- Selecciona un domicilio --</option>'];
        addresses.forEach((direccion) => {
            const suffix = direccion.es_default ? ' (Predeterminada)' : '';
            const esPlaceholder = isPlaceholderAddressText(direccion.direccion);
            // Sin alias, usamos la direccion real como etiqueta en vez de un ID generico sin sentido.
            let label = direccion.alias
                ? `${direccion.alias}${suffix}: ${direccion.direccion}`
                : `${direccion.direccion}${suffix}`;
            if (esPlaceholder) {
                label = `⚠ Sin direccion capturada (${label})`;
            }
            options.push(`<option value="${direccion.id_direccion}">${escapeHtml(label)}</option>`);
        });
        select.innerHTML = options.join('');
        block.style.display = 'block';

        if (preferredValue !== '') {
            select.value = preferredValue;
        }
        if (!select.value) {
            const selectedDefault = addresses.find((direccion) => direccion.es_default);
            select.value = selectedDefault ? String(selectedDefault.id_direccion) : String(addresses[0].id_direccion || '');
        }

        const selectedAddress = addresses.find((direccion) => String(direccion.id_direccion) === String(select.value));
        context.dataset.addressMode = selectedAddress ? 'saved' : 'none';
        if (selectedAddress) {
            const direccionInput = context.querySelector('.direccion_entrega');
            const mapsInput = context.querySelector('.maps_link_entrega');
            if (direccionInput) {
                direccionInput.value = selectedAddress.direccion || '';
            }
            if (mapsInput) mapsInput.value = selectedAddress.maps_link || '';
            context.dataset.customerMapsLink = String(selectedAddress.maps_link || '');
            context.dataset.customerAddress = String(selectedAddress.direccion || '');
        }
        updateDeliveryMapLink(context);
        M.updateTextFields();
    }

    function updateDeliveryAddressPreview(context, direccionActual) {
        const preview = context.querySelector('.delivery-address-preview');
        const previewText = context.querySelector('.delivery-address-preview-text');
        if (!preview || !previewText) return;

        if (direccionActual === '') {
            const selectedCustomerId = String(context.dataset.selectedCustomerId || '').trim();
            if (selectedCustomerId !== '') {
                preview.style.display = 'flex';
                preview.classList.add('delivery-address-preview-missing');
                previewText.innerHTML = CAN_MANAGE_CUSTOMERS
                    ? `Este cliente no tiene una direccion valida. Agregala en <a href="${MANAGE_CUSTOMERS_URL}" target="_blank" rel="noopener noreferrer">Administrar Clientes</a>.`
                    : 'Este cliente no tiene una direccion valida. Solicita al administrador que la registre en Administrar Clientes.';
                return;
            }
            preview.style.display = 'none';
            previewText.textContent = '';
            preview.classList.remove('delivery-address-preview-missing');
            return;
        }

        preview.style.display = 'flex';
        if (isPlaceholderAddressText(direccionActual)) {
            preview.classList.add('delivery-address-preview-missing');
            previewText.innerHTML = CAN_MANAGE_CUSTOMERS
                ? `El domicilio guardado dice "${escapeHtml(direccionActual)}": no es una direccion real. Corrigela en <a href="${MANAGE_CUSTOMERS_URL}" target="_blank" rel="noopener noreferrer">Administrar Clientes</a> antes de agendar.`
                : `El domicilio guardado dice "${escapeHtml(direccionActual)}": no es una direccion real. Solicita al administrador que la corrija en Administrar Clientes antes de agendar.`;
            return;
        }
        preview.classList.remove('delivery-address-preview-missing');
        previewText.textContent = direccionActual;
    }

    function updateDeliveryMapLink(context) {
        if (!context) return;

        const wrapper = context.querySelector('.delivery-map-link');
        const anchor = context.querySelector('.delivery-map-link-anchor');
        const text = context.querySelector('.delivery-map-link-text');
        const direccionActual = String(context.querySelector('.direccion_entrega')?.value || '').trim();
        const manualMapsLink = String(context.querySelector('.maps_link_entrega')?.value || '').trim();
        const savedMapsLink = String(context.dataset.customerMapsLink || '').trim();
        const savedAddress = String(context.dataset.customerAddress || '').trim();

        updateDeliveryAddressPreview(context, direccionActual);

        if (!wrapper || !anchor || !text) return;

        let href = '';
        let label = 'Abrir ubicación';

        if (manualMapsLink !== '') {
            href = manualMapsLink;
            label = 'Abrir link capturado';
        } else if (savedMapsLink !== '' && (direccionActual === '' || savedAddress === '' || direccionActual === savedAddress)) {
            href = savedMapsLink;
            label = 'Abrir ubicación guardada';
        } else if (direccionActual !== '') {
            href = 'https://www.google.com/maps/dir/?api=1&destination=' + encodeURIComponent(direccionActual);
            label = savedMapsLink !== '' ? 'Abrir ruta con dirección actual' : 'Abrir dirección en Google Maps';
        }

        if (href === '') {
            wrapper.style.display = 'none';
            anchor.setAttribute('href', '#');
            text.textContent = 'Abrir ubicación';
            return;
        }

        anchor.setAttribute('href', href);
        text.textContent = label;
        wrapper.style.display = 'block';

        const mapsLinkActual = String(context.querySelector('.maps_link_entrega')?.value || '').trim();
        updateSalesMapPreviewFromMapsLink(context, mapsLinkActual || href);
    }

    const prevenirCierre = (e) => {
        const contexts = Array.from(document.querySelectorAll('.venta-context'));
        if (contexts.some((ctx) => hasVentaData(ctx))) {
            e.preventDefault();
            e.returnValue = '';
        }
    };

    document.addEventListener('DOMContentLoaded', () => {

        clientesActivos.forEach((c) => registerCustomerRecord(c));

        M.FormSelect.init(document.querySelectorAll('select'));
        const closeModalNode = document.getElementById('modal-cerrar-venta');
        if (closeModalNode) M.Modal.init(closeModalNode, { dismissible: true });

        document.getElementById('btn-confirmar-cerrar-venta')?.addEventListener('click', () => {
            if (!pendingCloseVentaId) return;
            const targetId = pendingCloseVentaId;
            pendingCloseVentaId = null;
            getModalInstance('modal-cerrar-venta')?.close();
            ejecutarCierreVenta(targetId);
        });

        const nuevoClienteModalNode = document.getElementById('modal-nuevo-cliente');
        if (nuevoClienteModalNode) M.Modal.init(nuevoClienteModalNode, { dismissible: true });
        document.getElementById('btn-guardar-nuevo-cliente')?.addEventListener('click', guardarNuevoCliente);

        const agregarTelefonoModalNode = document.getElementById('modal-agregar-telefono');
        if (agregarTelefonoModalNode) M.Modal.init(agregarTelefonoModalNode, { dismissible: true });
        document.getElementById('btn-guardar-agregar-telefono')?.addEventListener('click', guardarTelefonoCliente);
        document.getElementById('btn-cancelar-agregar-telefono')?.addEventListener('click', (e) => {
            e.preventDefault();
            pendingPhoneContext = null;
            pendingPhoneClienteId = null;
            getModalInstance('modal-agregar-telefono')?.close();
        });

        const verificarLotesModalNode = document.getElementById('modal-verificar-lotes');
        if (verificarLotesModalNode) M.Modal.init(verificarLotesModalNode, { dismissible: false });
        // Hay una sola instancia del modal compartida entre todas las pestanas de venta
        // (SALES_TABS_STORAGE_KEY permite varias abiertas a la vez); resolverModalLotesActual
        // siempre apunta a la confirmacion pendiente que esta VISIBLE ahora mismo.
        // mostrarModalVerificarLotes() encola las demas en vez de pisar esta, para que
        // confirmar/cancelar nunca dispare la venta de otra pestana por error.
        document.getElementById('btn-cancelar-verificar-lotes')?.addEventListener('click', () => {
            resolverModalLotesActual?.(false);
        });
        document.getElementById('btn-confirmar-lotes')?.addEventListener('click', () => {
            const btn = document.getElementById('btn-confirmar-lotes');
            if (btn.classList.contains('disabled') || !resolverModalLotesActual) return;
            getModalInstance('modal-verificar-lotes')?.close();
            resolverModalLotesActual(true);
        });

        window.addEventListener('beforeunload', prevenirCierre);
        const ventaContainers = document.getElementById('ventas-containers');
        ventaContainers.addEventListener('input', scheduleSalesDraftSave);
        ventaContainers.addEventListener('change', scheduleSalesDraftSave);

        const hayBorradores = restoreSalesDrafts();
        if (!hayBorradores) {
            nuevaVenta();
        }
        preseleccionarClienteDesdeUrl();
    });

    // Llegar con ?id_cliente=NN (p.ej. desde Administrar Clientes, justo despues de crear un cliente y
    // elegir "agendar venta") abre la venta con ese cliente ya seleccionado, con su telefono y su
    // domicilio predeterminado. Si ya habia una venta EN CURSO (con datos) NO se toca: se abre una pestana nueva. Un
    // borrador VACIO no es una venta en curso (basta haber abierto Ventas antes para que quede guardado): se reutiliza su
    // pestana en vez de abrir otra y dejar la primera vacia.
    function preseleccionarClienteDesdeUrl() {
        const idPreset = parseInt(new URLSearchParams(window.location.search).get('id_cliente') || '0', 10) || 0;
        if (idPreset <= 0) return;

        // Se limpia la URL: al recargar la pagina no debe abrir otra pestana con el mismo cliente.
        try {
            window.history.replaceState(null, '', window.location.pathname);
        } catch (err) {
            // Sin History API la pestana extra al recargar es el unico costo.
        }

        const cliente = clientesActivos.find((c) => (parseInt(c.id_cliente, 10) || 0) === idPreset);
        if (!cliente) {
            M.toast({ html: 'No se encontro al cliente para la venta (revisa que este activo y sea de tu sucursal).', classes: 'orange darken-2' });
            return;
        }

        const contextos = Array.from(document.querySelectorAll('.venta-context'));
        let idTab;
        let context;
        if (contextos.some((ctx) => hasVentaData(ctx))) {
            nuevaVenta();
            idTab = 'v' + tabCount;
            context = document.getElementById('venta-' + idTab);
        } else {
            context = contextos[0] || null;
            idTab = context ? String(context.id || '').replace('venta-', '') : '';
            const tabsInstance = M.Tabs.getInstance(document.getElementById('ventas-tabs'));
            if (tabsInstance && context) tabsInstance.select('venta-' + idTab);
        }
        if (!context) return;

        setSelectedCustomer(context, {
            id_cliente: idPreset,
            nombre: String(cliente.nombre || ''),
            telefono: String(cliente.telefono || ''),
            direccion: String(cliente.direccion || ''),
            maps_link: String(cliente.maps_link || ''),
            direcciones: Array.isArray(cliente.direcciones) ? cliente.direcciones : [],
        });
        actualizarTituloTab(idTab, String(cliente.nombre || ''));
        M.updateTextFields();
        scheduleSalesDraftSave();
    }

    function nuevaVenta(draftTab = null) {
        let id = '';
        if (draftTab && typeof draftTab.id === 'string' && /^v\d+$/i.test(draftTab.id)) {
            id = draftTab.id.toLowerCase();
            const parsedNum = parseInt(id.substring(1), 10);
            if (Number.isInteger(parsedNum) && parsedNum > tabCount) {
                tabCount = parsedNum;
            }
        } else {
            tabCount++;
            id = 'v' + tabCount;
        }

        const colorIdx = (tabCount - 1) % 4;
        const tabsUl = document.getElementById('ventas-tabs');
        const li = document.createElement('li');
        li.id = `tab-li-${id}`;
        li.className = `tab tab-color-${colorIdx}`;
        li.innerHTML = `<a href="#venta-${id}"><span class="tab-title">Pedido ${tabCount}</span><i class="material-icons close-tab" onclick="cerrarVenta('${id}', event)">close</i></a>`;
        tabsUl.appendChild(li);

        const containers = document.getElementById('ventas-containers');
        const template = document.getElementById('venta-template').innerHTML;
        containers.insertAdjacentHTML('beforeend', template.replace(/{{id}}/g, id));

        const context = document.getElementById(`venta-${id}`);
        let tabsInstance = null;
        try {
            tabsInstance = M.Tabs.getInstance(tabsUl);
            if (tabsInstance) tabsInstance.destroy();
            tabsInstance = M.Tabs.init(tabsUl);
        } catch (err) {
            console.warn('No se pudo inicializar Tabs en ventas:', err);
        }

        M.FormSelect.init(context.querySelectorAll('select'));
        M.updateTextFields();
        clearSelectedCustomer(context, false);

        const buscador = context.querySelector('.buscador-producto');
        const clienteNombreInput = context.querySelector('.cliente_nombre');
        const clienteIdInput = context.querySelector('.cliente_id');
        const clienteTelefonoInput = context.querySelector('.cliente_telefono');
        const customerAddressSelect = context.querySelector('.customer-address-select');
        const direccionEntregaInput = context.querySelector('.direccion_entrega');
        const mapsLinkEntregaInput = context.querySelector('.maps_link_entrega');

        context.querySelector('.btn-nuevo-cliente-trigger')?.addEventListener('click', (e) => {
            e.preventDefault();
            openNuevoClienteModal(context);
        });

        context.querySelector('.cliente-editar-telefono-link')?.addEventListener('click', (e) => {
            e.preventDefault();
            const clienteId = String(clienteIdInput?.value || '').trim();
            if (clienteId === '') return;
            openAgregarTelefonoModal(context, clienteId, clienteNombreInput?.value || '');
        });

        clienteTelefonoInput?.addEventListener('input', () => {
            const clienteId = String(clienteIdInput?.value || '').trim();
            updateMissingPhoneAlert(context, clienteId !== '' ? { id_cliente: clienteId } : null);
        });

        direccionEntregaInput?.addEventListener('input', () => updateDeliveryMapLink(context));
        mapsLinkEntregaInput?.addEventListener('input', () => {
            updateDeliveryMapLink(context);
            updateSalesMapPreviewFromMapsLink(context, mapsLinkEntregaInput.value || '');
        });
        customerAddressSelect?.addEventListener('change', () => {
            const addresses = Array.isArray(context.__customerAddresses) ? context.__customerAddresses : [];
            const selectedAddress = addresses.find((direccion) => String(direccion.id_direccion) === String(customerAddressSelect.value));
            renderCustomerAddressOptions(context, customerMap[String(clienteNombreInput?.value || '').toLowerCase()] || null, String(customerAddressSelect.value || ''));
            if (!selectedAddress) {
                M.toast({ html: 'Selecciona una direccion valida del cliente.', classes: 'orange' });
            }
        });

        if (clienteNombreInput) {
            try {
                M.Autocomplete.init(clienteNombreInput, {
                    data: customerAutocompleteData,
                    limit: 8,
                    minLength: 1,
                    dropdownOptions: {
                        container: document.body
                    },
                    onAutocomplete: function(val) {
                        const cliente = customerMap[String(val || '').toLowerCase()] || findCustomerByInput(val);
                        if (!cliente) return;
                        setSelectedCustomer(context, cliente);
                        if (clienteTelefonoInput) clienteTelefonoInput.value = cliente.telefono || clienteTelefonoInput.value || '';
                        if (direccionEntregaInput && (!direccionEntregaInput.value || direccionEntregaInput.value.trim() === '')) {
                            direccionEntregaInput.value = cliente.direccion || '';
                        }
                        renderCustomerAddressOptions(context, cliente);
                        actualizarTituloTab(id, cliente.nombre);
                        M.updateTextFields();
                    }
                });
            } catch (err) {
                console.warn('No se pudo inicializar autocomplete de cliente:', err);
            }

            const resolveTypedCustomer = () => {
                const resolved = findCustomerByInput(clienteNombreInput.value);
                if (!resolved) return;
                setSelectedCustomer(context, resolved);
                if (clienteTelefonoInput) clienteTelefonoInput.value = resolved.telefono || clienteTelefonoInput.value || '';
                if (direccionEntregaInput && (!direccionEntregaInput.value || direccionEntregaInput.value.trim() === '')) {
                    direccionEntregaInput.value = resolved.direccion || '';
                }
                renderCustomerAddressOptions(context, resolved);
                actualizarTituloTab(id, resolved.nombre);
                M.updateTextFields();
            };

            clienteNombreInput.addEventListener('change', resolveTypedCustomer);
            clienteNombreInput.addEventListener('blur', resolveTypedCustomer);
            clienteNombreInput.addEventListener('keydown', (e) => {
                if (e.key !== 'Enter' && e.key !== 'Tab') return;
                const resolved = findCustomerByInput(clienteNombreInput.value);
                if (!resolved) return;
                e.preventDefault();
                resolveTypedCustomer();
            });

            clienteNombreInput.addEventListener('input', () => {
                const selectedId = String(clienteIdInput?.value || '').trim();
                const selectedName = String(clienteNombreInput.dataset.selectedClientName || '').trim();
                if (selectedId !== '' && selectedName !== '' && clienteNombreInput.value.trim() !== selectedName) {
                    clearSelectedCustomer(context, false);
                }
            });
        }

        initProductoDropdown(context, id, buscador);

        context.querySelectorAll('.tipo-entrega-radio').forEach((radio) => {
            radio.addEventListener('change', () => aplicarModoEntrega(context));
        });

        context.querySelector('.formulario-venta').addEventListener('submit', (e) => procesarVenta(e, id));

        if (draftTab && typeof draftTab === 'object') {
            if (draftTab.tipo_entrega === 'Sucursal') {
                const sucursalRadio = context.querySelector('.tipo-entrega-radio[value="Sucursal"]');
                if (sucursalRadio) sucursalRadio.checked = true;
            }
            if (clienteIdInput) clienteIdInput.value = String(draftTab.id_cliente || '');
            if (clienteNombreInput) clienteNombreInput.value = String(draftTab.cliente_nombre || '');
            if (clienteTelefonoInput) clienteTelefonoInput.value = String(draftTab.cliente_telefono || '');
            if (customerAddressSelect) customerAddressSelect.value = String(draftTab.customer_address_id || '');
            if (direccionEntregaInput) direccionEntregaInput.value = String(draftTab.direccion_entrega || '');
            if (mapsLinkEntregaInput) mapsLinkEntregaInput.value = String(draftTab.maps_link_entrega || '');

            const observacionesInput = context.querySelector('.observaciones');
            if (observacionesInput) observacionesInput.value = String(draftTab.observaciones || '');

            const metodoPagoSelect = context.querySelector('select[name="id_metodo_pago"]');
            if (metodoPagoSelect) {
                metodoPagoSelect.value = String(draftTab.id_metodo_pago || '');
                M.FormSelect.init(metodoPagoSelect);
            }

            if (Array.isArray(draftTab.productos)) {
                draftTab.productos.forEach((prodDraft) => {
                    const product = productosDisponibles.find((p) => String(p.id_producto) === String(prodDraft.id_producto));
                    if (!product) return;
                    agregarProductoALista(id, product, { silent: true });
                    const itemNode = context.querySelector(`.producto-item[data-id="${product.id_producto}"]`);
                    if (!itemNode) return;
                    const qtyInput = itemNode.querySelector('.cantidad');
                    const priceInput = itemNode.querySelector('.precio-unitario');
                    const discountInput = itemNode.querySelector('.descuento-linea');
                    const stockDisponible = parseInt(product.cantidad_actual || 0, 10) || 0;
                    if (qtyInput) {
                        const draftQty = parseInt(prodDraft.cantidad || 1, 10) || 1;
                        qtyInput.value = String(Math.max(1, Math.min(draftQty, stockDisponible > 0 ? stockDisponible : draftQty)));
                    }
                    if (priceInput) priceInput.value = String(parseFloat(prodDraft.precio_unitario || product.precio_venta || 0) || 0);
                    if (discountInput) discountInput.value = String(parseFloat(prodDraft.descuento_linea || 0) || 0);
                });
            }

            if (clienteIdInput && clienteIdInput.value !== '') {
                const selected = clientesActivos.find((c) => String(c.id_cliente) === String(clienteIdInput.value));
                if (selected) {
                    setSelectedCustomer(context, {
                        id_cliente: parseInt(selected.id_cliente, 10) || 0,
                        nombre: String(selected.nombre || ''),
                        telefono: String(selected.telefono || ''),
                        direccion: String(selected.direccion || ''),
                        maps_link: String(selected.maps_link || ''),
                        direcciones: Array.isArray(selected.direcciones) ? selected.direcciones : [],
                    }, false);
                    renderCustomerAddressOptions(context, selected, String(draftTab.customer_address_id || ''));
                }
            }

            M.updateTextFields();
            actualizarTotal(id);
            actualizarTituloTab(id, clienteNombreInput ? clienteNombreInput.value : '');
        }

        updateDeliveryMapLink(context);
        aplicarModoEntrega(context);

        if (tabsInstance) tabsInstance.select(`venta-${id}`);
        // Foco directo (sin setTimeout) para que el navegador aun lo cuente como parte
        // del gesto del usuario que abrio la pestaña y abra el teclado solo en moviles.
        clienteNombreInput?.focus();
        scheduleSalesDraftSave();
    }

    function actualizarTituloTab(id, nombre = '') {
        const tabTitle = document.querySelector(`#tab-li-${id} .tab-title`);
        if (tabTitle) tabTitle.textContent = nombre.trim() !== '' ? nombre.substring(0, 15) : `Pedido ${id.substring(1)}`;
    }

    // "En sucursal": el cliente ya esta en el mostrador, no hay entrega ni domicilio.
    // Ocultamos todo lo de ruta/direccion y ajustamos textos; el resto del flujo
    // (buscador, descuentos, inventario) es identico. El servidor recibe tipo_entrega.
    function getTipoEntrega(context) {
        return context?.querySelector('.tipo-entrega-radio:checked')?.value === 'Sucursal' ? 'Sucursal' : 'Domicilio';
    }

    function aplicarModoEntrega(context) {
        if (!context) return;
        const esSucursal = getTipoEntrega(context) === 'Sucursal';
        context.dataset.tipoEntrega = esSucursal ? 'Sucursal' : 'Domicilio';

        context.querySelectorAll('.delivery-only-row').forEach((row) => {
            row.style.display = esSucursal ? 'none' : '';
        });

        const hint = context.querySelector('.tipo-entrega-hint');
        if (hint) {
            hint.textContent = esSucursal
                ? 'Venta cobrada en el mostrador. No se pide domicilio y el inventario se descuenta al registrar.'
                : 'El pedido se agenda para reparto: el cliente debe tener un domicilio valido.';
        }

        const telHelper = context.querySelector('.telefono-helper-text');
        if (telHelper) {
            telHelper.textContent = esSucursal
                ? 'Opcional para venta en sucursal.'
                : 'Obligatorio para la entrega a domicilio.';
        }

        const titulo = context.querySelector('.resumen-titulo');
        if (titulo) titulo.textContent = esSucursal ? 'Resumen de la Venta' : 'Resumen del Pedido';

        const cobro = context.querySelector('.resumen-cobro-text');
        if (cobro) {
            cobro.textContent = esSucursal
                ? 'Venta cobrada en sucursal al registrarla.'
                : 'El cobro se realizará al momento de la entrega por el repartidor asignado.';
        }

        const btn = context.querySelector('.btn-enviar-venta');
        if (btn && !btn.disabled) {
            btn.innerHTML = esSucursal
                ? 'Registrar Venta <i class="material-icons right">point_of_sale</i>'
                : 'Agendar Pedido <i class="material-icons right">local_shipping</i>';
        }

        if (esSucursal) {
            // No arrastramos una direccion de un cliente elegido antes de cambiar a mostrador.
            const dir = context.querySelector('.direccion_entrega');
            if (dir) dir.value = '';
            const addrSelect = context.querySelector('.customer-address-select');
            if (addrSelect) addrSelect.value = '';
        }

        scheduleSalesDraftSave();
    }

    function abrirModalCerrarVenta(id) {
        pendingCloseVentaId = id;
        const modalInstance = getModalInstance('modal-cerrar-venta');
        if (modalInstance) {
            modalInstance.open();
            return;
        }
        if (confirm('Esta pestaña tiene datos del pedido. Si la cierras, perderás esa información. ¿Deseas continuar?')) {
            const targetId = pendingCloseVentaId;
            pendingCloseVentaId = null;
            if (targetId) ejecutarCierreVenta(targetId);
        } else {
            pendingCloseVentaId = null;
        }
    }

    function cerrarVenta(id, event) {
        if (event) event.stopPropagation();
        const context = document.getElementById(`venta-${id}`);
        if (hasVentaData(context)) {
            abrirModalCerrarVenta(id);
            return;
        }
        ejecutarCierreVenta(id);
    }

    function ejecutarCierreVenta(id) {
        const context = document.getElementById(`venta-${id}`);
        if (!context) return;
        const tabLi = document.getElementById(`tab-li-${id}`);
        if (tabLi) tabLi.remove();
        // El buscador de productos ya no usa M.Autocomplete (ver initProductoDropdown); su
        // dropdown es un div propio que se va con context.remove() de abajo, sin limpieza aparte.
        const autoCustomer = M.Autocomplete.getInstance(context.querySelector('.cliente_nombre'));
        if (autoCustomer) autoCustomer.destroy();
        context.remove();

        const tabsUl = document.getElementById('ventas-tabs');
        let tabsInstance = M.Tabs.getInstance(tabsUl);
        if (tabsInstance) tabsInstance.destroy();
        if (tabsUl.children.length === 0) {
            nuevaVenta();
        } else {
            M.Tabs.init(tabsUl);
        }

        clearSalesDraftIfEmpty();
        scheduleSalesDraftSave();
    }

    // "Ver beneficios": referencia interna (nunca prometas curas/diagnosticos, solo lenguaje
    // de bienestar orientativo) para que admin/encargado/vendedor puedan contestarle al
    // cliente si pregunta -- mismos datos que ya usa Alex, ver core/ai_assistant.php.
    function verBeneficiosProducto(idProducto) {
        const product = productosDisponibles.find((p) => String(p.id_producto) === String(idProducto));
        const modal = document.getElementById('modal-ver-beneficios');
        if (!product || !modal) return;

        const label = product.nombre_variante ? `${product.nombre} - ${product.nombre_variante}` : product.nombre;
        modal.querySelector('.beneficios-producto-nombre').textContent = label;

        const beneficios = String(product.beneficios || '').trim();
        const perfilRecomendado = String(product.perfil_recomendado || '').trim();

        const listaEl = modal.querySelector('.beneficios-producto-lista');
        const perfilWrap = modal.querySelector('.beneficios-producto-perfil');
        const perfilTexto = modal.querySelector('.beneficios-producto-perfil-texto');
        const vacioEl = modal.querySelector('.beneficios-producto-vacio');

        if (beneficios !== '') {
            const chips = beneficios.split(',').map((b) => b.trim()).filter(Boolean)
                .map((b) => `<span class="chip" style="background:#e8f5e9; color:#2e7d32;">${escHtmlBeneficios(b)}</span>`)
                .join('');
            listaEl.innerHTML = chips;
            listaEl.style.display = '';
        } else {
            listaEl.innerHTML = '';
            listaEl.style.display = 'none';
        }

        if (perfilRecomendado !== '') {
            perfilTexto.textContent = perfilRecomendado;
            perfilWrap.style.display = '';
        } else {
            perfilWrap.style.display = 'none';
        }

        vacioEl.style.display = (beneficios === '' && perfilRecomendado === '') ? '' : 'none';

        const modalInstance = M.Modal.getInstance(modal) || M.Modal.init(modal);
        modalInstance.open();
    }

    // Comparte el producto desde el carrito, donde el vendedor ya esta atendiendo al cliente.
    // El mensaje usa solo texto comercial y la ficha publica; beneficios y perfil son referencia interna.
    function compartirProducto(idProducto) {
        const product = productosDisponibles.find((p) => String(p.id_producto) === String(idProducto));
        const modal = document.getElementById('modal-compartir-producto');
        if (!product || !modal) return;

        const nombreCompleto = product.nombre_variante ? `${product.nombre} - ${product.nombre_variante}` : product.nombre;
        const precio = parseFloat(product.precio_venta || 0).toFixed(2);
        const urlPublica = PRODUCTO_PUBLICO_BASE_URL + encodeURIComponent(product.id_producto);
        const textoVenta = String(product.texto_compartir || '').trim();

        let mensaje = `*${nombreCompleto}*\n$${precio}\n`;
        if (textoVenta !== '') mensaje += `\n${textoVenta}\n`;
        mensaje += `\n${urlPublica}`;

        modal.querySelector('.compartir-producto-nombre').textContent = nombreCompleto;
        modal.querySelector('.compartir-producto-preview').textContent = mensaje;

        const linkWhatsapp = modal.querySelector('.compartir-link-whatsapp');
        const linkCorreo = modal.querySelector('.compartir-link-correo');
        const linkFacebook = modal.querySelector('.compartir-link-facebook');
        if (linkWhatsapp) linkWhatsapp.href = `https://wa.me/?text=${encodeURIComponent(mensaje)}`;
        if (linkCorreo) linkCorreo.href = `mailto:?subject=${encodeURIComponent(nombreCompleto)}&body=${encodeURIComponent(mensaje)}`;
        if (linkFacebook) linkFacebook.href = `https://www.facebook.com/sharer/sharer.php?u=${encodeURIComponent(urlPublica)}`;

        const modalInstance = M.Modal.getInstance(modal) || M.Modal.init(modal);
        modalInstance.open();
    }

    function escHtmlBeneficios(txt) {
        return String(txt || '').replace(/[&<>"']/g, (c) => ({
            '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;'
        })[c]);
    }

    function agregarProductoALista(tabId, product, options = {}) {
        const silent = !!options.silent;
        const context = document.getElementById(`venta-${tabId}`);
        context.querySelector('.sin-productos').style.display = 'none';
        const stockDisponible = parseInt(product.cantidad_actual || 0, 10) || 0;

        const existente = context.querySelector(`.producto-item[data-id="${product.id_producto}"]`);
        if (existente) {
            const cantInput = existente.querySelector('.cantidad');
            const nuevaCant = (parseInt(cantInput.value || '0', 10) || 0) + 1;
            if (stockDisponible > 0 && nuevaCant > stockDisponible) {
                if (!silent) M.toast({ html: `No hay más stock disponible de ${product.nombre} (${stockDisponible} max)`, classes: 'red' });
                return;
            }
            cantInput.value = String(nuevaCant);
            actualizarTotal(tabId);
            if (!silent) M.toast({ html: `+1 ${product.nombre}`, classes: 'blue lighten-3' });
            return;
        }

        if (stockDisponible <= 0) {
            if (!silent) M.toast({ html: `${product.nombre} está agotado en esta sucursal`, classes: 'red darken-2' });
            return;
        }

        const label = product.nombre_variante ? `${product.nombre} - ${product.nombre_variante}` : product.nombre;
        const imgSrc = resolveProductImageSrc(product.imagen_resuelta || product.imagen_fuente || product.imagen || product.imagen_url);
        const html = `
            <div class="producto-item animated fadeIn" data-id="${product.id_producto}">
                <input type="hidden" name="producto_${productoIndex}" value="${product.id_producto}">
                <div class="producto-item-layout">
                    <div class="producto-item-media">
                        <img src="${imgSrc}" class="materialboxed" style="border-radius: 4px;">
                    </div>
                    <div class="producto-item-info">
                        <p style="margin: 0; font-weight: bold; font-size: 1.1rem;">${label}
                            <span class="producto-item-actions">
                                <button type="button" class="producto-item-action producto-item-action--beneficios" onclick="verBeneficiosProducto(${product.id_producto});" title="Ver beneficios" aria-label="Ver beneficios del producto"><i class="material-icons">info</i></button>
                                <button type="button" class="producto-item-action producto-item-action--compartir" onclick="compartirProducto(${product.id_producto});" title="Compartir producto" aria-label="Compartir producto"><i class="material-icons">share</i></button>
                            </span>
                        </p>
                        <small class="grey-text">Cod: ${product.codigo_barras || 'N/A'}</small>
                    </div>
                    <div class="producto-item-fields">
                        <div class="producto-item-field-row">
                            <span class="producto-item-field-label">Cant.</span>
                            <div class="sales-qty-control">
                                <button type="button" class="btn-small grey lighten-1 black-text waves-effect" onclick="decrementarCantidad(this, '${tabId}')">-</button>
                                <input type="number" class="cantidad" name="cantidad_${productoIndex}" value="1" min="1" max="${stockDisponible}" oninput="actualizarTotal('${tabId}')">
                                <button type="button" class="btn-small grey lighten-1 black-text waves-effect" onclick="incrementarCantidad(this, '${tabId}')">+</button>
                            </div>
                        </div>
                        <div class="producto-item-field-row">
                            <span class="producto-item-field-label">Precio Unit.${product.en_oferta ? ' <span style="background:#e53935;color:#fff;border-radius:3px;padding:0 5px;font-size:0.75em;" title="Precio normal: $' + Number(product.precio_normal).toFixed(2) + '">OFERTA</span>' : ''}</span>
                            <input type="number" class="precio-unitario producto-item-field-input" name="precio_${productoIndex}" value="${product.precio_venta}" min="0.01" step="0.01" oninput="actualizarTotal('${tabId}')">
                        </div>
                        <div class="producto-item-field-row">
                            <span class="producto-item-field-label">Desc. $</span>
                            <input type="number" class="descuento-linea producto-item-field-input producto-item-field-input--desc" name="descuento_linea_${productoIndex}" value="0" min="0" step="0.01" oninput="actualizarTotal('${tabId}')">
                        </div>
                    </div>
                    <div class="producto-item-totals">
                        <div class="sales-line-total">$<span class="line-subtotal">0.00</span></div>
                        <button type="button" class="btn-floating btn-small waves-effect waves-light red" onclick="eliminarProducto(this, '${tabId}')"><i class="material-icons">delete</i></button>
                    </div>
                </div>
            </div>
        `;
        context.querySelector('.carrito-items').insertAdjacentHTML('afterbegin', html);
        try {
            M.Materialbox.init(context.querySelectorAll('.materialboxed'));
        } catch (err) {
            console.warn('No se pudo inicializar materialbox:', err);
        }

        productoIndex++;
        actualizarTotal(tabId);
        if (!silent) M.toast({ html: `Agregado: ${product.nombre}`, classes: 'green' });
    }

    function incrementarCantidad(btn, tabId) {
        const item = btn.closest('.producto-item');
        if (!item) return;
        const input = item.querySelector('.cantidad');
        const max = parseInt(input?.getAttribute('max') || '0', 10) || 0;
        const current = parseInt(input?.value || '0', 10) || 0;
        if (max > 0 && current >= max) {
            M.toast({ html: 'No hay más stock disponible para este producto.', classes: 'orange' });
            return;
        }
        input.value = String(current + 1);
        actualizarTotal(tabId);
    }

    function decrementarCantidad(btn, tabId) {
        const item = btn.closest('.producto-item');
        if (!item) return;
        const input = item.querySelector('.cantidad');
        const current = parseInt(input?.value || '0', 10) || 0;
        if (current <= 1) {
            eliminarProducto(btn, tabId);
            return;
        }
        input.value = String(current - 1);
        actualizarTotal(tabId);
    }

    function actualizarTotal(tabId) {
        const context = document.getElementById(`venta-${tabId}`);
        let subtotal = 0;
        let descuentoManual = 0;
        context.querySelectorAll('.producto-item').forEach((item) => {
            const id = item.dataset.id;
            const prodData = productosDisponibles.find((p) => p.id_producto == id);
            const stockMax = parseInt(prodData?.cantidad_actual || 0, 10) || 0;

            let cantidad = parseInt(item.querySelector('.cantidad').value, 10) || 0;
            if (stockMax > 0 && cantidad > stockMax) {
                M.toast({ html: `Stock superado para ${prodData.nombre}. Ajustando a ${stockMax}`, classes: 'orange' });
                cantidad = stockMax;
                item.querySelector('.cantidad').value = String(cantidad);
            }
            if (cantidad < 1) {
                cantidad = 1;
                item.querySelector('.cantidad').value = '1';
            }

            const precio = parseFloat(item.querySelector('.precio-unitario').value) || 0;
            const descuentoInput = item.querySelector('.descuento-linea');
            const subtotalBase = precio * cantidad;
            let descuentoLinea = parseFloat(descuentoInput?.value || '0') || 0;

            if (descuentoLinea < 0) {
                descuentoLinea = 0;
                if (descuentoInput) descuentoInput.value = '0';
            }
            if (descuentoLinea > subtotalBase) {
                descuentoLinea = subtotalBase;
                if (descuentoInput) descuentoInput.value = subtotalBase.toFixed(2);
                M.toast({ html: 'El descuento no puede superar el subtotal del producto.', classes: 'orange' });
            }

            subtotal += subtotalBase;
            descuentoManual += descuentoLinea;
            const subtotalLinea = Math.max(0, subtotalBase - descuentoLinea);
            const lineSubtotal = item.querySelector('.line-subtotal');
            if (lineSubtotal) lineSubtotal.textContent = subtotalLinea.toFixed(2);
        });

        const total = Math.max(0, subtotal - descuentoManual);
        context.querySelector('.subtotal-val').textContent = subtotal.toFixed(2);
        context.querySelector('.descuento-total-val').textContent = descuentoManual.toFixed(2);
        context.querySelector('.total-venta-val').textContent = total.toFixed(2);

        actualizarTituloTab(tabId, context.querySelector('.cliente_nombre').value);
        scheduleSalesDraftSave();
    }

    function eliminarProducto(btn, tabId) {
        const context = document.getElementById(`venta-${tabId}`);
        btn.closest('.producto-item').remove();
        if (context.querySelectorAll('.producto-item').length === 0) {
            context.querySelector('.sin-productos').style.display = 'block';
        }
        actualizarTotal(tabId);
        scheduleSalesDraftSave();
    }

    function procesarVenta(e, tabId) {
        e.preventDefault();
        const context = document.getElementById(`venta-${tabId}`);
        if (context.querySelectorAll('.producto-item').length === 0) {
            M.toast({ html: 'Debes agregar al menos un producto', classes: 'red darken-2' });
            return;
        }

        const esSucursal = getTipoEntrega(context) === 'Sucursal';
        const clienteId = String(context.querySelector('.cliente_id')?.value || '').trim();
        const telefonoCliente = (context.querySelector('.cliente_telefono')?.value || '').trim();
        const customerAddressId = String(context.querySelector('.customer-address-select')?.value || '').trim();
        const direccionEntrega = (context.querySelector('.direccion_entrega')?.value || '').trim();

        // En venta de sucursal el cliente esta presente: no exigimos cliente registrado
        // (si no se elige, el servidor la guarda como venta de mostrador sin cliente),
        // ni telefono, ni domicilio.
        if (!esSucursal) {
            if (clienteId === '') {
                M.toast({ html: 'Selecciona un cliente existente.', classes: 'red darken-2' });
                return;
            }
            if (telefonoCliente === '') {
                M.toast({ html: 'Captura el telefono del cliente para continuar.', classes: 'red darken-2' });
                return;
            }
            if (!/^\d+$/.test(customerAddressId) && direccionEntrega === '') {
                M.toast({ html: 'Selecciona una direccion guardada. Si el cliente no tiene, agregala en Administrar Clientes.', classes: 'red darken-2' });
                return;
            }
            if (isPlaceholderAddressText(direccionEntrega)) {
                M.toast({ html: 'El domicilio guardado no tiene una direccion real ("Por confirmar"). Actualizala en Administrar Clientes antes de agendar.', classes: 'red darken-2' });
                return;
            }
        }

        const form = e.target;
        const submitButton = form.querySelector('button[type="submit"]');
        const labelBoton = esSucursal
            ? 'Registrar Venta <i class="material-icons right">point_of_sale</i>'
            : 'Agendar Pedido <i class="material-icons right">local_shipping</i>';

        const productosCarrito = Array.from(context.querySelectorAll('.producto-item')).map((item) => ({
            id_producto: parseInt(item.dataset.id || '0', 10) || 0,
            cantidad: parseInt(item.querySelector('.cantidad')?.value || '0', 10) || 0,
        })).filter((p) => p.id_producto > 0 && p.cantidad > 0);

        const csrfToken = form.querySelector('[name="csrf_token"]')?.value || '';
        const enviar = (confirmoLotes) => enviarVentaAlServidor(form, context, tabId, submitButton, labelBoton, confirmoLotes);

        // Antes de cobrar: consulta que lote(s) le tocaria a cada producto (FEFO) y, si
        // hay algo que verificar, se lo pide al cajero antes de continuar. Si la consulta
        // falla no se bloquea la venta -- solo no se muestra el aviso (el servidor vuelve a
        // calcular lo mismo al cobrar y, si hay algo que verificar, rechaza la venta sin
        // confirmar_lotes=1 -- ver api/ventas.php).
        fetch(form.action, {
            method: 'POST',
            body: new URLSearchParams({
                modo: 'plan_lotes',
                csrf_token: csrfToken,
                id_almacen: String(ID_ALMACEN_VENTA || 0),
                items: JSON.stringify(productosCarrito),
            }),
        })
            .then((response) => response.json())
            .then((planData) => {
                const plan = (planData && planData.success && Array.isArray(planData.data)) ? planData.data : [];
                if (plan.length === 0) {
                    enviar(false);
                    return;
                }
                mostrarModalVerificarLotes(plan).then((confirmado) => {
                    if (confirmado) enviar(true);
                });
            })
            .catch((error) => {
                console.error('No se pudo obtener el plan de lotes para verificar:', error);
                enviar(false);
            });
    }

    // Banner por cada pedido a domicilio recien agendado: no se va solo (ni al recargar, que pasa
    // al cerrar la ultima pestaña) hasta que lo tocas; al tocarlo te lleva a Asignar Entregas con
    // ese pedido resaltado. Se guarda en localStorage de este navegador.
    const PEDIDOS_POR_ASIGNAR_KEY = 'ventas_pedidos_por_asignar';

    function leerPedidosPorAsignar() {
        try {
            const lista = JSON.parse(localStorage.getItem(PEDIDOS_POR_ASIGNAR_KEY) || '[]');
            return Array.isArray(lista) ? lista : [];
        } catch (_) {
            return [];
        }
    }

    function guardarPedidosPorAsignar(lista) {
        try { localStorage.setItem(PEDIDOS_POR_ASIGNAR_KEY, JSON.stringify(lista)); } catch (_) {}
    }

    function agregarPedidoPorAsignar(idPedido, numeroPedido) {
        const lista = leerPedidosPorAsignar().filter((p) => p.id !== idPedido);
        lista.push({ id: idPedido, numero: String(numeroPedido || idPedido) });
        guardarPedidosPorAsignar(lista);
        pintarPedidosPorAsignar(lista);
    }

    function pintarPedidosPorAsignar(lista) {
        const cont = document.getElementById('ventas-por-asignar');
        if (!cont) return;
        cont.innerHTML = '';
        lista.forEach((p) => {
            const a = document.createElement('a');
            a.href = <?php echo json_encode(BASE_URL . 'views/asignar_entregas.php?pedido=', JSON_UNESCAPED_SLASHES); ?> + encodeURIComponent(p.id);
            a.className = 'card-panel orange darken-2 white-text';
            a.style.cssText = 'display:flex; align-items:center; gap:10px; padding:12px 16px; margin:0 0 8px; font-weight:500;';
            a.innerHTML = '<i class="material-icons">local_shipping</i><span></span><i class="material-icons" style="margin-left:auto;">chevron_right</i>';
            a.querySelector('span').textContent = `Pedido ${p.numero} agendado sin repartidor. Toca aquí para asignarlo.`;
            a.addEventListener('click', () => {
                guardarPedidosPorAsignar(leerPedidosPorAsignar().filter((x) => x.id !== p.id));
            });
            cont.appendChild(a);
        });
    }

    document.addEventListener('DOMContentLoaded', () => pintarPedidosPorAsignar(leerPedidosPorAsignar()));

    function enviarVentaAlServidor(form, context, tabId, submitButton, labelBoton, confirmoLotes) {
        submitButton.disabled = true;
        submitButton.innerHTML = 'Procesando...';

        const formData = new FormData(form);
        if (confirmoLotes) formData.set('confirmar_lotes', '1');

        fetch(form.action, { method: 'POST', body: formData })
            .then((response) => response.json())
            .then((data) => {
                if (data.success) {
                    M.toast({ html: data.message || 'Venta registrada con éxito', classes: 'green darken-2' });
                    if (data.tipo_entrega === 'Domicilio' && data.id_pedido) {
                        agregarPedidoPorAsignar(data.id_pedido, data.numero_pedido);
                    }
                    document.getElementById(`tab-li-${tabId}`).remove();
                    context.remove();
                    saveSalesDraftNow();
                    if (document.querySelectorAll('.tab').length === 0) location.reload();
                } else {
                    M.toast({ html: data.message || 'Error al procesar el pedido', classes: 'red darken-2' });
                    submitButton.disabled = false;
                    submitButton.innerHTML = labelBoton;
                }
            })
            .catch((error) => {
                console.error(error);
                submitButton.disabled = false;
                submitButton.innerHTML = labelBoton;
            });
    }

    /**
     * Modal previo al cobro: por cada producto con lote(s) activos, muestra de cual
     * codigo/fecha debe salir (segun FEFO) y exige un checkbox por producto antes de
     * habilitar "Confirmo, cobrar". Devuelve una Promise<boolean> (true si confirmo,
     * false si cancelo). Hay una sola instancia de este modal compartida entre todas
     * las pestanas de venta abiertas, asi que las llamadas se encolan (modalLotesQueue)
     * en vez de pisar una confirmacion ya visible -- sin esto, confirmar el modal de
     * una pestana podia terminar cobrando la venta de otra.
     */
    function mostrarModalVerificarLotes(plan) {
        return new Promise((resolve) => {
            modalLotesQueue.push({ plan, resolve });
            procesarSiguienteModalLotes();
        });
    }

    function procesarSiguienteModalLotes() {
        if (modalLotesBusy || modalLotesQueue.length === 0) return;
        const { plan, resolve } = modalLotesQueue.shift();
        modalLotesBusy = true;
        resolverModalLotesActual = (confirmado) => {
            resolverModalLotesActual = null;
            modalLotesBusy = false;
            resolve(confirmado);
            procesarSiguienteModalLotes();
        };

        const lista = document.getElementById('verificar-lotes-lista');
        if (!lista) { resolverModalLotesActual(true); return; }

        lista.innerHTML = plan.map((p, idx) => {
            const filas = (p.asignaciones || []).map((a) =>
                `<li>${a.cantidad} pza(s) del lote <strong>${escapeHtml(a.codigo_lote || '(sin código)')}</strong> — caduca ${escapeHtml(a.fecha_caducidad || '?')}</li>`
            ).join('');
            const avisoSobrante = (p.sobrante || 0) > 0
                ? `<p class="orange-text text-darken-3" style="margin:4px 0; font-size:0.9rem;"><i class="material-icons tiny" style="vertical-align:middle;">warning</i> ${p.sobrante} pza(s) sin lote con existencia suficiente registrado.</p>`
                : '';
            return `
                <div class="row" style="border-bottom:1px solid #eee; padding:8px 0; margin:0 0 4px;">
                    <div class="col s12">
                        <p style="margin:0 0 4px;"><strong>${escapeHtml(p.producto_nombre)}</strong> — ${p.cantidad_pedida} pza(s) vendida(s)</p>
                        <ul style="margin:0 0 6px 20px; padding:0;">${filas}</ul>
                        ${avisoSobrante}
                        <label>
                            <input type="checkbox" class="verificar-lote-check" data-idx="${idx}" />
                            <span>Verifiqué / separé este producto del lote indicado</span>
                        </label>
                    </div>
                </div>`;
        }).join('');

        const btnConfirmar = document.getElementById('btn-confirmar-lotes');
        const checks = () => Array.from(lista.querySelectorAll('.verificar-lote-check'));
        const actualizarBoton = () => {
            btnConfirmar.classList.toggle('disabled', !checks().every((c) => c.checked));
        };
        checks().forEach((c) => c.addEventListener('change', actualizarBoton));
        actualizarBoton();

        getModalInstance('modal-verificar-lotes')?.open();
    }
</script>

<?php if (defined('GOOGLE_MAPS_API_KEY') && GOOGLE_MAPS_API_KEY !== ''): ?>
<script src="https://maps.googleapis.com/maps/api/js?key=<?php echo GOOGLE_MAPS_API_KEY; ?>&libraries=places&callback=initAutocompleteSales" async defer></script>
<?php endif; ?>

<?php include __DIR__ . '/includes/footer.php'; ?>