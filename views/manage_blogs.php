<?php
declare(strict_types=1);
require_once __DIR__ . '/../core/config.php';
require_once __DIR__ . '/../core/auth.php';

requireAuth();
requirePermission('gestionar_blogs', BASE_URL . 'views/dashboard.php');

$pageTitle = 'Gestionar Blogs';
$pdo = getPDO();
$success = ''; $error = '';

// Procesar Formulario
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['accion'])) {
    if (!validateCsrfToken($_POST['csrf_token'] ?? '')) {
        $error = 'Token de seguridad inválido.';
    } else {
        $id = (int)($_POST['id_blog'] ?? 0);
        $titulo = trim($_POST['titulo'] ?? '');
        $slug = strtolower(trim(preg_replace('/[^A-Za-z0-9-]+/', '-', $titulo)));
        $extracto = trim($_POST['extracto'] ?? '');
        $contenido = $_POST['contenido'] ?? ''; // RECIBIMOS HTML PURO
        $estado = $_POST['estado'] ?? 'publicado';

        try {
            if ($_POST['accion'] === 'guardar') {
                if ($id > 0) {
                    $stmt = $pdo->prepare("UPDATE blogs SET titulo = ?, slug = ?, extracto = ?, contenido = ?, estado = ? WHERE id_blog = ?");
                    $stmt->execute([$titulo, $slug, $extracto, $contenido, $estado, $id]);
                    $success = 'Artículo actualizado.';
                } else {
                    $stmt = $pdo->prepare("INSERT INTO blogs (titulo, slug, extracto, contenido, estado) VALUES (?, ?, ?, ?, ?)");
                    $stmt->execute([$titulo, $slug, $extracto, $contenido, $estado]);
                    $success = 'Artículo creado.';
                }
            } elseif ($_POST['accion'] === 'eliminar') {
                $pdo->prepare("DELETE FROM blogs WHERE id_blog = ?")->execute([$id]);
                $success = 'Artículo eliminado.';
            }
        } catch (Exception $e) { $error = 'Error: ' . $e->getMessage(); }
    }
}

$articulos = $pdo->query("SELECT * FROM blogs ORDER BY fecha_creacion DESC")->fetchAll();
include __DIR__ . '/includes/header.php';
?>

<!-- Script de TinyMCE para edición HTML -->
<script src="https://cdn.tiny.cloud/1/no-api-key/tinymce/6/tinymce.min.js" referrerpolicy="origin"></script>

<div class="container">
    <div class="row">
        <div class="col s12">
            <div style="display: flex; align-items: center; justify-content: space-between; margin-top: 20px;">
                <h4><i class="material-icons left">book</i> Gestión de Contenido</h4>
                <a href="dashboard.php" class="btn blue darken-4"><i class="material-icons left">dashboard</i> Dashboard</a>
            </div>
        </div>
    </div>

    <?php if ($success): ?><div class="card-panel green lighten-4 green-text"><?php echo esc($success); ?></div><?php endif; ?>
    <?php if ($error): ?><div class="card-panel red lighten-4 red-text"><?php echo esc($error); ?></div><?php endif; ?>

    <div class="row">
        <div class="col s12 l5">
            <div class="card">
                <div class="card-content">
                    <span class="card-title" id="form-title">Escribir Artículo</span>
                    <form method="POST" id="form-blog">
                        <?php echo csrfInput(); ?>
                        <input type="hidden" name="accion" value="guardar">
                        <input type="hidden" name="id_blog" id="id_blog" value="">
                        
                        <div class="input-field">
                            <input type="text" name="titulo" id="titulo" required>
                            <label for="titulo">Título del Post</label>
                        </div>
                        <div class="input-field">
                            <textarea name="extracto" id="extracto" class="materialize-textarea" placeholder="Breve resumen que aparece en la lista..."></textarea>
                            <label class="active">Extracto / Resumen</label>
                        </div>
                        <div class="row">
                            <div class="col s12">
                                <label>Contenido (Soporta HTML)</label>
                                <textarea name="contenido" id="editor-html"></textarea>
                            </div>
                        </div>
                        <div class="input-field">
                            <select name="estado" id="estado" class="browser-default" style="border: 1px solid #ccc;">
                                <option value="publicado">Publicado</option>
                                <option value="borrador">Borrador</option>
                            </select>
                        </div>
                        <button type="submit" class="btn-large indigo darken-4 w-100" style="margin-top: 20px;">GUARDAR POST</button>
                        <button type="button" class="btn-flat w-100" onclick="resetForm()">Cancelar</button>
                    </form>
                </div>
            </div>
        </div>

        <div class="col s12 l7">
            <div class="card">
                <div class="card-content">
                    <span class="card-title">Artículos Recientes</span>
                    <table class="striped">
                        <thead>
                            <tr>
                                <th>Título</th>
                                <th>Estado</th>
                                <th>Acciones</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($articulos as $art): ?>
                                <tr>
                                    <td><strong><?php echo esc($art['titulo']); ?></strong><br><small class="grey-text"><?php echo $art['fecha_creacion']; ?></small></td>
                                    <td><span class="badge <?php echo $art['estado'] === 'publicado' ? 'green' : 'grey'; ?> white-text" style="float:none;"><?php echo $art['estado']; ?></span></td>
                                    <td>
                                        <button class="btn-floating btn-small blue" onclick='cargarArticulo(<?php echo json_encode($art); ?>)'><i class="material-icons">edit</i></button>
                                        <form method="POST" style="display:inline;">
                                            <?php echo csrfInput(); ?>
                                            <input type="hidden" name="accion" value="eliminar">
                                            <input type="hidden" name="id_blog" value="<?php echo $art['id_blog']; ?>">
                                            <button type="submit" class="btn-floating btn-small red" onclick="return confirm('¿Eliminar post?')"><i class="material-icons">delete</i></button>
                                        </form>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
    tinymce.init({
        selector: '#editor-html',
        plugins: 'link image lists code table',
        toolbar: 'undo redo | bold italic | alignleft aligncenter alignright | bullist numlist | code',
        height: 400,
        menubar: false
    });

    function cargarArticulo(art) {
        document.getElementById('id_blog').value = art.id_blog;
        document.getElementById('titulo').value = art.titulo;
        document.getElementById('extracto').value = art.extracto;
        document.getElementById('estado').value = art.estado;
        tinymce.get('editor-html').setContent(art.contenido);
        document.getElementById('form-title').textContent = 'Editando Artículo';
        M.updateTextFields();
        window.scrollTo({ top: 0, behavior: 'smooth' });
    }

    function resetForm() {
        document.getElementById('form-blog').reset();
        document.getElementById('id_blog').value = '';
        tinymce.get('editor-html').setContent('');
        document.getElementById('form-title').textContent = 'Escribir Artículo';
    }
</script>
<style>.w-100 { width: 100%; }</style>
<?php include __DIR__ . '/includes/footer.php'; ?>