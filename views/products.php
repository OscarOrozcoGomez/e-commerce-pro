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
                            <input type="text" id="nombre_variante" name="nombre_variante" placeholder="Ej: 240 Caps, 500mg, Sabor Fresa">
                            <label for="nombre_variante" class="active">Valor de la Variante (Lo que lo hace único)</label>
                        </div>

                        <div class="input-field">
                            <input type="text" id="sku" name="sku">
                            <label for="sku">SKU (Código Interno)</label>
                        </div>
                        
                        <div class="input-field">
                            <input type="text" id="codigo_barras" name="codigo_barras">
                            <label for="codigo_barras">Código de Barras</label>
                        </div>
                        
                        <div class="input-field">
                            <textarea id="descripcion" name="descripcion" class="materialize-textarea" placeholder="Breve resumen comercial..."></textarea>
                            <label for="descripcion">Descripción</label>
                        </div>

                        <div class="input-field">
                            <textarea id="modo_uso" name="modo_uso" class="materialize-textarea"></textarea>
                            <label for="modo_uso">Modo de Uso</label>
                        </div>

                        <div class="input-field">
                            <textarea id="ingredientes" name="ingredientes" class="materialize-textarea"></textarea>
                            <label for="ingredientes">Ingredientes (Lista detallada)</label>
                        </div>

                        <div class="row grey lighten-4" style="margin: 10px 0; padding: 10px; border-radius: 4px; border: 1px dashed #999;">
                            <div class="input-field col s8" style="margin: 0;">
                                <input type="text" id="blife_id" placeholder="ID B-Life">
                                <input type="hidden" name="imagenes_orden_json" id="imagenes_orden_json">
                                <label for="blife_id" class="active">Sync con B-Life (Variant ID)</label>
                            </div>
                            <div class="col s4">
                                <button type="button" class="btn blue darken-2 waves-effect" onclick="fetchBlifeData(event)">SYNC</button>
                            </div>
                            <input type="hidden" name="remote_images_urls" id="remote_images_urls">
                            <div id="blife-external-images" class="col s12" style="margin-top: 10px; display: none;"></div>
                        </div>

                        <div class="input-field">
                            <textarea id="tabla_nutrimental" name="tabla_nutrimental" class="materialize-textarea json-textarea-constrained" placeholder='[{"label":"Sodio","porcion":"0.05mg","total":"10mg"}]' oninput="renderNutritionalPreview()"></textarea>
                            <label for="tabla_nutrimental">Información Nutrimental (Formato JSON)</label>
                            <span class="helper-text">Pega aquí el array de datos o usa el formato: [{"label":"Nutriente","porcion":"X","total":"Y"}]</span>
                        </div>

                        <div class="input-field" style="margin-bottom: 20px;">
                            <div class="switch">
                                <label>
                                    Ocultar Tabla
                                    <input type="checkbox" name="mostrar_tabla" id="mostrar_tabla" value="1" checked>
                                    <span class="lever"></span>
                                    Mostrar Información Nutrimental
                                </label>
                            </div>
                        </div>

                        <div id="nutritional-preview-container" style="margin-bottom: 20px;"></div>
                        
                        <div class="input-field">
                            <select id="unidad" name="unidad" class="browser-default" style="border: 1px solid #ccc; border-radius: 4px;">
                                <option value="" disabled selected>Presentación / Unidad (Elegir)</option>
                            </select>
                            <span class="helper-text">Ej: Cápsulas, Gramos (g), Mililitros (ml)...</span>
                        </div>

                        <div class="input-field">
                            <select id="id_padre" name="id_padre" class="browser-default" style="border: 1px solid #ccc; border-radius: 4px;">
                                <option value="">Es Producto Principal (Sin Padre)</option>
                                <!-- Se llena vía JS -->
                            </select>
                            <span class="helper-text">Si este producto es una variante, selecciona el producto "Padre" aquí.</span>
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
    let colaImagenes = []; // { type: 'local'|'server', file: File|null, path: string|null, preview: string }

    // Función para expandir/colapsar variantes
    window.toggleVariants = function(id) {
        const el = document.getElementById(id);
        if (!el) return;
        const isHidden = el.style.display === 'none';
        el.style.display = isHidden ? 'block' : 'none';
    };

    window.fetchBlifeData = function(e) {
        const btn = e.currentTarget;
        const variantId = document.getElementById('blife_id').value.trim();
        if (!variantId) {
            M.toast({html: 'Ingresa un ID de B-Life', classes: 'orange'});
            return;
        }
        btn.disabled = true;
        const originalText = btn.innerText;
        btn.innerText = '...';

        fetch(`${BASE_API}?action=fetch_blife_info&variant_id=${variantId}`)
            .then(r => r.json())
            .then(res => {
                if(!res.success) throw new Error(res.message);
                
                const fullData = res.blife_data;
                
                // 1. Llenar Ingredientes y Modo de Uso si vienen en la API
                if (fullData.producto) {
                    // Extraer ingredientes: puede venir en .ingredients o como una fila en la tabla
                    let rawIng = fullData.producto.ingredients || '';
                    if (!rawIng && fullData.rows) {
                        const found = fullData.rows.find(r => r[0]?.value?.toLowerCase().includes('ingredientes'));
                        if (found) rawIng = found[1]?.value || found[2]?.value || '';
                    }

                    if (rawIng) {
                        // Limpiar, separar por comas y formatear con bullets
                        const listIng = rawIng.replace(/^ingredientes:?\s*/i, '')
                            .split(',')
                            .map(i => i.trim())
                            .filter(i => i.length > 1)
                            .map(i => '• ' + i.charAt(0).toUpperCase() + i.slice(1))
                            .join('\n');
                        document.getElementById('ingredientes').value = listIng;
                    }

                    // Extraer Modo de Uso: puede venir en .mode_use o como una fila en la tabla
                    let rawUso = fullData.producto.mode_use || '';
                    if (!rawUso && fullData.rows) {
                        const foundUso = fullData.rows.find(r => 
                            r[0]?.value?.toLowerCase().includes('modo de uso') || 
                            r[0]?.value?.toLowerCase().includes('instrucciones') ||
                            r[0]?.value?.toLowerCase().includes('modo de empleo')
                        );
                        if (foundUso) rawUso = foundUso[1]?.value || foundUso[2]?.value || '';
                    }
                    if (rawUso) {
                        const cleanUso = rawUso.replace(/^(modo de uso|instrucciones|modo de empleo):?\s*/i, '');
                        document.getElementById('modo_uso').value = cleanUso.charAt(0).toUpperCase() + cleanUso.slice(1);
                    }
                    
                    // 2. Extraer presentación de la variante (ej: 90 Caps)
                    if (fullData.producto.variante && fullData.producto.variante.title) {
                        document.getElementById('unidad').value = fullData.producto.variante.title;
                    }

                    // 3. Normalizar nombre base para agrupamiento (Sin el conteo de caps al final)
                    if (fullData.producto.title)
                        document.getElementById('nombre').value = fullData.producto.title;
                }

                // 2. Intentar extraer la lista de nutrientes
                let list = [];
                if (fullData.rows && Array.isArray(fullData.rows)) {
                    // Estructura de "rows" detectada en la respuesta de B-Life
                    list = fullData.rows.map(r => ({
                        label: (r[0]?.value || '-').replace(/\n/g, ' '),
                        porcion: (r[1]?.value || '-').replace(/\n/g, ' '),
                        total: (r[2]?.value || '-').replace(/\n/g, ' ')
                    })).filter(item => {
                        // Excluir filas que ya extrajimos a campos de texto para evitar duplicidad en el JSON
                        const lbl = item.label.toLowerCase();
                        return !lbl.includes('ingredientes') && 
                               !lbl.includes('modo de uso') && 
                               !lbl.includes('instrucciones') && 
                               !lbl.includes('modo de empleo');
                    });
                } else {
                    let data = fullData?.data || fullData;
                    let rawList = Array.isArray(data) ? data : (data.nutritional_information || []);
                    
                    list = rawList.map(n => ({
                        label: n.name || n.nutrient || n.label || 'Desconocido',
                        porcion: n.amount_per_serving || n.serving || n.porcion || '0',
                        total: n.amount_per_100g || n.total || '0'
                    }));
                }
                
                // 3. Guardar solo lo necesario (filtrado)
                document.getElementById('tabla_nutrimental').value = list.length > 0 ? JSON.stringify(list) : '[]';

                // 4. Capturar y mostrar previsualización de imágenes externas para importación automática
                console.log("Datos de Variante B-Life recibidos:", fullData.producto?.variante);

                if (fullData.producto?.variante) {
                    const v = fullData.producto.variante;
                    
                    // Función para extraer la URL real de forma segura
                    const limpiarUrl = (img) => {
                        if (!img) return null;
                        // Si es un objeto, intentar sacar la propiedad de imagen
                        let url = (typeof img === 'object' && img !== null) ? (img.src || img.url || img.image_url || null) : img;
                        
                        if (typeof url !== 'string' || url === '') return null;
                        
                        url = url.trim();
                        const basura = ['null', 'undefined', '[object object]', 'false', 'true', 'none', 'nan'];
                        if (basura.includes(url.toLowerCase())) return null;
                        
                        if (url.startsWith('//')) url = 'https:' + url;
                        
                        // Validar que sea una URL absoluta, que tenga extensión de imagen y no sea un placeholder
                        const esValida = url.startsWith('http') && 
                                         /\.(jpg|jpeg|png|webp|gif)/i.test(url) && 
                                         !url.includes('no-image') &&
                                         !url.includes('no-product') && 
                                         !url.includes('placeholder');
                        
                        return esValida ? url : null;
                    };

                    // Procesar todas las fuentes posibles y eliminar nulos/duplicados
                    let urls = [v.featuredImage, v.secondaryImage, ...(v.gallery || [])]
                        .map(limpiarUrl)
                        .filter(Boolean);
                    
                    urls = [...new Set(urls)]; // Eliminar duplicados después de normalizar el protocolo

                    urls.forEach(url => {
                        // Evitar duplicados: Si la URL ya está (como remote) o si el nombre del archivo ya existe en la cola
                        const nombreArchivo = url.split('/').pop().split('?')[0];
                        const existe = colaImagenes.some(item => 
                            item.path === url || (item.path && item.path.includes(nombreArchivo))
                        );

                        if (!existe) {
                            colaImagenes.push({ type: 'remote', path: url, preview: url });
                        }
                    });
                    renderPreviews();
                }
                
                M.textareaAutoResize(document.getElementById('tabla_nutrimental'));
                M.textareaAutoResize(document.getElementById('ingredientes'));
                M.textareaAutoResize(document.getElementById('modo_uso'));
                M.updateTextFields();
                renderNutritionalPreview();
                M.toast({html: 'Información importada de B-Life', classes: 'green'});
            })
            .catch(err => M.toast({html: 'Error: ' + err.message, classes: 'red'}))
            .finally(() => { btn.disabled = false; btn.innerText = originalText; });
    };

    window.renderNutritionalPreview = function() {
        const raw = document.getElementById('tabla_nutrimental').value.trim();
        const container = document.getElementById('nutritional-preview-container');
        const toggle = document.getElementById('mostrar_tabla');

        if (!raw || (toggle && !toggle.checked)) {
            container.innerHTML = '';
            return;
        }
        try {
            const data = JSON.parse(raw);
            let list = [];
            
            if (Array.isArray(data)) {
                list = data;
            } else if (data && data.rows && Array.isArray(data.rows)) {
                // Manejo de estructura raw de B-Life
                list = data.rows.map(r => ({
                    label: (r[0]?.value || '-').replace(/\n/g, ' '),
                    porcion: (r[1]?.value || '-').replace(/\n/g, ' '),
                    total: (r[2]?.value || '-').replace(/\n/g, ' ')
                }));
            } else {
                const rawList = data.nutritional_information || data.data || [];
                list = rawList.map(item => ({
                    label: item.label || item.name || item.nutrient || '-',
                    porcion: item.porcion || item.amount_per_serving || item.serving || '-',
                    total: item.total || item.amount_per_100g || '-'
                }));
            }
            
            if (list.length === 0) throw new Error("No data");

            let html = '<table class="striped centered centered-table-preview"><thead><tr><th>Nutriente</th><th>Porción</th><th>100g</th></tr></thead><tbody>';
            list.forEach(item => {
                html += `<tr><td>${item.label}</td><td>${item.porcion}</td><td>${item.total}</td></tr>`;
            });
            html += '</tbody></table>';
            container.innerHTML = html;
        } catch (e) {
            container.innerHTML = '<p class="red-text" style="font-size:0.8rem;">⚠️ Datos inválidos o formato no reconocido. La tabla no se generará.</p>';
        }
    };

    window.renderPreviews = function() {
        const container = document.getElementById('preview-container');
        if (!container) return;
        container.innerHTML = '';

        colaImagenes.forEach((item, index) => {
            const div = document.createElement('div');
            div.className = 'col s4 m2 preview-item';
            div.style.position = 'relative';
            div.setAttribute('data-index', index);
            const labelBg = index === 0 ? '#2e7d32' : '#546e7a';
            div.innerHTML = `
                <div class="card" style="margin: 5px 0;">
                    <div class="card-image">
                        <img src="${item.preview}" style="height: 60px; object-fit: cover;">
                        <span style="position: absolute; top:0; left:0; background:${labelBg}; color:white; font-size:9px; padding:2px 5px; width:100%; text-align:center;">
                            ${index === 0 ? 'PRINCIPAL' : 'GALERÍA'}
                        </span>
                        <button type="button" onclick="quitarImagen(${index})" class="btn-floating btn-small red" style="position:absolute; top:-10px; right:-10px; width:24px; height:24px;">
                            <i class="material-icons" style="line-height:24px; font-size:14px;">close</i>
                        </button>
                        ${index > 0 ? `<button type="button" onclick="hacerPrincipal(${index})" class="btn-flat white-text" style="position:absolute; bottom:0; left:0; width:100%; background:rgba(0,0,0,0.5); font-size:8px; padding:0; height:18px;">SUBIR A PRINCIPAL</button>` : ''}
                    </div>
                </div>`;
            container.appendChild(div);
        });
    };

    window.quitarImagen = function(index) {
        colaImagenes.splice(index, 1);
        renderPreviews();
    };

    window.hacerPrincipal = function(index) {
        const item = colaImagenes.splice(index, 1)[0];
        colaImagenes.unshift(item); 
        renderPreviews();
    };

    document.addEventListener('DOMContentLoaded', () => {
        cargarDependencias();

        // Actualizar previsualización cuando se mueva el toggle de mostrar/ocultar
        document.getElementById('mostrar_tabla')?.addEventListener('change', renderNutritionalPreview);

        // Inicializar el Drag and Drop en el contenedor de previsualización
        const previewContainer = document.getElementById('preview-container');
        if (previewContainer) {
            new Sortable(previewContainer, {
                animation: 150,
                ghostClass: 'blue-lighten-5',
                onEnd: function() {
                    const nuevaCola = [];
                    previewContainer.querySelectorAll('.preview-item').forEach(el => {
                        const indexOriginal = el.getAttribute('data-index');
                        nuevaCola.push(colaImagenes[indexOriginal]);
                    });
                    colaImagenes = nuevaCola;
                    renderPreviews(); // Re-renderizar para actualizar etiquetas (Principal/Galería)
                }
            });
        }

        // Previsualización de imágenes seleccionadas
        document.getElementById('input-imagenes').addEventListener('change', function(e) {
            Array.from(this.files).forEach(file => {
                const reader = new FileReader();
                reader.onload = (event) => {
                    colaImagenes.push({ type: 'local', file: file, preview: event.target.result });
                    renderPreviews();
                };
                reader.readAsDataURL(file);
            });
            this.value = '';
        });
        
        // Manejar envío de formularios
        document.getElementById('form-producto').addEventListener('submit', function(e) {
            e.preventDefault();
            const formData = new FormData(this);
            const ordenMapa = [];
            let localIdx = 0;
            
            formData.delete('imagenes[]'); 
            colaImagenes.forEach(item => {
                if (item.type === 'local') {
                    formData.append('imagenes[]', item.file);
                    ordenMapa.push('local:' + localIdx++);
                } else if (item.type === 'server') {
                    ordenMapa.push('server:' + item.path);
                } else if (item.type === 'remote') {
                    ordenMapa.push('remote:' + item.path);
                }
            });
            formData.append('imagenes_orden_json', JSON.stringify(ordenMapa));
            
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

                // Llenar Presentaciones
                const presSelect = document.getElementById('unidad');
                if(presSelect && res.presentaciones) {
                    presSelect.innerHTML = '<option value="" disabled selected>Tipo de Presentación</option>' + 
                        res.presentaciones.map(p => `<option value="${p}">${p}</option>`).join('');
                }
                
                // Llenar lista de productos padre para asociación
                const parentSelect = document.getElementById('id_padre');
                if(parentSelect && res.productos_padre) {
                    parentSelect.innerHTML = '<option value="">Es Producto Principal (Sin Padre)</option>' + 
                        res.productos_padre.map(p => {
                            const variante = p.nombre_variante ? ` (${p.nombre_variante})` : '';
                            const sku = p.sku ? ` [${p.sku}]` : '';
                            return `<option value="${p.id_producto}">${p.nombre}${variante}${sku}</option>`;
                        }).join('');
                }

                cargarProductos(res.almacenes[0].id_almacen);
            });
    }

    function cargarProductos(almacenId) {
        const tbody = document.getElementById('tabla-productos-body');
        fetch(`${BASE_API}?action=list&almacen_id=${almacenId}`)
            .then(r => r.json())
            .then(res => {
                if(!res.success) throw new Error(res.message);
                
                // Agrupar productos por nombre base para visualización limpia estilo Odoo
                const grouped = res.data.reduce((acc, p) => {
                    const key = p.nombre.trim();
                    if (!acc[key]) acc[key] = [];
                    acc[key].push(p);
                    return acc;
                }, {});

                let html = '';
                // Ordenar alfabéticamente por nombre de producto
                Object.keys(grouped).sort().forEach(name => {
                    const variants = grouped[name];
                    if (variants.length === 1) {
                        html += renderRow(variants[0]);
                    } else {
                        html += renderGroupedRow(variants);
                    }
                });
                tbody.innerHTML = html;
                aplicarFiltros();
            })
            .catch(err => {
                tbody.innerHTML = `<tr><td colspan="8" class="center red-text">${err.message}</td></tr>`;
            });
    }

    // Ayudante JS para resolver la URL de la imagen similar a la función de PHP
    function getProductImgUrl(imgData) {
        let baseUrl = '<?php echo BASE_URL; ?>';
        if (!imgData || typeof imgData !== 'string' || imgData === 'NULL' || imgData === 'undefined' || imgData === '') {
            return '';
        }
        
        if (!baseUrl.endsWith('/')) baseUrl += '/';

        // Si ya es una URL completa, devolverla tal cual para evitar rutas deformes
        if (imgData.startsWith('http')) return imgData;
        
        // Si es una ruta de archivo (contiene carpeta o tiene extensión de imagen)
        if (imgData.includes('/') || /\.(jpg|jpeg|png|webp|gif|svg)$/i.test(imgData)) {
            const cleanPath = imgData.replace(/^\/+/, '');
            const finalUrl = baseUrl + 'assets/img/products/' + cleanPath;
            return finalUrl;
        }
        
        // Si es Base64
        if (imgData.includes('data:image') || imgData.length > 500) {
            return imgData.includes('data:image') ? imgData : `data:image/jpeg;base64,${imgData}`;
        }
        
        console.warn("No se pudo resolver la URL de la imagen para:", imgData);
        return '';
    }

    function renderRow(p) {
        let imgSrc = getProductImgUrl(p.imagen);
        const isLow = (parseInt(p.cantidad_actual) || 0) <= (parseInt(p.stock_minimo) || 2);
        const jsonP = JSON.stringify(p).replace(/'/g, "&apos;");

        return `
            <tr data-codes="${(p.codigo_barras || '').toLowerCase()}">
                <td>${imgSrc ? `<img src="${imgSrc}" style="width: 60px; height: 60px; object-fit: contain; background: #f5f5f5;" class="circle shadow-1">` : ''}</td>
                <td>${p.nombre} ${p.nombre_variante ? `<br><small class="blue-text">(${p.nombre_variante})</small>` : ''}</td>
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

    function renderGroupedRow(variants) {
        const p = variants[0]; // Producto base para datos genéricos
        const totalStock = variants.reduce((sum, v) => sum + (parseInt(v.cantidad_actual) || 0), 0);
        const minP = Math.min(...variants.map(v => parseFloat(v.precio_venta)));
        const maxP = Math.max(...variants.map(v => parseFloat(v.precio_venta)));
        const priceRange = minP === maxP ? `$${minP.toFixed(2)}` : `$${minP.toFixed(2)} - $${maxP.toFixed(2)}`;
        const groupId = 'variants-list-' + p.id_producto;
        
        let imgSrc = getProductImgUrl(p.imagen);
        
        // Lista de botones para cada variante
        let variantsHtml = `<div id="${groupId}" style="display:none; max-height: 200px; overflow-y: auto; border: 1px solid #bbdefb; border-radius: 4px; padding: 8px; background: #f1f8ff; min-width: 220px; margin-top:8px; box-shadow: inset 0 1px 3px rgba(0,0,0,0.1);">`;
        variants.forEach(v => {
            const jsonV = JSON.stringify(v).replace(/'/g, "&apos;");
            
            // Mejorar etiqueta visual en la lista de variantes del Admin
            let label = v.nombre_variante || '';
            let unit = (v.unidad && v.unidad.toLowerCase() !== 'unidades') ? v.unidad : '';
            if (unit && label && !label.toLowerCase().includes(unit.toLowerCase())) {
                label += ' ' + unit;
            } else if (!label) {
                label = v.unidad || v.sku;
            }

            variantsHtml += `
                <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:4px; padding-bottom:2px; border-bottom:1px dashed #eee;">
                    <span style="font-size:0.75rem; color:#444;">${label} (${v.cantidad_actual})</span>
                    <div>
                        <button type="button" class="btn-flat btn-small blue-text" style="height:18px; line-height:18px; padding:0 4px; font-size:10px; font-weight:bold;" onclick='abrirEditar(${jsonV})'>EDITAR</button>
                        <button type="button" class="btn-flat btn-small red-text" style="height:18px; line-height:18px; padding:0 4px; font-size:10px; font-weight:bold;" onclick='eliminarProducto(${v.id_producto})'>BORRAR</button>
                    </div>
                </div>`;
        });
        variantsHtml += '</div>';

        // Juntar todos los códigos para que el buscador funcione
        const allCodes = variants.map(v => v.codigo_barras).join(' ');

        return `
            <tr class="product-group-row" data-codes="${allCodes.toLowerCase()}">
                <td>${imgSrc ? `<img src="${imgSrc}" style="width: 60px; height: 60px; object-fit: contain; background: #f5f5f5;" class="circle shadow-1">` : ''}</td>
                <td>
                    <strong style="color: #1a237e; font-size: 1.1rem;">${p.nombre}</strong><br>
                    <button type="button" class="btn-small blue darken-2 waves-effect waves-light" style="font-size:0.65rem; height:24px; line-height:24px; padding:0 8px; border-radius:4px; margin-top:4px;" onclick="toggleVariants('${groupId}')">
                        <i class="material-icons left" style="font-size:1rem; margin-right:4px;">unfold_more</i>
                        ${variants.length} PRESENTACIONES
                    </button>
                </td>
                <td class="green-text text-darken-3" style="font-weight:bold;">${priceRange}</td>
            <td><span class="badge blue white-text" style="float:none; border-radius:4px;">ACTIVO</span></td>
                <td class="center-align">
                    <span class="badge blue white-text" style="float: none; font-weight: bold; border-radius: 4px;">${totalStock}</span>
                </td>
                <td class="center-align grey-text">--</td>
                <td>${variantsHtml}</td>
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
        document.getElementById('nombre_variante').value = prod.nombre_variante || '';
        document.getElementById('sku').value = prod.sku || '';
        document.getElementById('codigo_barras').value = prod.codigo_barras || '';
        document.getElementById('descripcion').value = prod.descripcion || '';
        document.getElementById('modo_uso').value = prod.modo_uso || '';
        document.getElementById('ingredientes').value = prod.ingredientes || '';
        document.getElementById('tabla_nutrimental').value = prod.tabla_nutrimental || '';
        document.getElementById('unidad').value = prod.unidad || '';
        document.getElementById('mostrar_tabla').checked = (prod.mostrar_tabla == 1);
        document.getElementById('precio_costo').value = prod.precio_costo;
        document.getElementById('id_padre').value = prod.id_padre || '';
        document.getElementById('precio_venta').value = prod.precio_venta;
        document.getElementById('precio_comparacion').value = prod.precio_comparacion || 0;

        // Cargar imágenes actuales a la cola
        colaImagenes = [];
        const rutasVistas = new Set();

        if (prod.imagen && prod.imagen !== 'NULL' && prod.imagen !== '') {
            colaImagenes.push({ type: 'server', path: prod.imagen, preview: getProductImgUrl(prod.imagen) });
            rutasVistas.add(prod.imagen);
        }

        if (prod.galeria_paths) {
            prod.galeria_paths.split(',').forEach(p => {
                if (p && p !== 'NULL' && !rutasVistas.has(p)) {
                    colaImagenes.push({ type: 'server', path: p, preview: getProductImgUrl(p) });
                    rutasVistas.add(p);
                }
            });
        }
        renderPreviews();

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
        M.textareaAutoResize(document.getElementById('modo_uso'));
        M.textareaAutoResize(document.getElementById('ingredientes'));
        M.textareaAutoResize(document.getElementById('tabla_nutrimental'));
        renderNutritionalPreview();
        
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
        colaImagenes = []; 
        renderPreviews();
        document.getElementById('blife_id').value = '';
        document.getElementById('remote_images_urls').value = '';
        document.getElementById('blife-external-images').innerHTML = '';
        document.getElementById('mostrar_tabla').checked = true;
        document.getElementById('accion').value = 'agregar';
        document.getElementById('sku').value = '';
        document.getElementById('nombre_variante').value = '';
        document.getElementById('id_padre').value = '';
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
        M.textareaAutoResize(document.getElementById('modo_uso'));
        M.textareaAutoResize(document.getElementById('ingredientes'));
        M.textareaAutoResize(document.getElementById('tabla_nutrimental'));
        renderNutritionalPreview();
        
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
            const codigo = (row.getAttribute('data-codes') || '');
            
            // El estado ahora está en la celda 3 tras quitar la de Código de Barras
            const estadoActual = row.cells[3] ? row.cells[3].textContent.trim().toLowerCase() : '';
            
            const coincideBusqueda = nombre.includes(busqueda) || codigo.includes(busqueda);
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

<style>
    .centered-table-preview {
        font-size: 0.8rem;
        border: 1px solid #e0e0e0;
    }
    .centered-table-preview th, .centered-table-preview td {
        padding: 5px;
    }
        /* Limitar el crecimiento del textarea de JSON y permitir scroll interno */
        .json-textarea-constrained {
            max-height: 180px !important;
            overflow-y: auto !important;
            padding: 10px !important;
            border: 1px solid #ddd !important;
            box-sizing: border-box !important;
            font-family: monospace;
            font-size: 0.85rem !important;
        }
</style>
<?php include __DIR__ . '/includes/footer.php'; ?>
