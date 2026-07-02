<?php
declare(strict_types=1);

require_once __DIR__ . '/../core/config.php';
require_once __DIR__ . '/../core/auth.php';

requireAuth();
if (!isAdmin()) {
    header('Location: ' . BASE_URL . 'views/dashboard.php');
    exit;
}

$pageTitle = 'Inteligencia de Negocio';
include __DIR__ . '/includes/header.php';
?>

<div class="container-fluid" id="analytics-app" style="padding: 20px; display: none;">
    <div class="row">
        <div class="col s12">
            <div style="display: flex; align-items: center; justify-content: space-between; flex-wrap: wrap; margin-bottom: 10px;">
                <h4 style="margin: 0;"><i class="material-icons left" style="font-size: 2.5rem; color: #1a237e;">analytics</i> Inteligencia de Negocio</h4>
                <a href="<?php echo BASE_URL; ?>views/dashboard.php" class="btn indigo darken-4 waves-effect waves-light"><i class="material-icons left">dashboard</i> Volver al Dashboard</a>
            </div>
            <p class="grey-text">Análisis de tendencias de venta y predicción de abastecimiento basada en datos históricos.</p>
        </div>
    </div>

    <!-- Gráficos Principales -->
    <div class="row">
        <div class="col s12 m8">
            <div class="card">
                <div class="card-content">
                    <span class="card-title">Tendencia de Ventas (Anual)</span>
                    <canvas id="chartVentas" height="150"></canvas>
                </div>
            </div>
        </div>
        <div class="col s12 m4">
            <div class="card">
                <div class="card-content">
                    <span class="card-title">Top Productos</span>
                    <canvas id="chartTop" height="310"></canvas>
                </div>
            </div>
        </div>
    </div>

    <!-- Predicción de Inventario -->
    <div class="row">
        <div class="col s12">
            <div class="card">
                <div class="card-content">
                    <span class="card-title"><i class="material-icons left indigo-text">precision_manufacturing</i> Predicción de Abastecimiento</span>
                    <p class="grey-text" style="margin-bottom: 20px;" id="prediccion-desc">Cargando estimaciones...</p>
                    
                    <table class="striped highlight">
                        <thead>
                            <tr>
                                <th>Producto</th>
                                <th>Stock Actual</th>
                                <th>Ventas Totales</th>
                                <th>Promedio Diario</th>
                                <th>Días Restantes (Est.)</th>
                                <th>Estado</th>
                                <th>Acción</th>
                            </tr>
                        </thead>
                        <tbody id="table-predicciones">
                            <!-- Llenado dinámico vía API -->
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
</div>

<div id="loader-analytics" class="center-align" style="margin-top: 100px;">
    <div class="preloader-wrapper big active">
        <div class="spinner-layer border-indigo">
            <div class="circle-clipper left"><div class="circle"></div></div><div class="gap-patch"><div class="circle"></div></div><div class="circle-clipper right"><div class="circle"></div></div>
        </div>
    </div>
    <p>Analizando datos históricos...</p>
</div>

<!-- Scripts para Gráficos -->
<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
<script>
    document.addEventListener('DOMContentLoaded', () => {
        fetch('<?php echo BASE_URL; ?>api/analytics_data.php')
            .then(r => r.json())
            .then(res => {
                if (!res.success) throw new Error(res.message);
                
                document.getElementById('loader-analytics').style.display = 'none';
                document.getElementById('analytics-app').style.display = 'block';
                document.getElementById('prediccion-desc').textContent = `Estimación basada en la velocidad de venta histórica (Promedio desde hace ${res.total_dias_historial} días).`;

                renderVentasChart(res.ventas_mensuales);
                renderTopChart(res.top_productos);
                renderTable(res.predicciones);
            })
            .catch(err => {
                M.toast({html: 'Error: ' + err.message, classes: 'red'});
            });
    });

    function renderVentasChart(data) {
        const ctx = document.getElementById('chartVentas').getContext('2d');
        new Chart(ctx, {
            type: 'line',
            data: {
                labels: ['Ene', 'Feb', 'Mar', 'Abr', 'May', 'Jun', 'Jul', 'Ago', 'Sep', 'Oct', 'Nov', 'Dic'],
                datasets: [{
                    label: 'Ventas ($)',
                    data: data,
                    borderColor: '#1a237e',
                    backgroundColor: 'rgba(26, 35, 126, 0.1)',
                    tension: 0.4, fill: true, borderWidth: 3
                }]
            },
            options: {
                responsive: true,
                plugins: { legend: { display: false } },
                scales: { y: { beginAtZero: true, ticks: { callback: v => '$' + v } } }
            }
        });
    }

    function renderTopChart(items) {
        const ctx = document.getElementById('chartTop').getContext('2d');
        new Chart(ctx, {
            type: 'doughnut',
            data: {
                labels: items.map(i => i.nombre),
                datasets: [{
                    data: items.map(i => i.cantidad),
                    backgroundColor: ['#1a237e', '#283593', '#303f9f', '#3949ab', '#3f51b5', '#5c6bc0', '#7986cb', '#9fa8da', '#c5cae9', '#e8eaf6']
                }]
            },
            options: {
                responsive: true,
                plugins: { legend: { position: 'bottom', labels: { boxWidth: 12, font: { size: 10 } } } }
            }
        });
    }

    function renderTable(list) {
        const tbody = document.getElementById('table-predicciones');
        list.forEach(p => {
            let color = 'green-text';
            let label = p.estado || 'Abastecido';
            let rowClass = p.sin_configuracion ? 'red lighten-5' : '';

            if (p.sin_configuracion) {
                color = 'red-text text-darken-4';
                label = 'Sin configuración';
            } else if (p.estado === 'Agotado') {
                color = 'red-text text-darken-4';
            } else if (p.estado === 'Sin rotación') {
                color = 'blue-text text-darken-2';
            } else if (p.estado === 'Sin histórico') {
                color = 'grey-text text-darken-2';
            } else if (p.dias_restantes !== '—') {
                if (p.dias_restantes < 7) { color = 'red-text'; label = 'Crítico'; }
                else if (p.dias_restantes < 15) { color = 'orange-text'; label = 'Reabastecer pronto'; }
            }

            tbody.innerHTML += `
                <tr class="${rowClass}">
                    <td><strong>${p.nombre}</strong>${p.sin_configuracion ? '<br><small class="red-text">Falta configurar precio/costo</small>' : ''}</td>
                    <td>${p.stock}</td>
                    <td>${p.ventas}</td>
                    <td>${p.promedio}</td>
                    <td class="center-align ${color}" style="font-weight: bold; font-size: 1.2rem;">${p.dias_restantes}</td>
                    <td class="${color} font-weight-bold">${label}</td>
                    <td class="center-align">
                        <a href="<?php echo BASE_URL; ?>views/products.php?id_producto=${p.id_producto}" class="btn-small blue darken-3 waves-effect waves-light" title="Abrir en productos">
                            <i class="material-icons" style="font-size: 1rem;">edit</i>
                        </a>
                    </td>
                </tr>`;
        });
    }
</script>

<style>
    .font-weight-bold { font-weight: bold; }
    canvas { width: 100% !important; }
</style>

<?php include __DIR__ . '/includes/footer.php'; ?>
