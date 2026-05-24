<?php
declare(strict_types=1);
require_once __DIR__ . '/../core/config.php';
require_once __DIR__ . '/../core/auth.php';
requireAuth();

$pageTitle = 'Chat de Soporte';
$pdo = getPDO();
$usuario = $_SESSION['usuario'];
$soyCliente = isCliente();

// Si es Staff, obtenemos la lista de clientes que han iniciado chats
$listaClientes = [];
if (!$soyCliente) {
    $listaClientes = $pdo->query("SELECT DISTINCT u.id_usuario, u.nombre, u.email, 
        (SELECT COUNT(*) FROM mensajes_soporte m2 WHERE m2.id_cliente = u.id_usuario AND m2.leido_staff = 0) as pendientes
        FROM usuarios u
        JOIN mensajes_soporte m ON u.id_usuario = m.id_cliente
        ORDER BY pendientes DESC, u.nombre ASC")->fetchAll();
}

include __DIR__ . '/includes/header.php';
?>

<!-- Elemento de audio para notificaciones -->
<audio id="chat-notification-sound" src="https://assets.mixkit.co/active_storage/sfx/2358/2358-preview.mp3" preload="auto"></audio>

<div class="container" style="margin-top: 20px;">
    <div class="row">
        <?php if (!$soyCliente): ?>
            <!-- Sidebar para Staff -->
            <div class="col s12 m4 l3">
                <div class="collection with-header z-depth-1" style="border-radius: 8px; overflow: hidden;">
                    <div class="collection-header blue darken-4 white-text"><h6>Conversaciones</h6></div>
                    <?php foreach ($listaClientes as $c): ?>
                        <a href="#!" onclick="seleccionarChat(<?php echo $c['id_usuario']; ?>, '<?php echo esc($c['nombre']); ?>')" 
                           class="collection-item black-text chat-user-item" id="user-item-<?php echo $c['id_usuario']; ?>">
                            <?php echo esc($c['nombre']); ?>
                            <?php if ($c['pendientes'] > 0): ?>
                                <span class="new badge red" data-badge-caption=""><?php echo $c['pendientes']; ?></span>
                            <?php endif; ?>
                        </a>
                    <?php endforeach; ?>
                </div>
            </div>
        <?php endif; ?>

        <!-- Ventana de Chat -->
        <div class="col s12 <?php echo $soyCliente ? 'm10 offset-m1' : 'm8 l9'; ?>">
            <div class="card z-depth-2" style="border-radius: 8px;">
                <div class="card-content" style="height: 500px; display: flex; flex-direction: column;">
                    <span class="card-title" id="chat-header">
                        <i class="material-icons left blue-text">chat</i>
                        <?php echo $soyCliente ? 'Soporte Técnico' : 'Selecciona un cliente'; ?>
                    </span>
                    
                    <div id="chat-box" style="flex-grow: 1; overflow-y: auto; padding: 15px; background: #fdfdfd; border: 1px solid #eee; margin: 10px 0;">
                        <!-- Mensajes cargados por JS -->
                        <?php if (!$soyCliente): ?>
                            <div class="center-align grey-text" style="margin-top: 50px;">
                                <i class="material-icons large">forum</i>
                                <p>Selecciona un cliente de la lista para ver el historial.</p>
                            </div>
                        <?php endif; ?>
                    </div>

                    <?php if (!$soyCliente): ?>
                        <!-- Buscador de productos para Staff -->
                        <div id="staff-product-search" style="display: none; padding: 10px; background: #f1f8e9; border-radius: 4px; margin-bottom: 10px;">
                            <div class="input-field" style="margin: 0;">
                                <i class="material-icons prefix" style="font-size: 1.2rem; margin-top: 5px;">search</i>
                                <input type="text" id="chat-product-autocomplete" class="autocomplete" placeholder="Escribe el nombre del producto..." style="border-bottom: 1px solid #c5e1a5 !important;">
                            </div>
                        </div>
                    <?php endif; ?>

                    <div class="chat-input-area" style="display: <?php echo $soyCliente ? 'flex' : 'none'; ?>; gap: 10px;">
                        <?php if (!$soyCliente): ?>
                            <button class="btn-floating waves-effect waves-light green" onclick="toggleProductSearch()"><i class="material-icons">add_shopping_cart</i></button>
                        <?php endif; ?>
                        <input type="text" id="msg-input" placeholder="Escribe un mensaje..." style="margin: 0;">
                        <button class="btn blue darken-4" onclick="enviarMensaje()"><i class="material-icons">send</i></button>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<style>
    .msg { margin-bottom: 10px; max-width: 80%; padding: 10px; border-radius: 12px; font-size: 0.95rem; clear: both; }
    .msg.me { float: right; background: #e3f2fd; color: #0d47a1; border-bottom-right-radius: 2px; }
    .msg.other { float: left; background: #f5f5f5; color: #333; border-bottom-left-radius: 2px; }
    .msg .time { font-size: 0.7rem; display: block; margin-top: 5px; opacity: 0.7; text-align: right; }
    
    /* Asegurar que el desplegable de búsqueda se vea sobre el chat */
    .autocomplete-content {
        z-index: 9999 !important;
    }

    .chat-product-card { background: white; border-radius: 8px; overflow: hidden; border: 1px solid #ddd; margin-top: 5px; width: 200px; }
    .chat-product-card img { width: 100%; height: 120px; object-fit: contain; background: #f9f9f9; }
    .chat-product-card .info { padding: 8px; }
</style>

<script>
let clienteActivo = <?php echo $soyCliente ? $usuario['id_usuario'] : 'null'; ?>;
const currentUserId = <?php echo $usuario['id_usuario']; ?>;
const esStaff = <?php echo !$soyCliente ? 'true' : 'false'; ?>;
let ultimoConteoMensajes = 0;
let primeraCarga = true;
let productosData = {};

document.addEventListener('DOMContentLoaded', () => {
    if (esStaff) {
        // Cargar productos para el buscador interno del chat
        fetch('<?php echo BASE_URL; ?>api/products.php')
            .then(r => r.json())
            .then(data => {
                if (data.success) {
                    const acData = {};
                    data.products.forEach(p => {
                        const label = `[${p.sku}] ${p.nombre}`;
                        // No usamos p.imagen aquí porque el base64 es muy pesado para el buscador
                        acData[label] = null; 
                        productosData[label] = p;
                    });
                    
                    const inputAC = document.getElementById('chat-product-autocomplete');
                    const instanceAC = M.Autocomplete.init(inputAC, {
                        data: acData,
                        onAutocomplete: (val) => {
                            if (productosData[val]) {
                                enviarProducto(productosData[val]);
                            }
                        },
                        dropdownOptions: {
                            container: document.body // Evita que se oculte por el overflow de la tarjeta
                        }
                    });

                    // Permitir seleccionar con la tecla Enter si hay una coincidencia exacta o sugerencia
                    inputAC.addEventListener('keydown', (e) => {
                        if (e.key === 'Enter') {
                            const val = inputAC.value.trim();
                            if (productosData[val]) {
                                enviarProducto(productosData[val]);
                            } else {
                                // Si no hay match exacto, intentar disparar el clic en la primera sugerencia
                                const first = document.querySelector('.autocomplete-content li');
                                if (first) first.click();
                            }
                        }
                    });
                }
            });
    }
});

function toggleProductSearch() {
    const div = document.getElementById('staff-product-search');
    if (!clienteActivo) {
        M.toast({html: 'Selecciona un cliente primero', classes: 'orange'});
        return;
    }
    div.style.display = div.style.display === 'none' ? 'block' : 'none';
    if (div.style.display === 'block') document.getElementById('chat-product-autocomplete').focus();
}

function seleccionarChat(id, nombre) {
    clienteActivo = id;
    document.getElementById('chat-header').innerHTML = `<i class="material-icons left blue-text">person</i> Chat con ${nombre}`;
    document.querySelector('.chat-input-area').style.display = 'flex';
    if (document.getElementById('staff-product-search')) document.getElementById('staff-product-search').style.display = 'none';
    cargarMensajes();
}

function cargarMensajes() {
    if (!clienteActivo) return;
    fetch(`<?php echo BASE_URL; ?>api/chat_handler.php?action=fetch&id_cliente=${clienteActivo}`)
        .then(r => r.json())
        .then(data => {
            if (data.success) {
                const box = document.getElementById('chat-box');
                
                // Si hay mensajes nuevos y no es la primera carga, sonar alerta
                if (!primeraCarga && data.mensajes.length > ultimoConteoMensajes) {
                    const ultimoMsg = data.mensajes[data.mensajes.length - 1];
                    const enviadoPorOtro = (esStaff && ultimoMsg.enviado_por === 'cliente') || (!esStaff && ultimoMsg.enviado_por === 'staff');
                    
                    if (enviadoPorOtro) {
                        document.getElementById('chat-notification-sound').play().catch(e => console.log("Audio bloqueado por navegador"));
                        if (!esStaff) M.toast({html: 'Nuevo mensaje de Soporte', classes: 'blue'});
                    }
                }

                ultimoConteoMensajes = data.mensajes.length;
                primeraCarga = false;

                box.innerHTML = '';
                data.mensajes.forEach(m => {
                    const isMe = (m.enviado_por === 'cliente' && !esStaff) || (m.enviado_por === 'staff' && esStaff);
                    const div = document.createElement('div');
                    div.className = `msg ${isMe ? 'me' : 'other'}`;
                    
                    if (m.tipo_mensaje === 'producto') {
                        const p = JSON.parse(m.mensaje);
                        div.innerHTML = renderProductCard(p, isMe) + `<span class="time">${m.fecha_envio.substring(11,16)}</span>`;
                    } else {
                        div.innerHTML = `${m.mensaje} <span class="time">${m.fecha_envio.substring(11,16)}</span>`;
                    }
                    box.appendChild(div);
                });
                box.scrollTop = box.scrollHeight;
            }
        });
}

function renderProductCard(p, isMe) {
    const img = p.imagen || '<?php echo BASE_URL; ?>assets/img/no-product.png';
    // Escapar comillas simples para evitar que rompan el atributo onclick
    const safeName = p.nombre.replace(/'/g, "\\'");
    return `
        <div class="chat-product-card">
            <img src="${img}">
            <div class="info">
                <div class="truncate" style="font-weight:bold; font-size:0.85rem;">${p.nombre}</div>
                <div class="blue-text" style="font-weight:bold;">$${parseFloat(p.precio_venta).toFixed(2)}</div>
                <button class="btn-small green darken-1 waves-effect" style="width:100%; margin-top:5px; height:28px; line-height:28px; font-size:0.7rem;" 
                        onclick="addToCartFromChat(${p.id_producto}, '${safeName}', ${p.precio_venta}, '${img}')">
                    AGREGAR
                </button>
            </div>
        </div>
    `;
}

function enviarMensaje(tipo = 'texto', contenido = null) {
    const input = document.getElementById('msg-input');
    const txt = contenido || input.value.trim();
    if (!txt || !clienteActivo) return;

    fetch(`<?php echo BASE_URL; ?>api/chat_handler.php?action=send`, {
        method: 'POST',
        body: JSON.stringify({ mensaje: txt, id_cliente: clienteActivo, tipo_mensaje: tipo })
    })
    .then(r => r.json())
    .then(data => {
        if (data.success) {
            if (tipo === 'texto') {
                input.value = '';
            } else if (tipo === 'producto') {
                document.getElementById('chat-product-autocomplete').value = '';
                toggleProductSearch();
            }
            cargarMensajes();
        } else {
            M.toast({html: 'Error al enviar: ' + data.message, classes: 'red'});
        }
    });
}

function enviarProducto(p) {
    const pData = JSON.stringify({
        id_producto: p.id_producto,
        nombre: p.nombre,
        precio_venta: p.precio_venta,
        imagen: p.imagen
    });
    enviarMensaje('producto', pData);
    document.getElementById('chat-product-autocomplete').value = '';
}

function addToCartFromChat(id, nombre, precio, imagen) {
    let cart = JSON.parse(localStorage.getItem('cart') || '[]');
    let item = cart.find(i => i.id_producto === id);
    if (item) {
        item.quantity += 1;
    } else {
        cart.push({
            id_producto: id,
            nombre: nombre,
            precio: precio,
            imagen: imagen,
            quantity: 1
        });
    }
    localStorage.setItem('cart', JSON.stringify(cart));
    M.toast({html: '🛒 ' + nombre + ' añadido al carrito', classes: 'green'});
    if (typeof updateCartBadge === 'function') updateCartBadge();
}

// Polling: revisar nuevos mensajes cada 5 segundos
setInterval(cargarMensajes, 5000);

document.getElementById('msg-input')?.addEventListener('keypress', (e) => {
    if(e.key === 'Enter') enviarMensaje();
});

if (clienteActivo) cargarMensajes();
</script>

<?php include __DIR__ . '/includes/footer.php'; ?>