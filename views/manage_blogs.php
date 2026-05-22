<?php
declare(strict_types=1);

require_once __DIR__ . '/../core/config.php';
require_once __DIR__ . '/../core/auth.php';

// Validar permisos
requireAuth();
requirePermission('gestionar_blogs', BASE_URL . 'views/dashboard.php');

$pageTitle = 'Gestionar Blogs';
$pdo = getPDO();
$usuario = $_SESSION['usuario'];
$error = '';
$success = '';

// Variables para edición
$editMode = false;
$editBlog = [
    'id_blog' => '',
    'titulo' => '',
    'slug' => '',
    'extracto' => '',
    'contenido' => '',
    'imagen' => '',
    'estado' => 'publicado'
];

// Procesar formulario
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['accion'])) {
    if (!validateCsrfToken($_POST['csrf_token'] ?? '')) {
        $error = 'Token CSRF inválido. Por favor recarga la página e inténtalo de nuevo.';
    } else {
        $accion = sanitize($_POST['accion']);
        
        if ($accion === 'agregar' || $accion === 'editar') {
            $titulo = sanitize($_POST['titulo'] ?? '');
            $slug = sanitize($_POST['slug'] ?? '');
            $extracto = sanitize($_POST['extracto'] ?? '');
            $contenido = trim($_POST['contenido'] ?? '');
            $imagen = $_POST['imagen_base64'] ?? null; // base64 string
            $estado = sanitize($_POST['estado'] ?? 'publicado');
            $id_usuario = (int)$usuario['id_usuario'];

            // Si está vacío el slug, generarlo del título
            if (empty($slug)) {
                $slug = createSlug($titulo);
            }

            if (empty($titulo) || empty($contenido)) {
                $error = 'El título y el contenido son obligatorios.';
            } else {
                try {
                    // Validar unicidad del slug (excluyendo el actual en edición)
                    $sqlCheck = "SELECT COUNT(*) FROM blogs WHERE slug = :slug";
                    if ($accion === 'editar') {
                        $sqlCheck .= " AND id_blog != :id_blog";
                    }
                    $stmtCheck = $pdo->prepare($sqlCheck);
                    $checkParams = [':slug' => $slug];
                    if ($accion === 'editar') {
                        $checkParams[':id_blog'] = (int)$_POST['id_blog'];
                    }
                    $stmtCheck->execute($checkParams);
                    if ($stmtCheck->fetchColumn() > 0) {
                        // Si ya existe, añadir sufijo aleatorio para evitar error de duplicado
                        $slug .= '-' . bin2hex(random_bytes(3));
                    }

                    if ($accion === 'agregar') {
                        $sql = "INSERT INTO blogs (titulo, slug, extracto, contenido, imagen, id_usuario, estado) 
                                VALUES (:titulo, :slug, :extracto, :contenido, :imagen, :id_usuario, :estado)";
                        $stmt = $pdo->prepare($sql);
                        $stmt->execute([
                            ':titulo' => $titulo,
                            ':slug' => $slug,
                            ':extracto' => $extracto,
                            ':contenido' => $contenido,
                            ':imagen' => $imagen,
                            ':id_usuario' => $id_usuario,
                            ':estado' => $estado
                        ]);
                        $newId = (int)$pdo->lastInsertId();
                        logAudit('BLOG_CREADO', 'blogs', $newId, "Blog creado con título: $titulo");
                        $success = 'Artículo del blog publicado correctamente.';
                    } else {
                        $id_blog = (int)$_POST['id_blog'];
                        
                        // Si no se subió una nueva imagen, mantener la existente
                        if (empty($imagen)) {
                            $sql = "UPDATE blogs SET titulo = :titulo, slug = :slug, extracto = :extracto, contenido = :contenido, estado = :estado 
                                    WHERE id_blog = :id_blog";
                            $stmt = $pdo->prepare($sql);
                            $stmt->execute([
                                ':titulo' => $titulo,
                                ':slug' => $slug,
                                ':extracto' => $extracto,
                                ':contenido' => $contenido,
                                ':estado' => $estado,
                                ':id_blog' => $id_blog
                            ]);
                        } else {
                            $sql = "UPDATE blogs SET titulo = :titulo, slug = :slug, extracto = :extracto, contenido = :contenido, imagen = :imagen, estado = :estado 
                                    WHERE id_blog = :id_blog";
                            $stmt = $pdo->prepare($sql);
                            $stmt->execute([
                                ':titulo' => $titulo,
                                ':slug' => $slug,
                                ':extracto' => $extracto,
                                ':contenido' => $contenido,
                                ':imagen' => $imagen,
                                ':estado' => $estado,
                                ':id_blog' => $id_blog
                            ]);
                        }
                        logAudit('BLOG_EDITADO', 'blogs', $id_blog, "Blog editado con título: $titulo");
                        $success = 'Artículo del blog actualizado correctamente.';
                    }
                } catch (PDOException $e) {
                    $error = 'Error en base de datos: ' . $e->getMessage();
                }
            }
        } elseif ($accion === 'eliminar') {
            try {
                $id_blog = (int)$_POST['id_blog'];
                $stmt = $pdo->prepare("DELETE FROM blogs WHERE id_blog = ?");
                $stmt->execute([$id_blog]);
                logAudit('BLOG_ELIMINADO', 'blogs', $id_blog, "Blog eliminado definitivamente");
                $success = 'Artículo del blog eliminado correctamente.';
            } catch (PDOException $e) {
                $error = 'Error al eliminar el artículo.';
            }
        }
    }
}

// Cargar para edición
if (isset($_GET['edit'])) {
    $editId = (int)$_GET['edit'];
    try {
        $stmt = $pdo->prepare("SELECT * FROM blogs WHERE id_blog = ?");
        $stmt->execute([$editId]);
        $blog = $stmt->fetch();
        if ($blog) {
            $editMode = true;
            $editBlog = $blog;
        } else {
            $error = 'El artículo solicitado no existe.';
        }
    } catch (PDOException $e) {
        $error = 'Error al cargar artículo para editar.';
    }
}

// Obtener listado de blogs
try {
    $sql = "SELECT b.*, u.nombre as autor 
            FROM blogs b 
            JOIN usuarios u ON b.id_usuario = u.id_usuario 
            ORDER BY b.fecha_creacion DESC";
    $blogs = $pdo->query($sql)->fetchAll();
} catch (PDOException $e) {
    $error = 'Error al obtener listado de blogs: ' . $e->getMessage();
    $blogs = [];
}

function sanitize(mixed $value): string {
    if (!is_string($value)) {
        return '';
    }
    return trim(htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'));
}

function createSlug(string $text): string {
    $text = preg_replace('~[^\pL\d]+~u', '-', $text);
    $text = iconv('utf-8', 'us-ascii//TRANSLIT', $text);
    $text = preg_replace('~[^-\w]+~', '', $text);
    $text = trim($text, '-');
    $text = preg_replace('~-+~', '-', $text);
    $text = strtolower($text);
    if (empty($text)) {
        return 'n-a';
    }
    return $text;
}

include __DIR__ . '/includes/header.php';
?>

<div class="container">
    <div class="row">
        <div class="col s12">
            <h4 class="header-title">
                <i class="material-icons medium left blue-text text-darken-4">book</i>
                Gestión de Blogs Informativos
            </h4>
            <p class="grey-text">Crea y administra artículos de interés general. Para ver el contenido completo de estos artículos, los visitantes del catálogo deberán iniciar sesión obligatoriamente.</p>
            
            <?php if ($error): ?>
                <div class="card red lighten-4">
                    <div class="card-content red-text">
                        <span class="card-title font-weight-bold" style="font-size: 1.2rem;"><i class="material-icons left">error</i>Error</span>
                        <p><?php echo esc($error); ?></p>
                    </div>
                </div>
            <?php endif; ?>
            
            <?php if ($success): ?>
                <div class="card green lighten-4">
                    <div class="card-content green-text text-darken-3">
                        <p><i class="material-icons left">check_circle</i><?php echo esc($success); ?></p>
                    </div>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <div class="row">
        <!-- Formulario Agregar/Editar -->
        <div class="col s12 l5">
            <div class="card hoverable-card">
                <div class="card-content">
                    <span class="card-title font-weight-bold blue-text text-darken-4">
                        <i class="material-icons left"><?php echo $editMode ? 'edit' : 'add_circle'; ?></i>
                        <?php echo $editMode ? 'Editar Artículo' : 'Crear Nuevo Artículo'; ?>
                    </span>
                    
                    <form method="POST" id="blog-form">
                        <?php echo csrfInput(); ?>
                        <input type="hidden" name="accion" value="<?php echo $editMode ? 'editar' : 'agregar'; ?>">
                        <?php if ($editMode): ?>
                            <input type="hidden" name="id_blog" value="<?php echo esc((string)$editBlog['id_blog']); ?>">
                        <?php endif; ?>
                        
                        <div class="input-field">
                            <input type="text" id="titulo" name="titulo" value="<?php echo esc($editBlog['titulo']); ?>" required class="validate">
                            <label for="titulo">Título del Artículo</label>
                        </div>
                        
                        <div class="input-field">
                            <input type="text" id="slug" name="slug" value="<?php echo esc($editBlog['slug']); ?>" placeholder="auto-generado-si-se-deja-vacio" class="validate">
                            <label for="slug">URL Amigable (Slug)</label>
                        </div>
                        
                        <div class="input-field">
                            <textarea id="extracto" name="extracto" class="materialize-textarea" data-length="500"><?php echo esc($editBlog['extracto']); ?></textarea>
                            <label for="extracto">Extracto o Resumen (Público)</label>
                            <span class="helper-text">Se mostrará en la tarjeta del catálogo para motivar la lectura.</span>
                        </div>
                        
                        <div class="input-field">
                            <textarea id="contenido" name="contenido" class="materialize-textarea" style="min-height: 150px;" required><?php echo esc($editBlog['contenido']); ?></textarea>
                            <label for="contenido">Contenido Completo (Protegido por Login)</label>
                        </div>

                        <div class="input-field" style="margin-top: 25px;">
                            <select id="estado" name="estado" class="browser-default" style="border: 1px solid #9e9e9e; border-radius: 4px; padding: 10px;">
                                <option value="publicado" <?php echo $editBlog['estado'] === 'publicado' ? 'selected' : ''; ?>>Publicado (Visible)</option>
                                <option value="borrador" <?php echo $editBlog['estado'] === 'borrador' ? 'selected' : ''; ?>>Borrador (Oculto)</option>
                            </select>
                            <label for="estado" class="active" style="position: absolute; top: -20px; font-size: 0.8rem;">Estado del Artículo</label>
                        </div>

                        <!-- Subida de Imagen Portada -->
                        <div class="file-field input-field" style="margin-top: 25px;">
                            <div class="btn blue darken-3 btn-small">
                                <span>Subir Imagen</span>
                                <input type="file" id="file-uploader" accept="image/*">
                            </div>
                            <div class="file-path-wrapper">
                                <input class="file-path validate" type="text" placeholder="Selecciona una imagen de portada">
                            </div>
                            <input type="hidden" name="imagen_base64" id="imagen_base64" value="<?php echo esc($editBlog['imagen'] ?? ''); ?>">
                        </div>

                        <!-- Preview de Imagen -->
                        <div id="preview-container" class="center-align" style="margin-top: 15px; display: <?php echo !empty($editBlog['imagen']) ? 'block' : 'none'; ?>;">
                            <p class="grey-text" style="font-size: 0.9rem;">Vista previa de portada:</p>
                            <?php
                                $imgSrc = '';
                                if (!empty($editBlog['imagen'])) {
                                    $mime = 'image/png';
                                    if (strpos($editBlog['imagen'], 'UklGR') === 0) $mime = 'image/webp';
                                    elseif (strpos($editBlog['imagen'], '/9j/') === 0) $mime = 'image/jpeg';
                                    elseif (strpos($editBlog['imagen'], 'iVBORw') === 0) $mime = 'image/png';
                                    elseif (strpos($editBlog['imagen'], 'R0lGOD') === 0) $mime = 'image/gif';
                                    $imgSrc = 'data:' . $mime . ';base64,' . $editBlog['imagen'];
                                }
                            ?>
                            <img id="image-preview" src="<?php echo $imgSrc; ?>" alt="Portada Preview" class="responsive-img z-depth-1 border-radius-4" style="max-height: 180px; object-fit: cover;">
                        </div>

                        <div class="center-align" style="margin-top: 30px;">
                            <?php if ($editMode): ?>
                                <a href="manage_blogs.php" class="btn grey waves-effect waves-light" style="margin-right: 10px;">Cancelar</a>
                            <?php endif; ?>
                            <button type="submit" class="btn waves-effect waves-light blue darken-4">
                                <?php echo $editMode ? 'Guardar Cambios' : 'Publicar Artículo'; ?>
                                <i class="material-icons right">send</i>
                            </button>
                        </div>
                    </form>
                </div>
            </div>
        </div>

        <!-- Listado de Blogs -->
        <div class="col s12 l7">
            <div class="card hoverable-card">
                <div class="card-content">
                    <span class="card-title font-weight-bold blue-text text-darken-4">
                        <i class="material-icons left">list</i>
                        Artículos Registrados
                    </span>
                    
                    <?php if (empty($blogs)): ?>
                        <p class="center-align grey-text" style="padding: 30px 0;">Aún no se han creado artículos de blog.</p>
                    <?php else: ?>
                        <div style="overflow-x: auto;">
                            <table class="striped responsive-table">
                                <thead>
                                    <tr>
                                        <th>Portada</th>
                                        <th>Título / Autor</th>
                                        <th>Estado</th>
                                        <th>Creado</th>
                                        <th class="center-align">Acciones</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($blogs as $b): ?>
                                        <tr>
                                            <td>
                                                <?php if (!empty($b['imagen'])): 
                                                    $mime = 'image/png';
                                                    if (strpos($b['imagen'], 'UklGR') === 0) $mime = 'image/webp';
                                                    elseif (strpos($b['imagen'], '/9j/') === 0) $mime = 'image/jpeg';
                                                    elseif (strpos($b['imagen'], 'iVBORw') === 0) $mime = 'image/png';
                                                    elseif (strpos($b['imagen'], 'R0lGOD') === 0) $mime = 'image/gif';
                                                ?>
                                                    <img src="data:<?php echo $mime; ?>;base64,<?php echo $b['imagen']; ?>" class="border-radius-4 z-depth-1" style="width: 60px; height: 40px; object-fit: cover; background: #e0e0e0;">
                                                <?php else: ?>
                                                    <div class="grey lighten-2 center-align border-radius-4" style="width: 60px; height: 40px; line-height: 40px; font-size: 0.8rem; color: #757575;">Sin foto</div>
                                                <?php endif; ?>
                                            </td>
                                            <td>
                                                <span class="font-weight-bold" style="font-size: 1rem; display: block;"><?php echo esc($b['titulo']); ?></span>
                                                <span class="grey-text" style="font-size: 0.85rem;"><i class="material-icons tiny left">person</i><?php echo esc($b['autor']); ?></span>
                                            </td>
                                            <td>
                                                <?php if ($b['estado'] === 'publicado'): ?>
                                                    <span class="badge green white-text" style="float: none; font-size: 0.8rem; border-radius: 4px;">Publicado</span>
                                                <?php else: ?>
                                                    <span class="badge orange white-text" style="float: none; font-size: 0.8rem; border-radius: 4px;">Borrador</span>
                                                <?php endif; ?>
                                            </td>
                                            <td>
                                                <span class="grey-text" style="font-size: 0.85rem;"><?php echo date('d/m/Y H:i', strtotime($b['fecha_creacion'])); ?></span>
                                            </td>
                                            <td class="center-align" style="white-space: nowrap;">
                                                <!-- Editar -->
                                                <a href="manage_blogs.php?edit=<?php echo $b['id_blog']; ?>" class="btn-floating btn-small blue waves-effect waves-light" title="Editar">
                                                    <i class="material-icons">edit</i>
                                                </a>
                                                <!-- Eliminar -->
                                                <form method="POST" style="display:inline; margin-left: 5px;">
                                                    <?php echo csrfInput(); ?>
                                                    <input type="hidden" name="accion" value="eliminar">
                                                    <input type="hidden" name="id_blog" value="<?php echo $b['id_blog']; ?>">
                                                    <button type="submit" class="btn-floating btn-small red waves-effect waves-light" onclick="return confirm('¿Estás seguro de que deseas eliminar permanentemente este artículo del blog?')" title="Eliminar">
                                                        <i class="material-icons">delete</i>
                                                    </button>
                                                </form>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
</div>

<style>
    .font-weight-bold { font-weight: bold; }
    .border-radius-4 { border-radius: 4px; }
    .hoverable-card {
        border-radius: 8px;
        transition: box-shadow 0.3s ease;
    }
    .hoverable-card:hover {
        box-shadow: 0 8px 24px rgba(0,0,0,0.15);
    }
</style>

<script>
    document.addEventListener('DOMContentLoaded', function() {
        const tituloInput = document.getElementById('titulo');
        const slugInput = document.getElementById('slug');
        const fileUploader = document.getElementById('file-uploader');
        const imagenBase64Input = document.getElementById('imagen_base64');
        const imagePreview = document.getElementById('image-preview');
        const previewContainer = document.getElementById('preview-container');

        // Inicializar contador de caracteres de Materialize
        M.CharacterCounter.init(document.querySelectorAll('textarea[data-length]'));

        // Generación automática del slug a partir del título
        tituloInput.addEventListener('input', function() {
            if (slugInput.value === '' || slugInput.dataset.autoGenerated === 'true') {
                slugInput.value = generateSlug(this.value);
                slugInput.dataset.autoGenerated = 'true';
                M.updateTextFields(); // Refrescar etiquetas Materialize
            }
        });

        // Detectar si el usuario edita el slug manualmente
        slugInput.addEventListener('input', function() {
            this.dataset.autoGenerated = 'false';
        });

        function generateSlug(text) {
            return text
                .toString()
                .toLowerCase()
                .normalize('NFD')
                .replace(/[\u0300-\u036f]/g, '') // Quitar acentos
                .replace(/\s+/g, '-')           // Reemplazar espacios por guiones
                .replace(/[^\w\-]+/g, '')       // Quitar caracteres no válidos
                .replace(/\-\-+/g, '-')         // Colapsar guiones múltiples
                .replace(/^-+/, '')              // Quitar guiones al principio
                .replace(/-+$/, '');             // Quitar guiones al final
        }

        // Subida y codificación de imagen en base64
        fileUploader.addEventListener('change', function(e) {
            const file = e.target.files[0];
            if (file) {
                const reader = new FileReader();
                reader.onload = function(e) {
                    const fullBase64 = e.target.result;
                    // Extraer solo la parte base64 (removiendo el prefijo "data:image/jpeg;base64,")
                    const rawBase64 = fullBase64.split(',')[1];
                    
                    imagenBase64Input.value = rawBase64;
                    imagePreview.src = fullBase64;
                    previewContainer.style.display = 'block';
                };
                reader.readAsDataURL(file);
            }
        });
    });
</script>

<?php include __DIR__ . '/includes/footer.php'; ?>
