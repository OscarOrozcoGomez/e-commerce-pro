<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo $pageTitle ?? 'POS Sistema'; ?></title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/materialize/1.0.0/css/materialize.min.css">
    <link href="https://fonts.googleapis.com/icon?family=Material+Icons" rel="stylesheet">
    <style>
        .product-card { margin-bottom: 20px; }
        .search-container { margin-bottom: 30px; }
        .product-image { height: 150px; object-fit: cover; }
        .stock-badge { position: absolute; top: 10px; right: 10px; }
        body { background-color: #f5f5f5; }
    </style>
</head>
<body class="grey lighten-4">
    <nav class="blue darken-4">
        <div class="nav-wrapper">
            <a href="<?php echo BASE_URL; ?>" class="brand-logo">POS Sistema</a>
            <ul id="nav-mobile" class="right hide-on-med-and-down">
                <li><a href="<?php echo BASE_URL; ?>">Catálogo</a></li>
                <?php if (isAuthenticated()): ?>
                    <li><a href="<?php echo BASE_URL; ?>views/dashboard.php">Dashboard</a></li>
                    <?php if (isAdmin()): ?>
                        <li><a href="<?php echo BASE_URL; ?>views/users.php">Usuarios</a></li>
                    <?php endif; ?>
                    <li><a href="<?php echo BASE_URL; ?>logout.php">Cerrar Sesión</a></li>
                    <li><a href="#"><?php echo esc($_SESSION['usuario']['nombre']); ?></a></li>
                <?php else: ?>
                    <li><a href="<?php echo BASE_URL; ?>views/login.php">Iniciar Sesión</a></li>
                <?php endif; ?>
            </ul>
        </div>
    </nav>