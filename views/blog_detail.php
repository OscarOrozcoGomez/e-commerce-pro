<?php
declare(strict_types=1);

require_once __DIR__ . '/../core/config.php';
require_once __DIR__ . '/../core/auth.php';

// Requerimos autenticación para ver el contenido completo
requireAuth();

$id = (int)($_GET['id'] ?? 0);
$pdo = getPDO();

try {
    $stmt = $pdo->prepare("SELECT b.*, u.nombre as autor FROM blogs b JOIN usuarios u ON b.id_usuario = u.id_usuario WHERE b.id_blog = ? AND b.estado = 'publicado'");
    $stmt->execute([$id]);
    $blog = $stmt->fetch();

    if (!$blog) {
        header('Location: blog.php');
        exit;
    }
} catch (PDOException $e) {
    error_log("Error al cargar detalle del blog: " . $e->getMessage());
    header('Location: blog.php');
    exit;
}

$pageTitle = $blog['titulo'];
include __DIR__ . '/includes/header.php';
?>

<div class="container" style="margin-top: 50px; margin-bottom: 50px;">
    <div class="row">
        <div class="col s12 l10 offset-l1">
            <a href="blog.php" class="btn-flat waves-effect" style="margin-bottom: 20px;">
                <i class="material-icons left">arrow_back</i> Volver al Blog
            </a>
            
            <?php if (!empty($blog['imagen'])): 
                $mime = 'image/png';
                if (strpos($blog['imagen'], 'UklGR') === 0) $mime = 'image/webp';
                elseif (strpos($blog['imagen'], '/9j/') === 0) $mime = 'image/jpeg';
                elseif (strpos($blog['imagen'], 'iVBORw') === 0) $mime = 'image/png';
            ?>
                <div class="center-align" style="margin-bottom: 30px;">
                    <img src="data:<?php echo $mime; ?>;base64,<?php echo $blog['imagen']; ?>" class="responsive-img z-depth-2 border-radius-8" style="max-height: 500px; width: 100%; object-fit: cover;">
                </div>
            <?php endif; ?>

            <h2 class="blue-text text-darken-4"><?php echo esc($blog['titulo']); ?></h2>
            
            <div class="grey-text text-darken-1" style="display: flex; align-items: center; gap: 20px; margin-bottom: 30px;">
                <span><i class="material-icons tiny left">person</i> Autor: <?php echo esc($blog['autor']); ?></span>
                <span><i class="material-icons tiny left">calendar_today</i> Publicado: <?php echo date('d M, Y', strtotime($blog['fecha_creacion'])); ?></span>
            </div>

            <div class="divider" style="margin-bottom: 30px;"></div>

            <div class="blog-content" style="font-size: 1.15rem; line-height: 1.8; color: #444;">
                <?php echo nl2br(esc($blog['contenido'])); ?>
            </div>

            <div class="card-panel blue lighten-5" style="margin-top: 50px; border-radius: 8px;">
                <div class="row valign-wrapper" style="margin-bottom: 0;">
                    <div class="col s2 center-align">
                        <i class="material-icons large blue-text">info</i>
                    </div>
                    <div class="col s10">
                        <p>¿Te interesan nuestros productos? Explora nuestro catálogo completo y descubre cómo mejorar tu bienestar hoy mismo.</p>
                        <a href="<?php echo BASE_URL; ?>" class="btn blue darken-4 waves-effect waves-light">Ir al Catálogo</a>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<style>
    .border-radius-8 { border-radius: 8px; }
    .blog-content p { margin-bottom: 20px; }
</style>

<?php include __DIR__ . '/includes/footer.php'; ?>