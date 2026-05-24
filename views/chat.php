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
    $stmtList = $pdo->prepare("SELECT DISTINCT u.id_usuario, u.nombre, u.email, u.asignado_a,
        (SELECT COUNT(*) FROM mensajes_soporte m2 WHERE m2.id_cliente = u.id_usuario AND m2.leido_staff = 0) as pendientes,
        (SELECT enviado_por FROM mensajes_soporte m3 WHERE m3.id_cliente = u.id_usuario ORDER BY fecha_envio DESC LIMIT 1) as ultimo_por
        FROM usuarios u
        JOIN mensajes_soporte m ON u.id_usuario = m.id_cliente
        WHERE u.soporte_activo = 1 
        AND (u.asignado_a IS NULL OR u.asignado_a = ?)
        ORDER BY pendientes DESC, u.nombre ASC");
    $stmtList->execute([$usuario['id_usuario']]);
    $listaClientes = $stmtList->fetchAll();
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
                           class="collection-item black-text chat-user-item <?php echo ($c['asignado_a'] == $usuario['id_usuario']) ? 'blue lighten-5' : ''; ?>" id="user-item-<?php echo $c['id_usuario']; ?>">
                            <?php echo esc($c['nombre']); ?>
                            <?php if ($c['asignado_a'] == $usuario['id_usuario']): ?>
                                <i class="material-icons tiny blue-text">push_pin</i>
                            <?php endif; ?>
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
                        <span id="chat-title-text"><?php echo $soyCliente ? 'Soporte Técnico' : 'Selecciona un cliente'; ?></span>
                        <div id="staff-actions" class="right" style="display:none;">
                            <button class="btn-flat blue-text" onclick="abrirTransferir()" title="Transferir chat"><i class="material-icons">swap_horiz</i></button>
                            <button class="btn-flat red-text" onclick="terminarChat()" title="Finalizar"><i class="material-icons">check_circle</i></button>
                        </div>
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

                    <!-- Indicador de "Escribiendo..." -->
                    <div id="typing-indicator" style="display: none; padding: 5px 15px; font-size: 0.85rem; color: #757575; font-style: italic;">
                        <span id="typing-name">Alguien</span> está escribiendo<span class="dots">...</span>
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
                        <button class="btn-floating waves-effect waves-light amber darken-2" id="emoji-trigger" type="button" title="Insertar Emoji"><i class="material-icons">sentiment_satisfied</i></button>
                        <input type="text" id="msg-input" placeholder="Escribe un mensaje..." style="margin: 0;">
                        <button class="btn blue darken-4" onclick="enviarMensaje()"><i class="material-icons">send</i></button>
                    </div>

                    <!-- Contenedor de Emojis -->
                    <div id="emoji-picker" class="z-depth-2" style="display: none; position: absolute; bottom: 80px; left: 5%; right: 5%; background: white; border: 1px solid #ddd; border-radius: 8px; padding: 10px; width: 90%; max-width: 450px; z-index: 1000;">
                        <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 10px; border-bottom: 1px solid #eee; padding-bottom: 5px;">
                            <span style="font-weight: bold; color: #1a237e; font-size: 0.9rem;">Seleccionar Emoji</span>
                            <button type="button" class="btn-flat btn-small" onclick="document.getElementById('emoji-picker').style.display='none'" style="padding: 0 5px;"><i class="material-icons">close</i></button>
                        </div>
                        <div style="display: grid; grid-template-columns: repeat(8, 1fr); gap: 5px; max-height: 320px; overflow-y: auto; padding: 5px;">
                            <?php 
                                $emojis = ['😀','😃','😄','😁','😆','😅','😂','🤣','😊','😇','🙂','🙃','😉','😌','😍','🥰','😘','😗','😙','😚','😋','😛','😝','😜','🤪','🤨','🧐','🤓','😎','🤩','🥳','😏','😒','😞','😔','😟','😕','🙁','🙁','☹️','😣','😖','😫','😩','🥺','😢','😭','😤','😠','😡','🤬','🤯','😳','🥵','🥶','😱','😨','😰','😥','😓','🤗','🤔','🤭','🤫','🤥','😶','😐','😑','😬','🙄','😯','😦','😧','😮','😲','🥱','😴','🤤','😪','😵','🤐','🥴','🤢','🤮','🤧','😷','🤒','🤕','🤑','🤠','😈','👿','👹','👺','🤡','👻','💀','☠️','👽','👾','🤖','💩','😺','😸','😹','😻','😼','😽','🙀','😿','😾'];
                                foreach ($emojis as $e) echo "<span class='emoji-item' style='cursor:pointer; font-size: 1.8rem; text-align:center; padding: 5px;'>$e</span>";
                            ?>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Modal de Transferencia -->
<div id="modal-transferir" class="modal" style="max-width: 400px;">
    <div class="modal-content">
        <h5>Transferir Conversación</h5>
        <p>Selecciona al compañero que se hará cargo:</p>
        <div class="input-field">
            <select id="select-staff" class="browser-default" style="border: 1px solid #ddd; padding: 10px; border-radius: 4px; width: 100%;">
                <!-- Cargado por JS -->
            </select>
        </div>
    </div>
    <div class="modal-footer">
        <button class="btn blue darken-4" onclick="confirmarTransferencia()">Transferir Ahora</button>
        <button class="modal-close btn-flat">Cancelar</button>
    </div>
</div>

<style>
    .msg { margin-bottom: 10px; max-width: 80%; padding: 12px 16px; border-radius: 12px; font-size: 1.15rem; clear: both; line-height: 1.4; }
    .msg.me { float: right; background: #e3f2fd; color: #0d47a1; border-bottom-right-radius: 2px; }
    .msg.other { float: left; background: #f5f5f5; color: #333; border-bottom-left-radius: 2px; }
    .msg .time { font-size: 0.7rem; display: block; margin-top: 5px; opacity: 0.7; text-align: right; }
    
    /* Animación de puntos suspensivos */
    .dots { display: inline-block; width: 15px; }
    @keyframes blink { 0% { opacity: .2; } 20% { opacity: 1; } 100% { opacity: .2; } }
    .dots { animation: blink 1.4s infinite both; }

    /* Estilo para que los iconos (emojis) se vean al doble de grande en el chat */
    .msg .chat-emoji { font-size: 2.3rem; line-height: 1; vertical-align: middle; display: inline-block; margin: 2px; }
    
    /* Asegurar que el desplegable de búsqueda se vea sobre el chat */
    .autocomplete-content {
        z-index: 9999 !important;
    }
    .emoji-item:hover { background: #eeeeee; border-radius: 4px; }

    .chat-product-card { background: white; border-radius: 8px; overflow: hidden; border: 1px solid #ddd; margin-top: 5px; width: 200px; }
    .chat-product-card img { width: 100%; height: 120px; object-fit: contain; background: #f9f9f9; }
    .chat-product-card .info { padding: 8px; }
</style>

<!-- Incluir SweetAlert2 para los diálogos de confirmación y cierre -->
<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>

<script>
let clienteActivo = <?php echo $soyCliente ? $usuario['id_usuario'] : 'null'; ?>;
const currentUserId = <?php echo $usuario['id_usuario']; ?>;
const esStaff = <?php echo !$soyCliente ? 'true' : 'false'; ?>;
let ultimoConteoMensajes = 0;
let primeraCarga = true;
let productosData = {};
let lastTypingSent = 0;
let miAsignacionPrevia = 0;
let chatEstabaActivo = false; // Rastrear transición de estado
let fechaInicioSesion = null; // Para persistir el marcador de inicio

document.addEventListener('DOMContentLoaded', () => {
    M.Modal.init(document.querySelectorAll('.modal'));
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
            
        // Cargar lista de staff
        fetch('<?php echo BASE_URL; ?>api/chat_handler.php?action=get_staff')
            .then(r => r.json())
            .then(data => {
                const select = document.getElementById('select-staff');
                data.staff.forEach(s => {
                    if(s.id_usuario != currentUserId) select.innerHTML += `<option value="${s.id_usuario}">${s.nombre}</option>`;
                });
            });
    }

    // Lógica para el selector de emojis
    const emojiTrigger = document.getElementById('emoji-trigger');
    const emojiPicker = document.getElementById('emoji-picker');
    const msgInput = document.getElementById('msg-input');

    if (emojiTrigger) {
        emojiTrigger.addEventListener('click', () => {
            emojiPicker.style.display = emojiPicker.style.display === 'none' ? 'block' : 'none';
        });

        document.querySelectorAll('.emoji-item').forEach(item => {
            item.addEventListener('click', () => {
                msgInput.value += item.textContent;
                msgInput.focus();
                emojiPicker.style.display = 'none';
            });
        });

        // Cerrar el selector si se hace clic fuera de él
        document.addEventListener('click', (e) => {
            if (emojiPicker && !emojiPicker.contains(e.target) && !emojiTrigger.contains(e.target)) {
                emojiPicker.style.display = 'none';
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
    document.getElementById('chat-title-text').textContent = ` Chat con ${nombre}`;
    document.getElementById('staff-actions').style.display = 'block';
    document.getElementById('chat-box').style.display = 'block';
    if (document.getElementById('staff-product-search')) document.getElementById('staff-product-search').style.display = 'none';
    cargarMensajes();
}

function abrirTransferir() {
    M.Modal.getInstance(document.getElementById('modal-transferir')).open();
}

function confirmarTransferencia() {
    const idDestino = document.getElementById('select-staff').value;
    if(!idDestino) return;
    
    fetch(`<?php echo BASE_URL; ?>api/chat_handler.php?action=transfer&id_cliente=${clienteActivo}&id_destino=${idDestino}`)
        .then(r => r.json())
        .then(data => {
            if(data.success) {
                M.toast({html: 'Chat transferido correctamente', classes: 'blue'});
                M.Modal.getInstance(document.getElementById('modal-transferir')).close();
                setTimeout(() => location.reload(), 1000);
            }
        });
}

function iniciarChat() {
    fechaInicioSesion = new Date().toLocaleDateString() + ' ' + new Date().toLocaleTimeString([], {hour: '2-digit', minute:'2-digit'});

    fetch(`<?php echo BASE_URL; ?>api/chat_handler.php?action=start`)
        .then(() => {
            chatEstabaActivo = true;
            document.getElementById('chat-welcome').style.display = 'none';
            document.getElementById('chat-box').style.display = 'block';
            document.querySelector('.chat-input-area').style.display = 'flex';
            cargarMensajes();
        });
}

function terminarChat() {
    if (!clienteActivo) return;
    const targetId = clienteActivo; // Asegurar el ID en el scope

    Swal.fire({
        title: '¿Finalizar consulta?',
        text: "Se cerrará la sesión de soporte actual.",
        icon: 'warning',
        showCancelButton: true,
        confirmButtonColor: '#1a237e',
        cancelButtonColor: '#d33',
        confirmButtonText: 'Sí, finalizar',
        cancelButtonText: 'Cancelar'
    }).then((result) => {
        if (result.isConfirmed) {
            fetch(`<?php echo BASE_URL; ?>api/chat_handler.php?action=close&id_cliente=${targetId}`, { cache: 'no-cache' })
                .then(r => r.json())
                .then(data => {
                    if (data.success) {
                        if (esStaff) {
                            location.reload();
                        } else {
                            window.location.href = '<?php echo BASE_URL; ?>index.php';
                        }
                    } else {
                        M.toast({html: 'Error al cerrar: ' + (data.message || 'Error desconocido'), classes: 'red'});
                    }
                })
                .catch(err => {
                    console.error("Error terminando chat:", err);
                    M.toast({html: 'Error de conexión', classes: 'red'});
                });
        }
    });
}

function cargarMensajes() {
    if (!clienteActivo) return;
    fetch(`<?php echo BASE_URL; ?>api/chat_handler.php?action=fetch&id_cliente=${clienteActivo}`)
        .then(r => r.json())
        .then(data => {
            if (data.success) {
                const box = document.getElementById('chat-box');
                // Detectar si el usuario está al final del chat antes de actualizar
                const wasAtBottom = box.scrollHeight - box.scrollTop <= box.clientHeight + 100;

                // Mostrar/Ocultar indicador de escritura
                const typingDiv = document.getElementById('typing-indicator');
                
                // Manejo de estado del chat (para el cliente)
                if (!esStaff) {
                    // Detectar si el staff cerró el chat en este ciclo
                    if (!data.soporte_activo && chatEstabaActivo) {
                        chatEstabaActivo = false;
                        const ahora = new Date();
                        const fechaHora = ahora.toLocaleDateString() + ' ' + ahora.toLocaleTimeString([], {hour: '2-digit', minute:'2-digit'});
                        
                        Swal.fire({
                            title: 'Sesión Finalizada',
                            html: `Soporte ha terminado la consulta.<br><small class="grey-text">Cerrado el: ${fechaHora}</small>`,
                            icon: 'info',
                            confirmButtonText: 'Ir al Catálogo',
                            confirmButtonColor: '#1a237e',
                            allowOutsideClick: false,
                            allowEscapeKey: false
                        }).then(() => {
                            window.location.href = '<?php echo BASE_URL; ?>index.php';
                        });
                        return;
                    }

                    // Si el soporte está inactivo, siempre volver a la pantalla de bienvenida
                    if (!data.soporte_activo) {
                        document.getElementById('chat-welcome').style.display = 'block';
                        document.getElementById('chat-box').style.display = 'none';
                        document.querySelector('.chat-input-area').style.display = 'none';
                        return;
                    }
                    
                    if (data.soporte_activo) chatEstabaActivo = true;
                } else {
                    document.querySelector('.chat-input-area').style.display = 'flex';
                    // Notificación si me asignaron un chat nuevo
                    if (data.asignado_a == currentUserId && miAsignacionPrevia != currentUserId && !primeraCarga) {
                        M.toast({html: '⚠️ Se te ha asignado un nuevo cliente', classes: 'blue darken-4'});
                        document.getElementById('chat-notification-sound').play();
                    }
                    miAsignacionPrevia = data.asignado_a;
                }

                if (data.is_typing) {
                    document.getElementById('typing-name').textContent = esStaff ? 'El cliente' : 'Soporte';
                    typingDiv.style.display = 'block';
                } else {
                    typingDiv.style.display = 'none';
                }
                
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
                
                // Re-insertar marcador de inicio si hay una sesión activa
                if (!esStaff && (chatEstabaActivo || data.soporte_activo) && fechaInicioSesion) {
                    box.innerHTML = `<div class="center-align" style="margin: 10px 0 20px 0;"><span class="grey lighten-3 grey-text text-darken-2" style="padding: 5px 15px; border-radius: 20px; font-size: 0.8rem; font-weight: bold;">CONVERSACIÓN INICIADA: ${fechaInicioSesion}</span></div>`;
                }

                data.mensajes.forEach(m => {
                    const isMe = (m.enviado_por === 'cliente' && !esStaff) || (m.enviado_por === 'staff' && esStaff);
                    const div = document.createElement('div');
                    div.className = `msg ${isMe ? 'me' : 'other'}`;
                    
                    if (m.tipo_mensaje === 'producto') {
                        const p = JSON.parse(m.mensaje);
                        div.innerHTML = renderProductCard(p, isMe) + `<span class="time">${m.fecha_envio.substring(11,16)}</span>`;
                    } else {
                        // Sanitización y wrap de emojis para duplicar su tamaño
                        let textoEscapado = m.mensaje.replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;');
                        const emojiRegex = /(\p{Emoji_Presentation}|\p{Extended_Pictographic})/gu;
                        const textoProcesado = textoEscapado.replace(emojiRegex, '<span class="chat-emoji">$1</span>');
                        
                        div.innerHTML = `${textoProcesado} <span class="time">${m.fecha_envio.substring(11,16)}</span>`;
                    }
                    box.appendChild(div);
                });

                // Solo bajar el scroll si ya estaba abajo o es la primera carga
                if (wasAtBottom || primeraCarga) {
                    box.scrollTop = box.scrollHeight;
                }
            } else {
                // Si el chat ya fue tomado por otro (data.success === false)
                const box = document.getElementById('chat-box');
                box.innerHTML = `<div class="center-align orange-text" style="margin-top:50px;"><i class="material-icons large">lock</i><p>${data.message}</p></div>`;
                document.querySelector('.chat-input-area').style.display = 'none';
                const actions = document.getElementById('staff-actions');
                if (actions) actions.style.display = 'none';
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

// Notificar que estoy escribiendo
document.getElementById('msg-input')?.addEventListener('input', () => {
    const now = Date.now();
    if (now - lastTypingSent > 4000) { // Throttling: solo enviar cada 4 segundos
        lastTypingSent = now;
        const url = `<?php echo BASE_URL; ?>api/chat_handler.php?action=typing` + (esStaff ? `&id_cliente=${clienteActivo}` : '');
        fetch(url);
    }
});

document.getElementById('msg-input')?.addEventListener('keypress', (e) => {
    if(e.key === 'Enter') enviarMensaje();
});

if (clienteActivo) cargarMensajes();
</script>

<?php include __DIR__ . '/includes/footer.php'; ?>