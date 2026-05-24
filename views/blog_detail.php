<?php
declare(strict_types=1);
require_once __DIR__ . '/../core/config.php';
require_once __DIR__ . '/../core/auth.php';

$slug = $_GET['s'] ?? '';
$pdo = getPDO();

$stmt = $pdo->prepare("SELECT * FROM blogs WHERE slug = ? AND estado = 'publicado'");
$stmt->execute([$slug]);
$post = $stmt->fetch();

if (!$post) {
    header('Location: blog.php');
    exit;
}

$pageTitle = $post['titulo'];
include __DIR__ . '/includes/header.php';
?>

<div class="container" style="margin-top: 40px; margin-bottom: 60px;">
    <div class="row">
        <div class="col s12 m10 offset-m1">
            <a href="blog.php" class="btn-flat waves-effect"><i class="material-icons left">arrow_back</i> Volver al Blog</a>
            
            <div class="blog-header" style="margin-top: 20px; border-bottom: 1px solid #eee; padding-bottom: 20px; margin-bottom: 30px;">
                <h1 style="font-weight: bold; margin-bottom: 10px;"><?php echo esc($post['titulo']); ?></h1>
                <p class="grey-text"><i class="material-icons tiny">calendar_today</i> Publicado el <?php echo date('d/m/Y', strtotime($post['fecha_creacion'])); ?></p>
            </div>

            <!-- CONTENIDO HTML REFLEJADO -->
            <div class="blog-body-content" style="font-size: 1.1rem; line-height: 1.8; color: #333;">
                <?php echo $post['contenido']; // AQUÍ SE RENDERIZA EL HTML DIRECTAMENTE ?>
            </div>

            <div class="divider" style="margin: 50px 0;"></div>
            <div class="center-align">
                <h5>¿Te gustó este artículo?</h5>
                <p>Compártelo con tus amigos y ayúdanos a crecer.</p>
            </div>
        </div>
    </div>
</div>

<style>
    .blog-body-content img { max-width: 100%; height: auto; border-radius: 8px; margin: 20px 0; }
    .blog-body-content iframe { width: 100%; aspect-ratio: 16/9; border-radius: 8px; border: none; margin: 20px 0; }
    .blog-body-content h2, .blog-body-content h3 { font-weight: bold; margin-top: 40px; color: #1a237e; }
</style>

<?php include __DIR__ . '/includes/footer.php'; ?>