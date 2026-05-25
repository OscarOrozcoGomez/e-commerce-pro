<?php
declare(strict_types=1);

require_once __DIR__ . '/../core/config.php';
require_once __DIR__ . '/../core/auth.php';

// Validar permisos
requireAuth();
requirePermission('gestionar_productos', BASE_URL . 'views/dashboard.php');
$pageTitle = 'Gestionar Productos';
include __DIR__ . '/includes/header.php';
?>
<!-- Librería para Arrastrar y Soltar -->
<script src="https://cdn.jsdelivr.net/npm/sortablejs@1.15.0/Sortable.min.js"></script>

<div class="container" style="width: 95%; max-width: 1800px;">
    <div class="row" id="alerts-container"></div>
    <div class="row">
        <div class="col s12">
            <div style="display: flex; align-items: center; justify-content: space-between; margin-top: 20px; flex-wrap: wrap;">
                <h4 style="margin: 0;">Gestionar Productos</h4>
                <a href="dashboard.php" class="btn blue darken-4 waves-effect waves-light"><i class="material-icons left">dashboard</i> Volver al Dashboard</a>
            </div>
        </div>
    </div>

    <?php if (isAdmin()): ?>
    <!-- Gestión de Categorías (Solo Admin) -->
    <div class="row">
        <div class="col s12">
            <div class="card-panel blue lighten-5">
                <form id="form-category" class="row" style="margin-bottom: 0; display: flex; align-items: center;">
                    <div class="input-field col s12 m8">
                        <i class="material-icons prefix">label</i>
                        <input type="text" id="nuevo_nombre_cat" name="nuevo_nombre_cat" required>
                        <label for="nuevo_nombre_cat">Nueva Categoría Maestra</label>
                    </div>
                    <div class="col s12 m4">
                        <button type="submit" class="btn blue darken-4">CREAR CATEGORÍA</button>
                    </div>
                </form>
            </div>
        </div>
    </div>
    <?php endif; ?>

    <div class="row">
        <div class="col s12 m4">
            <div class="card">
                <div class="card-content">
                    <span class="card-title" id="form-title">Agregar Nuevo Producto</span>
                    <form id="form-producto" enctype="multipart/form-data">
                        <?php echo csrfInput(); ?>
                        <input type="hidden" name="accion" id="accion" value="agregar">
                        <input type="hidden" name="id_producto" id="id_producto" value="">
                        
                        <div class="input-field">
                            <input type="text" id="nombre" name="nombre" required>
                            <label for="nombre">Nombre del Producto</label>
                        </div>
                        
                        <div class="input-field">
                            <input type="text" id="sku" name="sku" required>
                            <label for="sku">SKU</label>
                        </div>
                        
                        <div class="input-field">
                            <input type="text" id="codigo_barras" name="codigo_barras">
                            <label for="codigo_barras">Código de Barras</label>
                        </div>
                        
                        <div class="input-field">
                            <textarea id="descripcion" name="descripcion" class="materialize-textarea"></textarea>
                            <label for="descripcion">Descripción</label>
                        </div>
                        
                        <div class="input-field">
                            <input type="text" id="unidad" name="unidad">
                            <label for="unidad">Unidad de Medida</label>
                        </div>
                        
                        <div class="input-field">
                            <input type="number" id="precio_costo" name="precio_costo" step="0.01" required>
                            <label for="precio_costo">Precio de Costo</label>
                        </div>
                        
                        <div class="input-field">
                            <input type="number" id="precio_venta" name="precio_venta" step="0.01" required>
                            <label for="precio_venta">Precio de Venta</label>
                        </div>

                        <div class="input-field">
                            <input type="number" id="precio_comparacion" name="precio_comparacion" step="0.01" value="0">
                            <label for="precio_comparacion">Precio de Comparación (Tachado)</label>
                        </div>

                        <div class="input-field" style="margin-top: 30px; margin-bottom: 30px;">
                            <div class="switch">
                                <label>
                                    Oculto en Web
                                    <input type="checkbox" name="visible_catalogo" id="visible_catalogo" value="1" checked>
                                    <span class="lever"></span>
                                    Autorizado para Catálogo
                                </label>
                            </div>
                        </div>

                        <div class="file-field input-field">
                            <div class="btn blue-grey darken-2">
                                <span><i class="material-icons left">collections</i> Imágenes</span>
                                <input type="file" name="imagenes[]" id="input-imagenes" accept="image/*" multiple>
                            </div>
                            <div class="file-path-wrapper">
                                <input class="file-path validate" type="text" placeholder="Máx 6. La primera será la principal">
                            </div>
                        </div>
                        <div id="preview-container" class="row sortable-preview" style="margin-top: -10px; margin-bottom: 20px;"></div>
                        
                        <div class="input-field">
                            <select name="categorias[]" id="select-categorias" multiple>
                                <option value="" disabled>Selecciona una o varias categorías</option>
                            </select>
                            <label>Asignar Categorías</label>
                        </div>

                        <?php if (isAdmin()): ?>
                        <div class="row grey lighten-4" style="padding: 10px; border-radius: 4px; border: 1px solid #ddd;">
                            <div class="col s12"><p style="margin:0 0 10px 0;"><strong>Control de Inventario</strong></p></div>
                            <div class="input-field col s12">
                                <select name="id_almacen_stock" id="id_almacen_stock" class="browser-default" style="border: 1px solid #ccc;">
                                    <!-- Almacenes vía JS -->
                                </select>
                            </div>
                            <div class="input-field col s4">
                                <input type="number" id="cantidad_actual" name="cantidad_actual" value="0" min="0">
                                <label for="cantidad_actual" class="active">Stock Actual</label>
                                <span class="helper-text">Ajuste manual</span>
                            </div>
                            <div class="input-field col s4">
                                <input type="number" id="stock_minimo" name="stock_minimo" value="2" min="0">
                                <label for="stock_minimo" class="active">Stock Mínimo</label>
                            </div>
                            <div class="input-field col s4">
                                <input type="number" id="stock_maximo" name="stock_maximo" value="5" min="1">
                                <label for="stock_maximo" class="active">Stock Máximo</label>
                            </div>
                        </div>
                        <?php endif; ?>
                        
                        <button type="submit" id="btn-submit" class="btn waves-effect waves-light green">
                            Agregar Producto <i class="material-icons right">add</i>
                        </button>
                        <button type="button" id="btn-cancel" class="btn waves-effect waves-light grey" style="display:none;" onclick="cancelarEdicion()">
                            Cancelar
                        </button>
                    </form>
                </div>
            </div>
        </div>

        <div class="col s12 m8">
            <div class="card">
                <div class="card-content">
                    <div style="display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap;">
                        <span class="card-title">Listado de Productos</span>
                        <div style="width: 200px;">
                            <select id="almacen_view_selector" class="browser-default" onchange="cargarProductos(this.value)">
                                <!-- Almacenes vía JS -->
                            </select>
                        </div>
                    </div>
                    <div class="row" style="margin-bottom: 0;">
                        <div class="input-field col s12 m8">
                            <i class="material-icons prefix">search</i>
                            <input type="text" id="buscar_producto" placeholder="Buscar por nombre o SKU...">
                        </div>
                        <div class="input-field col s12 m4">
                            <select id="filtro_estado" class="browser-default" style="border: 1px solid #ccc; border-radius: 4px; height: 3rem;">
                                <option value="activo" selected>Ver: Solo Activos</option>
                                <option value="archivado">Ver: Solo Archivados</option>
                                <option value="todos">Ver: Todos</option>
                            </select>
                        </div>
                    </div>
                    <div style="overflow-x: auto; max-height: 700px; overflow-y: auto;">
                        <table class="striped">
                            <thead>
                                <tr>
                                    <th>Imagen</th>
                                    <th>Nombre</th>
                                    <th>SKU</th>
                                    <th>Precio</th>
                                    <th>Estado</th>
                                    <th class="center-align">Stock Actual</th>
                                    <th class="center-align">Mín/Máx</th>
                                    <th>Acciones</th>
                                </tr>
                            </thead>
                            <tbody id="tabla-productos-body">
                                <tr><td colspan="8" class="center">Cargando productos...</td></tr>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
    const BASE_API = '<?php echo BASE_URL; ?>api/products_manager.php';
    let archivosSeleccionados = []; // Acumulador global de archivos

    document.addEventListener('DOMContentLoaded', () => {
        cargarDependencias();

        // Inicializar el Drag and Drop en el contenedor de previsualización
        const previewContainer = document.getElementById('preview-container');
        new Sortable(previewContainer, {
            animation: 150,
            ghostClass: 'blue-lighten-5',
            onEnd: function() {
                // Reordenar el array archivosSeleccionados basado en el nuevo orden visual
                const nuevoOrden = [];
                previewContainer.querySelectorAll('.preview-item').forEach(el => {
                    const indexOriginal = el.getAttribute('data-index');
                    nuevoOrden.push(archivosSeleccionados[indexOriginal]);
                });
                archivosSeleccionados = nuevoOrden;
                renderPreviews(); // Re-renderizar para actualizar etiquetas (Principal/Galería)
            }
        });

        // Previsualización de imágenes seleccionadas
        document.getElementById('input-imagenes').addEventListener('change', function(e) {
            const nuevosArchivos = Array.from(this.files);
            
            // Añadir nuevos archivos al acumulador sin borrar los anteriores
            // Limitamos a un total de 6
            nuevosArchivos.forEach(file => {
                if (archivosSeleccionados.length < 6) {
                    archivosSeleccionados.push(file);
                }
            });

            // Limpiar el input para permitir volver a seleccionar los mismos archivos si se desea
            this.value = '';
            renderPreviews();
        });
        
        function renderPreviews() {
            const container = document.getElementById('preview-container');
            container.innerHTML = '';

            archivosSeleccionados.forEach((file, index) => {
                const reader = new FileReader();
                reader.onload = function(event) {
                    const div = document.createElement('div');
                    div.className = 'col s4 m2 preview-item';
                    div.style.position = 'relative';
                    div.setAttribute('data-index', index);
                    div.innerHTML = `
                        <div class="card" style="margin: 5px 0;">
                            <div class="card-image">
                                <img src="${event.target.result}" style="height: 60px; object-fit: cover;">
                                <span style="
                                    position: absolute; 
                                    top: 0; 
                                    left: 0; 
                                    background: ${index === 0 ? '#2e7d32' : '#546e7a'}; 
                                    color: white; 
                                    font-size: 9px; 
                                    padding: 2px 5px; 
                                    width: 100%; 
                                    text-align: center;">
                                    ${index === 0 ? 'PRINCIPAL' : 'GALERÍA'}
                                </span>
                                <button type="button" onclick="quitarImagen(${index})" class="btn-floating btn-small red" style="position: absolute; top: -10px; right: -10px; width: 24px; height: 24px;">
                                    <i class="material-icons" style="line-height: 24px; font-size: 14px;">close</i>
                                </button>
                                ${index > 0 ? `
                                <button type="button" onclick="hacerPrincipal(${index})" class="btn-flat white-text" style="position: absolute; bottom: 0; left: 0; width: 100%; background: rgba(0,0,0,0.5); font-size: 8px; padding: 0; height: 18px; line-height: 18px;">
                                    SUBIR A PRINCIPAL
                                </button>` : ''}
                            </div>
                        </div>
                    `;
                    container.appendChild(div);
                }
                reader.readAsDataURL(file);
            });
        }

        window.quitarImagen = function(index) {
            archivosSeleccionados.splice(index, 1);
            renderPreviews();
        };

        window.hacerPrincipal = function(index) {
            const item = archivosSeleccionados.splice(index, 1)[0];
            archivosSeleccionados.unshift(item); // Mover al principio
            renderPreviews();
        };
        
        // Manejar envío de formularios
        document.getElementById('form-producto').addEventListener('submit', function(e) {
            e.preventDefault();
            const formData = new FormData(this);
            
            // Limpiar las imágenes del formData original (las del input vacío)
            formData.delete('imagenes[]');
            // Añadir los archivos desde nuestro acumulador controlado
            archivosSeleccionados.forEach(file => {
                formData.append('imagenes[]', file);
            });
            
            fetch(`${BASE_API}?action=save`, { method: 'POST', body: formData })
                .then(r => r.json())
                .then(res => {
                    if(res.success) {
                        M.toast({html: res.message, classes: 'green'});
                        cancelarEdicion();
                        cargarProductos(document.getElementById('almacen_view_selector').value);
                    } else throw new Error(res.message);
                })
                .catch(err => M.toast({html: err.message, classes: 'red'}));
        });

        document.getElementById('form-category')?.addEventListener('submit', function(e) {
            e.preventDefault();
            const formData = new FormData(this);
            formData.append('csrf_token', document.querySelector('input[name="csrf_token"]').value);
            
            fetch(`${BASE_API}?action=add_category`, { method: 'POST', body: formData })
                .then(r => r.json())
                .then(res => {
                    if(res.success) {
                        M.toast({html: res.message, classes: 'green'});
                        this.reset();
                        cargarDependencias();
                    } else throw new Error(res.message);
                })
                .catch(err => M.toast({html: err.message, classes: 'red'}));
        });
    });

    function cargarDependencias() {
        fetch(`${BASE_API}?action=get_dependencies`)
            .then(r => r.json())
            .then(res => {
                if(!res.success) return;
                
                // Llenar selectores de almacén
                const selectors = ['id_almacen_stock', 'almacen_view_selector'];
                selectors.forEach(id => {
                    const el = document.getElementById(id);
                    if(!el) return;
                    el.innerHTML = res.almacenes.map(a => `<option value="${a.id_almacen}">${a.nombre}</option>`).join('');
                });
                
                // Llenar categorías
                const catSelect = document.getElementById('select-categorias');
                catSelect.innerHTML = '<option value="" disabled>Selecciona una o varias categorías</option>' + 
                    res.categorias.map(c => `<option value="${c.id_categoria}">${c.nombre}</option>`).join('');
                M.FormSelect.init(catSelect);

                cargarProductos(res.almacenes[0].id_almacen);
            });
    }

    function cargarProductos(almacenId) {
        const tbody = document.getElementById('tabla-productos-body');
        fetch(`${BASE_API}?action=list&almacen_id=${almacenId}`)
            .then(r => r.json())
            .then(res => {
                if(!res.success) throw new Error(res.message);
                tbody.innerHTML = res.data.map(p => renderRow(p)).join('');
            })
            .catch(err => {
                tbody.innerHTML = `<tr><td colspan="8" class="center red-text">${err.message}</td></tr>`;
            });
    }

    // Ayudante JS para resolver la URL de la imagen similar a la función de PHP
    function getProductImgUrl(imgData) {
        const baseUrl = '<?php echo BASE_URL; ?>';
        if (!imgData || imgData === 'NULL') return baseUrl + 'assets/img/no-product.png';
        
        // Si es una ruta de archivo (formato corto con extensión)
        if (imgData.length < 255 && /\.(jpg|jpeg|png|webp)$/i.test(imgData)) {
            return baseUrl + 'assets/img/products/' + imgData;
        }
        
        // Si es Base64
        if (imgData.includes('data:image') || imgData.length > 500) {
            return imgData.includes('data:image') ? imgData : `data:image/jpeg;base64,${imgData}`;
        }
        
        return baseUrl + 'assets/img/no-product.png';
    }

    function renderRow(p) {
        let imgSrc = getProductImgUrl(p.imagen);
        const isLow = (parseInt(p.cantidad_actual) || 0) <= (parseInt(p.stock_minimo) || 2);
        const jsonP = JSON.stringify(p).replace(/'/g, "&apos;");

        return `
            <tr>
                <td>${imgSrc ? `<img src="${imgSrc}" style="width: 60px; height: 60px; object-fit: contain; background: #f5f5f5;" class="circle shadow-1">` : ''}</td>
                <td>${p.nombre}</td>
                <td>${p.sku}</td>
                <td>
                    $${parseFloat(p.precio_venta).toFixed(2)}
                    ${parseFloat(p.precio_comparacion) > 0 ? `<br><small class="grey-text" style="text-decoration: line-through;">$${parseFloat(p.precio_comparacion).toFixed(2)}</small>` : ''}
                </td>
                <td>
                    <span class="badge ${p.estado === 'activo' ? 'blue' : 'grey darken-1'} white-text" style="float: none; border-radius: 4px;">
                        ${p.estado.toUpperCase()}
                    </span>
                </td>
                <td class="center-align">
                    <span class="badge ${isLow ? 'red white-text' : 'green lighten-4 green-text text-darken-4'}" style="float: none; font-weight: bold; border-radius: 4px;">
                        ${p.cantidad_actual || 0}
                    </span>
                </td>
                <td class="center-align">
                    <span class="orange-text text-darken-2" title="Mínimo"><strong>${p.stock_minimo || 2}</strong></span> / 
                    <span class="blue-text text-darken-2" title="Máximo"><strong>${p.stock_maximo || 5}</strong></span>
                </td>
                <td>
                    <button type="button" class="btn-floating btn-small blue waves-effect waves-light" onclick='abrirEditar(${jsonP})'>
                        <i class="material-icons">edit</i>
                    </button>
                    <button type="button" class="btn-floating btn-small red waves-effect waves-light" onclick="eliminarProducto(${p.id_producto})">
                        <i class="material-icons">delete</i>
                    </button>
                </td>
            </tr>
        `;
    }

    function eliminarProducto(id) {
        if(!confirm('¿Desactivar producto?')) return;
        const formData = new FormData();
        formData.append('id_producto', id);
        formData.append('csrf_token', document.querySelector('input[name="csrf_token"]').value);

        fetch(`${BASE_API}?action=delete`, { method: 'POST', body: formData })
            .then(r => r.json())
            .then(res => {
                if(res.success) {
                    M.toast({html: res.message, classes: 'green'});
                    cargarProductos(document.getElementById('almacen_view_selector').value);
                } else throw new Error(res.message);
            })
            .catch(err => M.toast({html: err.message, classes: 'red'}));
    }

    // Mantener las funciones de UI existentes pero adaptadas

    function abrirEditar(prod) {
        document.getElementById('accion').value = 'editar';
        document.getElementById('id_producto').value = prod.id_producto;
        
        document.getElementById('nombre').value = prod.nombre;
        document.getElementById('sku').value = prod.sku;
        document.getElementById('codigo_barras').value = prod.codigo_barras || '';
        document.getElementById('descripcion').value = prod.descripcion || '';
        document.getElementById('unidad').value = prod.unidad || '';
        document.getElementById('precio_costo').value = prod.precio_costo;
        document.getElementById('precio_venta').value = prod.precio_venta;
        document.getElementById('precio_comparacion').value = prod.precio_comparacion || 0;

        // Cargar estado
        const checkVisible = document.getElementById('visible_catalogo');
        checkVisible.checked = (prod.estado === 'activo');
        
        if (document.getElementById('stock_minimo')) {
            document.getElementById('cantidad_actual').value = prod.cantidad_actual || 0;
            document.getElementById('stock_minimo').value = prod.stock_minimo || 2;
            document.getElementById('stock_maximo').value = prod.stock_maximo || 5;
        }
        
        // Manejo de select múltiple
        const selectCats = document.querySelector('select[name="categorias[]"]');
        for (let i = 0; i < selectCats.options.length; i++) {
            selectCats.options[i].selected = false;
        }
        if (prod.categorias_ids) {
            const catIds = prod.categorias_ids.split(',');
            for (let i = 0; i < selectCats.options.length; i++) {
                if (catIds.includes(selectCats.options[i].value)) {
                    selectCats.options[i].selected = true;
                }
            }
        }
        
        M.updateTextFields();
        M.FormSelect.init(selectCats);
        M.textareaAutoResize(document.getElementById('descripcion'));
        
        const btnSubmit = document.getElementById('btn-submit');
        btnSubmit.innerHTML = 'Guardar Cambios <i class="material-icons right">save</i>';
        btnSubmit.classList.remove('green');
        btnSubmit.classList.add('blue');
        
        document.getElementById('form-title').innerText = 'Editar Producto';
        document.getElementById('btn-cancel').style.display = 'inline-block';
        
        window.scrollTo({top: 0, behavior: 'smooth'});
    }
    
    function cancelarEdicion() {
        document.getElementById('form-producto').reset();
        archivosSeleccionados = []; // Limpiar acumulador
        document.getElementById('preview-container').innerHTML = '';
        document.getElementById('accion').value = 'agregar';
        document.getElementById('id_producto').value = '';
        document.getElementById('precio_comparacion').value = 0;

        // Resetear estado
        const checkVisible = document.getElementById('visible_catalogo');
        checkVisible.checked = true;
        
        const selectCats = document.querySelector('select[name="categorias[]"]');
        for (let i = 0; i < selectCats.options.length; i++) {
            selectCats.options[i].selected = false;
        }
        
        M.updateTextFields();
        M.FormSelect.init(selectCats);
        M.textareaAutoResize(document.getElementById('descripcion'));
        
        const btnSubmit = document.getElementById('btn-submit');
        btnSubmit.innerHTML = 'Agregar Producto <i class="material-icons right">add</i>';
        btnSubmit.classList.remove('blue');
        btnSubmit.classList.add('green');
        
        document.getElementById('form-title').innerText = 'Agregar Nuevo Producto';
        document.getElementById('btn-cancel').style.display = 'none';
    }

    function aplicarFiltros() {
        const busqueda = document.getElementById('buscar_producto').value.toLowerCase();
        const estadoFiltro = document.getElementById('filtro_estado').value;
        const rows = document.querySelectorAll('table.striped tbody tr');
        
        rows.forEach(row => {
            const nombre = row.cells[1] ? row.cells[1].textContent.toLowerCase() : '';
            const sku = row.cells[2] ? row.cells[2].textContent.toLowerCase() : '';
            // El estado está en la celda 4 (badge)
            const estadoActual = row.cells[4] ? row.cells[4].textContent.trim().toLowerCase() : '';
            
            const coincideBusqueda = nombre.includes(busqueda) || sku.includes(busqueda);
            const coincideEstado = (estadoFiltro === 'todos') || (estadoActual === estadoFiltro);
            
            if (coincideBusqueda && coincideEstado) {
                row.style.display = '';
            } else {
                row.style.display = 'none';
            }
        });
    }

    document.getElementById('buscar_producto').addEventListener('keyup', aplicarFiltros);
    document.getElementById('filtro_estado').addEventListener('change', aplicarFiltros);

    document.addEventListener('DOMContentLoaded', function() {
        aplicarFiltros(); // Aplicar filtro por defecto (Activos) al cargar
        <?php if (isset($success) && $success): ?>
            M.toast({html: '<?php echo esc($success); ?>', classes: 'green darken-1 rounded', displayLength: 4000});
        <?php endif; ?>
    });
</script>

<?php include __DIR__ . '/includes/footer.php'; ?>
