<?php
declare(strict_types=1);
require_once __DIR__ . '/../core/config.php';
require_once __DIR__ . '/../core/auth.php';
requireAuth();

$pageTitle = 'Chat';
$pdo = getPDO();
$usuario = $_SESSION['usuario'];
$soyCliente = isCliente();
// Definimos los emojis por categorías estilo WhatsApp
$emojiCategories = [
    'Caritas' => ['😀','😃','😄','😁','😆','😅','😂','🤣','😊','😇','🙂','🙃','😉','😌','😍','🥰','😘','😗','😙','😚','😋','😛','😝','😜','🤪','🤨','🧐','🤓','😎','🤩','🥳','😏','😒','😞','😔','😟','😕','🙁','☹️','😣','😖','😫','😩','🥺','😢','😭','😤','😠','😡','🤬','🤯','😳','🥵','🥶','😱','😨','😰','😥','😓','🤗','🤔','🤭','🤫','🤥','😶','😐','😑','😬','🙄','😯','😦','😧','😮','😲','🥱','😴','🤤','😪','😵','🤐','🥴','🤢','🤮','🤧','😷','🤒','🤕','🤑','🤠','😈','👿','👹','👺','🤡','👻','💀','☠️','👽','👾','🤖','💩'],
    'Animales' => ['🐶','🐱','🐭','🐹','🐰','🦊','🐻','🐼','🐨','🐯','🦁','🐮','🐷','🐽','🐸','🐵','🐒','🦍','🦧','🐕','🐩','🐺','🦝','🐈','🦁','🐅','🐆','🐴','🐎','🦄','🦓','🦌','🦬','🐂','🐃','🐄','🐖','🐗','🐏','🐑','🐐','🐪','🐫','🦙','🦒','🐘','🦣','🦏','🦛','🐁','🐀','🐿️','🦫','🦔','🦎','🐢','🐍','🐲','🐉','🦕','🦖'],
    'Comida' => ['🍏','🍎','🍐','🍊','🍋','🍌','🍉','🍇','🍓','🫐','🍈','🍒','🍑','🥭','🍍','🥥','🥝','🍅','🍆','🥑','🥦','🥬','🥒','🌶️','🫑','🌽','🥕','🫒','🧄','🧅','🥔','🍠','🥐','🥯','🍞','🥖','🥨','🧀','🍳','🧈','🥞','🧇','🥓','🥩','🍗','🍖','🌭','🍔','🍟','🍕','🌮','🌯','🫔','🥙','🧆','🍲','🥣','🥗','🍿','🍱','🍘','🍙','🍚','🍛','🍜','🍝','🍢','🍣','🍤','🍥','🥮','🍡','🥟','🥠','🥡','🍦','🍧','🍨','🍩','🍪','🎂','🍰','🧁','🥧','🍫','🍬','🍭','🍮','🍯','☕','🫖','🍵','🍶','🍾','🍷','🍸','🍹','🍺','🍻','🥂','🥃','🥤','🧋','🧃','🧉','🧊'],
    'Actividad' => ['⚽','🏀','🏈','⚾','🥎','🎾','🏐','🏉','🥏','🎱','🪀','🏓','🏸','🏒','🏑','🥍','🏏','🪃','🥅','⛳','🪁','🏹','🎣','🤿','🥊','🥋','⛸️','🎿','🛷','🥌','🎯','🪗','🎮','🕹️','🎰','🎲','🧩','🧸','🪅','🪆','♠️','♥️','♦️','♣️','♟️','🃏','🎭','🖼️','🎨','🧵','🪡','🧶','🪢'],
    'Objetos' => ['⌚','📱','📲','💻','⌨️','🖥️','🖨️','🖱️','🖲️','📷','📸','📹','🎥','📽️','🎞️','📞','☎️','📟','📠','📺','📻','🎙️','🎚️','🎛️','🧭','⏱️','⏲️','⏰','🕰️','⌛','⏳','📡','🔋','🔌','💡','🔦','🕯️','🪔','🧯','🛢️','💸','💵','💴','💶','💷','🪙','💰','💳','💎','⚖️','🪜','🧰','🪛','🔧','🔨','⚒️','🛠️','⛏️','🪚','🔩','⚙️','🪠','🔫','💣','🧨','🪓','🔪','🗡','⚔️','🛡️','🚬','⚰️','🪦','⚱️','🏺','🔮','📿','🧿','💈','⚗️','🔭','🔬','🕳️','🩹','🩺','💊','💉','🩸','🧬','🦠','🧫','🧪','🌡️','🧹','🧺','🧻','🚽','🚰','🚿','🛁','🛀','🧼','🪥','🪒','🧽','🪣','🧴','🛎️','🔑','🗝️','🚪','🪑','🛋️','🛏️','🛌','🛍️','🛒','🎁','🎈','🎏','🎀','🪄','🎊','🎉','🎎','🏮','🎐','🧧','✉️','📩','📨','📧','💌','📥','📤','📦','🏷️','🪧','📪','📫','📬','📭','📮','📯','📜','📃','📄','📑','🧾','📊','📈','📉','🗒️','🗓️','📆','📅','🗑️','📇','🗃️','🗳️','🗄️','📋','📁','📂','🗂️','🗞️','📰','📓','📔','📒','📕','📗','📘','📙','📚','📖','🔖','🧷','🔗','📎','🖇️','📐','📏','🧮','📌','📍'],
    'Símbolos' => ['❤️','🧡','💛','💚','💙','💜','🖤','🤍','🤎','💔','❣️','💕','💞','💓','💗','💖','💘','💝','💟','☮️','✝️','☪️','🕉️','☸️','✡️','🔯','🕎','☯️','☦️','🛐','⛎','♈','♉','♊','♋','♌','♍','♎','♏','♐','♑','♒','♓','🆔','⚛️','🉑','☢️','☣️','📴','📳','🈶','🈚','🈸','🈺','🈷️','✴️','🆚','💮','🉐','㊙️','㊗️','🈴','🈵','🈹','🈲','🅰️','🅱️','🆎','🆑','🅾️','❌','⭕','🛑','⛔','📛','🚫','💯','💢','♨️','🚷','🚯','🚳','🚱','🔞','📵','🚭','❗','❕','❓','❔','‼️','⁉️','🔅','🔆','〽️','⚠️','🚸','🔱','⚜️','🔰','♻️','✅','🈯','💹','❇️','✳️','🌀','💤','🏧','🚾','♿','🅿️','🛗','🈳','🈂️','🛂','🛃','🛄','🛅','🚹','🚺','🚼','⚧️','🚻','🚮','🎦','📶','🈁','🔣','ℹ️','🔤','🔡','🔠','🆖','🆗','🆙','🆒','🆕','🆓','0️⃣','1️⃣','2️⃣','3️⃣','4️⃣','5️⃣','6️⃣','7️⃣','8️⃣','9️⃣','🔟']
];

include __DIR__ . '/includes/header.php';
?>

<!-- Elemento de audio para notificaciones -->
<audio id="chat-notification-sound" src="https://assets.mixkit.co/active_storage/sfx/2358/2358-preview.mp3" preload="auto"></audio>

<div class="container" style="margin-top: 20px;">
    <div class="row">
        <?php if (!$soyCliente): ?>
            <!-- Barra lateral para el Personal -->
            <div class="col s12 m4 l3">
                <div class="collection with-header z-depth-1" id="conversations-list" style="border-radius: 8px; overflow: hidden;">
                    <div class="collection-header blue darken-4 white-text"><h6>Conversaciones</h6></div>
                    <div class="center-align grey-text" style="padding: 20px;">
                        <div class="preloader-wrapper small active">
                            <div class="spinner-layer border-blue">
                                <div class="circle-clipper left"><div class="circle"></div></div><div class="gap-patch"><div class="circle"></div></div><div class="circle-clipper right"><div class="circle"></div></div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        <?php endif; ?>

        <!-- Ventana de Chat -->
        <div class="col s12 <?php echo $soyCliente ? 'm10 offset-m1' : 'm8 l9'; ?>">
            <div class="card z-depth-2" style="border-radius: 8px;">
                <div class="card-content" style="height: 500px; display: flex; flex-direction: column;">
                    <span class="card-title" id="chat-header">
                        <i class="material-icons left blue-text">chat</i>
                        <span id="chat-title-text"><?php echo $soyCliente ? 'Chat' : 'Selecciona un cliente'; ?></span>
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
                            <button class="btn-floating waves-effect waves-light purple darken-2" id="quick-msg-trigger" type="button" title="Respuestas Rápidas"><i class="material-icons">bolt</i></button>
                        <?php endif; ?>
                        <button class="btn-floating waves-effect waves-light amber darken-2" id="emoji-trigger" type="button" title="Insertar Emoji"><i class="material-icons">sentiment_satisfied</i></button>
                        <input type="text" id="msg-input" placeholder="Escribe un mensaje..." style="margin: 0;">
                        <button class="btn blue darken-4" onclick="enviarMensaje()"><i class="material-icons">send</i></button>
                    </div>

                    <!-- Contenedor de Emojis -->
                    <div id="emoji-picker" class="z-depth-2" style="display: none; position: absolute; bottom: 85px; left: 5%; right: 5%; background: white; border: 1px solid #ddd; border-radius: 8px; padding: 10px; width: 90%; max-width: 450px; z-index: 1000;">
                        <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 10px; border-bottom: 1px solid #eee; padding-bottom: 5px;">
                            <span style="font-weight: bold; color: #1a237e; font-size: 0.9rem;">Seleccionar Emoji</span>
                            <button type="button" class="btn-flat btn-small" onclick="document.getElementById('emoji-picker').style.display='none'" style="padding: 0 5px;"><i class="material-icons">close</i></button>
                        </div>
                        <!-- Pestañas de categorías -->
                        <div class="emoji-tabs-container">
                            <button type="button" class="emoji-tab-btn active" onclick="switchEmojiTab('Caritas', 'main')">😊</button>
                            <button type="button" class="emoji-tab-btn" onclick="switchEmojiTab('Animales', 'main')">🐶</button>
                            <button type="button" class="emoji-tab-btn" onclick="switchEmojiTab('Comida', 'main')">🍎</button>
                            <button type="button" class="emoji-tab-btn" onclick="switchEmojiTab('Actividad', 'main')">⚽</button>
                            <button type="button" class="emoji-tab-btn" onclick="switchEmojiTab('Objetos', 'main')">💡</button>
                            <button type="button" class="emoji-tab-btn" onclick="switchEmojiTab('Símbolos', 'main')">❤️</button>
                        </div>
                        <div id="emoji-content-main" style="max-height: 280px; overflow-y: auto; padding: 5px;">
                            <?php foreach ($emojiCategories as $catName => $list): ?>
                                <div id="main-cat-<?php echo $catName; ?>" class="emoji-grid" style="display: <?php echo $catName === 'Caritas' ? 'grid' : 'none'; ?>;">
                                    <?php foreach ($list as $e): ?>
                                        <span class='emoji-item' style='cursor:pointer; font-size: 1.8rem; text-align:center; padding: 5px;'><?php echo $e; ?></span>
                                    <?php endforeach; ?>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    </div>

                    <?php if (!$soyCliente): ?>
                    <!-- Contenedor de Respuestas Rápidas -->
                    <div id="quick-msg-picker" class="z-depth-2" style="display: none; position: absolute; bottom: 85px; left: 5%; right: 5%; background: white; border: 1px solid #ddd; border-radius: 8px; padding: 10px; width: 90%; max-width: 400px; z-index: 1001;">
                        <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 10px; border-bottom: 1px solid #eee; padding-bottom: 5px;">
                            <span style="font-weight: bold; color: #4a148c; font-size: 0.9rem;">Respuestas Rápidas</span>
                            <div>
                                <button type="button" class="btn-flat btn-small blue-text" onclick="abrirGestionQuick()" title="Configurar"><i class="material-icons">settings</i></button>
                                <button type="button" class="btn-flat btn-small" onclick="document.getElementById('quick-msg-picker').style.display='none'"><i class="material-icons">close</i></button>
                            </div>
                        </div>
                        <div id="quick-list-container" style="max-height: 250px; overflow-y: auto;">
                            <!-- Cargado por JS -->
                        </div>
                    </div>
                    <?php endif; ?>
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

<!-- Modal de Gestión de Respuestas Rápidas -->
<div id="modal-gestion-quick" class="modal modal-fixed-footer" style="max-width: 600px;">
    <div class="modal-content">
        <h5>Configurar Respuestas Rápidas</h5>
        <div class="row">
            <form id="form-quick-res">
                <input type="hidden" id="quick-id" value="">
                <div class="input-field col s12">
                    <input type="text" id="quick-titulo" placeholder="Ej: Saludo inicial" maxlength="50">
                    <label class="active">Título corto</label>
                </div>
                <div class="col s12" style="position: relative;">
                    <div class="input-field" style="margin-bottom: 0;">
                        <textarea id="quick-mensaje" class="materialize-textarea" placeholder="Escribe aquí el texto que se enviará..."></textarea>
                        <label class="active">Mensaje completo</label>
                    </div>
                    <button type="button" class="btn-flat btn-small amber-text text-darken-2" id="quick-emoji-trigger" style="position: absolute; right: 10px; bottom: 10px;" title="Insertar Emoji">
                        <i class="material-icons">sentiment_satisfied</i>
                    </button>
                    <!-- Mini Picker para el modal -->
                    <div id="quick-emoji-picker" class="z-depth-2" style="display: none; position: absolute; bottom: 50px; right: 10px; background: white; border: 1px solid #ddd; border-radius: 8px; padding: 10px; width: 280px; z-index: 1100;">
                        <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 5px; border-bottom: 1px solid #eee;">
                            <span style="font-size: 0.8rem; font-weight: bold; color: #1a237e;">Emojis</span>
                            <button type="button" class="btn-flat btn-small" onclick="document.getElementById('quick-emoji-picker').style.display='none'"><i class="material-icons" style="font-size: 1.2rem;">close</i></button>
                        </div>
                        <!-- Pestañas de categorías Mini -->
                        <div class="emoji-tabs-container mini">
                            <button type="button" class="emoji-tab-btn active" onclick="switchEmojiTab('Caritas', 'quick')">😊</button>
                            <button type="button" class="emoji-tab-btn" onclick="switchEmojiTab('Animales', 'quick')">🐶</button>
                            <button type="button" class="emoji-tab-btn" onclick="switchEmojiTab('Comida', 'quick')">🍎</button>
                            <button type="button" class="emoji-tab-btn" onclick="switchEmojiTab('Actividad', 'quick')">⚽</button>
                            <button type="button" class="emoji-tab-btn" onclick="switchEmojiTab('Objetos', 'quick')">💡</button>
                            <button type="button" class="emoji-tab-btn" onclick="switchEmojiTab('Símbolos', 'quick')">❤️</button>
                        </div>
                        <div id="emoji-content-quick" style="max-height: 150px; overflow-y: auto;">
                            <?php foreach ($emojiCategories as $catName => $list): ?>
                                <div id="quick-cat-<?php echo $catName; ?>" class="emoji-grid-mini" style="display: <?php echo $catName === 'Caritas' ? 'grid' : 'none'; ?>;">
                                    <?php foreach ($list as $e): ?>
                                        <span class='emoji-item-quick' style='cursor:pointer; font-size: 1.5rem; text-align:center; padding: 3px;'><?php echo $e; ?></span>
                                    <?php endforeach; ?>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                </div>
                <div class="col s12 center-align" style="margin-top: 15px;">
                    <button type="button" class="btn indigo" onclick="guardarQuickRes()">Guardar Respuesta</button>
                    <button type="button" class="btn-flat" onclick="limpiarFormQuick()">Limpiar</button>
                </div>
            </form>
        </div>
        <div class="divider"></div>
        <ul class="collection" id="quick-manage-list"></ul>
    </div>
    <div class="modal-footer">
        <button class="modal-close btn-flat">Cerrar</button>
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

    /* Estilo para mensajes de sistema (separadores) */
    .system-msg { clear: both; text-align: center; margin: 20px 0; }
    .system-msg span { background: #eeeeee; color: #757575; padding: 4px 15px; border-radius: 20px; font-size: 0.75rem; font-weight: bold; text-transform: uppercase; letter-spacing: 0.5px; }

    /* Estilo para los encabezados de fecha colapsables (Rediseño Intuitivo) */
    .chat-date-header { text-align: center; margin: 20px 0; cursor: pointer; position: relative; }
    .chat-date-header span { 
        background: #e8eaf6; 
        color: #1a237e; 
        padding: 6px 18px; 
        border-radius: 20px; 
        font-size: 0.8rem; 
        font-weight: 600; 
        display: inline-flex; 
        align-items: center; 
        gap: 6px; 
        box-shadow: 0 1px 4px rgba(0,0,0,0.1); 
        transition: all 0.2s ease;
        border: 1px solid #c5cae9;
    }
    .chat-date-header span:hover { 
        background: #c5cae9; 
        transform: scale(1.05); 
        box-shadow: 0 2px 6px rgba(0,0,0,0.15);
    }

    /* Asegurar que el desplegable de búsqueda se vea sobre el chat */
    .autocomplete-content {
        z-index: 9999 !important;
    }
    .emoji-item:hover { background: #eeeeee; border-radius: 4px; }
    .emoji-item-quick:hover { background: #eeeeee; border-radius: 4px; }

    /* Agrandar botones de acción en el chat y centrar sus iconos perfectamente */
    .chat-input-area { align-items: center !important; }
    .chat-input-area .btn-floating {
        width: 48px !important;
        height: 48px !important;
        display: flex !important;
        align-items: center !important;
        justify-content: center !important;
    }
    .chat-input-area .btn-floating i {
        font-size: 1.8rem !important;
        line-height: 1 !important;
    }
    /* Ajustar altura del input y botón de enviar para que coincidan con los botones circulares */
    .chat-input-area input#msg-input {
        height: 48px !important;
        margin-bottom: 0 !important;
        padding-left: 15px !important;
    }
    .chat-input-area .btn:not(.btn-floating) {
        height: 48px !important;
        line-height: 48px !important;
    }

    .emoji-tabs-container { display: flex; justify-content: space-around; background: #f5f5f5; border-radius: 4px; margin-bottom: 10px; padding: 2px; }
    .emoji-tab-btn { background: none; border: none; cursor: pointer; padding: 5px; font-size: 1.2rem; flex: 1; border-radius: 4px; transition: 0.2s; }
    .emoji-tab-btn:hover { background: #e0e0e0; }
    .emoji-tab-btn.active { background: white; box-shadow: 0 1px 3px rgba(0,0,0,0.1); border-bottom: 2px solid #1a237e; }
    .emoji-tabs-container.mini .emoji-tab-btn { font-size: 1rem; padding: 3px; }
    .emoji-grid { display: grid; grid-template-columns: repeat(8, 1fr); gap: 5px; }
    .emoji-grid-mini { display: grid; grid-template-columns: repeat(7, 1fr); gap: 3px; }

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
let diasExpandidos = new Set(); // Guardar qué días ha abierto el usuario
let quickResponses = [];

function formatFriendlyDate(dateStr) {
    const date = new Date(dateStr + 'T00:00:00');
    const hoy = new Date();
    hoy.setHours(0,0,0,0);
    const ayer = new Date(hoy);
    ayer.setDate(hoy.getDate() - 1);

    if (date.getTime() === hoy.getTime()) return 'Hoy';
    if (date.getTime() === ayer.getTime()) return 'Ayer';
    
    return date.toLocaleDateString('es-ES', { day: 'numeric', month: 'long', year: 'numeric' });
}

document.addEventListener('DOMContentLoaded', () => {
    M.Modal.init(document.querySelectorAll('.modal'));
    if (esStaff) cargarListaClientes();
    if (esStaff) {
        // Cargar productos para el buscador interno del chat
        const idAlmacenStaff = <?php echo json_encode($_SESSION['usuario']['id_almacen'] ?? 1); ?>;
        fetch('<?php echo BASE_URL; ?>api/products_manager.php?action=list&almacen_id=' + idAlmacenStaff)
            .then(r => r.json())
            .then(data => {
                if (data.success && data.data) {
                    const acData = {};
                    data.data.forEach(p => {
                        const label = `[${p.sku || 'S/S'}] ${p.nombre}${p.nombre_variante ? ' (' + p.nombre_variante + ')' : ''}`;
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

        loadQuickResponses();
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

    // Lógica para el selector de respuestas rápidas
    const quickTrigger = document.getElementById('quick-msg-trigger');
    const quickPicker = document.getElementById('quick-msg-picker');
    if (quickTrigger) {
        quickTrigger.addEventListener('click', () => {
            quickPicker.style.display = quickPicker.style.display === 'none' ? 'block' : 'none';
        });
        document.addEventListener('click', (e) => {
            if (quickPicker && !quickPicker.contains(e.target) && !quickTrigger.contains(e.target)) {
                quickPicker.style.display = 'none';
            }
        });
    }

    // Lógica para el selector de emojis en Respuestas Rápidas (Modal)
    const quickEmojiTrigger = document.getElementById('quick-emoji-trigger');
    const quickEmojiPicker = document.getElementById('quick-emoji-picker');
    const quickMsgInput = document.getElementById('quick-mensaje');

    if (quickEmojiTrigger) {
        quickEmojiTrigger.addEventListener('click', () => {
            quickEmojiPicker.style.display = quickEmojiPicker.style.display === 'none' ? 'block' : 'none';
        });

        document.querySelectorAll('.emoji-item-quick').forEach(item => {
            item.addEventListener('click', () => {
                quickMsgInput.value += item.textContent;
                quickMsgInput.focus();
                M.textareaAutoResize(quickMsgInput); // Ajustar altura del textarea de Materialize
                quickEmojiPicker.style.display = 'none';
            });
        });
    }
});

function cargarListaClientes() {
    if (!esStaff) return;
    fetch('<?php echo BASE_URL; ?>api/chat_handler.php?action=fetch_clients')
        .then(r => r.json())
        .then(data => {
            if (data.success) {
                renderListaClientes(data.clientes);
            }
        });
}

function renderListaClientes(clientes) {
    const list = document.getElementById('conversations-list');
    if (!list) return;
    const header = `<div class="collection-header blue darken-4 white-text"><h6>Conversaciones</h6></div>`;
    let html = header;

    clientes.forEach(c => {
        const isMe = c.asignado_a == currentUserId;
        const hasAlerts = parseInt(c.alertas_sistema) > 0;
        const activeClass = hasAlerts ? 'orange lighten-5' : (isMe ? 'blue lighten-5' : '');
        const textStyle = hasAlerts ? 'font-weight: bold; color: #e65100;' : '';
        const alertPrefix = hasAlerts ? '⚠️ ' : '';
        const badge = parseInt(c.pendientes) > 0 ? `<span class="new badge red" data-badge-caption="">${c.pendientes}</span>` : '';
        const pin = (isMe && !hasAlerts) ? `<i class="material-icons tiny blue-text right">push_pin</i>` : '';

        html += `
            <a href="#!" onclick="seleccionarChat(${c.id_usuario}, '${c.nombre.replace(/'/g, "\\'")}')" 
               class="collection-item black-text chat-user-item ${activeClass}" id="user-item-${c.id_usuario}">
                <span style="${textStyle}">
                    ${alertPrefix}${c.nombre}
                </span>
                ${pin}
                ${badge}
            </a>`;
    });

    if (clientes.length === 0) {
        html += `<div class="center-align grey-text" style="padding: 20px;">No hay chats activos.</div>`;
    }

    list.innerHTML = html;
}

function switchEmojiTab(category, pickerType) {
    const containerId = pickerType === 'main' ? 'emoji-content-main' : 'emoji-content-quick';
    const prefix = pickerType === 'main' ? 'main-cat-' : 'quick-cat-';
    const tabsContainer = event.currentTarget.parentElement;

    document.querySelectorAll(`#${containerId} > div`).forEach(el => el.style.display = 'none');
    document.getElementById(prefix + category).style.display = 'grid';
    tabsContainer.querySelectorAll('.emoji-tab-btn').forEach(btn => btn.classList.remove('active'));
    event.currentTarget.classList.add('active');
}

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

function loadQuickResponses() {
    fetch(`<?php echo BASE_URL; ?>api/chat_handler.php?action=fetch_quick`)
        .then(r => r.json())
        .then(data => {
            if (data.success) {
                quickResponses = data.responses;
                renderQuickPickers();
            }
        });
}

function renderQuickPickers() {
    const list = document.getElementById('quick-list-container');
    const manageList = document.getElementById('quick-manage-list');
    if (!list) return;

    list.innerHTML = quickResponses.length === 0 ? '<p class="center grey-text">No tienes respuestas guardadas.</p>' : '';
    manageList.innerHTML = '';

    quickResponses.forEach(r => {
        // Para el picker del chat
        const div = document.createElement('div');
        div.className = 'collection-item';
        div.style = 'padding: 10px; cursor: pointer; border-bottom: 1px solid #f5f5f5;';
        div.innerHTML = `<strong class="purple-text text-darken-4">${r.titulo}</strong><br><small class="grey-text truncate">${r.mensaje}</small>`;
        div.onclick = () => {
            const input = document.getElementById('msg-input');
            input.value = r.mensaje;
            input.focus();
            document.getElementById('quick-msg-picker').style.display = 'none';
        };
        list.appendChild(div);

        // Para el modal de gestión
        const li = document.createElement('li');
        li.className = 'collection-item';
        li.innerHTML = `<div><strong>${r.titulo}</strong>: ${r.mensaje}
            <div class="secondary-content">
                <a href="#!" onclick="cargarQuickForm(${r.id_respuesta}, '${r.titulo.replace(/'/g, "\\'")}', '${r.mensaje.replace(/'/g, "\\'")}')"><i class="material-icons blue-text">edit</i></a>
                <a href="#!" onclick="borrarQuick(${r.id_respuesta})"><i class="material-icons red-text">delete</i></a>
            </div></div>`;
        manageList.appendChild(li);
    });
}

function abrirGestionQuick() {
    document.getElementById('quick-msg-picker').style.display = 'none';
    M.Modal.getInstance(document.getElementById('modal-gestion-quick')).open();
}

function guardarQuickRes() {
    const id = document.getElementById('quick-id').value;
    const titulo = document.getElementById('quick-titulo').value.trim();
    const mensaje = document.getElementById('quick-mensaje').value.trim();

    if (!titulo || !mensaje) return M.toast({html: 'Completa todos los campos'});

    fetch(`<?php echo BASE_URL; ?>api/chat_handler.php?action=save_quick`, {
        method: 'POST',
        body: JSON.stringify({ id_respuesta: id, titulo, mensaje })
    }).then(r => r.json()).then(data => {
        if (data.success) {
            M.toast({html: 'Guardado'});
            limpiarFormQuick();
            loadQuickResponses();
        }
    });
}

function cargarQuickForm(id, titulo, mensaje) {
    document.getElementById('quick-id').value = id;
    document.getElementById('quick-titulo').value = titulo;
    document.getElementById('quick-mensaje').value = mensaje;
    M.textareaAutoResize(document.getElementById('quick-mensaje'));
    M.updateTextFields();
}

function limpiarFormQuick() {
    document.getElementById('form-quick-res').reset();
    document.getElementById('quick-id').value = '';
}

function borrarQuick(id) {
    if (!confirm('¿Eliminar esta respuesta?')) return;
    fetch(`<?php echo BASE_URL; ?>api/chat_handler.php?action=delete_quick&id_respuesta=${id}`)
        .then(() => loadQuickResponses());
}

function iniciarChat() {
    fetch(`<?php echo BASE_URL; ?>api/chat_handler.php?action=start`)
        .then(r => r.json())
        .then(data => {
            if (data.success) {
                chatEstabaActivo = true;
                document.getElementById('chat-welcome').style.display = 'none';
                document.getElementById('chat-box').style.display = 'block';
                document.querySelector('.chat-input-area').style.display = 'flex';
                cargarMensajes();
            } else {
                M.toast({html: 'Error al iniciar: ' + data.message, classes: 'red'});
            }
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
                            html: `El Chat ha terminado la consulta.<br><small class="grey-text">Cerrado el: ${fechaHora}</small>`,
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
                    document.getElementById('typing-name').textContent = esStaff ? 'El cliente' : 'Chat';
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
                        if (!esStaff) M.toast({html: 'Nuevo mensaje del Chat', classes: 'blue'});
                    }
                }

                ultimoConteoMensajes = data.mensajes.length;
                primeraCarga = false;

                box.innerHTML = '';
                let lastDateStr = null;
                let currentDayContent = null;
                // Obtener fecha de hoy en formato local YYYY-MM-DD para evitar desfases de zona horaria (UTC)
                const n = new Date();
                const todayStr = n.getFullYear() + '-' + String(n.getMonth() + 1).padStart(2, '0') + '-' + String(n.getDate()).padStart(2, '0');

                data.mensajes.forEach(m => {
                    const msgDate = m.fecha_envio.split(' ')[0]; // YYYY-MM-DD

                    // Crear un nuevo grupo si cambia el día
                    if (msgDate !== lastDateStr) {
                        const isToday = (msgDate === todayStr);
                        // El día de hoy siempre aparece expandido; otros días solo si el usuario los abrió
                        const isExpanded = isToday || diasExpandidos.has(msgDate);
                        
                        const header = document.createElement('div');
                        header.className = 'chat-date-header';
                        header.innerHTML = `<span>${formatFriendlyDate(msgDate)} <i class="material-icons" style="font-size: 1.2rem;">${isExpanded ? 'keyboard_arrow_up' : 'keyboard_arrow_down'}</i></span>`;
                        
                        currentDayContent = document.createElement('div');
                        currentDayContent.className = 'day-content';
                        currentDayContent.style.display = isExpanded ? 'block' : 'none';
                        
                        header.onclick = () => {
                            const isHidden = currentDayContent.style.display === 'none';
                            currentDayContent.style.display = isHidden ? 'block' : 'none';
                            header.querySelector('i').textContent = isHidden ? 'keyboard_arrow_up' : 'keyboard_arrow_down';
                            if (isHidden) diasExpandidos.add(msgDate); else diasExpandidos.delete(msgDate);
                        };

                        box.appendChild(header);
                        box.appendChild(currentDayContent);
                        lastDateStr = msgDate;
                    }

                    if (m.tipo_mensaje === 'sistema' || m.tipo_mensaje === 'seguridad') {
                        const div = document.createElement('div');
                        div.className = 'system-msg';
                        div.innerHTML = `<span>${m.mensaje}</span>`;
                        currentDayContent.appendChild(div);
                        return;
                    }

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
                    currentDayContent.appendChild(div);
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

// Ayudante JS para resolver la URL de la imagen de forma robusta en el chat
function getProductImgUrl(imgData) {
    let baseUrl = '<?php echo BASE_URL; ?>';
    if (!imgData || typeof imgData !== 'string') return baseUrl + 'assets/img/no-product.png';
    
    imgData = imgData.trim();
    if (['NULL', 'undefined', '[object Object]', 'null', ''].includes(imgData)) {
        return baseUrl + 'assets/img/no-product.png';
    }
    if (!baseUrl.endsWith('/')) baseUrl += '/';

    // 1. Si ya es una URL completa o un data-uri
    if (imgData.startsWith('http') || imgData.startsWith('data:image')) return imgData;

    // 2. Detección de Base64 (PNG, JPG, WebP)
    if (/^(iVBORw|\/9j\/|UklGR)/.test(imgData)) {
        let mime = 'image/jpeg';
        if (imgData.startsWith('iVBORw')) mime = 'image/png';
        if (imgData.startsWith('UklGR')) mime = 'image/webp';
        return `data:${mime};base64,${imgData}`;
    }
    
    // 3. Ruta de archivo (asumimos que está en la carpeta de productos)
    if (imgData.includes('/') || /\.(jpg|jpeg|png|webp|gif|svg)$/i.test(imgData)) {
        const cleanPath = imgData.replace(/^\/+/, '');
        return baseUrl + 'assets/img/products/' + cleanPath;
    }
    return baseUrl + 'assets/img/no-product.png';
}

function renderProductCard(p, isMe) {
    const img = getProductImgUrl(p.imagen);
    const detailUrl = `<?php echo BASE_URL; ?>product_detail.php?id=${p.id_producto}`;
    // Escapar comillas simples para evitar que rompan el atributo onclick
    const safeName = p.nombre.replace(/'/g, "\\'");
    return `
        <div class="chat-product-card">
            <a href="${detailUrl}" target="_blank" title="Ver detalles del producto">
                <img src="${img}" style="cursor: pointer;">
            </a>
            <div class="info">
                <a href="${detailUrl}" target="_blank" class="black-text" title="Ver detalles del producto">
                    <div class="truncate" style="font-weight:bold; font-size:0.85rem; cursor: pointer;">${p.nombre}</div>
                </a>
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
    const nombreCompleto = p.nombre + (p.nombre_variante ? ' (' + p.nombre_variante + ')' : '');
    const pData = JSON.stringify({
        id_producto: p.id_producto,
        nombre: nombreCompleto,
        precio_venta: p.precio_venta,
        imagen: p.imagen
    });
    enviarMensaje('producto', pData);
    document.getElementById('chat-product-autocomplete').value = '';
}

function addToCartFromChat(id, nombre, precio, imagen) {
    let cart = getCart();
    let item = cart.find(i => String(i.id_producto) === String(id));
    
    if (item) {
        item.quantity = (parseInt(item.quantity) || 0) + 1;
    } else {
        cart.push({
            id_producto: String(id),
            nombre: nombre,
            precio: parseFloat(precio),
            imagen: imagen,
            quantity: 1
        });
    }

    localStorage.setItem('cart', JSON.stringify(cart));
    M.toast({html: '🛒 <b>' + nombre + '</b> añadido al carrito', classes: 'green rounded'});
    updateCartBadge();
}

// Polling: revisar nuevos mensajes cada 5 segundos
setInterval(cargarMensajes, 5000);
if (esStaff) setInterval(cargarListaClientes, 10000);

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