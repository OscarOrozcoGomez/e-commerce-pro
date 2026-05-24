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
        body { background-color: #f5f5f5; }
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
    </style>
</head>
<body class="grey lighten-4">
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
                <li><a href="<?php echo BASE_URL; ?>">Catálogo</a></li>
                <li><a href="<?php echo BASE_URL; ?>views/blog.php">Blog</a></li>
                <li>
                    <a href="<?php echo BASE_URL; ?>views/cart.php" class="nav-cart-link">
                        <i class="material-icons">shopping_cart</i>
                        <span id="cart-count" class="new badge red" data-badge-caption="" style="display: none;">0</span>
                    </a>
                </li>
                
                <?php if (isAuthenticated() && !isCliente()): ?>
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
                    <!-- Dropdown Trigger -->
                    <li style="height: 64px; display: flex; align-items: center;">
                        <a class="dropdown-trigger waves-effect waves-light" href="#!" data-target="user-dropdown" 
                           style="display: flex; align-items: center; justify-content: center; width: 60px; height: 64px; margin-left: 5px;">
                            <i class="material-icons blue darken-3 circle white-text" style="width: 40px; height: 40px; line-height: 40px; text-align: center;">person</i>
                        </a>
                    </li>
                    
                    <!-- Dropdown Structure -->
                    <ul id="user-dropdown" class="dropdown-content profile-dropdown">
                        <li class="user-info grey lighten-4">
                            <div class="user-details">
                                <span class="user-name"><strong><?php echo esc($_SESSION['usuario']['nombre']); ?></strong></span>
                                <span class="user-email grey-text"><?php echo esc($_SESSION['usuario']['email']); ?></span>
                                <span class="user-role badge blue white-text" style="float: none; margin-left: 0; margin-top: 5px;"><?php echo ucfirst(esc($_SESSION['usuario']['rol'])); ?></span>
                            </div>
                        </li>
                        <li class="divider"></li>
                        <?php if (isCliente()): ?>
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
        <li class="blue darken-4 white-text center-align" style="padding: 20px 0;">
            <img src="<?php echo BASE_URL; ?>assets/img/logo.png" alt="Logo" style="height: 50px;">
            <?php if (isAuthenticated()): ?>
                <p style="margin: 10px 0 0 0; font-size: 0.9rem;"><?php echo esc($_SESSION['usuario']['nombre']); ?></p>
            <?php endif; ?>
        </li>
        <li><a href="<?php echo BASE_URL; ?>"><i class="material-icons">home</i> Catálogo</a></li>
        <li><a href="<?php echo BASE_URL; ?>views/blog.php"><i class="material-icons">book</i> Blog</a></li>
        <li><a href="<?php echo BASE_URL; ?>views/cart.php"><i class="material-icons">shopping_cart</i> Carrito</a></li>
        
        <?php if (isAuthenticated()): ?>
            <li class="divider"></li>
            <?php if (isCliente()): ?>
                <li><a href="<?php echo BASE_URL; ?>views/mis_compras.php"><i class="material-icons">shopping_bag</i> Mis Compras</a></li>
                <li><a href="<?php echo BASE_URL; ?>views/mis_direcciones.php"><i class="material-icons">place</i> Mis Direcciones</a></li>
                <li><a href="<?php echo BASE_URL; ?>views/chat.php"><i class="material-icons">chat</i> Soporte en vivo</a></li>
            <?php else: ?>
                <li><a href="<?php echo BASE_URL; ?>views/dashboard.php"><i class="material-icons">dashboard</i> Dashboard</a></li>
                <li><a href="<?php echo BASE_URL; ?>views/chat.php"><i class="material-icons">chat</i> Mensajes</a></li>
            <?php endif; ?>
            <li><a href="<?php echo BASE_URL; ?>logout.php" class="red-text"><i class="material-icons red-text">exit_to_app</i> Salir</a></li>
        <?php else: ?>
            <li><a href="<?php echo BASE_URL; ?>views/login.php" class="blue-text"><i class="material-icons blue-text">login</i> Iniciar Sesión</a></li>
            <li><a href="<?php echo BASE_URL; ?>views/register.php"><i class="material-icons">person_add</i> Registrarse</a></li>
        <?php endif; ?>
    </ul>

    <!-- Scripts para Inicializar Componentes -->
    <script src="https://cdnjs.cloudflare.com/ajax/libs/materialize/1.0.0/js/materialize.min.js"></script>
    <script>
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
        });
    </script>

    <style>
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