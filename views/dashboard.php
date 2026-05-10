<?php
declare(strict_types=1);

require_once __DIR__ . '/../core/config.php';
require_once __DIR__ . '/../core/auth.php';

// Validar autenticación
requireAuth();

if (isCliente()) {
    header('Location: ' . BASE_URL . 'index.php');
    exit;
}

$pageTitle = 'Dashboard - Sistema POS';
$pdo = getPDO();
$usuario = $_SESSION['usuario'];
$error = '';

// Obtener estadísticas según el rol
try {
    if (isAdmin()) {
        // Admin: Estadísticas globales
        $statsAdmin = getAdminStats($pdo);
    } elseif (isEncargado()) {
        // Encargado: Estadísticas de su almacén
        $statsEncargado = getEncargadoStats($pdo, $usuario['id_almacen']);
    } elseif (isVendedor()) {
        // Vendedor: Estadísticas personales
        $statsVendedor = getVendedorStats($pdo, $usuario['id_usuario']);
    }
} catch (Exception $e) {
    $error = 'Error al cargar estadísticas: ' . $e->getMessage();
}

/**
 * Obtiene estadísticas para Admin
 * @param PDO $pdo
 * @return array
 */
function getAdminStats(PDO $pdo): array
{
    $stats = [];
    
    // Total de ventas del día
    $sql = "SELECT COUNT(*) as total, SUM(total) as monto 
            FROM pedidos 
            WHERE DATE(fecha_creacion) = CURDATE() 
            AND estado != 'cancelado'";
    $stmt = $pdo->prepare($sql);
    $stmt->execute();
    $stats['ventas_hoy'] = $stmt->fetch();
    
    // Total de clientes
    $sql = "SELECT COUNT(*) as total FROM clientes WHERE estado = 'activo'";
    $stmt = $pdo->prepare($sql);
    $stmt->execute();
    $stats['clientes'] = $stmt->fetch();
    
    // Total de productos
    $sql = "SELECT COUNT(*) as total FROM productos WHERE estado = 'activo'";
    $stmt = $pdo->prepare($sql);
    $stmt->execute();
    $stats['productos'] = $stmt->fetch();
    
    // Total de usuarios
    $sql = "SELECT COUNT(*) as total FROM usuarios WHERE estado = 'activo'";
    $stmt = $pdo->prepare($sql);
    $stmt->execute();
    $stats['usuarios'] = $stmt->fetch();
    
    // Ingresos mensuales
    $sql = "SELECT SUM(total) as total FROM pedidos 
            WHERE YEAR(fecha_creacion) = YEAR(NOW()) 
            AND MONTH(fecha_creacion) = MONTH(NOW())
            AND estado != 'cancelado'";
    $stmt = $pdo->prepare($sql);
    $stmt->execute();
    $stats['ingresos_mes'] = $stmt->fetch();
    
    // Stock bajo (según mínimo configurado)
    $sql = "SELECT COUNT(*) as total FROM inventario_almacen WHERE cantidad_actual <= stock_minimo";
    $stmt = $pdo->prepare($sql);
    $stmt->execute();
    $stats['stock_bajo'] = $stmt->fetch();

    // Productos incompletos (Sin precio, sin costo o sin registro de stock)
    $sql = "SELECT COUNT(DISTINCT p.id_producto) as total FROM productos p 
            LEFT JOIN inventario_almacen ia ON p.id_producto = ia.id_producto
            WHERE p.precio_venta <= 0 OR p.precio_costo <= 0 OR ia.id_producto IS NULL OR ia.cantidad_actual <= 0";
    $stmt = $pdo->prepare($sql);
    $stmt->execute();
    $stats['incompletos'] = $stmt->fetch();
    
    return $stats;
}

/**
 * Obtiene estadísticas para Encargado
 * @param PDO $pdo
 * @param int|null $idAlmacen
 * @return array
 */
function getEncargadoStats(PDO $pdo, ?int $idAlmacen): array
{
    $stats = [];
    
    if (!$idAlmacen) {
        return [];
    }
    
    // Ventas del día en su almacén
    $sql = "SELECT COUNT(*) as total, SUM(total) as monto 
            FROM pedidos 
            WHERE id_almacen = :almacen
            AND DATE(fecha_creacion) = CURDATE()
            AND estado != 'cancelado'";
    $stmt = $pdo->prepare($sql);
    $stmt->execute([':almacen' => $idAlmacen]);
    $stats['ventas_hoy'] = $stmt->fetch();
    
    // Stock bajo en su almacén
    $sql = "SELECT COUNT(*) as total FROM inventario_almacen 
            WHERE id_almacen = :almacen AND cantidad_actual <= stock_minimo";
    $stmt = $pdo->prepare($sql);
    $stmt->execute([':almacen' => $idAlmacen]);
    $stats['stock_bajo'] = $stmt->fetch();
    
    // Productos en su almacén
    $sql = "SELECT COUNT(DISTINCT id_producto) as total FROM inventario_almacen 
            WHERE id_almacen = :almacen";
    $stmt = $pdo->prepare($sql);
    $stmt->execute([':almacen' => $idAlmacen]);
    $stats['productos'] = $stmt->fetch();
    
    // Ingresos mensuales de su almacén
    $sql = "SELECT SUM(total) as total FROM pedidos 
            WHERE id_almacen = :almacen
            AND YEAR(fecha_creacion) = YEAR(NOW())
            AND MONTH(fecha_creacion) = MONTH(NOW())
            AND estado != 'cancelado'";
    $stmt = $pdo->prepare($sql);
    $stmt->execute([':almacen' => $idAlmacen]);
    $stats['ingresos_mes'] = $stmt->fetch();
    
    return $stats;
}

/**
 * Obtiene estadísticas para Vendedor
 * @param PDO $pdo
 * @param int $idUsuario
 * @return array
 */
function getVendedorStats(PDO $pdo, int $idUsuario): array
{
    $stats = [];
    
    // Ventas realizadas hoy por este vendedor
    $sql = "SELECT COUNT(*) as total, SUM(total) as monto 
            FROM pedidos 
            WHERE id_usuario = :usuario
            AND DATE(fecha_creacion) = CURDATE()
            AND estado != 'cancelado'";
    $stmt = $pdo->prepare($sql);
    $stmt->execute([':usuario' => $idUsuario]);
    $stats['ventas_hoy'] = $stmt->fetch();
    
    // Total de clientes atendidos este mes
    $sql = "SELECT COUNT(DISTINCT id_cliente) as total FROM pedidos 
            WHERE id_usuario = :usuario
            AND MONTH(fecha_creacion) = MONTH(NOW())
            AND YEAR(fecha_creacion) = YEAR(NOW())";
    $stmt = $pdo->prepare($sql);
    $stmt->execute([':usuario' => $idUsuario]);
    $stats['clientes_mes'] = $stmt->fetch();
    
    // Ingresos totales este mes
    $sql = "SELECT SUM(total) as total FROM pedidos 
            WHERE id_usuario = :usuario
            AND MONTH(fecha_creacion) = MONTH(NOW())
            AND YEAR(fecha_creacion) = YEAR(NOW())
            AND estado != 'cancelado'";
    $stmt = $pdo->prepare($sql);
    $stmt->execute([':usuario' => $idUsuario]);
    $stats['ingresos_mes'] = $stmt->fetch();
    
    return $stats;
}

include __DIR__ . '/includes/header.php';
?>

<div class="container">
    <div class="row">
        <div class="col s12">
            <h4>Bienvenido, <?php echo esc($usuario['nombre']); ?>!</h4>
            <p class="grey-text">Rol: <strong><?php echo esc(ucfirst($usuario['rol'])); ?></strong></p>
        </div>
    </div>

    <?php if (isAdmin()): ?>
        <!-- DASHBOARD ADMIN -->
        <div class="row">
            <div class="col s12 m6 l3">
                <div class="card teal lighten-2">
                    <div class="card-content white-text">
                        <span class="card-title">Ventas Hoy</span>
                        <p class="display-metric"><?php echo esc((string)($statsAdmin['ventas_hoy']['total'] ?? 0)); ?></p>
                        <p class="text-small">$ <?php echo number_format((float)($statsAdmin['ventas_hoy']['monto'] ?? 0), 2); ?></p>
                    </div>
                </div>
            </div>
            <div class="col s12 m6 l3">
                <div class="card blue lighten-2">
                    <div class="card-content white-text">
                        <span class="card-title">Clientes</span>
                        <p class="display-metric"><?php echo esc((string)($statsAdmin['clientes']['total'] ?? 0)); ?></p>
                    </div>
                </div>
            </div>
            <div class="col s12 m6 l3">
                <div class="card purple lighten-2">
                    <div class="card-content white-text">
                        <span class="card-title">Productos</span>
                        <p class="display-metric"><?php echo esc((string)($statsAdmin['productos']['total'] ?? 0)); ?></p>
                    </div>
                </div>
            </div>
            <div class="col s12 m6 l3">
                <div class="card orange lighten-2">
                    <div class="card-content white-text">
                        <span class="card-title">Usuarios</span>
                        <p class="display-metric"><?php echo esc((string)($statsAdmin['usuarios']['total'] ?? 0)); ?></p>
                    </div>
                </div>
            </div>
        </div>

        <div class="row">
            <div class="col s12 m6 l4">
                <div class="card">
                    <div class="card-content">
                        <span class="card-title">Ingresos Mes</span>
                        <p class="display-metric">$ <?php echo number_format((float)($statsAdmin['ingresos_mes']['total'] ?? 0), 2); ?></p>
                    </div>
                </div>
            </div>
            <div class="col s12 m6 l4">
                <div class="card red darken-4">
                    <div class="card-content white-text">
                        <span class="card-title">Auditoría: Incompletos</span>
                        <p class="display-metric"><?php echo esc((string)($statsAdmin['incompletos']['total'] ?? 0)); ?></p>
                        <p class="text-small">Sin precio, costo o stock registrado</p>
                    </div>
                    <div class="card-action">
                        <a href="<?php echo BASE_URL; ?>views/analytics.php" class="white-text">Ver Detalles</a>
                    </div>
                </div>
            </div>
        </div>

        <div class="row">
            <div class="col s12 m6 l4">
                <div class="card">
                    <div class="card-content">
                        <span class="card-title">Gestionar Productos</span>
                        <p>Agregar, editar y eliminar productos del sistema</p>
                    </div>
                    <div class="card-action">
                        <a href="<?php echo BASE_URL; ?>views/products.php" class="btn waves-effect waves-light teal">Ir</a>
                    </div>
                </div>
            </div>
            <div class="col s12 m6 l4">
                <div class="card">
                    <div class="card-content">
                        <span class="card-title">Gestionar Usuarios</span>
                        <p>Crear, editar y administrar usuarios del sistema</p>
                    </div>
                    <div class="card-action">
                        <a href="<?php echo BASE_URL; ?>views/users.php" class="btn waves-effect waves-light blue">Ir</a>
                    </div>
                </div>
            </div>
            <div class="col s12 m6 l4">
                <div class="card">
                    <div class="card-content">
                        <span class="card-title">Analítica Inteligente</span>
                        <p>Predecir stock y analizar tendencias de venta mensuales</p>
                    </div>
                    <div class="card-action">
                        <a href="<?php echo BASE_URL; ?>views/analytics.php" class="btn waves-effect waves-light indigo darken-4">Analizar</a>
                    </div>
                </div>
            </div>
            <div class="col s12 m6 l4">
                <div class="card">
                    <div class="card-content">
                        <span class="card-title">Liberar Stock</span>
                        <p>Cancelar pedidos expirados y devolver productos al inventario</p>
                    </div>
                    <div class="card-action">
                        <button onclick="cleanupStock()" class="btn waves-effect waves-light orange darken-3">Limpiar</button>
                    </div>
                </div>
            </div>
            <div class="col s12 m6 l4">
                <div class="card">
                    <div class="card-content">
                        <span class="card-title">Auditoría y Logs</span>
                        <p>Monitorear clics, visitas y acciones de cada usuario</p>
                    </div>
                    <div class="card-action">
                        <a href="<?php echo BASE_URL; ?>views/activity_logs.php" class="btn waves-effect waves-light grey darken-3">Ver Logs</a>
                    </div>
                </div>
            </div>
            <div class="col s12 m6 l4">
                <div class="card">
                    <div class="card-content">
                        <span class="card-title">Resurtido y Compras</span>
                        <p>Generar listas de pedido y recibir mercancía de proveedores</p>
                    </div>
                    <div class="card-action">
                        <a href="<?php echo BASE_URL; ?>views/purchase_orders.php" class="btn waves-effect waves-light blue darken-3">Gestionar</a>
                    </div>
                </div>
            </div>
            <div class="col s12 m6 l4">
                <div class="card">
                    <div class="card-content">
                        <span class="card-title">Entradas de Inventario</span>
                        <p>Registrar llegada de mercancía y abastecer stock</p>
                    </div>
                    <div class="card-action">
                        <a href="<?php echo BASE_URL; ?>views/inventario_entradas.php" class="btn waves-effect waves-light green darken-2">Surtir</a>
                    </div>
                </div>
            </div>
        </div>

    <?php elseif (isEncargado()): ?>
        <!-- DASHBOARD ENCARGADO -->
        <div class="row">
            <div class="col s12 m6 l3">
                <div class="card teal lighten-2">
                    <div class="card-content white-text">
                        <span class="card-title">Ventas Hoy</span>
                        <p class="display-metric"><?php echo esc((string)($statsEncargado['ventas_hoy']['total'] ?? 0)); ?></p>
                        <p class="text-small">$ <?php echo number_format((float)($statsEncargado['ventas_hoy']['monto'] ?? 0), 2); ?></p>
                    </div>
                </div>
            </div>
            <div class="col s12 m6 l3">
                <div class="card purple lighten-2">
                    <div class="card-content white-text">
                        <span class="card-title">Productos</span>
                        <p class="display-metric"><?php echo esc((string)($statsEncargado['productos']['total'] ?? 0)); ?></p>
                    </div>
                </div>
            </div>
            <div class="col s12 m6 l3">
                <div class="card red lighten-2">
                    <div class="card-content white-text">
                        <span class="card-title">Stock Bajo</span>
                        <p class="display-metric"><?php echo esc((string)($statsEncargado['stock_bajo']['total'] ?? 0)); ?></p>
                    </div>
                </div>
            </div>
            <div class="col s12 m6 l3">
                <div class="card indigo lighten-2">
                    <div class="card-content white-text">
                        <span class="card-title">Por Entregar</span>
                        <?php
                            $stmtE = $pdo->prepare("SELECT COUNT(*) FROM pedidos WHERE id_repartidor IS NOT NULL AND estado = 'pagado' AND id_almacen = ?");
                            $stmtE->execute([$usuario['id_almacen']]);
                            $countE = $stmtE->fetchColumn();
                        ?>
                        <p class="display-metric"><?php echo $countE; ?></p>
                    </div>
                </div>
            </div>
        </div>

        <div class="row">
            <div class="col s12 m6 l4">
                <div class="card">
                    <div class="card-content">
                        <span class="card-title">Gestionar Productos</span>
                        <p>Dar de alta productos, actualizar precios e inventario</p>
                    </div>
                    <div class="card-action">
                        <a href="<?php echo BASE_URL; ?>views/products.php" class="btn waves-effect waves-light teal">Ir</a>
                    </div>
                </div>
            </div>
            <div class="col s12 m6 l4">
                <div class="card">
                    <div class="card-content">
                        <span class="card-title">Asignar Entregas</span>
                        <p>Asignar pedidos pagados a repartidores a domicilio</p>
                    </div>
                    <div class="card-action">
                        <a href="<?php echo BASE_URL; ?>views/asignar_entregas.php" class="btn waves-effect waves-light indigo">Ir</a>
                    </div>
                </div>
            </div>
            <div class="col s12 m6 l4">
                <div class="card">
                    <div class="card-content">
                        <span class="card-title">Realizar Venta</span>
                        <p>Procesar nuevas ventas y consultar historial</p>
                    </div>
                    <div class="card-action">
                        <a href="<?php echo BASE_URL; ?>views/sales.php" class="btn waves-effect waves-light green">Ir</a>
                    </div>
                </div>
            </div>
            <div class="col s12 m6 l4">
                <div class="card">
                    <div class="card-content">
                        <span class="card-title">Resurtido y Compras</span>
                        <p>Generar lista de pedido y recibir mercancía</p>
                    </div>
                    <div class="card-action">
                        <a href="<?php echo BASE_URL; ?>views/purchase_orders.php" class="btn waves-effect waves-light blue darken-3">Gestionar</a>
                    </div>
                </div>
            </div>
            <div class="col s12 m6 l4">
                <div class="card">
                    <div class="card-content">
                        <span class="card-title">Entradas de Inventario</span>
                        <p>Registrar llegada de mercancía y abastecer stock</p>
                    </div>
                    <div class="card-action">
                        <a href="<?php echo BASE_URL; ?>views/inventario_entradas.php" class="btn waves-effect waves-light green darken-2">Surtir</a>
                    </div>
                </div>
            </div>
            <div class="col s12 m6 l4">
                <div class="card">
                    <div class="card-content">
                        <span class="card-title">Reportes de Ventas</span>
                        <p>Generar archivos de ventas y análisis del período</p>
                    </div>
                    <div class="card-action">
                        <a href="<?php echo BASE_URL; ?>views/reportes.php" class="btn waves-effect waves-light purple">Exportar</a>
                    </div>
            <div class="col s12 m6 l4">
                <div class="card">
                    <div class="card-content">
                        <span class="card-title">Liberar Stock</span>
                        <p>Devolver al inventario productos de pedidos no concretados</p>
                    </div>
                    <div class="card-action">
                        <button onclick="cleanupStock()" class="btn waves-effect waves-light orange darken-3">Limpiar</button>
                    </div>
                </div>
            </div>
        </div>

    <?php elseif (isRepartidor()): ?>
        <!-- DASHBOARD REPARTIDOR -->
        <div class="row">
            <div class="col s12">
                <div class="card indigo lighten-1">
                    <div class="card-content white-text center-align">
                        <i class="material-icons large">local_shipping</i>
                        <h4>Mis Entregas Hoy</h4>
                        <?php
                            $stmtR = $pdo->prepare("SELECT COUNT(*) FROM pedidos WHERE id_repartidor = ? AND estado = 'pagado'");
                            $stmtR->execute([$usuario['id_usuario']]);
                            $countR = $stmtR->fetchColumn();
                        ?>
                        <p style="font-size: 3rem; font-weight: bold;"><?php echo $countR; ?></p>
                        <p>Pedidos pendientes por entregar</p>
                    </div>
                    <div class="card-action center-align">
                        <a href="<?php echo BASE_URL; ?>views/entregas.php" class="btn-large white indigo-text waves-effect waves-light">VER MIS ENTREGAS</a>
                    </div>
                </div>
            </div>
        </div>

    <?php elseif (isVendedor()): ?>
        <!-- DASHBOARD VENDEDOR -->
        <div class="row">
            <div class="col s12 m6 l4">
                <div class="card teal lighten-2">
                    <div class="card-content white-text">
                        <span class="card-title">Ventas Hoy</span>
                        <p class="display-metric"><?php echo esc((string)($statsVendedor['ventas_hoy']['total'] ?? 0)); ?></p>
                        <p class="text-small">$ <?php echo number_format((float)($statsVendedor['ventas_hoy']['monto'] ?? 0), 2); ?></p>
                    </div>
                </div>
            </div>
            <div class="col s12 m6 l4">
                <div class="card blue lighten-2">
                    <div class="card-content white-text">
                        <span class="card-title">Clientes Este Mes</span>
                        <p class="display-metric"><?php echo esc((string)($statsVendedor['clientes_mes']['total'] ?? 0)); ?></p>
                    </div>
                </div>
            </div>
            <div class="col s12 m6 l4">
                <div class="card green lighten-2">
                    <div class="card-content white-text">
                        <span class="card-title">Ingresos Mes</span>
                        <p class="text-small">$ <?php echo number_format((float)($statsVendedor['ingresos_mes']['total'] ?? 0), 2); ?></p>
                    </div>
                </div>
            </div>
        </div>

        <div class="row">
            <div class="col s12 m6 l6">
                <div class="card">
                    <div class="card-content">
                        <span class="card-title">Realizar Venta</span>
                        <p>Procesar nuevas ventas, registrar métodos de pago y generar recibos</p>
                    </div>
                    <div class="card-action">
                        <a href="<?php echo BASE_URL; ?>views/sales.php" class="btn waves-effect waves-light green btn-large">Ir</a>
                    </div>
                </div>
            </div>
            <div class="col s12 m6 l6">
                <div class="card">
                    <div class="card-content">
                        <span class="card-title">Ver Catálogo</span>
                        <p>Consultar productos disponibles, precios e información</p>
                    </div>
                    <div class="card-action">
                        <a href="<?php echo BASE_URL; ?>" class="btn waves-effect waves-light blue">Ir</a>
                    </div>
                </div>
            </div>
        </div>
    <?php endif; ?>

</div>

<style>
    .display-metric {
        font-size: 2.5rem;
        font-weight: bold;
        margin: 10px 0;
    }
    .text-small {
        font-size: 0.9rem;
    }
</style>

<script>
    function cleanupStock() {
        if(!confirm('¿Deseas liberar el stock de pedidos pendientes de más de 10 minutos?')) return;
        fetch('<?php echo BASE_URL; ?>api/cleanup_reservations.php')
            .then(r => r.json())
            .then(data => {
                if(data.success) {
                    M.toast({html: data.message, classes: 'green'});
                    setTimeout(() => location.reload(), 1500);
                } else {
                    M.toast({html: 'Error: ' + data.error, classes: 'red'});
                }
            });
    }
</script>
<?php include __DIR__ . '/includes/footer.php'; ?>