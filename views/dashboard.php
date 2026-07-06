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
        <div class="row dashboard-metrics-row">
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
            <div class="col s12 m6 l3">
                <div class="card deep-orange lighten-1">
                    <div class="card-content white-text">
                        <span class="card-title">Retiros Sucursal</span>
                        <p class="display-metric" id="stat-pickup-pendientes">0</p>
                        <p class="text-small" id="stat-pickup-breakdown">Nuevas: 0 | Vistas: 0 | Atendidas hoy: 0</p>
                    </div>
                </div>
            </div>
        </div>

        <!-- SECCIÓN 1: GESTIÓN COMERCIAL Y CONTENIDO -->
        <div class="row"><div class="col s12"><h5><i class="material-icons left">shopping_basket</i> Catálogo y Clientes</h5></div></div>
        <div class="row dashboard-actions">
            <div class="col s12 m6 l4">
                <div class="card">
                    <div class="card-content">
                        <span class="card-title">Ingresos Mes</span>
                        <p class="display-metric" id="stat-ingresos-mes">$ 0.00</p>
                        <p class="text-small">Ventas registradas del mes</p>
                    </div>
                </div>
            </div>
            <div class="col s12 m6 l4">
                <div class="card red darken-4">
                    <div class="card-content white-text">
                        <span class="card-title">Productos sin configuración</span>
                        <p class="display-metric" id="stat-incompletos">0</p>
                        <p class="text-small">Sin precio, costo o inventario base</p>
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
                        <p class="display-metric" id="stat-blogs">0</p>
                        <p class="text-small">Publicaciones informativas en catálogo</p>
                    </div>
                    <div class="card-action">
                        <a href="<?php echo BASE_URL; ?>views/manage_blogs.php" class="white-text">Gestionar Blogs</a>
                    </div>
                </div>
            </div>
        </div>

        <div class="row dashboard-actions">
            <div class="col s12 m6 l4">
                <div class="card green darken-2">
                    <div class="card-content white-text">
                        <span class="card-title">Utilidad Bruta Mes</span>
                        <p class="display-metric" id="stat-utilidad-mes">$ 0.00</p>
                        <p class="text-small">Ingresos menos costo histórico</p>
                    </div>
                </div>
            </div>
            <div class="col s12 m6 l4">
                <div class="card amber darken-2">
                    <div class="card-content white-text">
                        <span class="card-title">Costo Mes</span>
                        <p class="display-metric" id="stat-costo-mes">$ 0.00</p>
                        <p class="text-small">Costo de mercancía vendida</p>
                    </div>
                </div>
            </div>
            <div class="col s12 m6 l4">
                <div class="card blue darken-3">
                    <div class="card-content white-text">
                        <span class="card-title">Margen Bruto</span>
                        <p class="display-metric" id="stat-margen-mes">0%</p>
                        <p class="text-small">Utilidad / ingresos</p>
                    </div>
                </div>
            </div>
        </div>

        <div class="row dashboard-actions">
            <div class="col s12">
                <div class="card">
                    <div class="card-content">
                        <span class="card-title">Desglose Diario del Mes</span>
                        <div style="overflow-x:auto;">
                            <table class="striped highlight">
                                <thead>
                                    <tr>
                                        <th>Fecha</th>
                                        <th class="right-align">Ingresos</th>
                                        <th class="right-align">Costo</th>
                                        <th class="right-align">Utilidad</th>
                                    </tr>
                                </thead>
                                <tbody id="finance-daily-body">
                                    <tr><td colspan="4" class="center grey-text">Cargando desglose financiero...</td></tr>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <div class="row dashboard-actions">
            <div class="col s12">
                <div class="card">
                    <div class="card-content">
                        <span class="card-title">Rendimiento de Vendedores por Sucursal (Mes Actual)</span>
                        <div style="overflow-x:auto; margin-bottom: 18px;">
                            <table class="striped highlight">
                                <thead>
                                    <tr>
                                        <th>Sucursal</th>
                                        <th class="right-align">Vendedores</th>
                                        <th class="right-align">Ventas Hoy</th>
                                        <th class="right-align">Ventas Mes</th>
                                        <th class="right-align">Comision Mes</th>
                                        <th class="right-align">Entregado</th>
                                        <th class="right-align">Pendiente</th>
                                    </tr>
                                </thead>
                                <tbody id="admin-branch-summary-body">
                                    <tr><td colspan="7" class="center grey-text">Cargando resumen por sucursal...</td></tr>
                                </tbody>
                            </table>
                        </div>

                        <span class="card-title" style="font-size:1.4rem; margin-top: 8px; display:block;">Detalle por Vendedor</span>
                        <div style="overflow-x:auto;">
                            <table class="striped highlight">
                                <thead>
                                    <tr>
                                        <th>Sucursal</th>
                                        <th>Vendedor</th>
                                        <th class="right-align">Ventas Hoy</th>
                                        <th class="right-align">Ventas Mes</th>
                                        <th class="right-align">Piezas Mes</th>
                                        <th class="right-align">Comision Mes</th>
                                        <th class="right-align">Entregado</th>
                                        <th class="right-align">Pendiente</th>
                                    </tr>
                                </thead>
                                <tbody id="admin-seller-summary-body">
                                    <tr><td colspan="8" class="center grey-text">Cargando detalle de vendedores...</td></tr>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <div class="row dashboard-actions">
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
        <div class="row dashboard-actions">
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
                        <p>Revisar pedidos expirados y devolver productos al inventario</p>
                    </div>
                    <div class="card-action">
                            <a href="<?php echo BASE_URL; ?>views/cleanup_reservations.php" class="btn waves-effect waves-light orange darken-3">Revisar</a>
                    </div>
                </div>
            </div>
            <div class="col s12 m6 l4">
                <div class="card">
                    <div class="card-content">
                        <span class="card-title">Asignar Entregas</span>
                        <p>Asignar pedidos a domicilio a repartidores disponibles</p>
                    </div>
                    <div class="card-action">
                        <a href="<?php echo BASE_URL; ?>views/asignar_entregas.php" class="btn waves-effect waves-light indigo">Ir</a>
                    </div>
                </div>
            </div>
            <div class="col s12 m6 l4">
                <div class="card">
                    <div class="card-content">
                        <span class="card-title">Notificaciones Pickup</span>
                        <p>Ver y dar seguimiento a pedidos para recoger en sucursal</p>
                    </div>
                    <div class="card-action">
                        <a href="<?php echo BASE_URL; ?>views/pickup_notifications.php" class="btn waves-effect waves-light deep-orange darken-2">Gestionar</a>
                    </div>
                </div>
            </div>
        </div>

        <!-- SECCIÓN 3: CONFIGURACIÓN Y PERSONAL -->
        <div class="row"><div class="col s12"><h5><i class="material-icons left">settings</i> Administración y Usuarios</h5></div></div>
        <div class="row dashboard-actions">
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
                        <span class="card-title">Chat</span>
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
        <div class="row dashboard-metrics-row">
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
            <div class="col s12 m6 l3">
                <div class="card deep-orange lighten-1">
                    <div class="card-content white-text">
                        <span class="card-title">Retiros Sucursal</span>
                        <p class="display-metric" id="stat-pickup-pendientes">0</p>
                        <p class="text-small" id="stat-pickup-breakdown">Nuevas: 0 | Vistas: 0 | Atendidas hoy: 0</p>
                    </div>
                </div>
            </div>
        </div>

        <div class="row"><div class="col s12"><h5><i class="material-icons left">point_of_sale</i> Ventas y Atención</h5></div></div>
        <div class="row dashboard-actions">
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
                        <span class="card-title">Reportes de Ventas</span>
                        <p>Generar archivos de ventas y análisis del período</p>
                    </div>
                    <div class="card-action">
                        <a href="<?php echo BASE_URL; ?>views/reportes.php" class="btn waves-effect waves-light purple">Exportar</a>
                    </div>
                </div>
            </div>
            <div class="col s12 m6 l4">
                <div class="card">
                    <div class="card-content">
                        <span class="card-title">Notificaciones Pickup</span>
                        <p>Seguimiento de pedidos por recoger y reabasto de sucursal</p>
                    </div>
                    <div class="card-action">
                        <a href="<?php echo BASE_URL; ?>views/pickup_notifications.php" class="btn waves-effect waves-light deep-orange darken-2">Atender</a>
                    </div>
                </div>
            </div>
        </div>

        <div class="row"><div class="col s12"><h5><i class="material-icons left">inventory_2</i> Inventario y Operación</h5></div></div>
        <div class="row dashboard-actions">
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
                        <span class="card-title">Liberar Stock</span>
                        <p>Revisar pedidos expirados y devolver productos al inventario</p>
                    </div>
                    <div class="card-action">
                        <a href="<?php echo BASE_URL; ?>views/cleanup_reservations.php" class="btn waves-effect waves-light orange darken-3">Revisar</a>
                    </div>
                </div>
            </div>
        </div>

        <div class="row"><div class="col s12"><h5><i class="material-icons left">article</i> Contenido</h5></div></div>
        <div class="row dashboard-actions">
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
        <div class="row dashboard-metrics-row">
            <div class="col s12">
                <div class="card indigo lighten-1">
                    <div class="card-content white-text center-align">
                        <i class="material-icons large">local_shipping</i>
                        <h4>Mis Entregas Hoy</h4>
                        <p style="font-size: 3rem; font-weight: bold;" id="stat-entregas-hoy">0</p>
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
        <div class="row dashboard-metrics-row">
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
                <div class="card cyan darken-2">
                    <div class="card-content white-text">
                        <span class="card-title">Ventas Mes</span>
                        <p class="display-metric" id="stat-ventas-mes-total">0</p>
                        <p class="text-small" id="stat-ventas-mes-monto">$ 0.00</p>
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
            <div class="col s12 m6 l3">
                <div class="card amber darken-2">
                    <div class="card-content white-text">
                        <span class="card-title">Piezas Hoy</span>
                        <p class="display-metric" id="stat-piezas-hoy">0</p>
                    </div>
                </div>
            </div>
            <div class="col s12 m6 l3">
                <div class="card deep-orange darken-2">
                    <div class="card-content white-text">
                        <span class="card-title">Comision Hoy</span>
                        <p class="display-metric" id="stat-comision-hoy">$ 0.00</p>
                    </div>
                </div>
            </div>
            <div class="col s12 m6 l3">
                <div class="card light-blue darken-3">
                    <div class="card-content white-text">
                        <span class="card-title">Comision Mes</span>
                        <p class="display-metric" id="stat-comision-mes">$ 0.00</p>
                    </div>
                </div>
            </div>
            <div class="col s12 m6 l3">
                <div class="card brown darken-2">
                    <div class="card-content white-text">
                        <span class="card-title">Por Entregar Hoy</span>
                        <p class="display-metric" id="stat-entrega-hoy">$ 0.00</p>
                    </div>
                </div>
            </div>
        </div>

        <div class="row dashboard-actions">
            <div class="col s12">
                <div class="card">
                    <div class="card-content">
                        <span class="card-title">Liquidacion de Ganancias</span>
                        <p class="grey-text" style="margin-top:0;">Tarifa fija para vendedor: <strong id="stat-tarifa-comision">$ 50.00</strong> por producto vendido.</p>

                        <div class="row" style="margin-bottom:0;">
                            <div class="col s12 m6">
                                <div class="card-panel orange lighten-5">
                                    <h6 style="margin-top:0;">Corte del Dia</h6>
                                    <p style="margin: 6px 0;"><strong>Monto sugerido a entregar:</strong> <span id="stat-sugerido-dia">$ 0.00</span></p>
                                    <p class="grey-text text-small" id="stat-formula-dia" style="margin: 0 0 8px 0;">Calculo automatico: $ 0.00 ventas - $ 0.00 comision = $ 0.00</p>
                                    <p class="grey-text text-small" id="stat-acumulado-pendiente" style="margin: 0 0 8px 0;">Acumulado pendiente desde ultimo corte: $ 0.00</p>
                                    <p style="margin: 6px 0;"><strong>Ultima declaracion:</strong> <span id="stat-declaracion-dia">Sin declarar</span></p>
                                    <div class="input-field" style="margin-top: 12px;">
                                        <input type="number" id="input-entregado-dia" min="0" step="0.01" placeholder="Monto entregado hoy" readonly>
                                        <label for="input-entregado-dia" class="active">Monto entregado hoy (calculado automaticamente)</label>
                                    </div>
                                    <div class="input-field" style="margin-top: 4px;">
                                        <input type="text" id="input-observaciones-dia" maxlength="255" placeholder="Notas del corte del dia">
                                        <label for="input-observaciones-dia" class="active">Observaciones (opcional)</label>
                                    </div>
                                    <button type="button" id="btn-liquidar-dia" class="btn orange darken-3 waves-effect waves-light">Declarar Entrega del Dia</button>
                                </div>
                            </div>

                        </div>
                    </div>
                </div>
            </div>
        </div>

        <div class="row"><div class="col s12"><h5><i class="material-icons left">point_of_sale</i> Ventas y Catálogo</h5></div></div>
        <div class="row dashboard-actions">
            <div class="col s12">
                <div class="card">
                    <div class="card-content">
                        <span class="card-title">Mis Ventas Recientes</span>
                        <div style="overflow-x:auto;">
                            <table class="striped highlight">
                                <thead>
                                    <tr>
                                        <th>Pedido</th>
                                        <th>Fecha</th>
                                        <th class="right-align">Total</th>
                                        <th>Estado</th>
                                    </tr>
                                </thead>
                                <tbody id="seller-recent-sales-body">
                                    <tr><td colspan="4" class="center grey-text">Cargando ventas recientes...</td></tr>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <div class="row dashboard-actions">
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

    .dashboard-actions .col {
        display: flex;
    }

    .dashboard-actions .card {
        display: flex;
        flex-direction: column;
        width: 100%;
    }

    .dashboard-actions .card-content {
        flex: 1;
        min-height: 140px;
    }

    .dashboard-actions .card-title {
        line-height: 1.3;
        word-break: break-word;
    }

    .dashboard-actions .card-action .btn {
        width: 100%;
    }

    .dashboard-metrics-row {
        display: grid !important;
        grid-template-columns: repeat(auto-fit, minmax(240px, 1fr));
        gap: 0;
    }

    .dashboard-metrics-row .col {
        float: none !important;
        width: auto !important;
        margin-left: 0 !important;
        left: auto !important;
        right: auto !important;
        min-width: 0;
        display: flex;
    }

    .dashboard-metrics-row .card {
        display: flex;
        flex-direction: column;
        width: 100%;
    }

    .dashboard-metrics-row .card-content {
        flex: 1;
        min-height: 152px;
    }

    .dashboard-metrics-row .card-title {
        line-height: 1.3;
    }
</style>

<script>
    document.addEventListener('DOMContentLoaded', function() {
        const csrfToken = '<?php echo esc(getCsrfToken()); ?>';
        const settlementSuggested = { dia: 0 };

        const currency = (value) => '$ ' + parseFloat(value || 0).toFixed(2);
        const fmtDateTime = (value) => {
            if (!value) return 'Sin declarar';
            const d = new Date(String(value).replace(' ', 'T'));
            if (Number.isNaN(d.getTime())) return 'Sin declarar';
            return d.toLocaleString('es-MX');
        };

        const updateEl = (id, value) => {
            const el = document.getElementById(id);
            if (el) el.textContent = value;
        };

        const renderAdminSellers = (d) => {
            const branchBody = document.getElementById('admin-branch-summary-body');
            const sellerBody = document.getElementById('admin-seller-summary-body');

            if (branchBody && Array.isArray(d.resumen_vendedores_sucursal)) {
                if (d.resumen_vendedores_sucursal.length === 0) {
                    branchBody.innerHTML = '<tr><td colspan="7" class="center grey-text">Sin informacion de vendedores por sucursal.</td></tr>';
                } else {
                    branchBody.innerHTML = d.resumen_vendedores_sucursal.map(row => `
                        <tr>
                            <td>${row.sucursal || 'Sin sucursal'}</td>
                            <td class="right-align">${parseInt(row.vendedores || 0, 10)}</td>
                            <td class="right-align">${currency(row.ventas_hoy)}</td>
                            <td class="right-align">${currency(row.ventas_mes)}</td>
                            <td class="right-align">${currency(row.comision_mes)}</td>
                            <td class="right-align">${currency(row.entregado_mes)}</td>
                            <td class="right-align">${currency(row.pendiente_mes)}</td>
                        </tr>`).join('');
                }
            }

            if (sellerBody && Array.isArray(d.detalle_vendedores_admin)) {
                if (d.detalle_vendedores_admin.length === 0) {
                    sellerBody.innerHTML = '<tr><td colspan="8" class="center grey-text">Sin vendedores activos.</td></tr>';
                } else {
                    sellerBody.innerHTML = d.detalle_vendedores_admin.map(row => `
                        <tr>
                            <td>${row.sucursal || 'Sin sucursal'}</td>
                            <td>${row.vendedor || 'N/A'}</td>
                            <td class="right-align">${currency(row.ventas_hoy)}</td>
                            <td class="right-align">${currency(row.ventas_mes)}</td>
                            <td class="right-align">${parseInt(row.piezas_mes || 0, 10)}</td>
                            <td class="right-align">${currency(row.comision_mes)}</td>
                            <td class="right-align">${currency(row.entregado_mes)}</td>
                            <td class="right-align">${currency(row.pendiente_mes)}</td>
                        </tr>`).join('');
                }
            }
        };

        const renderSellerSettlement = (d) => {
            if (!d.comisiones) return;

            const ventasBaseDia = parseFloat(d.comisiones.ventas_base_corte_dia || 0);
            const comisionBaseDia = parseFloat(d.comisiones.comision_base_corte_dia || 0);

            updateEl('stat-piezas-hoy', parseInt(d.comisiones.piezas_hoy || 0, 10));
            updateEl('stat-comision-hoy', currency(d.comisiones.comision_hoy));
            updateEl('stat-comision-mes', currency(d.comisiones.comision_mes));
            updateEl('stat-entrega-hoy', currency(d.comisiones.monto_a_entregar_hoy));
            updateEl('stat-tarifa-comision', currency(d.comisiones.tarifa_por_pieza));
            updateEl('stat-sugerido-dia', currency(d.comisiones.monto_a_entregar_hoy));

            settlementSuggested.dia = parseFloat(d.comisiones.monto_a_entregar_hoy || 0);

            updateEl(
                'stat-formula-dia',
                'Calculo automatico: ' + currency(ventasBaseDia)
                + ' ventas acumuladas - ' + currency(comisionBaseDia)
                + ' comision = ' + currency(settlementSuggested.dia)
            );
            updateEl('stat-acumulado-pendiente', 'Acumulado pendiente desde ultimo corte: ' + currency(settlementSuggested.dia));

            updateEl('stat-declaracion-dia', fmtDateTime(d.liquidacion_hoy?.fecha_declaracion || d.liquidacion_hoy?.fecha_entrega_ganancias));

            const inputDia = document.getElementById('input-entregado-dia');
            if (inputDia) {
                inputDia.value = settlementSuggested.dia.toFixed(2);
            }
        };

        const renderSellerRecentSales = (d) => {
            const body = document.getElementById('seller-recent-sales-body');
            if (!body) return;

            if (!Array.isArray(d.ventas_recientes_vendedor) || d.ventas_recientes_vendedor.length === 0) {
                body.innerHTML = '<tr><td colspan="4" class="center grey-text">No hay ventas registradas.</td></tr>';
                return;
            }

            body.innerHTML = d.ventas_recientes_vendedor.map(row => {
                const fecha = row.fecha_creacion ? fmtDateTime(row.fecha_creacion) : 'N/A';
                return `
                    <tr>
                        <td>${row.numero_pedido || 'N/A'}</td>
                        <td>${fecha}</td>
                        <td class="right-align">${currency(row.total)}</td>
                        <td>${row.estado || 'N/A'}</td>
                    </tr>`;
            }).join('');
        };

        const loadDashboardData = () => fetch('<?php echo BASE_URL; ?>api/dashboard_data.php')
            .then(r => r.json())
            .then(res => {
                if (!res.success) throw new Error(res.message);
                const d = res.data;
                
                if (d.ventas_hoy) {
                    updateEl('stat-ventas-hoy-total', d.ventas_hoy.total || 0);
                    updateEl('stat-ventas-hoy-monto', currency(d.ventas_hoy.monto));
                }
                
                if (d.clientes) updateEl('stat-clientes', d.clientes.total || 0);
                if (d.clientes_mes) updateEl('stat-clientes', d.clientes_mes.total || 0); // Para el vendedor
                if (d.productos) updateEl('stat-productos', d.productos.total || 0);
                if (d.usuarios) updateEl('stat-usuarios', d.usuarios.total || 0);
                if (d.ingresos_mes) updateEl('stat-ingresos-mes', currency(d.ingresos_mes.total));
                if (d.utilidad_mes) updateEl('stat-utilidad-mes', currency(d.utilidad_mes.total));
                if (d.costo_mes) updateEl('stat-costo-mes', currency(d.costo_mes.total));
                if (d.finanzas_mes) updateEl('stat-margen-mes', (parseFloat(d.finanzas_mes.margen || 0)).toFixed(2) + '%');
                if (d.blogs) updateEl('stat-blogs', d.blogs.total || 0);
                if (d.incompletos) updateEl('stat-incompletos', d.incompletos.total || 0);
                if (d.stock_bajo) updateEl('stat-stock-bajo', d.stock_bajo.total || 0);
                if (d.por_entregar) updateEl('stat-por-entregar', d.por_entregar.total || 0);
                if (d.entregas_hoy) updateEl('stat-entregas-hoy', d.entregas_hoy.total || 0);
                if (d.pickup_pendientes) updateEl('stat-pickup-pendientes', d.pickup_pendientes.total || 0);
                if (d.pickup_metrics) {
                    updateEl(
                        'stat-pickup-breakdown',
                        'Nuevas: ' + (d.pickup_metrics.nuevas || 0)
                        + ' | Vistas: ' + (d.pickup_metrics.vistas || 0)
                        + ' | Atendidas hoy: ' + (d.pickup_metrics.atendidas_hoy || 0)
                    );
                }
                if (d.ventas_mes) updateEl('stat-ventas-mes-total', d.ventas_mes.total || 0);
                if (d.ventas_mes) updateEl('stat-ventas-mes-monto', currency(d.ventas_mes.monto));

                renderAdminSellers(d);
                renderSellerSettlement(d);
                renderSellerRecentSales(d);

                if (d.finanzas_mes?.diario) {
                    const tbody = document.getElementById('finance-daily-body');
                    if (tbody) {
                        tbody.innerHTML = '';
                        d.finanzas_mes.diario.forEach(row => {
                            const date = new Date(row.fecha + 'T00:00:00');
                            const fechaTxt = date.toLocaleDateString('es-ES', { day: '2-digit', month: 'short' });
                            tbody.innerHTML += `
                                <tr>
                                    <td>${fechaTxt}</td>
                                    <td class="right-align">${currency(row.ingresos)}</td>
                                    <td class="right-align">${currency(row.costos)}</td>
                                    <td class="right-align">${currency(row.utilidad)}</td>
                                </tr>`;
                        });
                    }
                }
            })
            .catch(err => {
                M.toast({html: 'Error cargando estadísticas', classes: 'red'});
                console.error(err);
            });

        const declararLiquidacion = (periodo) => {
            const montoInput = document.getElementById(periodo === 'dia' ? 'input-entregado-dia' : 'input-entregado-mes');
            const obsInput = document.getElementById(periodo === 'dia' ? 'input-observaciones-dia' : 'input-observaciones-mes');

            const formData = new FormData();
            formData.append('csrf_token', csrfToken);
            formData.append('periodo', periodo);
            if (montoInput) {
                const suggested = parseFloat(settlementSuggested[periodo] || 0);
                const montoToSend = Number.isNaN(suggested) ? 0 : suggested;

                if (montoToSend <= 0) {
                    M.toast({html: 'No hay monto pendiente para declarar en este periodo.', classes: 'orange darken-3'});
                    return;
                }

                montoInput.value = montoToSend.toFixed(2);
                formData.append('monto_entregado', String(montoToSend));
            }
            if (obsInput && obsInput.value !== '') {
                formData.append('observaciones', obsInput.value);
            }

            fetch('<?php echo BASE_URL; ?>api/vendor_settlement.php', {
                method: 'POST',
                body: formData
            })
            .then(r => r.json())
            .then(res => {
                if (!res.success) throw new Error(res.message || 'No se pudo declarar la liquidacion.');
                M.toast({html: res.message || 'Liquidacion registrada', classes: 'green'});
                return loadDashboardData();
            })
            .catch(err => {
                M.toast({html: err.message || 'Error al declarar liquidacion', classes: 'red'});
                console.error(err);
            });
        };

        const btnDia = document.getElementById('btn-liquidar-dia');
        if (btnDia) {
            btnDia.addEventListener('click', () => declararLiquidacion('dia'));
        }

        loadDashboardData();
    });

    function cleanupStock() {
            window.location.href = '<?php echo BASE_URL; ?>views/cleanup_reservations.php';
    }
</script>
<?php include __DIR__ . '/includes/footer.php'; ?>