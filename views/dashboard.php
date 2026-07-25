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
                        <p class="text-small" id="stat-pickup-breakdown">Nuevas: 0 | Vistas: 0 | Apartadas: 0 | Atendidas hoy: 0</p>
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
                        <div style="display:flex; justify-content:space-between; align-items:center; gap:12px; flex-wrap:wrap;">
                            <span class="card-title" style="margin:0;">Desglose Diario del Mes</span>
                            <button type="button" id="btn-finance-toggle" class="btn-small blue-grey lighten-1 waves-effect waves-light">Ver todo</button>
                        </div>
                        <p class="grey-text text-small" style="margin-top:6px; margin-bottom:12px;">Se muestran los 10 dias mas recientes para mantener la vista compacta.</p>
                        <div class="finance-daily-table-wrap">
                            <table class="striped highlight finance-daily-table">
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
                        <span class="card-title">Rendimiento de Vendedores (Mes Actual)</span>
                        <p class="grey-text text-small" style="margin-top: 4px; margin-bottom: 16px;">La primera tabla muestra totales por sucursal. La segunda, el detalle por cada vendedor.</p>
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
                                        <th class="right-align">Saldo por Entregar</th>
                                    </tr>
                                </thead>
                                <tbody id="admin-branch-summary-body">
                                    <tr><td colspan="7" class="center grey-text">Cargando resumen por sucursal...</td></tr>
                                </tbody>
                            </table>
                        </div>

                        <span class="card-title" style="font-size:1.4rem; margin-top: 8px; display:block;">Detalle por Vendedor</span>
                        <p class="grey-text text-small" style="margin-top: 0; margin-bottom: 16px;">Saldo por entregar = Ventas del mes - Comisión del mes - Monto ya entregado en cortes del mes.</p>
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
                                        <th class="right-align">Saldo por Entregar</th>
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

        <div class="row"><div class="col s12"><h5><i class="material-icons left">point_of_sale</i> Ventas y Atención</h5></div></div>
        <div class="row dashboard-actions">
            <div class="col s12 m6 l4">
                <div class="card">
                    <div class="card-content">
                        <span class="card-title">Agendar Pedido</span>
                        <p>Capturar pedidos a domicilio, cliente y productos para reparto</p>
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
                        <p class="text-small" id="stat-pickup-breakdown">Nuevas: 0 | Vistas: 0 | Apartadas: 0 | Atendidas hoy: 0</p>
                    </div>
                </div>
            </div>
        </div>

        <div class="row"><div class="col s12"><h5><i class="material-icons left">point_of_sale</i> Ventas y Atención</h5></div></div>
        <div class="row dashboard-actions">
            <div class="col s12 m6 l4">
                <div class="card">
                    <div class="card-content">
                        <span class="card-title">Agendar Pedido</span>
                        <p>Capturar pedidos a domicilio, cliente y productos para reparto</p>
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
                            <div class="col s12 l10 offset-l1">
                                <div class="card-panel orange lighten-5 liquidacion-corte-panel">
                                    <h6 style="margin-top:0;">Corte del Dia</h6>
                                    <div class="liquidacion-summary-grid">
                                        <div class="liquidacion-summary-card liquidacion-summary-card--comision">
                                            <div class="liquidacion-summary-label">Mi Comision de Hoy</div>
                                            <div class="liquidacion-summary-amount" id="stat-comision-card-hoy">$ 0.00</div>
                                            <div class="liquidacion-summary-note"><span id="stat-comision-card-piezas">0</span> piezas x <span id="stat-tarifa-comision-card">$ 50.00</span></div>
                                        </div>
                                        <div class="liquidacion-summary-card liquidacion-summary-card--entrega">
                                            <div class="liquidacion-summary-label">Dinero a Entregar</div>
                                            <div class="liquidacion-summary-amount" id="stat-entrega-card-hoy">$ 0.00</div>
                                            <div class="liquidacion-summary-note">Monto neto de hoy</div>
                                        </div>
                                    </div>

                                    <div class="grey-text text-small liquidacion-desglose" style="margin: 0 0 12px 0; line-height: 1.6;">
                                        <div>Total de Ventas Brutas: <strong id="stat-resumen-ventas-acum">$ 0.00</strong></div>
                                        <div>(-) Tu Comision Automatica Acumulada: <strong id="stat-resumen-comision-acum">-$ 0.00</strong> (<span id="stat-corresponde-vendedor-acumulado-piezas">0</span> piezas)</div>
                                        <div>Comision al Ultimo Corte: <strong id="stat-resumen-comision-ultimo-corte">$ 0.00</strong> (<span id="stat-resumen-piezas-ultimo-corte">0</span> piezas)</div>
                                        <div>Comision Nueva Desde Ultimo Corte: <strong id="stat-resumen-comision-nueva">$ 0.00</strong> (<span id="stat-resumen-piezas-nuevas">0</span> piezas)</div>
                                        <div>Total Neto a Entregar Hoy: <strong id="stat-resumen-pendiente">$ 0.00</strong></div>
                                        <div class="text-small" style="margin-top:4px;">Entregado anteriormente: <strong id="stat-resumen-entregado-previo">$ 0.00</strong></div>
                                    </div>
                                    <p style="margin: 6px 0;"><strong>Ultima declaracion:</strong> <span id="stat-declaracion-dia">Sin declarar</span></p>
                                    <div class="input-field" style="margin-top: 16px; margin-bottom: 18px;">
                                        <input type="number" id="input-entregado-dia" min="0" step="0.01" placeholder="Monto entregado hoy" readonly>
                                        <label for="input-entregado-dia" class="active">Monto entregado hoy (calculado automaticamente)</label>
                                    </div>
                                    <div class="input-field" style="margin-top: 10px; margin-bottom: 20px;">
                                        <input type="text" id="input-observaciones-dia" maxlength="255" placeholder="Notas del corte del dia">
                                        <label for="input-observaciones-dia" class="active">Observaciones (opcional)</label>
                                    </div>
                                    <button type="button" id="btn-liquidar-dia" class="btn orange darken-3 waves-effect waves-light liquidacion-main-btn">DECLARAR Y ENTREGAR $ 0.00</button>
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
                        <div class="row" style="margin-bottom: 10px;">
                            <div class="input-field col s12 m4">
                                <input type="date" id="seller-sales-date-from">
                                <label for="seller-sales-date-from" class="active">Desde</label>
                            </div>
                            <div class="input-field col s12 m4">
                                <input type="date" id="seller-sales-date-to">
                                <label for="seller-sales-date-to" class="active">Hasta</label>
                            </div>
                            <div class="input-field col s12 m4">
                                <select id="seller-sales-status-filter">
                                    <option value="" selected>Todos</option>
                                    <option value="pagado">Pagado</option>
                                    <option value="pendiente_pago">Pendiente pago</option>
                                    <option value="en_reparto">En reparto</option>
                                    <option value="entregado">Entregado</option>
                                    <option value="apartado">Apartado</option>
                                    <option value="cancelado">Cancelado</option>
                                </select>
                                <label>Estado</label>
                            </div>
                            <div class="col s12" style="display:flex; gap:8px; align-items:center; margin-top: 4px;">
                                <button type="button" id="btn-seller-sales-filter" class="btn waves-effect waves-light blue">Filtrar</button>
                                <button type="button" id="btn-seller-sales-clear" class="btn-flat waves-effect">Limpiar</button>
                            </div>
                        </div>
                        <div style="overflow-x:auto;">
                            <table class="striped highlight">
                                <thead>
                                    <tr>
                                        <th>Pedido</th>
                                        <th>Cliente / Referencia</th>
                                        <th>Fecha</th>
                                        <th class="right-align">Total</th>
                                        <th>Estado</th>
                                    </tr>
                                </thead>
                                <tbody id="seller-recent-sales-body">
                                    <tr><td colspan="5" class="center grey-text">Cargando ventas recientes...</td></tr>
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
                        <p>Capturar pedidos a domicilio para tus clientes</p>
                    </div>
                    <div class="card-action">
                        <a href="<?php echo BASE_URL; ?>views/sales.php" class="btn waves-effect waves-light green btn-large">Ir a Ventas</a>
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

    .finance-daily-table-wrap {
        overflow-x: auto;
        max-height: 320px;
        border: 1px solid #eceff1;
        border-radius: 8px;
    }

    .finance-daily-table thead th {
        position: sticky;
        top: 0;
        background: #f8fbfc;
        z-index: 1;
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

    .liquidacion-summary-grid {
        display: grid;
        grid-template-columns: 1fr;
        gap: 14px;
        margin: 12px 0 16px;
    }

    .liquidacion-summary-card {
        border-radius: 12px;
        padding: 14px 16px;
        border: 1px solid transparent;
    }

    .liquidacion-corte-panel {
        padding: 22px 24px 20px !important;
    }

    .liquidacion-corte-panel h6 {
        font-size: 1.55rem;
        font-weight: 700;
        margin-bottom: 6px;
    }

    .liquidacion-desglose > div {
        margin-bottom: 6px;
        color: #5f6368;
    }

    .liquidacion-desglose > div strong {
        color: #2f343a;
    }

    .liquidacion-summary-card--comision {
        background: #e8f5e9;
        border-color: #66bb6a;
        color: #1b5e20;
    }

    .liquidacion-summary-card--entrega {
        background: #fff3e0;
        border-color: #ff9800;
        color: #e65100;
    }

    .liquidacion-summary-label {
        font-size: 0.84rem;
        font-weight: 700;
        letter-spacing: .02em;
        text-transform: uppercase;
    }

    .liquidacion-summary-amount {
        font-size: 2.2rem;
        font-weight: 800;
        line-height: 1.1;
        margin: 8px 0;
    }

    .liquidacion-summary-note {
        font-size: 0.98rem;
        font-weight: 500;
    }

    .liquidacion-main-btn {
        width: 100%;
        font-weight: 800;
        letter-spacing: .02em;
        height: 48px;
        font-size: 1rem;
    }

    @media only screen and (min-width: 900px) {
        .liquidacion-summary-grid {
            grid-template-columns: 1fr 1fr;
        }
    }

    @media only screen and (max-width: 600px) {
        .liquidacion-corte-panel {
            padding: 18px 14px 16px !important;
        }

        .liquidacion-summary-amount {
            font-size: 2rem;
        }
    }
</style>

<script>
    document.addEventListener('DOMContentLoaded', function() {
        const csrfToken = '<?php echo esc(getCsrfToken()); ?>';
        const settlementSuggested = { dia: 0 };
        const sellerSalesFilters = { fechaInicio: '', fechaFin: '', estado: '' };
        const financeDailyState = { expanded: false, rows: [] };

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

        const renderFinanceDaily = () => {
            const tbody = document.getElementById('finance-daily-body');
            const toggleBtn = document.getElementById('btn-finance-toggle');
            const wrap = document.querySelector('.finance-daily-table-wrap');
            if (!tbody) return;

            const rows = Array.isArray(financeDailyState.rows) ? financeDailyState.rows : [];
            if (rows.length === 0) {
                tbody.innerHTML = '<tr><td colspan="4" class="center grey-text">Sin datos para el periodo seleccionado.</td></tr>';
                if (toggleBtn) toggleBtn.style.display = 'none';
                return;
            }

            const maxCompactRows = 10;
            const visibleRows = financeDailyState.expanded ? rows : rows.slice(-maxCompactRows);
            tbody.innerHTML = '';
            visibleRows.forEach(row => {
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

            if (toggleBtn) {
                if (rows.length > maxCompactRows) {
                    toggleBtn.style.display = '';
                    toggleBtn.textContent = financeDailyState.expanded ? 'Ver menos' : 'Ver todo';
                } else {
                    toggleBtn.style.display = 'none';
                }
            }

            if (wrap) {
                wrap.style.maxHeight = financeDailyState.expanded ? '520px' : '320px';
            }
        };

        const syncFiltersFromUrl = () => {
            const params = new URLSearchParams(window.location.search || '');
            sellerSalesFilters.fechaInicio = String(params.get('ventas_fecha_inicio') || '').trim();
            sellerSalesFilters.fechaFin = String(params.get('ventas_fecha_fin') || '').trim();
            sellerSalesFilters.estado = String(params.get('ventas_estado') || '').trim();

            const fromInput = document.getElementById('seller-sales-date-from');
            const toInput = document.getElementById('seller-sales-date-to');
            const estadoInput = document.getElementById('seller-sales-status-filter');

            if (fromInput) fromInput.value = sellerSalesFilters.fechaInicio;
            if (toInput) toInput.value = sellerSalesFilters.fechaFin;
            if (estadoInput) estadoInput.value = sellerSalesFilters.estado;

            if (estadoInput) {
                M.FormSelect.init(estadoInput);
            }
            M.updateTextFields();
        };

        const syncUrlWithFilters = (filters) => {
            const url = new URL(window.location.href);
            if (filters?.fechaInicio) {
                url.searchParams.set('ventas_fecha_inicio', filters.fechaInicio);
            } else {
                url.searchParams.delete('ventas_fecha_inicio');
            }

            if (filters?.fechaFin) {
                url.searchParams.set('ventas_fecha_fin', filters.fechaFin);
            } else {
                url.searchParams.delete('ventas_fecha_fin');
            }

            if (filters?.estado) {
                url.searchParams.set('ventas_estado', filters.estado);
            } else {
                url.searchParams.delete('ventas_estado');
            }

            window.history.replaceState({}, '', url.toString());
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
            const piezasBaseDia = parseInt(d.comisiones.piezas_base_corte_dia || 0, 10);
            const comisionUltimoCorteDia = Math.max(0, parseFloat(d.liquidacion_hoy?.comision_total || 0));
            const piezasUltimoCorteDia = Math.max(0, parseInt(d.liquidacion_hoy?.piezas_total || 0, 10) || 0);
            const comisionNuevaDesdeUltimoCorte = Math.max(0, comisionBaseDia - comisionUltimoCorteDia);
            const piezasNuevasDesdeUltimoCorte = Math.max(0, piezasBaseDia - piezasUltimoCorteDia);
            const pendienteDia = parseFloat(d.comisiones.monto_a_entregar_hoy || 0);
            const entregadoPrevioDia = Math.max(0, ventasBaseDia - comisionBaseDia - pendienteDia);

            updateEl('stat-piezas-hoy', parseInt(d.comisiones.piezas_hoy || 0, 10));
            updateEl('stat-comision-hoy', currency(d.comisiones.comision_hoy));
            updateEl('stat-comision-mes', currency(d.comisiones.comision_mes));
            updateEl('stat-entrega-hoy', currency(pendienteDia));
            updateEl('stat-tarifa-comision', currency(d.comisiones.tarifa_por_pieza));
            updateEl('stat-sugerido-dia', currency(pendienteDia));
            updateEl('stat-comision-card-hoy', currency(d.comisiones.comision_hoy));
            updateEl('stat-comision-card-piezas', parseInt(d.comisiones.piezas_hoy || 0, 10));
            updateEl('stat-tarifa-comision-card', currency(d.comisiones.tarifa_por_pieza));
            updateEl('stat-entrega-card-hoy', currency(pendienteDia));
            updateEl('stat-resumen-ventas-acum', currency(ventasBaseDia));
            updateEl('stat-resumen-comision-acum', '- ' + currency(comisionBaseDia));
            updateEl('stat-resumen-entregado-previo', currency(entregadoPrevioDia));
            updateEl('stat-resumen-pendiente', currency(pendienteDia));
            updateEl('stat-corresponde-vendedor-acumulado-piezas', piezasBaseDia);
            updateEl('stat-resumen-comision-ultimo-corte', currency(comisionUltimoCorteDia));
            updateEl('stat-resumen-piezas-ultimo-corte', piezasUltimoCorteDia);
            updateEl('stat-resumen-comision-nueva', currency(comisionNuevaDesdeUltimoCorte));
            updateEl('stat-resumen-piezas-nuevas', piezasNuevasDesdeUltimoCorte);

            settlementSuggested.dia = pendienteDia;

            updateEl('stat-declaracion-dia', fmtDateTime(d.liquidacion_hoy?.fecha_declaracion || d.liquidacion_hoy?.fecha_entrega_ganancias));

            const inputDia = document.getElementById('input-entregado-dia');
            if (inputDia) {
                inputDia.value = settlementSuggested.dia.toFixed(2);
            }

            const btnDia = document.getElementById('btn-liquidar-dia');
            if (btnDia) {
                btnDia.textContent = 'DECLARAR Y ENTREGAR ' + currency(settlementSuggested.dia);
            }
        };

        const renderSellerRecentSales = (d) => {
            const body = document.getElementById('seller-recent-sales-body');
            if (!body) return;

            if (!Array.isArray(d.ventas_recientes_vendedor) || d.ventas_recientes_vendedor.length === 0) {
                body.innerHTML = '<tr><td colspan="5" class="center grey-text">No hay ventas registradas con esos filtros.</td></tr>';
                return;
            }

            body.innerHTML = d.ventas_recientes_vendedor.map(row => {
                const fecha = row.fecha_creacion ? fmtDateTime(row.fecha_creacion) : 'N/A';
                return `
                    <tr>
                        <td>${row.numero_pedido || 'N/A'}</td>
                        <td>${row.cliente_referencia || 'Sin referencia'}</td>
                        <td>${fecha}</td>
                        <td class="right-align">${currency(row.total)}</td>
                        <td>${row.estado || 'N/A'}</td>
                    </tr>`;
            }).join('');
        };

        const loadDashboardData = (filters = sellerSalesFilters) => {
            const params = new URLSearchParams();
            if (filters?.fechaInicio) params.set('ventas_fecha_inicio', filters.fechaInicio);
            if (filters?.fechaFin) params.set('ventas_fecha_fin', filters.fechaFin);
            if (filters?.estado) params.set('ventas_estado', filters.estado);

            const query = params.toString();
            const url = '<?php echo BASE_URL; ?>api/dashboard_data.php' + (query ? ('?' + query) : '');

            return fetch(url)
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
                        + ' | Apartadas: ' + (d.pickup_metrics.apartadas || 0)
                        + ' | Atendidas hoy: ' + (d.pickup_metrics.atendidas_hoy || 0)
                    );
                }
                if (d.ventas_mes) updateEl('stat-ventas-mes-total', d.ventas_mes.total || 0);
                if (d.ventas_mes) updateEl('stat-ventas-mes-monto', currency(d.ventas_mes.monto));

                renderAdminSellers(d);
                renderSellerSettlement(d);
                renderSellerRecentSales(d);

                financeDailyState.rows = Array.isArray(d.finanzas_mes?.diario) ? d.finanzas_mes.diario : [];
                renderFinanceDaily();
            })
            .catch(err => {
                M.toast({html: 'Error cargando estadísticas', classes: 'red'});
                console.error(err);
            });
        };

        const financeToggleBtn = document.getElementById('btn-finance-toggle');
        if (financeToggleBtn) {
            financeToggleBtn.addEventListener('click', () => {
                financeDailyState.expanded = !financeDailyState.expanded;
                renderFinanceDaily();
            });
        }

        const btnFilter = document.getElementById('btn-seller-sales-filter');
        if (btnFilter) {
            btnFilter.addEventListener('click', () => {
                const fromInput = document.getElementById('seller-sales-date-from');
                const toInput = document.getElementById('seller-sales-date-to');
                const estadoInput = document.getElementById('seller-sales-status-filter');
                const fechaInicio = fromInput ? String(fromInput.value || '').trim() : '';
                const fechaFin = toInput ? String(toInput.value || '').trim() : '';
                const estado = estadoInput ? String(estadoInput.value || '').trim() : '';

                if (fechaInicio !== '' && fechaFin !== '' && fechaInicio > fechaFin) {
                    M.toast({html: 'La fecha inicial no puede ser mayor a la final.', classes: 'orange darken-3'});
                    return;
                }

                sellerSalesFilters.fechaInicio = fechaInicio;
                sellerSalesFilters.fechaFin = fechaFin;
                sellerSalesFilters.estado = estado;
                syncUrlWithFilters(sellerSalesFilters);
                loadDashboardData(sellerSalesFilters);
            });
        }

        const btnClear = document.getElementById('btn-seller-sales-clear');
        if (btnClear) {
            btnClear.addEventListener('click', () => {
                const fromInput = document.getElementById('seller-sales-date-from');
                const toInput = document.getElementById('seller-sales-date-to');
                const estadoInput = document.getElementById('seller-sales-status-filter');
                if (fromInput) fromInput.value = '';
                if (toInput) toInput.value = '';
                if (estadoInput) estadoInput.value = '';
                M.FormSelect.init(document.querySelectorAll('select'));

                sellerSalesFilters.fechaInicio = '';
                sellerSalesFilters.fechaFin = '';
                sellerSalesFilters.estado = '';
                syncUrlWithFilters(sellerSalesFilters);
                loadDashboardData(sellerSalesFilters);
            });
        }

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

        syncFiltersFromUrl();
        loadDashboardData(sellerSalesFilters);
    });

    function cleanupStock() {
            window.location.href = '<?php echo BASE_URL; ?>views/cleanup_reservations.php';
    }
</script>
<?php include __DIR__ . '/includes/footer.php'; ?>