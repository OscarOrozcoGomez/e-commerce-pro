<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo $pageTitle ?? 'POS Sistema'; ?></title>
    <link rel="icon" type="image/png" href="<?php echo BASE_URL; ?>assets/img/logo.png">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/materialize/1.0.0/css/materialize.min.css">
    <link href="https://fonts.googleapis.com/icon?family=Material+Icons" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    <style>
        .product-card { margin-bottom: 20px; }
        .search-container { margin-bottom: 30px; }
        .product-image { height: 150px; object-fit: cover; }
        .stock-badge { position: absolute; top: 10px; right: 10px; }
        
        /* Estructura para Sticky Footer */
        body { 
            display: flex;
            min-height: 100vh;
            flex-direction: column;
            background-color: #f5f5f5; 
        }
        /* Estilos para el contador del carrito */
        .nav-cart-link {
            position: relative;
            display: flex !important;
            align-items: center;
        }
        #cart-count {
            position: absolute;
            top: 5px;
            right: -5px;
            min-width: 18px;
            height: 18px;
            line-height: 18px;
            padding: 0 4px;
        }
        .nav-favorites-link {
            position: relative;
            display: flex !important;
            align-items: center;
        }
        #favorites-count {
            position: absolute;
            top: 5px;
            right: -5px;
            min-width: 18px;
            height: 18px;
            line-height: 18px;
            padding: 0 4px;
            display: inline-block;
        }
        .nav-wrapper .brand-logo img {
            height: 50px;
            margin-top: 7px;
            margin-left: 15px;
        }
        .delivery-banner {
            background: linear-gradient(90deg, #ff9800 0%, #ed6c02 100%);
            color: white;
            padding: 8px 0;
            text-align: center;
            font-size: 0.95rem;
            font-weight: 500;
            box-shadow: 0 2px 5px rgba(0,0,0,0.1);
        }
        .banner-content { display: flex; align-items: center; justify-content: center; gap: 10px; }

        /* Estilo para Leyendas Legales / Advertencias */
        .legal-disclaimer {
            border: 2px solid #fbc02d !important;
            border-radius: 12px !important;
            box-shadow: 0 2px 8px rgba(0,0,0,0.1) !important;
        }
    </style>
</head>
<body class="grey lighten-4">
    <?php
        $headerDisplayName = '';
        $headerAvatarInitials = 'U';
        if (isAuthenticated()) {
            $headerDisplayName = trim((string)($_SESSION['usuario']['nombre'] ?? ''));
            if ($headerDisplayName !== '') {
                $nameParts = preg_split('/\s+/u', $headerDisplayName, -1, PREG_SPLIT_NO_EMPTY);
                if (is_array($nameParts) && count($nameParts) > 0) {
                    $firstInitial = mb_substr($nameParts[0], 0, 1, 'UTF-8');
                    $lastInitial = count($nameParts) > 1 ? mb_substr($nameParts[count($nameParts) - 1], 0, 1, 'UTF-8') : '';
                    $headerAvatarInitials = mb_strtoupper($firstInitial . $lastInitial, 'UTF-8');
                }
            }
        }
    ?>
    <!-- Banner Informativo de Entregas -->
    <div class="delivery-banner">
        <div class="container">
            <div class="banner-content">
                <i class="material-icons tiny">info</i>
                <span><strong>¡Entregas Programadas!</strong> Solo Miércoles y Sábados. Garantiza tu lugar enviando el anticipo de $50.</span>
            </div>
        </div>
    </div>
    <nav class="blue darken-4">
        <div class="nav-wrapper">
            <a href="<?php echo BASE_URL; ?>" class="brand-logo" style="margin-left: 10px;">
                <img src="<?php echo BASE_URL; ?>assets/img/logo.png" alt="Logo">
            </a>
            <!-- Botón para menú móvil -->
            <a href="#" data-target="mobile-nav" class="sidenav-trigger"><i class="material-icons">menu</i></a>

            <ul id="nav-mobile" class="right hide-on-med-and-down">
                <li><a href="<?php echo BASE_URL; ?>views/catalogo.php">Catálogo</a></li>
                <li><a href="<?php echo BASE_URL; ?>views/blog.php">Blog</a></li>
                <li class="nav-cart-container">
                    <a href="<?php echo BASE_URL; ?>views/cart.php" class="nav-cart-link">
                        <i class="material-icons">shopping_cart</i>
                        <span id="cart-count" class="new badge red" data-badge-caption="" style="display: none;">0</span>
                    </a>
                </li>
                
                <?php if (isAuthenticated() && !isCliente() && !isRepartidor()): ?>
                    <?php
                        $pdoHead = getPDO();
                        $sqlAlert = "SELECT COUNT(*) FROM inventario_almacen ia 
                                     JOIN productos p ON ia.id_producto = p.id_producto 
                                     WHERE ia.cantidad_actual <= ia.stock_minimo AND p.estado = 'activo'";
                        $almacenId = getCurrentAlmacenId();
                        if (!isAdmin() && $almacenId) {
                            $sqlAlert .= " AND ia.id_almacen = " . intval($almacenId);
                        }
                        $countLowStock = $pdoHead->query($sqlAlert)->fetchColumn();
                    ?>
                    <li style="position: relative;">
                        <a href="<?php echo (isAdmin() || isEncargado()) ? BASE_URL . 'views/purchase_orders.php' : '#'; ?>" title="Alertas de Stock" style="position: relative;">
                            <i class="material-icons <?php echo $countLowStock > 0 ? 'orange-text text-lighten-2 animated pulse infinite' : ''; ?>">notifications</i>
                            <?php if ($countLowStock > 0): ?>
                                <span class="new badge orange" data-badge-caption="" style="position: absolute; top: 10px; right: -5px; min-width: 18px; height: 18px; line-height: 18px; padding: 0 4px; font-size: 11px;"><?php echo $countLowStock; ?></span>
                            <?php endif; ?>
                        </a>
                    </li>
                <?php endif; ?>

                <?php if (isAuthenticated()): ?>
                    <?php
                        // Preparar notificaciones de chat para cualquier usuario logueado
                        if (!isset($pdoHead)) $pdoHead = getPDO();
                        $id_u = (int)$_SESSION['usuario']['id_usuario'];
                        $col = isCliente() ? 'leido_cliente' : 'leido_staff';
                        
                        // Contar mensajes no leídos
                        $sqlChat = "SELECT COUNT(*) FROM mensajes_soporte WHERE $col = 0 AND " . (isCliente() ? "id_cliente = $id_u" : "1=1");
                        $unreadChat = (int)$pdoHead->query($sqlChat)->fetchColumn();

                        // Verificar si alguno de esos mensajes es una alerta de sistema (bloqueo)
                        $hasSecurityAlert = false;
                        if (!isCliente() && $unreadChat > 0) {
                            $hasSecurityAlert = (int)$pdoHead->query("SELECT COUNT(*) FROM mensajes_soporte WHERE leido_staff = 0 AND tipo_mensaje = 'sistema'")->fetchColumn() > 0;
                        }
                    ?>
                    <li>
                        <a href="<?php echo BASE_URL; ?>views/chat.php" title="Chat de Soporte" style="position: relative;">
                            <i class="material-icons <?php echo $unreadChat > 0 ? ($hasSecurityAlert ? 'orange-text text-darken-2' : 'green-text text-lighten-2') : ''; ?>">chat</i>
                            <?php if ($unreadChat > 0): ?>
                                <span class="new badge <?php echo $hasSecurityAlert ? 'orange darken-3' : 'green'; ?>" data-badge-caption="" style="position: absolute; top: 10px; right: -5px; min-width: 18px; height: 18px; line-height: 18px; padding: 0 4px; font-size: 11px;">
                                    <?php echo $unreadChat; ?>
                                </span>
                            <?php endif; ?>
                        </a>
                    </li>
                    <li>
                        <a href="<?php echo BASE_URL; ?>favoritos.php" class="nav-favorites-link" title="Mis Favoritos">
                            <i class="material-icons">favorite</i>
                            <span id="favorites-count" class="new badge pink" data-badge-caption="">0</span>
                        </a>
                    </li>
                    <!-- Dropdown Trigger -->
                    <li style="height: 64px; display: flex; align-items: center;">
                        <a class="dropdown-trigger waves-effect waves-light" href="#!" data-target="user-dropdown" 
                           style="display: flex; align-items: center; justify-content: center; width: 60px; height: 64px; margin-left: 5px;">
                            <span class="profile-avatar-chip" title="Perfil">
                                <i class="material-icons avatar-person">person</i>
                                <span class="avatar-initials"><?php echo esc($headerAvatarInitials); ?></span>
                            </span>
                        </a>
                    </li>
                    
                    <!-- Dropdown Structure -->
                    <ul id="user-dropdown" class="dropdown-content profile-dropdown">
                        <li class="user-info grey lighten-4">
                            <div class="dropdown-avatar-wrap">
                                <span class="profile-avatar-chip large">
                                    <i class="material-icons avatar-person">person</i>
                                    <span class="avatar-initials"><?php echo esc($headerAvatarInitials); ?></span>
                                </span>
                            </div>
                            <div class="user-details">
                                <span class="user-name"><strong><?php echo esc($_SESSION['usuario']['nombre']); ?></strong></span>
                                <span class="user-email grey-text"><?php echo esc($_SESSION['usuario']['email']); ?></span>
                                <span class="user-role badge blue white-text" style="float: none; margin-left: 0; margin-top: 5px;"><?php echo ucfirst(esc($_SESSION['usuario']['rol'])); ?></span>
                            </div>
                        </li>
                        <li class="divider"></li>
                        <?php if (isCliente()): ?>
                            <li><a href="<?php echo BASE_URL; ?>views/mi_perfil.php"><i class="material-icons">person_outline</i> Mi Perfil</a></li>
                            <li><a href="<?php echo BASE_URL; ?>views/mis_compras.php"><i class="material-icons">shopping_bag</i> Mis Compras</a></li>
                            <li><a href="<?php echo BASE_URL; ?>views/mis_direcciones.php"><i class="material-icons">place</i> Mis Direcciones</a></li>
                            <li><a href="<?php echo BASE_URL; ?>views/chat.php"><i class="material-icons">chat</i> Soporte en vivo</a></li>
                        <?php else: ?>
                            <li><a href="<?php echo BASE_URL; ?>views/dashboard.php"><i class="material-icons">dashboard</i> Dashboard</a></li>
                            <li><a href="<?php echo BASE_URL; ?>views/chat.php"><i class="material-icons">chat</i> Centro de Mensajes</a></li>
                        <?php endif; ?>
                        
                        <?php if (hasPermission('gestionar_blogs')): ?>
                            <li><a href="<?php echo BASE_URL; ?>views/manage_blogs.php"><i class="material-icons">book</i> Gestionar Blogs</a></li>
                        <?php endif; ?>
                        
                        <?php if (isAdmin()): ?>
                            <li><a href="<?php echo BASE_URL; ?>views/users.php"><i class="material-icons">people</i> Usuarios</a></li>
                        <?php endif; ?>
                        
                        <li class="divider"></li>
                        <li><a href="<?php echo BASE_URL; ?>logout.php" class="red-text text-darken-1"><i class="material-icons red-text">exit_to_app</i> Cerrar Sesión</a></li>
                    </ul>
                <?php else: ?>
                    <li><a href="<?php echo BASE_URL; ?>views/register.php">Registrarse</a></li>
                    <li><a href="<?php echo BASE_URL; ?>views/login.php" class="btn blue darken-3 waves-effect waves-light">Iniciar Sesión</a></li>
                <?php endif; ?>
            </ul>
        </div>
    </nav>

    <!-- Estructura del Menú Lateral (Móvil) -->
    <ul class="sidenav" id="mobile-nav">
        <li class="blue darken-4 white-text center-align" style="padding: 20px 0; position: relative;">
            <!-- Botón de Cerrar para mejor UX en móvil -->
            <a href="#!" class="sidenav-close white-text" style="position: absolute; right: 15px; top: 15px;">
                <i class="material-icons">close</i>
            </a>
            <img src="<?php echo BASE_URL; ?>assets/img/logo.png" alt="Logo" style="height: 50px;">
            <?php if (isAuthenticated()): ?>
                <div style="margin-top: 10px; display: flex; justify-content: center;">
                    <span class="profile-avatar-chip large mobile-avatar-chip">
                        <i class="material-icons avatar-person">person</i>
                        <span class="avatar-initials"><?php echo esc($headerAvatarInitials); ?></span>
                    </span>
                </div>
                <p style="margin: 10px 0 0 0; font-size: 0.9rem;"><?php echo esc($_SESSION['usuario']['nombre']); ?></p>
            <?php endif; ?>
        </li>
        <li><a href="<?php echo BASE_URL; ?>"><i class="material-icons">home</i> Catálogo</a></li>
        <li><a href="<?php echo BASE_URL; ?>views/blog.php"><i class="material-icons">book</i> Blog</a></li>
        <li>
            <a href="<?php echo BASE_URL; ?>views/cart.php">
                <i class="material-icons">shopping_cart</i> Carrito 
                <span class="new badge red cart-count-mobile" data-badge-caption="" style="display: none; float: none; margin-left: 5px;">0</span>
            </a>
        </li>
        
        <?php if (isAuthenticated()): ?>
            <li class="divider"></li>
            <?php if (isCliente()): ?>
                <li><a href="<?php echo BASE_URL; ?>views/mi_perfil.php"><i class="material-icons">person_outline</i> Mi Perfil</a></li>
                <li><a href="<?php echo BASE_URL; ?>views/mis_compras.php"><i class="material-icons">shopping_bag</i> Mis Compras</a></li>
                <li><a href="<?php echo BASE_URL; ?>views/mis_direcciones.php"><i class="material-icons">place</i> Mis Direcciones</a></li>
                <li><a href="<?php echo BASE_URL; ?>views/chat.php"><i class="material-icons">chat</i> Soporte en vivo</a></li>
                <li><a href="<?php echo BASE_URL; ?>favoritos.php"><i class="material-icons">favorite</i> Mis Favoritos <span class="new badge pink favorites-count-mobile" data-badge-caption="" style="float: none; margin-left: 5px;">0</span></a></li>
            <?php else: ?>
                <li><a href="<?php echo BASE_URL; ?>views/dashboard.php"><i class="material-icons">dashboard</i> Dashboard</a></li>
                <li><a href="<?php echo BASE_URL; ?>views/chat.php"><i class="material-icons">chat</i> Mensajes</a></li>
                <li><a href="<?php echo BASE_URL; ?>favoritos.php"><i class="material-icons">favorite</i> Mis Favoritos <span class="new badge pink favorites-count-mobile" data-badge-caption="" style="float: none; margin-left: 5px;">0</span></a></li>
            <?php endif; ?>
            <li><a href="<?php echo BASE_URL; ?>logout.php" class="red-text"><i class="material-icons red-text">exit_to_app</i> Salir</a></li>
        <?php else: ?>
            <li><a href="<?php echo BASE_URL; ?>views/login.php" class="blue-text"><i class="material-icons blue-text">login</i> Iniciar Sesión</a></li>
            <li><a href="<?php echo BASE_URL; ?>views/register.php"><i class="material-icons">person_add</i> Registrarse</a></li>
        <?php endif; ?>
    </ul>

    <!-- Contenedor Principal para empujar el footer hacia abajo -->
    <main style="flex: 1 0 auto;">

    <!-- Scripts para Inicializar Componentes -->
    <script src="https://cdnjs.cloudflare.com/ajax/libs/materialize/1.0.0/js/materialize.min.js"></script>
    <script>
        const USER_IS_AUTHENTICATED = <?php echo isAuthenticated() ? 'true' : 'false'; ?>;
        const CURRENT_USER_ID = <?php echo isAuthenticated() ? (int)($_SESSION['usuario']['id_usuario'] ?? 0) : 0; ?>;
        const FAVORITES_API_URL = '<?php echo BASE_URL; ?>api/favorites.php';

        // Persistir parametros de marketing para atribucion de conversiones.
        (function persistAttribution() {
            const params = new URLSearchParams(window.location.search);
            const keys = ['gclid', 'wbraid', 'gbraid', 'utm_source', 'utm_medium', 'utm_campaign', 'utm_term', 'utm_content'];
            const found = {};
            let hasAny = false;

            keys.forEach((key) => {
                const value = params.get(key);
                if (value && value.trim() !== '') {
                    found[key] = value.trim();
                    hasAny = true;
                }
            });

            if (!hasAny) {
                return;
            }

            const payload = {
                ...found,
                landing_page: window.location.pathname,
                captured_at: new Date().toISOString(),
                referrer: document.referrer || null
            };

            try {
                localStorage.setItem('bb_marketing_attribution', JSON.stringify(payload));
            } catch (e) {
                // No bloquear UX si localStorage esta deshabilitado.
            }
        })();

        // Función global para obtener el carrito de forma segura
        function getCart() {
            try {
                let cart = JSON.parse(localStorage.getItem('cart') || '[]');
                if (!Array.isArray(cart)) return [];
                
                // LIMPIEZA Y NORMALIZACIÓN: 
                // Filtra items corruptos y asegura que las propiedades sean las correctas
                const cleanedCart = cart.filter(item => {
                    return item && (item.id_producto || item.id) && (item.nombre || item.name);
                }).map(item => ({
                    id_producto: String(item.id_producto || item.id),
                    nombre: item.nombre || item.name,
                    precio: parseFloat(item.precio || item.price || 0),
                    imagen: item.imagen || item.image || '',
                    quantity: Math.max(1, parseInt(item.quantity) || 1)
                }));

                // Si hubo cambios por limpieza, actualizamos el almacenamiento
                if (cart.length !== cleanedCart.length) {
                    localStorage.setItem('cart', JSON.stringify(cleanedCart));
                }
                return cleanedCart;
            } catch (e) { 
                console.error("Error al obtener carrito:", e);
                return []; 
            }
        }

        // Función global para actualizar todos los numerales del carrito en la página
        function updateCartBadge() {
            const cart = getCart();
            const totalItems = cart.reduce((sum, item) => sum + (parseInt(item.quantity) || 0), 0);
            const badges = document.querySelectorAll('#cart-count, .cart-count-mobile, #mini-cart-count, .cart-badge, .nav-cart-count');
            badges.forEach(badge => {
                badge.textContent = totalItems;
                badge.style.setProperty('display', totalItems > 0 ? 'inline-block' : 'none', 'important');
            });
        }

        function paintFavoritesBadge(totalFavorites) {
            const badges = document.querySelectorAll('#favorites-count, .favorites-count-mobile');
            badges.forEach(badge => {
                badge.textContent = totalFavorites;
                badge.style.setProperty('display', 'inline-block', 'important');
            });
        }

        async function updateFavoritesBadge(totalFavoritesOverride = null) {
            if (!USER_IS_AUTHENTICATED) {
                return;
            }

            if (typeof totalFavoritesOverride === 'number' && Number.isFinite(totalFavoritesOverride)) {
                paintFavoritesBadge(Math.max(0, Math.floor(totalFavoritesOverride)));
                return;
            }

            try {
                const response = await fetch(`${FAVORITES_API_URL}?mode=count`);
                const data = await response.json();
                if (response.ok && data.success) {
                    paintFavoritesBadge(parseInt(data.count, 10) || 0);
                }
            } catch (e) {
                console.error('Error al actualizar favoritos:', e);
            }
        }

        async function syncLegacyFavoritesToServer() {
            if (!USER_IS_AUTHENTICATED || CURRENT_USER_ID <= 0) {
                return;
            }

            const migrationKey = `bb_favorites_synced_user_${CURRENT_USER_ID}`;
            if (localStorage.getItem(migrationKey) === '1') {
                return;
            }

            let legacy = [];
            try {
                const parsed = JSON.parse(localStorage.getItem('favorites') || '[]');
                legacy = Array.isArray(parsed) ? parsed : [];
            } catch (e) {
                legacy = [];
            }

            const productIds = Array.from(new Set(
                legacy
                    .map(item => parseInt(item?.id_producto ?? item?.id ?? 0, 10))
                    .filter(id => Number.isFinite(id) && id > 0)
            ));

            if (productIds.length === 0) {
                localStorage.setItem(migrationKey, '1');
                return;
            }

            try {
                const response = await fetch(FAVORITES_API_URL, {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({
                        action: 'sync',
                        items: productIds
                    })
                });
                const data = await response.json();
                if (response.ok && data.success) {
                    localStorage.removeItem('favorites');
                    localStorage.setItem(migrationKey, '1');
                    await updateFavoritesBadge(data.count);
                }
            } catch (e) {
                console.error('Error migrando favoritos legacy:', e);
            }
        }

        document.addEventListener('DOMContentLoaded', function() {
            // Inicializar Menú Lateral
            var sidenavElems = document.querySelectorAll('.sidenav');
            M.Sidenav.init(sidenavElems);

            var elems = document.querySelectorAll('.dropdown-trigger');
            var instances = M.Dropdown.init(elems, {
                alignment: 'right',
                constrainWidth: false,
                coverTrigger: false,
                closeOnClick: true
            });

            // Lógica para el botón "Ir Arriba"
            const scrollBtn = document.getElementById('scroll-to-top');
            if (scrollBtn) {
                window.addEventListener('scroll', () => {
                    if (window.scrollY > 400) {
                        scrollBtn.style.display = 'block';
                    } else {
                        scrollBtn.style.display = 'none';
                    }
                });

                scrollBtn.addEventListener('click', () => {
                    window.scrollTo({ top: 0, behavior: 'smooth' });
                });
            }

            // Carga inicial del contador al entrar a cualquier página
            updateCartBadge();
            syncLegacyFavoritesToServer().finally(() => {
                updateFavoritesBadge();
            });

            window.addEventListener('storage', function(event) {
                if (event.key === 'cart') {
                    updateCartBadge();
                }
            });
        });
    </script>

    <style>
        .profile-avatar-chip {
            position: relative;
            width: 40px;
            height: 40px;
            border-radius: 50%;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            background: radial-gradient(circle at 25% 20%, #42a5f5 0%, #1565c0 65%, #0d47a1 100%);
            border: 2px solid rgba(255, 255, 255, 0.45);
            box-shadow: 0 4px 10px rgba(0, 0, 0, 0.25);
            overflow: hidden;
        }
        .profile-avatar-chip.large {
            width: 52px;
            height: 52px;
        }
        .profile-avatar-chip .avatar-person {
            position: absolute;
            bottom: -2px;
            left: -1px;
            font-size: 26px;
            color: rgba(255, 255, 255, 0.4);
            pointer-events: none;
        }
        .profile-avatar-chip.large .avatar-person { font-size: 32px; }
        .profile-avatar-chip .avatar-initials {
            position: relative;
            z-index: 1;
            font-size: 0.92rem;
            font-weight: 700;
            letter-spacing: 0.5px;
            color: #ffffff;
            text-shadow: 0 1px 2px rgba(0,0,0,0.35);
        }
        .profile-avatar-chip.large .avatar-initials { font-size: 1.02rem; }
        .dropdown-avatar-wrap {
            display: flex;
            justify-content: center;
            margin-bottom: 10px;
        }
        .mobile-avatar-chip {
            border-color: rgba(255,255,255,0.6);
            box-shadow: 0 2px 8px rgba(0,0,0,0.25);
        }
        .profile-dropdown {
            min-width: 250px !important;
            border-radius: 8px !important;
            overflow: hidden;
            box-shadow: 0 4px 20px rgba(0,0,0,0.15) !important;
        }
        .user-info {
            padding: 20px !important;
            line-height: 1.2 !important;
            cursor: default !important;
        }
        .user-info:hover { background-color: #f5f5f5 !important; }
        .user-details {
            display: flex;
            flex-direction: column;
        }
        .user-name { font-size: 1.1rem; color: #333; }
        .user-email { font-size: 0.9rem; }
        .profile-dropdown li a {
            padding: 15px 20px !important;
            display: flex !important;
            align-items: center;
        }
        .profile-dropdown li a i {
            margin-right: 15px !important;
            margin-left: 0 !important;
        }
    </style>

    <?php if (isAuthenticated()): ?>
    <!-- Sistema de Rastreo de Actividad -->
    <script>
        (function() {
            const apiEndpoint = '<?php echo BASE_URL; ?>api/log_activity.php';
            
            // 1. Registrar Visita a la página
            fetch(apiEndpoint, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({
                    tipo: 'visit',
                    url: window.location.href
                })
            });

            // 2. Registrar Clics en elementos interactivos
            document.addEventListener('click', function(e) {
                const target = e.target.closest('a, button, .btn, .btn-floating');
                if (target) {
                    fetch(apiEndpoint, {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/json' },
                        body: JSON.stringify({
                            tipo: 'click',
                            url: window.location.href,
                            id: target.id || target.name || null,
                            texto: target.innerText.trim().substring(0, 50) || target.title || 'Icon/Image'
                        })
                    });
                }
            }, true);
        })();
    </script>
    <?php endif; ?>