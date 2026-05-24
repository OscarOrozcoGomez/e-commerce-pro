<?php
declare(strict_types=1);
require_once __DIR__ . '/../core/config.php';
require_once __DIR__ . '/../core/auth.php';
requireAuth();
if (!isCliente()) { header('Location: dashboard.php'); exit; }

$pdo = getPDO();
$idCliente = $_SESSION['usuario']['id_cliente'];
$error = ''; $success = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['accion'])) {
    if (!validateCsrfToken($_POST['csrf_token'] ?? '')) {
        $error = 'Token inválido.';
    } else {
        $accion = $_POST['accion'];
        try {
            if ($accion === 'agregar') {
                $stmtCount = $pdo->prepare("SELECT COUNT(*) FROM cliente_direcciones WHERE id_cliente = ?");
                $stmtCount->execute([$idCliente]);
                if ($stmtCount->fetchColumn() >= 5) throw new Exception("Límite de 5 direcciones alcanzado.");

                $alias = trim($_POST['alias'] ?? '');
                $direccion = trim($_POST['direccion'] ?? '');
                $maps_link = trim($_POST['maps_link'] ?? '');
                if (empty($alias) || empty($direccion)) throw new Exception("Campos obligatorios.");

                $pdo->prepare("INSERT INTO cliente_direcciones (id_cliente, alias, direccion, maps_link) VALUES (?, ?, ?, ?)")
                    ->execute([$idCliente, $alias, $direccion, $maps_link]);
                $success = 'Dirección guardada.';
            } elseif ($accion === 'editar') {
                $id_dir = (int)$_POST['id_direccion'];
                $alias = trim($_POST['alias'] ?? '');
                $direccion = trim($_POST['direccion'] ?? '');
                $maps_link = trim($_POST['maps_link'] ?? '');
                if (empty($alias) || empty($direccion)) throw new Exception("Campos obligatorios.");

                $pdo->prepare("UPDATE cliente_direcciones SET alias = ?, direccion = ?, maps_link = ? WHERE id_direccion = ? AND id_cliente = ?")
                    ->execute([$alias, $direccion, $maps_link, $id_dir, $idCliente]);
                $success = 'Dirección actualizada correctamente.';
            } elseif ($accion === 'eliminar') {
                $id_dir = (int)$_POST['id_direccion'];
                $pdo->prepare("DELETE FROM cliente_direcciones WHERE id_direccion = ? AND id_cliente = ?")
                    ->execute([$id_dir, $idCliente]);
                $success = 'Dirección eliminada.';
            } elseif ($accion === 'set_default') {
                $id_dir = (int)$_POST['id_direccion'];
                $pdo->beginTransaction();
                $pdo->prepare("UPDATE cliente_direcciones SET es_default = 0 WHERE id_cliente = ?")->execute([$idCliente]);
                $pdo->prepare("UPDATE cliente_direcciones SET es_default = 1 WHERE id_direccion = ? AND id_cliente = ?")->execute([$id_dir, $idCliente]);
                $pdo->commit();
                $success = 'Dirección predeterminada actualizada.';
            }
        } catch (Exception $e) { $error = $e->getMessage(); }
    }
}

$stmt = $pdo->prepare("SELECT * FROM cliente_direcciones WHERE id_cliente = ? ORDER BY es_default DESC, fecha_creacion DESC");
$stmt->execute([$idCliente]);
$direcciones = $stmt->fetchAll();

$pageTitle = 'Mis Direcciones';
include __DIR__ . '/includes/header.php';
?>
<div class="container" style="margin-top: 30px;">
    <h4><i class="material-icons left">place</i> Mis Direcciones de Entrega</h4>
    <p class="grey-text">Puedes guardar hasta 5 direcciones para tus pedidos.</p>

    <?php if ($error): ?><div class="card-panel red lighten-4 red-text"><?php echo esc($error); ?></div><?php endif; ?>
    <?php if ($success): ?><div class="card-panel green lighten-4 green-text"><?php echo esc($success); ?></div><?php endif; ?>

    <div class="row">
        <div class="col s12 m7">
            <div class="card">
                <div class="card-content">
                    <span class="card-title">Direcciones Guardadas (<?php echo count($direcciones); ?>/5)</span>
                    <?php if (empty($direcciones)): ?>
                        <p class="center grey-text">No tienes direcciones guardadas.</p>
                    <?php else: ?>
                        <ul class="collection">
                            <?php foreach ($direcciones as $d): ?>
                                <li class="collection-item avatar">
                                    <i class="material-icons circle <?php echo $d['es_default'] ? 'blue' : 'grey'; ?>">location_on</i>
                                    <span class="title"><strong><?php echo esc($d['alias']); ?></strong></span>
                                    <p><?php echo esc($d['direccion']); ?></p>
                                    <?php if($d['maps_link']): ?>
                                        <a href="<?php echo $d['maps_link']; ?>" target="_blank" class="blue-text" style="font-size: 0.8rem;">
                                            <i class="material-icons tiny">map</i> Ver en Google Maps
                                        </a>
                                    <?php endif; ?>
                                    <div style="margin-top: 10px;">
                                        <button type="button" class="btn-small blue-text btn-flat" onclick='cargarEdicion(<?php echo json_encode($d); ?>)'>Editar</button>
                                        <?php if (!$d['es_default']): ?>
                                            <form method="POST" style="display:inline;">
                                                <?php echo csrfInput(); ?>
                                                <input type="hidden" name="accion" value="set_default">
                                                <input type="hidden" name="id_direccion" value="<?php echo $d['id_direccion']; ?>">
                                                <button type="submit" class="btn-small blue-text btn-flat">Hacer Default</button>
                                            </form>
                                        <?php else: ?>
                                            <span class="blue-text text-darken-2"><strong>Predeterminada</strong></span>
                                        <?php endif; ?>
                                        <form method="POST" style="display:inline; margin-left: 15px;">
                                            <?php echo csrfInput(); ?>
                                            <input type="hidden" name="accion" value="eliminar">
                                            <input type="hidden" name="id_direccion" value="<?php echo $d['id_direccion']; ?>">
                                            <button type="submit" class="btn-small red-text btn-flat" onclick="return confirm('¿Eliminar?')">Eliminar</button>
                                        </form>
                                    </div>
                                </li>
                            <?php endforeach; ?>
                        </ul>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <?php if (count($direcciones) < 5): ?>
        <div class="col s12 m5">
            <div class="card">
                <div class="card-content" id="form-container">
                    <span class="card-title" id="form-title">Agregar Nueva</span>
                    
                    <!-- Buscador Inteligente -->
                    <div class="input-field" style="margin-top: 20px;">
                        <i class="material-icons prefix blue-text">search</i>
                        <input type="text" id="autocomplete_search" placeholder="Escribe tu calle y número...">
                        <span class="helper-text">Usa el buscador para mayor precisión</span>
                    </div>

                    <!-- Visualización del Mapa -->
                    <div id="map-preview" class="z-depth-1" style="height: 200px; width: 100%; margin-bottom: 20px; border-radius: 4px; display: none; border: 1px solid #ddd;"></div>

                    <form method="POST" id="form-direccion">
                        <?php echo csrfInput(); ?>
                        <input type="hidden" name="accion" id="form-accion" value="agregar">
                        <input type="hidden" name="id_direccion" id="form-id-dir" value="">
                        <input type="hidden" name="maps_link" id="maps_link" value="">
                        
                        <div class="input-field">
                            <input type="text" id="alias" name="alias" required placeholder="Ej: Casa, Oficina, Mamá">
                            <label for="alias">Alias / Nombre</label>
                        </div>
                        <div class="input-field">
                            <textarea id="direccion" name="direccion" class="materialize-textarea" required placeholder="Detalles adicionales: Piso, apto, color de casa..."></textarea>
                            <label for="direccion" class="active">Dirección Detallada</label>
                        </div>
                        <button type="submit" id="btn-submit" class="btn blue darken-4 w-100">Guardar Dirección</button>
                        <button type="button" id="btn-cancel" class="btn grey w-100" style="display:none; margin-top:10px;" onclick="resetForm()">Cancelar Edición</button>
                    </form>
                </div>
            </div>
        </div>
        <?php endif; ?>
    </div>
</div>

<!-- Añadimos 'callback' para que Google llame a la función automáticamente al terminar de cargar -->
<script src="https://maps.googleapis.com/maps/api/js?key=AIzaSyAhJ3ApP1EPr_8IyZ8Unt-LlH1C8j5GZYE&libraries=places&callback=initAutocomplete" async defer></script>

<script>
let map, marker;

function initAutocomplete() {
    if (typeof google === 'undefined') {
        console.error('Google Maps no pudo cargarse. Revisa tu API Key y Facturación.');
        return;
    }

    const input = document.getElementById('autocomplete_search');
    if (!input) return;

    const autocomplete = new google.maps.places.Autocomplete(input, {
        types: ['address'],
        componentRestrictions: {country: 'mx'} // O tu país
    });

    // Inicializar mapa (oculto al inicio)
    map = new google.maps.Map(document.getElementById("map-preview"), {
        center: { lat: 23.6345, lng: -102.5528 }, // Centro de México por defecto
        zoom: 5,
        disableDefaultUI: true,
        zoomControl: true
    });
    marker = new google.maps.Marker({ map: map });

    autocomplete.addListener('place_changed', function() {
        const place = autocomplete.getPlace();
        if (!place.geometry) return;

        // Mostrar mapa
        document.getElementById('map-preview').style.display = 'block';

        // Auto-popular campos
        document.getElementById('direccion').value = place.formatted_address;
        document.getElementById('maps_link').value = `https://www.google.com/maps/search/?api=1&query=${place.geometry.location.lat()},${place.geometry.location.lng()}`;
        
        // Actualizar Mapa y Pin
        map.setCenter(place.geometry.location);
        map.setZoom(17);
        marker.setPosition(place.geometry.location);

        M.textareaAutoResize(document.getElementById('direccion'));
        M.updateTextFields();
    });
}

function cargarEdicion(dir) {
    document.getElementById('form-title').textContent = 'Editar Dirección';
    document.getElementById('form-accion').value = 'editar';
    document.getElementById('form-id-dir').value = dir.id_direccion;
    document.getElementById('alias').value = dir.alias;
    document.getElementById('direccion').value = dir.direccion;
    document.getElementById('maps_link').value = dir.maps_link || '';
    
    // Si hay link de mapas, intentar extraer coordenadas para mostrar en el mapa
    if (dir.maps_link) {
        const coords = dir.maps_link.split('query=')[1];
        if (coords) {
            actualizarMapaDesdeCoords(coords);
        }
    }

    document.getElementById('btn-submit').textContent = 'Actualizar Dirección';
    document.getElementById('btn-cancel').style.display = 'block';
    M.updateTextFields();
    M.textareaAutoResize(document.getElementById('direccion'));
    window.scrollTo({ top: document.getElementById('form-container').offsetTop, behavior: 'smooth' });
}

function actualizarMapaDesdeCoords(coords) {
    const [lat, lng] = coords.split(',').map(Number);
    if (!isNaN(lat) && !isNaN(lng)) {
        document.getElementById('map-preview').style.display = 'block';
        const pos = { lat, lng };
        map.setCenter(pos);
        map.setZoom(17);
        marker.setPosition(pos);
        
        // Pequeño timeout para que Google Maps se redibuje correctamente si estaba oculto
        setTimeout(() => google.maps.event.trigger(map, 'resize'), 100);
    }
}

function resetForm() {
    document.getElementById('form-direccion').reset();
    document.getElementById('form-title').textContent = 'Agregar Nueva';
    document.getElementById('form-accion').value = 'agregar';
    document.getElementById('form-id-dir').value = '';
    document.getElementById('maps_link').value = '';
    document.getElementById('autocomplete_search').value = '';
    document.getElementById('map-preview').style.display = 'none';
    document.getElementById('btn-submit').textContent = 'Guardar Dirección';
    document.getElementById('btn-cancel').style.display = 'none';
}

// También podemos asegurar la inicialización si el script cargara antes de que el DOM esté listo
document.addEventListener('DOMContentLoaded', () => {
    if (typeof google !== 'undefined') initAutocomplete();
});
</script>
<style>
    .w-100 { width: 100%; } 
    .btn-flat { font-weight: bold; }
    /* Asegurar que el autocompletado de Google sea visible sobre Materialize */
    .pac-container { 
        z-index: 1051 !important; 
        border-radius: 4px;
    }
</style>
<?php include __DIR__ . '/includes/footer.php'; ?>