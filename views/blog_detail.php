<?php
declare(strict_types=1);

require_once __DIR__ . '/../core/config.php';
require_once __DIR__ . '/../core/auth.php';

$id_blog = isset($_GET['id']) ? (int)$_GET['id'] : 0;

// Validar autenticación obligatoria para lectura de blogs
if (!isAuthenticated()) {
    header('Location: ' . BASE_URL . 'views/login.php?redirect=views/blog_detail.php?id=' . $id_blog);
    exit;
}

$pageTitle = 'Artículo de Interés';
$pdo = getPDO();
$error = '';
$blog = null;

try {
    if ($id_blog <= 0) {
        $error = 'Artículo no válido.';
    } else {
        // Cargar el artículo y su autor
        $stmt = $pdo->prepare("SELECT b.*, u.nombre as autor, u.email as autor_email 
                               FROM blogs b 
                               JOIN usuarios u ON b.id_usuario = u.id_usuario 
                               WHERE b.id_blog = ? AND b.estado = 'publicado'");
        $stmt->execute([$id_blog]);
        $blog = $stmt->fetch();
        
        if (!$blog) {
            $error = 'El artículo solicitado no existe o aún no ha sido publicado.';
        } else {
            $pageTitle = $blog['titulo'];
        }
    }
} catch (PDOException $e) {
    $error = 'Error al cargar el contenido del artículo.';
}

include __DIR__ . '/includes/header.php';
?>

<div class="container" style="margin-top: 30px; margin-bottom: 50px;">
    <!-- Botón Volver -->
    <div class="row" style="margin-bottom: 10px;">
        <div class="col s12">
            <a href="<?php echo BASE_URL; ?>" class="btn-flat blue-text text-darken-4 font-weight-bold waves-effect" style="display: inline-flex; align-items: center; padding-left: 0;">
                <i class="material-icons left">arrow_back</i> Volver al Catálogo
            </a>
        </div>
    </div>

    <?php if ($error || !$blog): ?>
        <div class="row">
            <div class="col s12">
                <div class="card red lighten-4">
                    <div class="card-content red-text center-align">
                        <i class="material-icons large">error_outline</i>
                        <h4>Ups, algo salió mal</h4>
                        <p style="font-size: 1.1rem;"><?php echo esc($error ?: 'No pudimos encontrar el artículo solicitado.'); ?></p>
                        <a href="<?php echo BASE_URL; ?>" class="btn blue darken-4" style="margin-top: 20px;">Explorar Productos</a>
                    </div>
                </div>
            </div>
        </div>
    <?php else: ?>
        <!-- Vista del Artículo Premium -->
        <div class="row">
            <div class="col s12 m10 offset-m1 l8 offset-l2">
                <div class="card blog-detail-card z-depth-2">
                    <!-- Portada del Blog -->
                    <?php if (!empty($blog['imagen'])): 
                        $mime = 'image/png';
                        if (strpos($blog['imagen'], 'UklGR') === 0) $mime = 'image/webp';
                        elseif (strpos($blog['imagen'], '/9j/') === 0) $mime = 'image/jpeg';
                        elseif (strpos($blog['imagen'], 'iVBORw') === 0) $mime = 'image/png';
                        elseif (strpos($blog['imagen'], 'R0lGOD') === 0) $mime = 'image/gif';
                        $imgSrc = 'data:' . $mime . ';base64,' . $blog['imagen'];
                    ?>
                        <div class="card-image blog-banner">
                            <img src="<?php echo $imgSrc; ?>" alt="<?php echo esc($blog['titulo']); ?>">
                        </div>
                    <?php endif; ?>

                    <div class="card-content">
                        <!-- Título del Artículo -->
                        <h1 class="blog-title blue-text text-darken-4"><?php echo esc($blog['titulo']); ?></h1>
                        
                        <!-- Metadatos del Post -->
                        <div class="blog-meta grey-text text-darken-1">
                            <div class="meta-item">
                                <i class="material-icons tiny">person</i>
                                <span>Por <strong><?php echo esc($blog['autor']); ?></strong></span>
                            </div>
                            <div class="meta-item">
                                <i class="material-icons tiny">calendar_today</i>
                                <span>Publicado el: <?php echo date('d/m/Y', strtotime($blog['fecha_creacion'])); ?></span>
                            </div>
                            <div class="meta-item">
                                <span class="badge blue darken-4 white-text" style="float: none; font-size: 0.75rem; border-radius: 4px; padding: 3px 6px;">Exclusivo Socios</span>
                            </div>
                        </div>

                        <!-- Extracto (Destacado) -->
                        <?php if (!empty($blog['extracto'])): ?>
                            <blockquote class="blog-excerpt blue-theme-quote">
                                <?php echo esc($blog['extracto']); ?>
                            </blockquote>
                        <?php endif; ?>

                        <!-- Cuerpo del Artículo -->
                        <div class="blog-body">
                            <?php echo nl2br(esc($blog['contenido'])); ?>
                        </div>
                    </div>

                    <!-- Footer del Artículo -->
                    <div class="card-action grey lighten-4 center-align" style="border-top: 1px solid #e0e0e0; padding: 25px;">
                        <p class="grey-text text-darken-2 font-weight-bold" style="margin-top: 0;">¿Te gustó este artículo? ¡Compártelo con tus amigos!</p>
                        <a href="https://api.whatsapp.com/send?text=<?php echo urlencode('¡Mira este artículo informativo! ' . $blog['titulo'] . ' - ' . (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? "https" : "http") . "://$_SERVER[HTTP_HOST]$_SERVER[REQUEST_URI]"); ?>" 
                           target="_blank" 
                           class="btn-large green accent-4 waves-effect waves-light font-weight-bold" 
                           style="display: inline-flex; align-items: center; justify-content: center; gap: 8px; border-radius: 30px;">
                            <i class="material-icons">share</i> Compartir en WhatsApp
                        </a>
                    </div>
                </div>
            </div>
        </div>
    <?php endif; ?>
</div>

<style>
    .font-weight-bold {
        font-weight: bold;
    }
    .blog-detail-card {
        border-radius: 12px;
        overflow: hidden;
    }
    .blog-banner {
        max-height: 400px;
        overflow: hidden;
        background-color: #eceff1;
    }
    .blog-banner img {
        width: 100%;
        height: 100%;
        max-height: 400px;
        object-fit: cover;
    }
    .blog-title {
        font-size: 2.5rem;
        font-weight: 800;
        line-height: 1.2;
        margin-top: 15px;
        margin-bottom: 20px;
        font-family: 'Outfit', 'Inter', sans-serif;
    }
    .blog-meta {
        display: flex;
        flex-wrap: wrap;
        gap: 20px;
        font-size: 0.95rem;
        margin-bottom: 30px;
        padding-bottom: 15px;
        border-bottom: 1px solid #eceff1;
        align-items: center;
    }
    .meta-item {
        display: flex;
        align-items: center;
        gap: 6px;
    }
    .blog-excerpt {
        font-size: 1.15rem;
        font-style: italic;
        color: #455a64;
        margin: 25px 0;
        padding-left: 20px;
        border-left: 5px solid #0d47a1;
        background-color: #f1f8ff;
        padding-top: 15px;
        padding-bottom: 15px;
        padding-right: 15px;
        border-radius: 0 8px 8px 0;
    }
    .blog-body {
        font-size: 1.15rem;
        line-height: 1.8;
        color: #37474f;
        text-align: justify;
        letter-spacing: 0.01rem;
    }
    
    @media (max-width: 600px) {
        .blog-title {
            font-size: 1.8rem;
        }
        .blog-meta {
            flex-direction: column;
            align-items: flex-start;
            gap: 10px;
        }
    }
</style>

<?php include __DIR__ . '/includes/footer.php'; ?>
