<?php
declare(strict_types=1);

require_once __DIR__ . '/../core/config.php';
require_once __DIR__ . '/../core/auth.php';

requireAuth();
if (!canBulkAssignCategories()) {
    header('Location: ' . BASE_URL . 'views/dashboard.php');
    exit;
}
$pageTitle = 'Asignar Categoria a Varios Productos';
include __DIR__ . '/includes/header.php';
?>

<div class="container">
    <div class="row">
        <div class="col s12">
            <div style="display:flex; align-items:center; justify-content:space-between; margin-top:20px; flex-wrap:wrap; gap:10px;">
                <h4 style="margin:0;">Asignar Categoría a Varios Productos</h4>
                <a href="<?php echo BASE_URL; ?>views/products.php" class="btn blue darken-4 waves-effect waves-light"><i class="material-icons left">arrow_back</i> Volver a Productos</a>
            </div>
            <p class="grey-text">Selecciona muchos productos y asígnales una categoría de una sola vez. No se quitan las categorías que ya tenían.</p>
        </div>
    </div>

    <div class="row">
        <div class="col s12 m4">
            <div class="card">
                <div class="card-content">
                    <span class="card-title">1. Elige la categoría</span>

                    <ul class="tabs" id="bac-cat-tabs" style="margin-top:10px;">
                        <li class="tab col s6"><a id="bac-tab-existente" class="active" href="#bac-mode-existente">Existente</a></li>
                        <li class="tab col s6"><a id="bac-tab-nueva" href="#bac-mode-nueva">Nueva</a></li>
                    </ul>

                    <div id="bac-mode-existente" style="padding-top:16px;">
                        <div class="input-field" style="margin-top:0;">
                            <select id="bac-select-categoria" class="browser-default">
                                <option value="">Cargando categorías...</option>
                            </select>
                        </div>
                    </div>
                    <div id="bac-mode-nueva" style="padding-top:16px;">
                        <?php if (isAdmin()): ?>
                            <div class="input-field" style="margin-top:0;">
                                <input type="text" id="bac-nueva-categoria" maxlength="120" placeholder="Ej: Vitaminas">
                                <label for="bac-nueva-categoria" class="active">Nombre de la nueva categoría</label>
                            </div>
                            <p class="grey-text" style="font-size:0.82rem; margin-top:-10px;">Se crea la categoría y se asigna a los productos seleccionados en un solo paso.</p>
                        <?php else: ?>
                            <p class="grey-text">Solo un administrador puede crear categorías nuevas. Pídele que la cree, o usa una existente en la otra pestaña.</p>
                        <?php endif; ?>
                    </div>

                    <div class="card-panel blue lighten-5" id="bac-resumen-seleccion" style="margin-top:16px; font-size:0.9rem; padding:10px;">
                        0 producto(s) seleccionado(s)
                    </div>

                    <button type="button" id="bac-btn-asignar" class="btn green waves-effect waves-light w-100" disabled>
                        <i class="material-icons left">label</i> Asignar Categoría
                    </button>
                </div>
            </div>
        </div>

        <div class="col s12 m8">
            <div class="card">
                <div class="card-content">
                    <div style="display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:10px;">
                        <span class="card-title">2. Elige los productos</span>
                        <div>
                            <button type="button" class="btn-flat waves-effect" id="bac-btn-todos">Seleccionar visibles</button>
                            <button type="button" class="btn-flat waves-effect" id="bac-btn-ninguno">Quitar selección</button>
                        </div>
                    </div>
                    <div class="input-field">
                        <i class="material-icons prefix">search</i>
                        <input type="text" id="bac-buscar" placeholder="Buscar por nombre, SKU, código, categoría, descripción, ingredientes, modo de uso o tabla nutrimental...">
                    </div>
                    <div style="max-height:600px; overflow-y:auto; border:1px solid #e0e0e0; border-radius:4px;">
                        <table class="striped" style="margin-bottom:0;">
                            <thead>
                                <tr>
                                    <th style="width:40px;"></th>
                                    <th>Producto</th>
                                    <th>Categorías actuales</th>
                                </tr>
                            </thead>
                            <tbody id="bac-tabla-body">
                                <tr><td colspan="3" class="center">Cargando productos...</td></tr>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<style>
    .w-100 { width: 100%; }
    #bac-tabla-body td { vertical-align: middle; }
    #bac-tabla-body .chip { margin: 2px 4px 2px 0; }
</style>

<script>
    const BAC_API = <?php echo json_encode(BASE_URL . 'api/products_manager.php', JSON_UNESCAPED_UNICODE); ?>;
    const BAC_CSRF = <?php echo json_encode(getCsrfToken(), JSON_UNESCAPED_UNICODE); ?>;

    let bacCategorias = [];
    let bacProductos = [];
    const bacSeleccionados = new Set();
    let bacCatMode = 'existente';

    document.addEventListener('DOMContentLoaded', () => {
        cargarDependenciasBac();

        document.getElementById('bac-buscar').addEventListener('keyup', filtrarProductosBac);
        document.getElementById('bac-btn-todos').addEventListener('click', seleccionarVisiblesBac);
        document.getElementById('bac-btn-ninguno').addEventListener('click', quitarSeleccionBac);
        document.getElementById('bac-btn-asignar').addEventListener('click', asignarCategoriaBac);

        document.getElementById('bac-tab-existente').addEventListener('click', () => {
            bacCatMode = 'existente';
            actualizarEstadoBotonBac();
        });
        document.getElementById('bac-tab-nueva').addEventListener('click', () => {
            bacCatMode = 'nueva';
            actualizarEstadoBotonBac();
        });

        const selectCat = document.getElementById('bac-select-categoria');
        if (selectCat) {
            selectCat.addEventListener('change', actualizarEstadoBotonBac);
        }
        const nuevaCatInput = document.getElementById('bac-nueva-categoria');
        if (nuevaCatInput) {
            nuevaCatInput.addEventListener('input', actualizarEstadoBotonBac);
        }
    });

    function cargarDependenciasBac() {
        fetch(`${BAC_API}?action=get_dependencies`)
            .then(r => r.json())
            .then(res => {
                if (!res.success) return;
                bacCategorias = res.categorias || [];

                const select = document.getElementById('bac-select-categoria');
                select.innerHTML = '<option value="">-- Selecciona una categoría --</option>' +
                    bacCategorias.map(c => `<option value="${c.id_categoria}">${c.nombre}</option>`).join('');

                const almacenId = (res.almacenes && res.almacenes[0]) ? res.almacenes[0].id_almacen : 1;
                cargarProductosBac(almacenId);
            })
            .catch(() => {
                M.toast({ html: 'No se pudieron cargar las categorías.', classes: 'red' });
            });
    }

    function cargarProductosBac(almacenId) {
        fetch(`${BAC_API}?action=list&almacen_id=${almacenId}`)
            .then(r => r.json())
            .then(res => {
                if (!res.success) throw new Error(res.message);
                bacProductos = (res.data || []).filter(p => p.estado === 'activo');
                renderTablaBac();
            })
            .catch(err => {
                document.getElementById('bac-tabla-body').innerHTML =
                    `<tr><td colspan="3" class="center red-text">${err.message}</td></tr>`;
            });
    }

    function nombreCategoriaPorIdBac(id) {
        const cat = bacCategorias.find(c => String(c.id_categoria) === String(id));
        return cat ? cat.nombre : null;
    }

    // Insensible a mayusculas/acentos, para que "vitaminas" tambien encuentre "Vitamínicos".
    function normalizarBusquedaBac(value) {
        return String(value || '')
            .normalize('NFD')
            .replace(/[\u0300-\u036f]/g, '')
            .toLowerCase()
            .trim();
    }

    // La tabla nutrimental se guarda como JSON (ej: [{"label":"Sodio","porcion":"...", ...}]);
    // se extraen solo los valores para buscar, en vez del texto crudo con llaves y comillas.
    function textoTablaNutrimentalBac(raw) {
        if (!raw) return '';
        try {
            const parsed = JSON.parse(raw);
            if (Array.isArray(parsed)) {
                return parsed.map((item) => [item.label, item.porcion, item.total].filter(Boolean).join(' ')).join(' ');
            }
        } catch (e) {
            // No es JSON valido (dato viejo o mal capturado): se usa el texto tal cual.
        }
        return String(raw);
    }

    // Junta todos los campos de texto utiles del producto para que la busqueda encuentre
    // coincidencias por nombre, SKU, codigo de barras, categorias asignadas, descripcion,
    // beneficios, ingredientes, modo de uso, presentacion y tabla nutrimental.
    function construirTextoBusquedaBac(p, catNombres) {
        const campos = [
            p.nombre,
            p.nombre_variante,
            p.sku,
            p.codigo_barras,
            catNombres.join(' '),
            p.categoria,
            p.descripcion,
            p.beneficios,
            p.ingredientes,
            p.modo_uso,
            p.unidad,
            textoTablaNutrimentalBac(p.tabla_nutrimental),
        ];
        return normalizarBusquedaBac(campos.filter(Boolean).join(' '));
    }

    function escapeAttrBac(value) {
        return String(value || '')
            .replace(/&/g, '&amp;')
            .replace(/"/g, '&quot;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;');
    }

    function renderTablaBac() {
        const tbody = document.getElementById('bac-tabla-body');
        if (bacProductos.length === 0) {
            tbody.innerHTML = '<tr><td colspan="3" class="center">No hay productos activos.</td></tr>';
            return;
        }

        tbody.innerHTML = bacProductos.map(p => {
            const nombreCompleto = p.nombre + (p.nombre_variante ? ' - ' + p.nombre_variante : '');
            const catIds = String(p.categorias_ids || '').split(',').filter(Boolean);
            const catNombres = catIds.map(nombreCategoriaPorIdBac).filter(Boolean);
            const catsHtml = catNombres.length
                ? catNombres.map(n => `<span class="chip" style="font-size:0.72rem; height:22px; line-height:22px;">${n}</span>`).join('')
                : '<span class="grey-text" style="font-size:0.8rem;">Sin categoría</span>';
            const checked = bacSeleccionados.has(String(p.id_producto)) ? 'checked' : '';
            const busqueda = construirTextoBusquedaBac(p, catNombres);

            return `
                <tr data-search="${escapeAttrBac(busqueda)}">
                    <td><label><input type="checkbox" class="filled-in bac-check" data-id="${p.id_producto}" ${checked}/><span></span></label></td>
                    <td>${nombreCompleto}</td>
                    <td>${catsHtml}</td>
                </tr>`;
        }).join('');

        document.querySelectorAll('.bac-check').forEach((chk) => {
            chk.addEventListener('change', () => {
                const id = chk.getAttribute('data-id');
                if (chk.checked) {
                    bacSeleccionados.add(id);
                } else {
                    bacSeleccionados.delete(id);
                }
                actualizarResumenBac();
            });
        });

        actualizarResumenBac();
    }

    function filtrarProductosBac() {
        const q = normalizarBusquedaBac(document.getElementById('bac-buscar').value);
        document.querySelectorAll('#bac-tabla-body tr[data-search]').forEach((row) => {
            row.style.display = row.getAttribute('data-search').includes(q) ? '' : 'none';
        });
    }

    function seleccionarVisiblesBac() {
        document.querySelectorAll('#bac-tabla-body tr[data-search]').forEach((row) => {
            if (row.style.display === 'none') {
                return;
            }
            const chk = row.querySelector('.bac-check');
            if (chk && !chk.checked) {
                chk.checked = true;
                bacSeleccionados.add(chk.getAttribute('data-id'));
            }
        });
        actualizarResumenBac();
    }

    function quitarSeleccionBac() {
        bacSeleccionados.clear();
        document.querySelectorAll('.bac-check').forEach((chk) => {
            chk.checked = false;
        });
        actualizarResumenBac();
    }

    function actualizarResumenBac() {
        document.getElementById('bac-resumen-seleccion').textContent =
            bacSeleccionados.size + ' producto(s) seleccionado(s)';
        actualizarEstadoBotonBac();
    }

    function actualizarEstadoBotonBac() {
        const btn = document.getElementById('bac-btn-asignar');
        let categoriaValida = false;

        if (bacCatMode === 'nueva') {
            const nuevaCatInput = document.getElementById('bac-nueva-categoria');
            categoriaValida = !!(nuevaCatInput && nuevaCatInput.value.trim() !== '');
        } else {
            const sel = document.getElementById('bac-select-categoria');
            categoriaValida = !!(sel && sel.value);
        }

        btn.disabled = !(categoriaValida && bacSeleccionados.size > 0);
    }

    function asignarCategoriaBac() {
        const formData = new FormData();
        formData.append('csrf_token', BAC_CSRF);

        if (bacCatMode === 'nueva') {
            formData.append('nueva_categoria', document.getElementById('bac-nueva-categoria').value.trim());
        } else {
            formData.append('id_categoria', document.getElementById('bac-select-categoria').value);
        }
        bacSeleccionados.forEach((id) => formData.append('productos_ids[]', id));

        const btn = document.getElementById('bac-btn-asignar');
        const originalHtml = btn.innerHTML;
        btn.disabled = true;
        btn.innerHTML = 'Asignando...';

        fetch(`${BAC_API}?action=bulk_assign_category`, { method: 'POST', body: formData })
            .then(r => r.json())
            .then(res => {
                if (!res.success) {
                    throw new Error(res.message);
                }
                M.toast({ html: res.message, classes: 'green' });
                quitarSeleccionBac();

                if (bacCatMode === 'nueva') {
                    document.getElementById('bac-nueva-categoria').value = '';
                }
                // Refresca categorias y productos para que la tabla muestre el nuevo chip.
                cargarDependenciasBac();
            })
            .catch(err => M.toast({ html: err.message, classes: 'red' }))
            .finally(() => {
                btn.innerHTML = originalHtml;
                actualizarEstadoBotonBac();
            });
    }
</script>

<?php include __DIR__ . '/includes/footer.php'; ?>
