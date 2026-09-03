<?php
declare(strict_types=1);

require_once __DIR__ . '/../core/config.php';
require_once __DIR__ . '/../core/auth.php';
require_once __DIR__ . '/../core/lote_caducidad_utils.php';

requireAuth();
if (!isAdmin() && !isEncargado()) {
    header('Location: ' . BASE_URL . 'views/dashboard.php');
    exit;
}

$pageTitle = 'Control de Caducidades';
$pdo = getPDO();

$tablaLista = loteTablaExiste($pdo, 'lotes_inventario');

$almacenes = $tablaLista
    ? $pdo->query("SELECT id_almacen, nombre FROM almacenes WHERE estado = 'activo' ORDER BY nombre")->fetchAll(PDO::FETCH_ASSOC)
    : [];
$categorias = $tablaLista
    ? $pdo->query("SELECT DISTINCT categoria FROM productos WHERE categoria IS NOT NULL AND categoria <> '' ORDER BY categoria")->fetchAll(PDO::FETCH_COLUMN)
    : [];
$productos = $tablaLista
    ? $pdo->query("SELECT id_producto, nombre, sku, codigo_barras, capsulas_por_envase FROM productos WHERE estado = 'activo' ORDER BY nombre")->fetchAll(PDO::FETCH_ASSOC)
    : [];

include __DIR__ . '/includes/header.php';
?>
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/sweetalert2@11/dist/sweetalert2.min.css">

<div class="container" style="max-width: 1400px;">
    <div style="display:flex;align-items:center;justify-content:space-between;margin-top:20px;flex-wrap:wrap;gap:10px;">
        <h4 style="margin:0;"><i class="material-icons left" style="font-size:2.4rem;color:#ef6c00;">event_busy</i> Control de Caducidades</h4>
        <div>
            <a href="dashboard.php" class="btn grey lighten-1 waves-effect"><i class="material-icons left">arrow_back</i>Dashboard</a>
            <a href="#modal-lote" class="btn orange darken-2 modal-trigger waves-effect" id="btn-nuevo-lote"><i class="material-icons left">add</i>Nuevo lote</a>
        </div>
    </div>
    <p class="grey-text">Compara la cantidad de cada lote contra su fecha de caducidad y la velocidad de venta de los últimos <span id="ventana-dias">90</span> días. Te avisa con anticipación cuáles no alcanzarán a venderse para poder ponerlos en oferta.</p>

    <?php if (!$tablaLista): ?>
        <div class="card orange lighten-4 orange-text text-darken-4" style="padding:16px;">
            <i class="material-icons left">warning</i>
            Falta aplicar la migración <code>20260901_000001_crear_tabla_lotes_inventario.sql</code>.
            Ejecuta <code>php scripts/migrate.php</code> y recarga.
        </div>
    <?php else: ?>

    <div class="row" id="resumen-cards">
        <div class="col s6 m3"><div class="card red darken-1 white-text center-align" style="padding:14px;"><div style="font-size:2rem;font-weight:700;" id="r-critico">–</div><div>Críticas (&lt;30 días)</div><div class="small" id="r-novendible" style="opacity:.85;"></div></div></div>
        <div class="col s6 m3"><div class="card deep-orange darken-1 white-text center-align" style="padding:14px;"><div style="font-size:2rem;font-weight:700;" id="r-urgente">–</div><div>Urgentes (30–90)</div></div></div>
        <div class="col s6 m3"><div class="card amber darken-2 white-text center-align" style="padding:14px;"><div style="font-size:2rem;font-weight:700;" id="r-planificar">–</div><div>Planificar (90–180)</div></div></div>
        <div class="col s6 m3"><div class="card grey darken-1 white-text center-align" style="padding:14px;"><div style="font-size:2rem;font-weight:700;" id="r-vigilar">–</div><div>Vigilar / caducados</div></div></div>
    </div>

    <div class="card" style="padding:14px;">
        <div class="row" style="margin-bottom:0;">
            <div class="input-field col s12 m3">
                <select id="f-severidad">
                    <option value="">Todas las severidades</option>
                    <option value="critico">Crítico</option>
                    <option value="urgente">Urgente</option>
                    <option value="planificar">Planificar</option>
                    <option value="vigilar">Vigilar</option>
                    <option value="caducado">Caducado</option>
                    <option value="sin_rotacion">Sin rotación</option>
                    <option value="sin_historico">Sin histórico</option>
                    <option value="ok">Ok</option>
                </select>
                <label>Severidad</label>
            </div>
            <div class="input-field col s12 m3">
                <select id="f-almacen">
                    <option value="">Todos los almacenes</option>
                    <?php foreach ($almacenes as $a): ?>
                        <option value="<?php echo (int) $a['id_almacen']; ?>"><?php echo esc($a['nombre']); ?></option>
                    <?php endforeach; ?>
                </select>
                <label>Almacén</label>
            </div>
            <div class="input-field col s12 m3">
                <select id="f-categoria">
                    <option value="">Todas las categorías</option>
                    <?php foreach ($categorias as $c): ?>
                        <option value="<?php echo esc((string) $c); ?>"><?php echo esc((string) $c); ?></option>
                    <?php endforeach; ?>
                </select>
                <label>Categoría</label>
            </div>
            <div class="input-field col s12 m3">
                <input type="text" id="f-q" placeholder="Producto, SKU o lote">
                <label for="f-q">Buscar</label>
            </div>
        </div>
        <label style="margin-left:6px;">
            <input type="checkbox" id="f-excedente" class="filled-in" />
            <span>Solo lotes con excedente proyectado</span>
        </label>
    </div>

    <div id="preloader" class="center-align" style="padding:40px;">
        <div class="preloader-wrapper active"><div class="spinner-layer spinner-orange-only"><div class="circle-clipper left"><div class="circle"></div></div><div class="gap-patch"><div class="circle"></div></div><div class="circle-clipper right"><div class="circle"></div></div></div></div>
    </div>

    <div class="card" id="tabla-wrap" style="display:none;overflow-x:auto;">
        <table class="striped highlight">
            <thead>
                <tr>
                    <th>Producto</th><th>Lote</th><th>Caduca</th><th>Días</th>
                    <th>Restante</th><th>Vel. 90d</th><th>Se agota</th>
                    <th>Excedente</th><th title="Días que rinde un envase vs días que faltan para caducar">Rinde / margen</th>
                    <th>Severidad</th><th></th>
                </tr>
            </thead>
            <tbody id="tabla-body"></tbody>
        </table>
        <p id="tabla-vacia" class="grey-text center-align" style="display:none;padding:20px;">Sin lotes que coincidan con el filtro.</p>
    </div>
    <?php endif; ?>
</div>

<!-- Modal alta / edición -->
<div id="modal-lote" class="modal modal-fixed-footer">
    <div class="modal-content">
        <h5 id="modal-lote-titulo">Nuevo lote</h5>

        <div class="card grey lighten-4" style="padding:14px;">
            <strong><i class="material-icons tiny">photo_camera</i> Leer con la cámara</strong>
            <p class="grey-text" style="margin:6px 0;">Foto del bote (lote + caducidad), foto de la tabla nutrimental (cápsulas por envase, porción) y/o el código de barras. Se prellenan los campos; revísalos antes de guardar.</p>
            <div class="row" style="margin-bottom:0;">
                <div class="col s12 m4">
                    <div class="file-field input-field">
                        <div class="btn orange darken-2"><span>Foto del lote</span>
                            <input type="file" id="foto-lote" accept="image/*" capture="environment">
                        </div>
                        <div class="file-path-wrapper"><input class="file-path" type="text" placeholder="Lote y caducidad"></div>
                    </div>
                </div>
                <div class="col s12 m4">
                    <div class="file-field input-field">
                        <div class="btn grey"><span>Tabla nutrimental</span>
                            <input type="file" id="foto-tabla" accept="image/*" capture="environment">
                        </div>
                        <div class="file-path-wrapper"><input class="file-path" type="text" placeholder="Opcional"></div>
                    </div>
                </div>
                <div class="col s12 m4">
                    <div class="file-field input-field">
                        <div class="btn blue-grey"><span>Código de barras</span>
                            <input type="file" id="foto-barcode" accept="image/*" capture="environment">
                        </div>
                        <div class="file-path-wrapper"><input class="file-path" type="text" placeholder="Foto del código"></div>
                    </div>
                </div>
            </div>
            <button type="button" class="btn-small blue darken-2 waves-effect" id="btn-leer-ocr"><i class="material-icons left">auto_fix_high</i>Leer</button>
            <span id="ocr-status" class="grey-text" style="margin-left:10px;"></span>
            <div id="ocr-extra" class="grey-text small" style="margin-top:6px;"></div>
        </div>

        <form id="form-lote">
            <?php echo csrfInput(); ?>
            <input type="hidden" name="accion" value="guardar">
            <input type="hidden" name="id_lote" id="l-id" value="0">
            <input type="hidden" name="id_producto" id="l-id-producto" value="">
            <input type="hidden" name="foto_evidencia" id="l-foto" value="">
            <input type="hidden" name="caducidad_aproximada" id="l-aprox" value="0">
            <input type="hidden" id="l-codigo-barras" value="">
            <input type="hidden" id="l-ocr-caps" value="">
            <input type="hidden" id="l-ocr-porcion" value="">

            <div class="input-field">
                <input type="text" id="l-producto-busca" class="autocomplete" autocomplete="off">
                <label for="l-producto-busca">Producto (nombre o SKU)</label>
            </div>
            <div class="row">
                <div class="input-field col s12 m6">
                    <input type="text" name="codigo_lote" id="l-codigo" required>
                    <label for="l-codigo">Código de lote</label>
                </div>
                <div class="input-field col s12 m6">
                    <input type="date" name="fecha_caducidad" id="l-fecha" required>
                    <label for="l-fecha" class="active">Fecha de caducidad</label>
                </div>
            </div>
            <p style="margin:0 0 10px;">
                <label><input type="checkbox" class="filled-in" id="l-solo-mes"><span>Solo conozco mes y año (uso día 01)</span></label>
            </p>
            <div class="row">
                <div class="input-field col s12 m6">
                    <input type="number" min="0" id="l-cantidad" required>
                    <label for="l-cantidad">Cantidad</label>
                </div>
                <div class="input-field col s12 m6">
                    <select id="l-almacen" name="id_almacen">
                        <option value="">Sin asignar</option>
                        <?php foreach ($almacenes as $a): ?>
                            <option value="<?php echo (int) $a['id_almacen']; ?>"><?php echo esc($a['nombre']); ?></option>
                        <?php endforeach; ?>
                    </select>
                    <label>Almacén</label>
                </div>
            </div>
            <p style="margin:0 0 10px;">
                <label><input type="checkbox" class="filled-in" id="l-en-capsulas"><span>Capturé la cantidad en cápsulas (dividir entre cápsulas por envase)</span></label>
                <span class="grey-text" id="l-capsulas-hint"></span>
            </p>
            <p id="l-guardar-caps-wrap" style="margin:0 0 10px;display:none;">
                <label><input type="checkbox" class="filled-in" id="l-guardar-caps"><span id="l-guardar-caps-txt">Guardar cápsulas por envase / porción en el producto</span></label>
            </p>
            <input type="hidden" name="cantidad" id="l-cantidad-final">
            <div class="row">
                <div class="input-field col s12 m6">
                    <input type="number" step="0.01" min="0" name="costo_unitario" id="l-costo">
                    <label for="l-costo">Costo unitario (opcional)</label>
                </div>
                <div class="input-field col s12 m6">
                    <input type="text" name="notas" id="l-notas">
                    <label for="l-notas">Notas (opcional)</label>
                </div>
            </div>
        </form>
    </div>
    <div class="modal-footer">
        <a href="#!" class="modal-close btn-flat">Cancelar</a>
        <button type="button" class="btn orange darken-2 waves-effect" id="btn-guardar-lote">Guardar lote</button>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
<script>
const BASE = '<?php echo BASE_URL; ?>';
const CSRF = '<?php echo getCsrfToken(); ?>';
const PRODUCTOS = <?php echo json_encode($productos, JSON_UNESCAPED_UNICODE); ?>;

const SEV = {
  critico:      {t:'Crítico',      c:'red darken-1 white-text'},
  urgente:      {t:'Urgente',      c:'deep-orange darken-1 white-text'},
  planificar:   {t:'Planificar',   c:'amber darken-2 white-text'},
  vigilar:      {t:'Vigilar',      c:'blue-grey lighten-1 white-text'},
  caducado:     {t:'Caducado',     c:'black white-text'},
  sin_rotacion: {t:'Sin rotación', c:'grey darken-1 white-text'},
  sin_historico:{t:'Sin histórico',c:'grey lighten-1'},
  ok:           {t:'Ok',           c:'green lighten-1 white-text'},
};

function esc(s){ return String(s ?? '').replace(/[&<>"]/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;'}[c])); }

let lookupProducto = {};
function initAutocomplete(){
  const data = {};
  lookupProducto = {};
  PRODUCTOS.forEach(p => {
    const label = p.sku ? `${p.nombre} (${p.sku})` : p.nombre;
    data[label] = null;
    lookupProducto[label.toLowerCase()] = p;
    lookupProducto[String(p.nombre).toLowerCase()] = p;
    if (p.sku) lookupProducto[String(p.sku).toLowerCase()] = p;
  });
  const el = document.getElementById('l-producto-busca');
  M.Autocomplete.init(el, { data, minLength: 1, limit: 8, onAutocomplete: () => resolverProducto() });
  el.addEventListener('blur', resolverProducto);
}
function resolverProducto(){
  const el = document.getElementById('l-producto-busca');
  const p = lookupProducto[el.value.trim().toLowerCase()];
  document.getElementById('l-id-producto').value = p ? p.id_producto : '';
  const hint = document.getElementById('l-capsulas-hint');
  hint.dataset.caps = p && p.capsulas_por_envase ? p.capsulas_por_envase : '';
  actualizarHintCapsulas();
}
function actualizarHintCapsulas(){
  const caps = document.getElementById('l-capsulas-hint').dataset.caps;
  const on = document.getElementById('l-en-capsulas').checked;
  document.getElementById('l-capsulas-hint').textContent =
    on ? (caps ? `÷ ${caps} cápsulas por envase` : 'El producto no tiene cápsulas por envase configuradas') : '';
}

function cargar(){
  const p = document.getElementById('preloader');
  const w = document.getElementById('tabla-wrap');
  if (p) p.style.display = 'block';
  const qs = new URLSearchParams({
    severidad: document.getElementById('f-severidad').value,
    id_almacen: document.getElementById('f-almacen').value,
    categoria: document.getElementById('f-categoria').value,
    q: document.getElementById('f-q').value,
    solo_con_excedente: document.getElementById('f-excedente').checked ? '1' : '',
  });
  fetch(`${BASE}api/caducidades_data.php?${qs}`)
    .then(r => r.json())
    .then(res => {
      if (p) p.style.display = 'none';
      if (!res.success) { M.toast({html: res.message || 'Error', classes:'red'}); return; }
      pintarResumen(res.data.resumen);
      document.getElementById('ventana-dias').textContent = res.data.ventana_dias;
      pintarTabla(res.data.lotes);
      if (w) w.style.display = 'block';
    })
    .catch(() => { if (p) p.style.display = 'none'; M.toast({html:'Error de conexión', classes:'red'}); });
}

function pintarResumen(r){
  document.getElementById('r-critico').textContent = r.critico;
  document.getElementById('r-urgente').textContent = r.urgente;
  document.getElementById('r-planificar').textContent = r.planificar;
  document.getElementById('r-vigilar').textContent = r.vigilar + r.caducado;
  const nv = document.getElementById('r-novendible');
  if (nv) nv.textContent = r.no_vendible ? `${r.no_vendible} no vendible(s)` : '';
}

let lotesActuales = [];
function pintarTabla(lotes){
  lotesActuales = lotes;
  const tb = document.getElementById('tabla-body');
  const vacia = document.getElementById('tabla-vacia');
  tb.innerHTML = '';
  vacia.style.display = lotes.length ? 'none' : 'block';
  lotes.forEach(l => {
    const sev = SEV[l.severidad] || {t:l.severidad, c:'grey'};
    const dias = l.dias_hasta_caducar;
    const exc = l.excedente_proyectado;
    const desc = l.descuento_sugerido_pct ? ` · sug. -${l.descuento_sugerido_pct}%` : '';
    const descuadre = l.descuadre ? ` <span class="new badge red" data-badge-caption="" title="lotes ${l.stock_lotes} vs stock ${l.stock_sistema}">descuadre</span>` : '';
    let rinde = '—';
    if (l.dias_tratamiento_envase != null) {
      const m = l.margen_consumo_dias;
      const cls = m != null && m < 0 ? 'red-text' : (m != null && m < 30 ? 'orange-text' : 'grey-text');
      rinde = `${l.dias_tratamiento_envase} d <span class="${cls}">(${m != null ? (m>=0?'+':'')+m : '?'} d)</span>`;
    }
    const noVend = l.no_vendible ? ' <span class="new badge red darken-3 white-text" data-badge-caption="" title="Un envase comprado hoy no se alcanza a terminar antes de caducar">NO VENDIBLE</span>' : '';
    const tr = document.createElement('tr');
    tr.innerHTML = `
      <td>${esc(l.producto_nombre)}<br><small class="grey-text">${esc(l.producto_sku||'')}${descuadre}</small></td>
      <td>${esc(l.codigo_lote)}</td>
      <td>${esc(l.fecha_caducidad)}${l.caducidad_aproximada ? ' <small class="grey-text">(aprox)</small>':''}</td>
      <td>${dias}</td>
      <td>${l.cantidad_restante}</td>
      <td>${l.vel_diaria}</td>
      <td>${l.dias_para_agotar_lote ?? '—'}</td>
      <td>${exc === null ? '—' : '<strong>'+exc+'</strong>'+desc}</td>
      <td>${rinde}</td>
      <td><span class="new badge ${sev.c}" data-badge-caption="">${sev.t}</span>${noVend}</td>
      <td>
        <a class="btn-flat btn-small" title="Ajustar cantidad" onclick="accionAjustar(${l.id_lote}, ${l.cantidad_restante})"><i class="material-icons">tune</i></a>
        <a class="btn-flat btn-small" title="Marcar atendida / en oferta" onclick="accionAtender(${l.id_lote})"><i class="material-icons">local_offer</i></a>
        <a class="btn-flat btn-small" title="Editar" onclick="accionEditar(${l.id_lote})"><i class="material-icons">edit</i></a>
        <a class="btn-flat btn-small" title="Retirar" onclick="accionEstado(${l.id_lote}, 'retirado')"><i class="material-icons">block</i></a>
        <a class="btn-flat btn-small red-text" title="Eliminar" onclick="accionEliminar(${l.id_lote})"><i class="material-icons">delete</i></a>
      </td>`;
    tb.appendChild(tr);
  });
}

function post(data){
  data.csrf_token = CSRF;
  return fetch(`${BASE}api/lotes_manager.php`, {method:'POST', body: new URLSearchParams(data)})
    .then(r => r.json());
}

function accionAjustar(id, actual){
  Swal.fire({title:'Ajustar cantidad restante', input:'number', inputValue: actual, showCancelButton:true})
    .then(r => { if (r.isConfirmed) post({accion:'ajustar', id_lote:id, cantidad:r.value}).then(despues); });
}
function accionAtender(id){
  Swal.fire({
    title:'Marcar alerta atendida', html:
      '<label><input type="checkbox" id="sw-oferta"> Ya la puse en oferta</label><br><br>'+
      '<textarea id="sw-notas" class="swal2-textarea" placeholder="Notas"></textarea>',
    showCancelButton:true,
    preConfirm: () => ({oferta: document.getElementById('sw-oferta').checked ? '1':'', notas: document.getElementById('sw-notas').value})
  }).then(r => { if (r.isConfirmed) post({accion:'marcar_atendida', id_lote:id, en_oferta:r.value.oferta, notas:r.value.notas}).then(despues); });
}
function accionEstado(id, estado){
  Swal.fire({title:`¿Marcar el lote como ${estado}?`, icon:'warning', showCancelButton:true})
    .then(r => { if (r.isConfirmed) post({accion:'cambiar_estado', id_lote:id, estado}).then(despues); });
}
function accionEliminar(id){
  Swal.fire({title:'¿Eliminar el lote?', icon:'warning', showCancelButton:true, confirmButtonColor:'#d32f2f'})
    .then(r => { if (r.isConfirmed) post({accion:'eliminar', id_lote:id}).then(despues); });
}
function despues(res){
  M.toast({html: res.message || 'Listo', classes: res.success ? 'green' : 'red'});
  if (res.success) cargar();
}

function abrirModal(l){
  document.getElementById('form-lote').reset();
  document.getElementById('l-id').value = l ? l.id_lote : 0;
  document.getElementById('l-id-producto').value = l ? l.id_producto : '';
  document.getElementById('l-foto').value = '';
  document.getElementById('l-aprox').value = l && l.caducidad_aproximada ? 1 : 0;
  document.getElementById('modal-lote-titulo').textContent = l ? 'Editar lote' : 'Nuevo lote';
  document.getElementById('ocr-status').textContent = '';
  document.getElementById('ocr-extra').innerHTML = '';
  document.getElementById('l-codigo-barras').value = '';
  document.getElementById('l-ocr-caps').value = '';
  document.getElementById('l-ocr-porcion').value = '';
  document.getElementById('l-guardar-caps-wrap').style.display = 'none';
  document.getElementById('l-guardar-caps').checked = false;
  ['foto-lote','foto-tabla','foto-barcode'].forEach(id => { const e = document.getElementById(id); if (e) e.value = ''; });
  if (l) {
    document.getElementById('l-producto-busca').value = l.producto_nombre + (l.producto_sku ? ` (${l.producto_sku})` : '');
    document.getElementById('l-codigo').value = l.codigo_lote;
    document.getElementById('l-fecha').value = l.fecha_caducidad;
    document.getElementById('l-cantidad').value = l.cantidad_restante;
    document.getElementById('l-almacen').value = l.id_almacen || '';
    document.getElementById('l-costo').value = l.costo_unitario || '';
    document.getElementById('l-notas').value = l.notas_seguimiento || '';
  }
  M.updateTextFields();
  M.FormSelect.init(document.querySelectorAll('#modal-lote select'));
  const inst = M.Modal.getInstance(document.getElementById('modal-lote'));
  inst.open();
}
function accionEditar(id){
  const l = lotesActuales.find(x => Number(x.id_lote) === Number(id));
  if (l) abrirModal(l);
}

function guardarLote(){
  const idProd = document.getElementById('l-id-producto').value;
  if (!idProd) { M.toast({html:'Selecciona un producto válido', classes:'red'}); return; }
  let cant = parseFloat(document.getElementById('l-cantidad').value || '0');
  if (document.getElementById('l-en-capsulas').checked) {
    const caps = parseFloat(document.getElementById('l-capsulas-hint').dataset.caps || '0');
    if (!caps) { M.toast({html:'El producto no tiene cápsulas por envase', classes:'red'}); return; }
    cant = Math.round(cant / caps);
  }
  document.getElementById('l-cantidad-final').value = cant;
  document.getElementById('l-aprox').value = document.getElementById('l-solo-mes').checked ? 1 : 0;

  const fd = new FormData(document.getElementById('form-lote'));
  fd.set('id_producto', idProd);
  fd.set('csrf_token', CSRF);
  fetch(`${BASE}api/lotes_manager.php`, {method:'POST', body: fd})
    .then(r => r.json())
    .then(res => {
      M.toast({html: res.message, classes: res.success ? 'green' : 'red'});
      if (!res.success) return;
      // Guardar cápsulas por envase / porción en el producto si se pidió.
      if (document.getElementById('l-guardar-caps').checked) {
        const caps = document.getElementById('l-ocr-caps').value;
        const porc = document.getElementById('l-ocr-porcion').value;
        post({accion:'actualizar_capsulas_producto', id_producto: idProd, capsulas_por_envase: caps, porcion_capsulas: porc})
          .then(r2 => M.toast({html: r2.message, classes: r2.success ? 'green' : 'red'}));
      }
      M.Modal.getInstance(document.getElementById('modal-lote')).close();
      cargar();
    });
}

async function decodificarCodigoBarras(file){
  if (!file || !('BarcodeDetector' in window)) return null;
  try {
    const det = new BarcodeDetector();
    const bmp = await createImageBitmap(file);
    const codes = await det.detect(bmp);
    return codes && codes.length ? codes[0].rawValue : null;
  } catch (e) { return null; }
}

async function leerOcr(){
  const fl = document.getElementById('foto-lote').files[0];
  const ft = document.getElementById('foto-tabla').files[0];
  const fb = document.getElementById('foto-barcode').files[0];
  if (!fl && !ft && !fb) { M.toast({html:'Elige al menos una foto', classes:'red'}); return; }
  const st = document.getElementById('ocr-status');
  st.textContent = 'Leyendo…';

  let barcode = '';
  if (fb) {
    barcode = await decodificarCodigoBarras(fb) || '';
    if (barcode) document.getElementById('l-codigo-barras').value = barcode;
  }

  const fd = new FormData();
  fd.set('csrf_token', CSRF);
  if (fl) fd.set('foto_lote', fl);
  if (ft) fd.set('foto_tabla', ft);
  if (barcode) fd.set('codigo_barras', barcode);

  fetch(`${BASE}api/lote_ocr.php`, {method:'POST', body: fd})
    .then(r => r.json())
    .then(res => {
      if (res.foto) document.getElementById('l-foto').value = res.foto;
      const d = res.data || {};
      // Producto por código de barras
      if (d.producto_match) {
        document.getElementById('l-id-producto').value = d.producto_match.id_producto;
        document.getElementById('l-producto-busca').value = d.producto_match.nombre;
        resolverProducto();
      }
      if (!res.success && !d.producto_match) { st.textContent = res.message || 'No se pudo leer'; return; }
      if (d.codigo_lote) document.getElementById('l-codigo').value = d.codigo_lote;
      if (d.fecha_caducidad) document.getElementById('l-fecha').value = d.fecha_caducidad;
      if (d.caducidad_aproximada) document.getElementById('l-solo-mes').checked = true;

      // Cápsulas por envase / porción
      const caps = d.capsulas_por_envase || null;
      const porc = d.porcion_capsulas || null;
      if (caps || porc) {
        document.getElementById('l-ocr-caps').value = caps || '';
        document.getElementById('l-ocr-porcion').value = porc || '';
        const prodCaps = document.getElementById('l-capsulas-hint').dataset.caps;
        if (caps) document.getElementById('l-capsulas-hint').dataset.caps = caps;
        actualizarHintCapsulas();
        const wrap = document.getElementById('l-guardar-caps-wrap');
        wrap.style.display = 'block';
        document.getElementById('l-guardar-caps').checked = !prodCaps;
        document.getElementById('l-guardar-caps-txt').textContent =
          `Guardar en el producto: ${caps || '?'} cáps/envase · ${porc || '?'} cáps/porción` +
          (caps && porc ? ` (rinde ≈ ${Math.floor(caps / porc)} días)` : '');
      }

      const extra = [];
      if (d.nombre_detectado) extra.push(`Nombre: ${esc(d.nombre_detectado)}`);
      if (d.marca) extra.push(`Marca: ${esc(d.marca)}`);
      if (d.contenido_neto) extra.push(`Contenido: ${esc(d.contenido_neto)}`);
      if (d.forma) extra.push(`Forma: ${esc(d.forma)}`);
      if (d.servings_por_envase) extra.push(`Porciones/envase: ${d.servings_por_envase}`);
      if (d.fecha_fabricacion) extra.push(`Fabricación: ${esc(d.fecha_fabricacion)}`);
      if (barcode) extra.push(`Cód. barras: ${esc(barcode)}`);
      document.getElementById('ocr-extra').innerHTML = extra.join(' &nbsp;·&nbsp; ');

      const conf = d.confianza != null ? ` (confianza ${Math.round(d.confianza*100)}%)` : '';
      st.textContent = `Leído${conf}. Revisa y corrige antes de guardar.`;
      M.updateTextFields();
    })
    .catch(() => { st.textContent = 'Error de conexión'; });
}

document.addEventListener('DOMContentLoaded', () => {
  M.Modal.init(document.querySelectorAll('.modal'));
  <?php if ($tablaLista): ?>
  initAutocomplete();
  ['f-severidad','f-almacen','f-categoria','f-excedente'].forEach(id =>
    document.getElementById(id).addEventListener('change', cargar));
  let t; document.getElementById('f-q').addEventListener('input', () => { clearTimeout(t); t = setTimeout(cargar, 350); });
  document.getElementById('l-en-capsulas').addEventListener('change', actualizarHintCapsulas);
  document.getElementById('btn-nuevo-lote').addEventListener('click', (e) => { e.preventDefault(); abrirModal(null); });
  document.getElementById('btn-guardar-lote').addEventListener('click', guardarLote);
  document.getElementById('btn-leer-ocr').addEventListener('click', leerOcr);
  cargar();
  <?php endif; ?>
});
</script>

<?php include __DIR__ . '/includes/footer.php'; ?>
