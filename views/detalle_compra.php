<?php
declare(strict_types=1);

require_once __DIR__ . '/../core/config.php';
require_once __DIR__ . '/../core/auth.php';

requireAuth();

$idPedido = (int)($_GET['id'] ?? 0);
$usuario = $_SESSION['usuario'];
$pdo = getPDO();

try {
    // Validar que el pedido exista y pertenezca al usuario logueado
    $sql = "SELECT p.*, mp.nombre as metodo_nombre,
                   c.nombre as cliente_nombre, c.email as cliente_email
            FROM pedidos p
            JOIN clientes c ON p.id_cliente = c.id_cliente
            LEFT JOIN metodos_pago mp ON p.id_metodo_pago = mp.id_metodo
            WHERE p.id_pedido = :id_pedido AND c.id_usuario = :id_usuario";
    
    $stmt = $pdo->prepare($sql);
    $stmt->execute([
        ':id_pedido' => $idPedido,
        ':id_usuario' => $usuario['id_usuario']
    ]);
    $pedido = $stmt->fetch();

    if (!$pedido) {
        header('Location: mis_compras.php');
        exit;
    }

    // Obtener detalles con imágenes
    $sqlItems = "SELECT dp.*, p.nombre, p.nombre_variante, p.sku, p.imagen, p.imagen_url, p.unidad,
                        COALESCE(
                            (SELECT pi.ruta_archivo
                             FROM producto_imagenes pi
                             WHERE pi.id_producto = p.id_producto
                             ORDER BY pi.orden ASC
                             LIMIT 1),
                            p.imagen,
                            p.imagen_url
                        ) AS imagen_resuelta
                 FROM detalle_pedidos dp
                 JOIN productos p ON dp.id_producto = p.id_producto
                 WHERE dp.id_pedido = :id_pedido";
    $stmtItems = $pdo->prepare($sqlItems);
    $stmtItems->execute([':id_pedido' => $idPedido]);
    $items = $stmtItems->fetchAll();

    foreach ($items as &$item) {
        $item['imagen_render'] = getProductImageUrl($item['imagen_resuelta'] ?? ($item['imagen'] ?? ''));
        $item['liberado'] = ((int)($item['cantidad'] ?? 0) <= 0);
    }
    unset($item);

    $hasReleasedItems = false;
    foreach ($items as $it) {
        if (!empty($it['liberado'])) {
            $hasReleasedItems = true;
            break;
        }
    }

} catch (PDOException $e) {
    error_log("Error en detalle_compra: " . $e->getMessage());
    header('Location: mis_compras.php');
    exit;
}

function getStatusLabel(string $status): string {
    switch ($status) {
        case 'apartado': return '<span class="badge grey darken-1 white-text">Apartado</span>';
        case 'pendiente_pago': return '<span class="badge grey white-text">Pendiente de Pago</span>';
        case 'pagado': return '<span class="badge blue white-text">Pagado / Confirmado</span>';
        case 'en_reparto': return '<span class="badge orange white-text">En Reparto</span>';
        case 'entregado': return '<span class="badge green white-text">Entregado</span>';
        case 'cancelado': return '<span class="badge red white-text">Cancelado</span>';
        default: return '<span class="badge grey">'.$status.'</span>';
    }
}

$pageTitle = 'Detalle de Pedido ' . $pedido['numero_pedido'];
include __DIR__ . '/includes/header.php';

// Lógica para el Timeline de estados
$estadosTimeline = [
    'pendiente_pago' => ['icon' => 'payments', 'label' => 'Pendiente'],
    'pagado'         => ['icon' => 'check_circle', 'label' => 'Confirmado'],
    'en_reparto'     => ['icon' => 'local_shipping', 'label' => 'En camino'],
    'entregado'      => ['icon' => 'home', 'label' => 'Entregado']
];
$ordenEstados = array_keys($estadosTimeline);
$estadoTimelineActual = ($pedido['estado'] === 'apartado') ? 'pendiente_pago' : $pedido['estado'];
$indiceActual = array_search($estadoTimelineActual, $ordenEstados);
if ($indiceActual === false) $indiceActual = -1; // Para estados como 'cancelado'
?>

<div class="container" style="margin-top: 30px; margin-bottom: 50px;">
    <div class="row">
        <div class="col s12">
            <a href="mis_compras.php" class="btn-flat waves-effect" style="margin-bottom: 20px;">
                <i class="material-icons left">arrow_back</i> Volver a Mis Compras
            </a>
            <div class="card-panel z-depth-1">
                <div class="row" style="margin-bottom: 0;">
                    <div class="col s12 m8">
                        <h4 style="margin: 0; font-weight: bold;">Pedido: <?php echo esc($pedido['numero_pedido']); ?></h4>
                        <p class="grey-text"><?php echo date('d \d\e F, Y - H:i', strtotime($pedido['fecha_creacion'])); ?> hs</p>
                    </div>
                    <div class="col s12 m4 right-align">
                        <?php echo getStatusLabel($pedido['estado']); ?>
                        <div style="margin-top: 10px;">
                            <!-- Habilidad: Repetir Pedido -->
                            <button onclick="repetirPedido()" class="btn-small indigo darken-4 waves-effect waves-light">
                                <i class="material-icons left">replay</i> REPETIR PEDIDO
                            </button>
                        </div>
                    </div>
                </div>

                <?php if ($pedido['estado'] !== 'cancelado'): ?>
                <!-- Habilidad: Timeline de Seguimiento -->
                <div class="row" style="margin-top: 35px; margin-bottom: 10px;">
                    <div class="col s12">
                        <div class="timeline-container">
                            <?php foreach ($estadosTimeline as $key => $data): 
                                $active = (array_search($key, $ordenEstados) <= $indiceActual) ? 'active' : '';
                            ?>
                                <div class="timeline-step <?php echo $active; ?>">
                                    <div class="step-icon"><i class="material-icons"><?php echo $data['icon']; ?></i></div>
                                    <div class="step-label"><?php echo $data['label']; ?></div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                </div>
                <div class="row" style="margin-bottom: 0;">
                    <div class="col s12">
                        <div class="status-rules-box">
                            <strong>Flujo de estados:</strong>
                            <span>Pendiente/Apartado -> Confirmado (vendedor o encargado valida pago) -> En camino (repartidor) -> Entregado (repartidor).</span>
                        </div>
                    </div>
                </div>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <div class="row">
        <!-- Información de Entrega y Pago -->
        <div class="col s12 m4">
            <div class="card">
                <div class="card-content">
                    <span class="card-title" style="font-size: 1.2rem; font-weight: bold;"><i class="material-icons left blue-text">info</i> Resumen del Pedido</span>
                    <p style="margin-top: 15px;"><strong>Método de Pago:</strong><br><?php echo esc($pedido['metodo_nombre'] ?? 'No especificado'); ?></p>
                    <div class="divider" style="margin: 15px 0;"></div>
                    <p><strong>Información de entrega:</strong></p>
                    <p class="grey-text text-darken-2" style="font-size: 0.9rem; white-space: pre-line;">
                        <?php echo esc($pedido['observaciones'] ?? 'Sin datos adicionales.'); ?>
                    </p>
                </div>
            </div>
        </div>

        <!-- Listado Detallado de Productos con Imágenes -->
        <div class="col s12 m8">
            <div class="card">
                <div class="card-content">
                    <span class="card-title" style="font-size: 1.2rem; font-weight: bold;"><i class="material-icons left green-text">inventory_2</i> Productos en este pedido</span>
                    <?php if (!empty($hasReleasedItems)): ?>
                        <div class="released-legend">
                            <i class="material-icons left">info</i>
                            Uno o mas productos fueron liberados por tiempo maximo de apartado. Esto ocurre para devolver inventario cuando hay espera de otros clientes.
                        </div>
                    <?php endif; ?>
                    <ul class="collection" style="border: none; margin-top: 20px;">
                        <?php foreach ($items as $item):
                            $imgSrc = $item['imagen_render'] ?? '';
                            $isReleased = !empty($item['liberado']);
                        ?>
                            <li class="collection-item avatar <?php echo $isReleased ? 'released-item' : ''; ?>" style="border-bottom: 1px solid #f0f0f0; padding-left: 85px; min-height: 100px;">
                                <?php if ($imgSrc): ?>
                                    <img src="<?php echo $imgSrc; ?>" onerror="this.onerror=null;this.src='<?php echo getDefaultProductImageUrl(); ?>';" class="circle" style="width: 60px; height: 60px; border-radius: 4px; left: 10px; background: #f9f9f9; object-fit: contain;">
                                <?php else: ?>
                                    <img src="<?php echo getDefaultProductImageUrl(); ?>" class="circle" style="width: 60px; height: 60px; border-radius: 4px; left: 10px; background: #f9f9f9; object-fit: contain;">
                                <?php endif; ?>
                                
                                <span class="title" style="font-weight: bold;"><?php echo esc($item['nombre'] . ($item['nombre_variante'] ? " - " . $item['nombre_variante'] : "")); ?></span>
                                <p class="grey-text" style="font-size: 0.85rem;">SKU: <?php echo esc($item['sku']); ?></p>
                                <?php if ($isReleased): ?>
                                    <p class="released-note"><i class="material-icons tiny">report_problem</i> Producto liberado por tiempo maximo de apartado.</p>
                                <?php endif; ?>
                                <div class="row" style="margin-bottom: 0; margin-top: 10px;">
                                    <div class="col s6">
                                        <span class="<?php echo $isReleased ? 'red-text text-darken-2' : 'blue-text'; ?>"><?php echo $item['cantidad']; ?> x $<?php echo number_format((float)$item['precio_unitario'], 2); ?></span>
                                    </div>
                                    <div class="col s6 right-align">
                                        <span class="font-weight-bold <?php echo $isReleased ? 'red-text text-darken-2' : ''; ?>" style="font-size: 1.1rem;">$<?php echo number_format((float)$item['cantidad'] * (float)$item['precio_unitario'], 2); ?></span>
                                    </div>
                                </div>
                            </li>
                        <?php endforeach; ?>
                    </ul>

                    <div class="right-align" style="margin-top: 20px; padding: 20px; background: #f5f5f5; border-radius: 4px;">
                        <h6 class="grey-text text-darken-1">Subtotal: $<?php echo number_format((float)$pedido['subtotal'], 2); ?></h6>
                        <?php if ((float)($pedido['descuento_total'] ?? 0) > 0): ?>
                            <h6 class="red-text">Descuento: -$<?php echo number_format((float)$pedido['descuento_total'], 2); ?></h6>
                        <?php endif; ?>
                        <h4 class="indigo-text text-darken-4" style="margin: 10px 0; font-weight: bold;">Total: $<?php echo number_format((float)$pedido['total'], 2); ?></h4>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<style>
    .badge { float: none !important; border-radius: 4px; padding: 4px 12px; font-weight: bold; font-size: 1rem; }
    .font-weight-bold { font-weight: bold; }
    .collection .collection-item.avatar { border-left: none; }
    .released-legend { background: #fff3e0; color: #e65100; border: 1px solid #ffe0b2; padding: 10px 12px; border-radius: 6px; font-size: 0.92rem; margin-top: 10px; }
    .released-item { background: #fff8f8; border-left: 4px solid #ef5350 !important; }
    .released-note { color: #c62828; font-size: 0.82rem; margin-top: 6px; display: flex; align-items: center; gap: 4px; }
    .status-rules-box { margin-top: 12px; padding: 10px 12px; border-radius: 6px; background: #eef4ff; border: 1px solid #d7e3ff; color: #1a237e; font-size: 0.88rem; display: flex; gap: 8px; flex-wrap: wrap; }

    /* Estilos para el Seguimiento Visual */
    .timeline-container { display: flex; justify-content: space-between; position: relative; padding: 0 10px; }
    .timeline-container::before { content: ''; position: absolute; top: 20px; left: 40px; right: 40px; height: 2px; background: #e0e0e0; z-index: 1; }
    .timeline-step { position: relative; z-index: 2; text-align: center; flex: 1; }
    .step-icon { width: 40px; height: 40px; background: #e0e0e0; border-radius: 50%; display: flex; align-items: center; justify-content: center; margin: 0 auto 8px; color: white; transition: 0.3s; }
    .step-label { font-size: 0.7rem; color: #9e9e9e; font-weight: bold; text-transform: uppercase; }
    .timeline-step.active .step-icon { background: #1a237e; box-shadow: 0 2px 5px rgba(0,0,0,0.2); }
    .timeline-step.active .step-label { color: #1a237e; }
</style>

<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
<script>
function repetirPedido() {
    // Obtenemos los productos actuales del pedido desde PHP
    const itemsPedido = <?php echo json_encode($items); ?>;
    
    // Mapeamos al formato que espera tu carrito en localStorage
    const nuevoCarrito = itemsPedido
    .filter(item => parseInt(item.cantidad, 10) > 0)
    .map(item => ({
        id_producto: parseInt(item.id_producto),
        nombre: item.nombre + (item.nombre_variante ? ' - ' + item.nombre_variante : ''),
        precio: parseFloat(item.precio_unitario),
        quantity: parseInt(item.cantidad),
        imagen: item.imagen_render || ''
    }));

    if (nuevoCarrito.length === 0) {
        Swal.fire({
            title: 'Sin productos disponibles',
            text: 'Los productos de este pedido fueron liberados y no pueden repetirse.',
            icon: 'warning',
            confirmButtonColor: '#b71c1c'
        });
        return;
    }

    Swal.fire({
        title: '¿Quieres repetir este pedido?',
        text: "Los productos se añadirán a tu carrito actual.",
        icon: 'question',
        showCancelButton: true,
        confirmButtonColor: '#1a237e',
        confirmButtonText: 'Sí, llenar carrito',
        cancelButtonText: 'Ahora no'
    }).then((result) => {
        if (result.isConfirmed) {
            localStorage.setItem('cart', JSON.stringify(nuevoCarrito));
            window.location.href = 'cart.php';
        }
    });
}
</script>

<?php include __DIR__ . '/includes/footer.php'; ?>