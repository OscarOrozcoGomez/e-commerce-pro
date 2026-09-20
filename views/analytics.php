<?php
declare(strict_types=1);

require_once __DIR__ . '/../core/config.php';
require_once __DIR__ . '/../core/auth.php';

requireAuth();
// Permiso 'ver_analitica_negocio' abre esta vista; el admin entra siempre (short-circuit).
if (!hasPermission('ver_analitica_negocio') && !isAdmin()) {
    header('Location: ' . BASE_URL . 'views/dashboard.php');
    exit;
}

$pageTitle = 'Inteligencia de Negocio';
include __DIR__ . '/includes/header.php';
?>

<div class="container-fluid" id="analytics-app" style="padding: 20px; display: none;">
    <div class="row">
        <div class="col s12">
            <div style="display: flex; align-items: center; justify-content: space-between; flex-wrap: wrap; gap: 10px; margin-bottom: 10px;">
                <h4 style="margin: 0;"><i class="material-icons left" style="font-size: 2.5rem; color: #1a237e;">analytics</i> Inteligencia de Negocio</h4>
                <a href="<?php echo BASE_URL; ?>views/dashboard.php" class="btn indigo darken-4 waves-effect waves-light"><i class="material-icons left">dashboard</i> Volver al Dashboard</a>
            </div>
            <p class="grey-text">Qué reponer, qué poner en el aparador y qué mover, con base en tus ventas, stock, margen, visitas y caducidades.</p>
        </div>
    </div>

    <!-- Cuánta evidencia hay detrás: para no confundir una guía por reglas con un pronóstico -->
    <div class="row">
        <div class="col s12">
            <div class="card-panel amber lighten-5" id="evidencia-banner" style="border-left: 4px solid #ffb300; margin: 0;">
                <i class="material-icons left amber-text text-darken-3">info</i>
                <span id="evidencia-texto">Cargando...</span>
            </div>
        </div>
    </div>

    <!-- Resumen -->
    <div class="row rec-kpis">
        <div class="col s6 m3">
            <div class="card rec-kpi rec-kpi-comprar"><div class="card-content"><span class="rec-kpi-num" id="kpi-comprar">0</span><span class="rec-kpi-label">Por comprar</span></div></div>
        </div>
        <div class="col s6 m3">
            <div class="card rec-kpi rec-kpi-aparador"><div class="card-content"><span class="rec-kpi-num" id="kpi-aparador">0</span><span class="rec-kpi-label">Para el aparador</span></div></div>
        </div>
        <div class="col s6 m3">
            <div class="card rec-kpi rec-kpi-mover"><div class="card-content"><span class="rec-kpi-num" id="kpi-mover">0</span><span class="rec-kpi-label">Por mover / no recomprar</span></div></div>
        </div>
        <div class="col s6 m3">
            <div class="card rec-kpi rec-kpi-capital"><div class="card-content"><span class="rec-kpi-num" id="kpi-capital">$0</span><span class="rec-kpi-label">Capital parado (a costo)</span></div></div>
        </div>
    </div>

    <!-- Qué hacer -->
    <div class="row">
        <div class="col s12">
            <div class="card">
                <div class="card-content">
                    <span class="card-title"><i class="material-icons left indigo-text">checklist</i> Qué hacer</span>

                    <div class="rec-tabs" role="tablist" aria-label="Recomendaciones">
                        <button type="button" class="rec-tab is-active" role="tab" aria-selected="true" data-panel="comprar">Comprar <span class="rec-badge" id="badge-comprar">0</span></button>
                        <button type="button" class="rec-tab" role="tab" aria-selected="false" data-panel="aparador">Aparador <span class="rec-badge" id="badge-aparador">0</span></button>
                        <button type="button" class="rec-tab" role="tab" aria-selected="false" data-panel="mover">Mover <span class="rec-badge" id="badge-mover">0</span></button>
                    </div>

                    <section class="rec-panel is-active" id="panel-comprar" role="tabpanel">
                        <p class="grey-text rec-panel-desc">Productos que se venden y se están acabando, o que la gente busca y no hay. Ordenados por urgencia.</p>
                        <div class="rec-table-wrap"><table class="rec-table"><thead id="head-comprar"></thead><tbody id="body-comprar"></tbody></table></div>
                    </section>
                    <section class="rec-panel" id="panel-aparador" role="tabpanel">
                        <p class="grey-text rec-panel-desc">Lo que conviene tener a la vista: se vende, lo visitan o deja buen margen. Con stock disponible.</p>
                        <div class="rec-table-wrap"><table class="rec-table"><thead id="head-aparador"></thead><tbody id="body-aparador"></tbody></table></div>
                    </section>
                    <section class="rec-panel" id="panel-mover" role="tabpanel">
                        <p class="grey-text rec-panel-desc">Stock que no está saliendo o lotes por caducar: ofértalo, cámbialo de lugar o no lo vuelvas a comprar. Ordenados por capital parado.</p>
                        <div class="rec-table-wrap"><table class="rec-table"><thead id="head-mover"></thead><tbody id="body-mover"></tbody></table></div>
                    </section>

                    <details class="rec-reglas">
                        <summary>Cómo decide esto</summary>
                        <div id="reglas-texto"></div>
                    </details>
                </div>
            </div>
        </div>
    </div>

    <!-- Contexto: gráficos (sin pedidos ni productos de prueba) -->
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
<script src="https://cdn.jsdelivr.net/npm/chart.js@4"></script>
<script>
    const PRODUCTOS_URL = '<?php echo BASE_URL; ?>views/products.php?id_producto=';

    function escHtml(value) {
        return String(value ?? '').replace(/[&<>"']/g, (c) => ({
            '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;'
        })[c]);
    }

    function dinero(n) {
        return '$' + Number(n || 0).toLocaleString('es-MX', { minimumFractionDigits: 0, maximumFractionDigits: 0 });
    }

    // Color de la etiqueta de cada sugerencia (Materialize).
    const COLOR_ETIQUETA = {
        'Agotado': 'red darken-2',
        'Se acaba pronto': 'orange darken-2',
        'En su minimo': 'amber darken-3',
        'Lo piden y no hay': 'purple darken-1',
        'Se vende': 'green darken-1',
        'Lo ven y deja margen': 'teal darken-1',
        'Por caducar': 'deep-orange darken-2',
        'Estancado': 'blue-grey darken-1',
        'No recomprar': 'grey darken-1',
    };

    // Columnas de cada lista: [titulo, clase, funcion que devuelve el HTML de la celda].
    const COLUMNAS = {
        comprar: [
            ['Producto', 'rec-nombre', (p) => `<strong>${escHtml(p.nombre)}</strong>`],
            ['Sugerencia', 'rec-accion', (p) => chip(p.etiqueta)],
            ['Por qué', 'rec-motivo', (p) => escHtml(p.motivo)],
            ['Stock', 'rec-num', (p) => escHtml(p.stock)],
            ['Vendidas (90 d)', 'rec-num', (p) => escHtml(p.v90)],
            ['Alcanza (días)', 'rec-num', (p) => p.cobertura_dias === null ? '—' : escHtml(p.cobertura_dias)],
        ],
        aparador: [
            ['Producto', 'rec-nombre', (p) => `<strong>${escHtml(p.nombre)}</strong>`],
            ['Sugerencia', 'rec-accion', (p) => chip(p.etiqueta)],
            ['Por qué', 'rec-motivo', (p) => escHtml(p.motivo)],
            ['Stock', 'rec-num', (p) => escHtml(p.stock)],
            ['Vendidas (90 d)', 'rec-num', (p) => escHtml(p.v90)],
            ['Visitantes (30 d)', 'rec-num', (p) => escHtml(p.visitantes30)],
            ['Margen', 'rec-num', (p) => p.margen_pct === null ? '—' : escHtml(p.margen_pct) + '%'],
        ],
        mover: [
            ['Producto', 'rec-nombre', (p) => `<strong>${escHtml(p.nombre)}</strong>`],
            ['Sugerencia', 'rec-accion', (p) => chip(p.etiqueta)],
            ['Por qué', 'rec-motivo', (p) => escHtml(p.motivo)],
            ['Stock', 'rec-num', (p) => escHtml(p.stock)],
            ['Capital parado', 'rec-num', (p) => dinero(p.capital)],
        ],
    };

    function chip(etiqueta) {
        const color = COLOR_ETIQUETA[etiqueta] || 'grey';
        return `<span class="rec-chip ${color} white-text">${escHtml(etiqueta)}</span>`;
    }

    function renderLista(clave, lista, total) {
        const cols = COLUMNAS[clave];
        const head = document.getElementById('head-' + clave);
        const body = document.getElementById('body-' + clave);

        head.innerHTML = '<tr>' + cols.map(([t, c]) => `<th class="${c}">${escHtml(t)}</th>`).join('') + '<th></th></tr>';

        if (!Array.isArray(lista) || lista.length === 0) {
            body.innerHTML = `<tr class="rec-vacio"><td colspan="${cols.length + 1}" class="grey-text center-align">Nada por aquí por ahora.</td></tr>`;
            return;
        }

        body.innerHTML = lista.map((p) => {
            const celdas = cols.map(([titulo, clase, fn]) => `<td class="${clase}" data-label="${escHtml(titulo)}">${fn(p)}</td>`).join('');
            const abrir = `<td class="rec-abrir"><a href="${PRODUCTOS_URL}${Number(p.id_producto) || 0}" class="btn-small blue darken-3 waves-effect waves-light" title="Abrir en productos"><i class="material-icons" style="font-size:1rem;">edit</i><span class="rec-abrir-texto">Abrir producto</span></a></td>`;
            return `<tr>${celdas}${abrir}</tr>`;
        }).join('') + (total > lista.length
            ? `<tr class="rec-vacio"><td colspan="${cols.length + 1}" class="grey-text center-align">Se muestran los ${lista.length} más prioritarios de ${total}.</td></tr>`
            : '');
    }

    function renderReglas(s) {
        const objetivo = Number(s.dias_entrega_proveedor) + Number(s.dias_colchon);
        document.getElementById('reglas-texto').innerHTML = `
            <p>No es un pronóstico: con pocas ventas por producto un modelo daría ruido. Son reglas simples que puedes revisar y corregir con lo que tú sabes del negocio.</p>
            <ul class="rec-reglas-lista">
                <li><strong>Comprar:</strong> se vendió en los últimos ${escHtml(s.ventana_dias)} días y está agotado, o el stock no alcanza para los ~${escHtml(objetivo)} días que tarda el proveedor (${escHtml(s.dias_entrega_proveedor)} + ${escHtml(s.dias_colchon)} de colchón), o está en su stock mínimo y ya se ha vendido. También aparece lo que visitan ${escHtml(s.min_visitantes_interes)}+ personas en 30 días y no hay.</li>
                <li><strong>Aparador:</strong> con stock y precio, se vendieron 2+ piezas en ${escHtml(s.ventana_dias)} días, o 1 pieza y además lo visitan o deja margen alto (${escHtml(s.margen_alto_pct)}%+), o lo visitan y deja margen alto.</li>
                <li><strong>Mover:</strong> con stock y un lote que caduca en ${escHtml(s.dias_caducidad_mover)} días o menos, o sin ventas en ${escHtml(s.dias_sin_venta_mover)}+ días (o nunca vendido tras ${escHtml(s.dias_sin_venta_mover)}+ días en catálogo). Si además nadie lo visita, sale como "No recomprar".</li>
            </ul>
            <p class="grey-text"><strong>Supuestos a validar:</strong> el tiempo de entrega del proveedor (${escHtml(s.dias_entrega_proveedor)} días) es una estimación; no se guarda el real. Las visitas al producto se registran en pocas páginas, así que "sin visitas" no siempre significa que nadie lo vio. No se consideran pedidos cancelados, productos rechazados en la entrega ni productos de pruebas automatizadas.</p>`;
    }

    function initTabs() {
        const tabs = document.querySelectorAll('.rec-tab');
        tabs.forEach((tab) => {
            tab.addEventListener('click', () => {
                tabs.forEach((t) => {
                    const activa = t === tab;
                    t.classList.toggle('is-active', activa);
                    t.setAttribute('aria-selected', activa ? 'true' : 'false');
                });
                document.querySelectorAll('.rec-panel').forEach((panel) => {
                    panel.classList.toggle('is-active', panel.id === 'panel-' + tab.dataset.panel);
                });
            });
        });
    }

    document.addEventListener('DOMContentLoaded', () => {
        const loader = document.getElementById('loader-analytics');

        const showError = (msg) => {
            loader.innerHTML = `
                <i class="material-icons large red-text">error_outline</i>
                <p class="grey-text">${escHtml(msg)}</p>
                <button class="btn blue darken-2" onclick="location.reload()">Reintentar</button>`;
            if (typeof M !== 'undefined' && M.toast) {
                M.toast({html: 'Error: ' + escHtml(msg), classes: 'red'});
            }
        };

        fetch('<?php echo BASE_URL; ?>api/analytics_data.php', { headers: { 'Accept': 'application/json' } })
            .then(async (r) => {
                const text = await r.text();
                try {
                    return JSON.parse(text);
                } catch (e) {
                    throw new Error(`Respuesta no válida del servidor (HTTP ${r.status}). Recarga la página o vuelve a iniciar sesión.`);
                }
            })
            .then(res => {
                if (!res.success) throw new Error(res.message || 'Error desconocido');

                loader.style.display = 'none';
                document.getElementById('analytics-app').style.display = 'block';

                const rec = res.recomendaciones || {};
                const ctx = rec.contexto || {};
                const resumen = rec.resumen || {};

                document.getElementById('evidencia-texto').textContent =
                    `Base de estas sugerencias: ${ctx.pedidos_reales || 0} pedidos reales, ${ctx.piezas_vendidas || 0} piezas de ${ctx.productos_vendidos || 0} productos en ${ctx.dias_historial || 0} días. ` +
                    'Son pocas ventas por producto: úsalo como guía por reglas, no como pronóstico. Mientras más historial limpio se acumule, más confiable será.';

                document.getElementById('kpi-comprar').textContent = resumen.comprar || 0;
                document.getElementById('kpi-aparador').textContent = resumen.aparador || 0;
                document.getElementById('kpi-mover').textContent = resumen.mover || 0;
                document.getElementById('kpi-capital').textContent = dinero(resumen.capital_parado);
                document.getElementById('badge-comprar').textContent = resumen.comprar || 0;
                document.getElementById('badge-aparador').textContent = resumen.aparador || 0;
                document.getElementById('badge-mover').textContent = resumen.mover || 0;

                renderLista('comprar', rec.comprar, resumen.comprar || 0);
                renderLista('aparador', rec.aparador, resumen.aparador || 0);
                renderLista('mover', rec.mover, resumen.mover || 0);
                renderReglas(ctx.supuestos || {});
                initTabs();

                renderVentasChart(res.ventas_mensuales);
                renderTopChart(res.top_productos);
            })
            .catch(err => showError(err.message));
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
                labels: items.map(i => String(i.nombre ?? '')),
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
</script>

<style>
    canvas { width: 100% !important; }

    /* Resumen */
    .rec-kpi { margin: 0.5rem 0; }
    .rec-kpi .card-content { padding: 14px 16px; }
    .rec-kpi-num { display: block; font-size: 1.9rem; font-weight: 700; line-height: 1.1; }
    .rec-kpi-label { display: block; font-size: 0.85rem; color: #607d8b; margin-top: 2px; }
    .rec-kpi-comprar { border-top: 4px solid #d32f2f; }
    .rec-kpi-aparador { border-top: 4px solid #388e3c; }
    .rec-kpi-mover { border-top: 4px solid #546e7a; }
    .rec-kpi-capital { border-top: 4px solid #f9a825; }

    /* Pestañas propias: se envuelven y son faciles de tocar en celular */
    .rec-tabs { display: flex; flex-wrap: wrap; gap: 8px; margin: 12px 0 16px; }
    .rec-tab { flex: 1 1 0; min-width: 96px; min-height: 44px; padding: 8px 12px; border: 1px solid #c5cae9; border-radius: 22px; background: #fff; color: #1a237e; font-size: 0.95rem; font-weight: 600; cursor: pointer; }
    .rec-tab.is-active { background: #1a237e; border-color: #1a237e; color: #fff; }
    .rec-badge { display: inline-block; min-width: 22px; margin-left: 4px; padding: 0 6px; border-radius: 11px; background: rgba(0, 0, 0, 0.12); font-size: 0.8rem; line-height: 22px; }
    .rec-tab.is-active .rec-badge { background: rgba(255, 255, 255, 0.25); }

    .rec-panel { display: none; }
    .rec-panel.is-active { display: block; }
    .rec-panel-desc { margin: 0 0 12px; }

    .rec-table-wrap { overflow-x: auto; -webkit-overflow-scrolling: touch; }
    .rec-table { width: 100%; border-collapse: collapse; }
    .rec-table th { text-align: left; font-size: 0.85rem; color: #546e7a; border-bottom: 2px solid #e0e0e0; padding: 8px 10px; }
    .rec-table td { padding: 10px; border-bottom: 1px solid #eee; vertical-align: top; }
    .rec-table .rec-num { text-align: center; white-space: nowrap; }
    .rec-table .rec-motivo { min-width: 220px; font-size: 0.9rem; }
    .rec-abrir-texto { display: none; }
    .rec-chip { display: inline-block; padding: 2px 10px; border-radius: 12px; font-size: 0.8rem; font-weight: 600; white-space: nowrap; }

    .rec-reglas { margin-top: 18px; }
    .rec-reglas summary { cursor: pointer; padding: 10px 0; color: #1a237e; font-weight: 600; }
    .rec-reglas-lista { padding-left: 18px; }
    .rec-reglas-lista li { list-style: disc; margin-bottom: 6px; }

    /* Celular: cada fila se vuelve una tarjeta (sin tabla que desplazar de lado) */
    @media (max-width: 600px) {
        #analytics-app { padding: 12px !important; }
        .rec-table thead { display: none; }
        .rec-table, .rec-table tbody, .rec-table tr, .rec-table td { display: block; width: 100%; box-sizing: border-box; }
        .rec-table tr { margin-bottom: 12px; padding: 10px 12px; border: 1px solid #e0e0e0; border-radius: 8px; background: #fff; }
        .rec-table tr.rec-vacio { border: 0; padding: 0; background: transparent; }
        .rec-table td { padding: 4px 0; border: 0; }
        .rec-table td[data-label]::before { content: attr(data-label) ': '; font-weight: 600; color: #546e7a; }
        .rec-table td.rec-nombre::before, .rec-table td.rec-accion::before, .rec-table td.rec-motivo::before { content: none; }
        .rec-table .rec-num { text-align: left; white-space: normal; }
        .rec-table .rec-motivo { min-width: 0; }
        .rec-table td.rec-abrir { padding-top: 8px; }
        .rec-table td.rec-abrir .btn-small { width: 100%; height: 44px; line-height: 44px; }
        .rec-table td.rec-abrir .btn-small i { margin-right: 6px; vertical-align: middle; }
        .rec-table td.rec-abrir .rec-abrir-texto { display: inline; }
        .rec-tab { min-width: 0; padding: 8px 4px; font-size: 0.85rem; }
    }
</style>

<?php include __DIR__ . '/includes/footer.php'; ?>
