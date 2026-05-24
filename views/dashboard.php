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

$usuario = $_SESSION['usuario'];
$pageTitle = 'Dashboard - Sistema POS';

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
                        <p class="display-metric" id="stat-ventas-hoy-total">0</p>
                        <p class="text-small" id="stat-ventas-hoy-monto">$ 0.00</p>
                    </div>
                </div>
            </div>
            <div class="col s12 m6 l3">
                <div class="card blue lighten-2">
                    <div class="card-content white-text">
                        <span class="card-title">Clientes</span>
                        <p class="display-metric" id="stat-clientes">0</p>
                    </div>
                </div>
            </div>
            <div class="col s12 m6 l3">
                <div class="card purple lighten-2">
                    <div class="card-content white-text">
                        <span class="card-title">Productos</span>
                        <p class="display-metric" id="stat-productos">0</p>
                    </div>
                </div>
            </div>
            <div class="col s12 m6 l3">
                <div class="card orange lighten-2">
                    <div class="card-content white-text">
                        <span class="card-title">Usuarios</span>
                        <p class="display-metric" id="stat-usuarios">0</p>
                    </div>
                </div>
            </div>
        </div>

        <!-- SECCIÓN 1: GESTIÓN COMERCIAL Y CONTENIDO -->
        <div class="row"><div class="col s12"><h5><i class="material-icons left">shopping_basket</i> Catálogo y Clientes</h5></div></div>
        <div class="row">
            <div class="col s12 m6 l4">
                <div class="card">
                    <div class="card-content">
                        <span class="card-title">Ingresos Mes</span>
                        <p class="display-metric" id="stat-ingresos-mes">$ 0.00</p>
                    </div>
                </div>
            </div>
            <div class="col s12 m6 l4">
                <div class="card red darken-4">
                    <div class="card-content white-text">
                        <span class="card-title">Auditoría: Incompletos</span>
                        <p class="display-metric" id="stat-incompletos">0</p>
                        <p class="text-small">Sin precio, costo o stock registrado</p>
                    </div>
                    <div class="card-action">
                        <a href="<?php echo BASE_URL; ?>views/analytics.php" class="white-text">Ver Detalles</a>
                    </div>
                </div>
            </div>
            <div class="col s12 m6 l4">
                <div class="card blue-grey darken-1">
                    <div class="card-content white-text">
                        <span class="card-title">Artículos de Blog</span>
                        <p class="display-metric"><?php echo esc((string)($statsAdmin['blogs']['total'] ?? 0)); ?></p>
                        <p class="text-small">Publicaciones informativas en catálogo</p>
                    </div>
                    <div class="card-action">
                        <a href="<?php echo BASE_URL; ?>views/manage_blogs.php" class="white-text">Gestionar Blogs</a>
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
                        <span class="card-title">Gestionar Clientes</span>
                        <p>Administrar base de datos de clientes y sus direcciones guardadas.</p>
                    </div>
                    <div class="card-action">
                        <a href="<?php echo BASE_URL; ?>views/manage_customers.php" class="btn waves-effect waves-light blue darken-2">Clientes</a>
                    </div>
                </div>
            </div>
            <div class="col s12 m6 l4">
                <div class="card">
                    <div class="card-content">
                        <span class="card-title">Gestionar Blogs</span>
                        <p>Publicar, editar o eliminar artículos informativos en el catálogo</p>
                    </div>
                    <div class="card-action">
                        <a href="<?php echo BASE_URL; ?>views/manage_blogs.php" class="btn waves-effect waves-light blue darken-4">Blogs</a>
                    </div>
                </div>
            </div>
        </div>

        <!-- SECCIÓN 2: LOGÍSTICA E INVENTARIO -->
        <div class="row"><div class="col s12"><h5><i class="material-icons left">inventory</i> Operaciones y Stock</h5></div></div>
        <div class="row">
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
        </div>

        <!-- SECCIÓN 3: CONFIGURACIÓN Y PERSONAL -->
        <div class="row"><div class="col s12"><h5><i class="material-icons left">settings</i> Administración y Usuarios</h5></div></div>
        <div class="row">
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
                        <span class="card-title">Gestionar Sucursales</span>
                        <p>Crear nuevas sucursales y configurar ubicaciones.</p>
                    </div>
                    <div class="card-action">
                        <a href="<?php echo BASE_URL; ?>views/manage_branches.php" class="btn waves-effect waves-light orange darken-4">Configurar</a>
                    </div>
                </div>
            </div>
            <div class="col s12 m6 l4">
                <div class="card">
                    <div class="card-content">
                        <span class="card-title">Chat de Soporte</span>
                        <p>Atender dudas y consultas directas de tus clientes en tiempo real.</p>
                    </div>
                    <div class="card-action">
                        <a href="<?php echo BASE_URL; ?>views/chat.php" class="btn waves-effect waves-light indigo darken-1">Centro de Mensajes</a>
                    </div>
                </div>
            </div>
            <div class="col s12 m6 l4">
                <div class="card">
                    <div class="card-content">
                        <span class="card-title">Auditoría y Logs</span>
                        <p>Monitorear clics, visitas y acciones de cada usuario en el sistema</p>
                    </div>
                    <div class="card-action">
                        <a href="<?php echo BASE_URL; ?>views/activity_logs.php" class="btn waves-effect waves-light grey darken-3">Ver Logs</a>
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
                        <p class="display-metric" id="stat-ventas-hoy-total">0</p>
                        <p class="text-small" id="stat-ventas-hoy-monto">$ 0.00</p>
                    </div>
                </div>
            </div>
            <div class="col s12 m6 l3">
                <div class="card purple lighten-2">
                    <div class="card-content white-text">
                        <span class="card-title">Productos</span>
                        <p class="display-metric" id="stat-productos">0</p>
                    </div>
                </div>
            </div>
            <div class="col s12 m6 l3">
                <div class="card red lighten-2">
                    <div class="card-content white-text">
                        <span class="card-title">Stock Bajo</span>
                        <p class="display-metric" id="stat-stock-bajo">0</p>
                    </div>
                </div>
            </div>
            <div class="col s12 m6 l3">
                <div class="card indigo lighten-2">
                    <div class="card-content white-text">
                        <span class="card-title">Por Entregar</span>
                        <p class="display-metric" id="stat-por-entregar">0</p>
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
            <div class="col s12 m6 l4">
                <div class="card">
                    <div class="card-content">
                        <span class="card-title">Gestionar Blogs</span>
                        <p>Publicar, editar o eliminar artículos informativos en el catálogo</p>
                    </div>
                    <div class="card-action">
                        <a href="<?php echo BASE_URL; ?>views/manage_blogs.php" class="btn waves-effect waves-light blue darken-4">Blogs</a>
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
                        <p class="display-metric" id="stat-ventas-hoy-total">0</p>
                        <p class="text-small" id="stat-ventas-hoy-monto">$ 0.00</p>
                    </div>
                </div>
            </div>
            <div class="col s12 m6 l4">
                <div class="card blue lighten-2">
                    <div class="card-content white-text">
                        <span class="card-title">Clientes Este Mes</span>
                        <p class="display-metric" id="stat-clientes">0</p>
                    </div>
                </div>
            </div>
            <div class="col s12 m6 l4">
                <div class="card green lighten-2">
                    <div class="card-content white-text">
                        <span class="card-title">Ingresos Mes</span>
                        <p class="display-metric" id="stat-ingresos-mes">$ 0.00</p>
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
    document.addEventListener('DOMContentLoaded', function() {
        fetch('<?php echo BASE_URL; ?>api/dashboard_data.php')
            .then(r => r.json())
            .then(res => {
                if (!res.success) throw new Error(res.message);
                const d = res.data;
                
                // Mapeo dinámico de elementos
                if (d.ventas_hoy) {
                    document.getElementById('stat-ventas-hoy-total').textContent = d.ventas_hoy.total || 0;
                    document.getElementById('stat-ventas-hoy-monto').textContent = '$ ' + parseFloat(d.ventas_hoy.monto || 0).toFixed(2);
                }
                if (d.clientes) document.getElementById('stat-clientes').textContent = d.clientes.total || 0;
                if (d.productos) document.getElementById('stat-productos').textContent = d.productos.total || 0;
                if (d.usuarios) document.getElementById('stat-usuarios').textContent = d.usuarios.total || 0;
                if (d.ingresos_mes) document.getElementById('stat-ingresos-mes').textContent = '$ ' + parseFloat(d.ingresos_mes.total || 0).toFixed(2);
                if (d.incompletos) document.getElementById('stat-incompletos').textContent = d.incompletos.total || 0;
                if (d.stock_bajo) document.getElementById('stat-stock-bajo').textContent = d.stock_bajo.total || 0;
                if (d.por_entregar) document.getElementById('stat-por-entregar').textContent = d.por_entregar.total || 0;
                if (d.clientes_mes) document.getElementById('stat-clientes').textContent = d.clientes_mes.total || 0;
            })
            .catch(err => {
                M.toast({html: 'Error cargando estadísticas', classes: 'red'});
                console.error(err);
            });
    });

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