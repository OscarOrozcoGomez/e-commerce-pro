<?php
declare(strict_types=1);

require_once __DIR__ . '/../core/config.php';
require_once __DIR__ . '/../core/auth.php';

$pageTitle = 'Blog de Bienestar';
$pdo = getPDO();

try {
    // Obtenemos los blogs publicados junto con el nombre del autor
    $stmt = $pdo->prepare("SELECT b.*, u.nombre as autor FROM blogs b JOIN usuarios u ON b.id_usuario = u.id_usuario WHERE b.estado = 'publicado' ORDER BY b.fecha_creacion DESC");
    $stmt->execute();
    $blogs = $stmt->fetchAll();
} catch (PDOException $e) {
    error_log("Error al cargar blogs: " . $e->getMessage());
    $blogs = [];
}

include __DIR__ . '/includes/header.php';
?>

<div class="container" style="margin-top: 50px;">
    <div class="row">
        <div class="col s12">
            <h3 class="blue-text text-darken-4 center-align" style="font-weight: 300;">Nuestro Blog de Bienestar</h3>
            <p class="center-align grey-text text-darken-1" style="font-size: 1.2rem; margin-bottom: 50px;">
                Encuentra artículos de interés, consejos y noticias sobre salud y belleza.
            </p>
        </div>
    </div>

    <div class="row">
        <?php if (empty($blogs)): ?>
            <div class="col s12 center-align" style="padding: 100px 0;">
                <i class="material-icons large grey-text lighten-2">article</i>
                <h5 class="grey-text">Aún no hay artículos publicados.</h5>
                <p class="grey-text">Vuelve pronto para descubrir contenido nuevo.</p>
                <a href="<?php echo BASE_URL; ?>" class="btn blue darken-4" style="margin-top: 20px;">Volver al Catálogo</a>
            </div>
        <?php else: ?>
            <?php foreach ($blogs as $b): ?>
                <div class="col s12 m6 l4">
                    <div class="card hoverable border-radius-8" style="height: 480px; display: flex; flex-direction: column;">
                        <div class="card-image" style="height: 200px; overflow: hidden; background: #e0e0e0;">
                            <?php if (!empty($b['imagen'])): 
                                $mime = 'image/png';
                                if (strpos($b['imagen'], 'UklGR') === 0) $mime = 'image/webp';
                                elseif (strpos($b['imagen'], '/9j/') === 0) $mime = 'image/jpeg';
                                elseif (strpos($b['imagen'], 'iVBORw') === 0) $mime = 'image/png';
                            ?>
                                <img src="data:<?php echo $mime; ?>;base64,<?php echo $b['imagen']; ?>" style="height: 100%; width: 100%; object-fit: cover;">
                            <?php else: ?>
                                <div class="center-align" style="line-height: 200px;">
                                    <i class="material-icons grey-text" style="font-size: 5rem;">image</i>
                                </div>
                            <?php endif; ?>
                        </div>
                        <div class="card-content" style="flex-grow: 1;">
                            <span class="card-title grey-text text-darken-4 truncate" style="font-weight: bold; font-size: 1.2rem;">
                                <?php echo esc($b['titulo']); ?>
                            </span>
                            <p class="grey-text" style="font-size: 0.85rem; margin-bottom: 10px;">
                                <i class="material-icons tiny left">person</i> <?php echo esc($b['autor']); ?> | 
                                <i class="material-icons tiny">calendar_today</i> <?php echo date('d M, Y', strtotime($b['fecha_creacion'])); ?>
                            </p>
                            <p class="grey-text text-darken-2 truncate-4-lines">
                                <?php echo esc($b['extracto'] ?? 'No hay un resumen disponible para este artículo.'); ?>
                            </p>
                        </div>
                        <div class="card-action">
                            <a href="blog_detail.php?id=<?php echo $b['id_blog']; ?>" class="blue-text text-darken-4 font-weight-bold">LEER MÁS</a>
                        </div>
                    </div>
                </div>
            <?php endforeach; ?>
        <?php endif; ?>
    </div>
</div>

<style>
    .border-radius-8 { border-radius: 8px; overflow: hidden; }
    .truncate-4-lines {
        display: -webkit-box;
        -webkit-line-clamp: 4;
        -webkit-box-orient: vertical;  
        overflow: hidden;
    }
</style>

<?php include __DIR__ . '/includes/footer.php'; ?>