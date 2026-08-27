<?php
declare(strict_types=1);

require_once __DIR__ . '/../core/config.php';
require_once __DIR__ . '/../core/auth.php';
require_once __DIR__ . '/../core/ventas_features.php';

requireAuth();
if (!isAdmin()) {
    header('Location: ' . BASE_URL . 'views/dashboard.php');
    exit;
}

$pageTitle = 'Nuevas Iniciativas de Ventas';
$pdo = getPDO();
$error = '';
$success = '';

$featureLabels = [
    'atribucion_ventas' => [
        'nombre' => 'Atribución de Ventas',
        'descripcion' => 'Guarda de qué plataforma/campaña vino cada pedido web, para saber qué campaña realmente vende (no solo cuál trae tráfico).',
        'requiere_cuenta' => 'No.',
        'tiene_config' => false,
    ],
    'catalogo_feed' => [
        'nombre' => 'Catálogo Dinámico (Feed de Productos)',
        'descripcion' => 'Expone un feed público de productos (formato Google Shopping / Meta Catalog) para anuncios de remarketing dinámico. Al activarse, la URL del feed queda accesible sin login.',
        'requiere_cuenta' => 'Sí, para usarlo en anuncios: cuenta en Google Merchant Center y/o Meta Commerce Manager.',
        'tiene_config' => false,
    ],
    'programa_referidos' => [
        'nombre' => 'Programa de Referidos',
        'descripcion' => 'Cada cliente tiene un código para compartir; cuando alguien nuevo lo usa en el checkout, aplica un descuento.',
        'requiere_cuenta' => 'No.',
        'tiene_config' => true,
    ],
    'stock_calendario_campanas' => [
        'nombre' => 'Stock vs. Calendario de Campañas',
        'descripcion' => 'Cruza la predicción de inventario con las fechas de campañas que registres, para avisar si vas a anunciar un producto que se agota antes de que termine la campaña.',
        'requiere_cuenta' => 'No.',
        'tiene_config' => false,
    ],
    'comportamiento_sitio' => [
        'nombre' => 'Comportamiento en el Sitio',
        'descripcion' => 'Qué productos y páginas ven más tus visitantes, cuánto tiempo se quedan, y qué tanto le dan clic a "Agregar al Carrito" — lo que Google/Facebook no reportan porque pasa dentro de tu sitio.',
        'requiere_cuenta' => 'No.',
        'tiene_config' => false,
    ],
];

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['accion'] ?? '') === 'guardar_config') {
    if (!validateCsrfToken($_POST['csrf_token'] ?? '')) {
        $error = 'Token CSRF inválido.';
    } else {
        try {
            foreach (VENTAS_FEATURE_KEYS as $key) {
                $activo = !empty($_POST['activo_' . $key]);
                $config = [];
                if ($key === 'programa_referidos') {
                    $descuento = (float) ($_POST['referidos_descuento_porcentaje'] ?? 10);
                    $montoMinimo = (float) ($_POST['referidos_monto_minimo'] ?? 0);
                    $config = [
                        'descuento_porcentaje' => max(0.0, min(100.0, $descuento)),
                        'monto_minimo_pedido' => max(0.0, $montoMinimo),
                    ];
                }
                ventasFeatureSave($pdo, $key, $activo, $config);
            }
            $success = 'Configuración guardada.';
        } catch (Throwable $e) {
            $error = 'No se pudo guardar: ' . $e->getMessage();
        }
    }
}

$features = ventasFeaturesGetAll($pdo);

include __DIR__ . '/includes/header.php';
?>

<div class="container-fluid" style="padding: 20px; max-width: 900px;">
    <div style="display: flex; align-items: center; justify-content: space-between; margin-bottom: 10px; flex-wrap: wrap; gap: 10px;">
        <h4 style="margin: 0;"><i class="material-icons left" style="font-size: 2.5rem;">toggle_on</i> Nuevas Iniciativas de Ventas</h4>
        <a href="dashboard.php" class="btn blue darken-4 waves-effect waves-light"><i class="material-icons left">dashboard</i> Volver al Dashboard</a>
    </div>
    <p class="grey-text">Enciende o apaga cada iniciativa. Todo queda apagado hasta que lo actives explícitamente, salvo las que son solo reportes internos sin ningún riesgo.</p>

    <?php if ($error): ?>
        <div class="card-panel red lighten-4 red-text text-darken-4"><?php echo esc($error); ?></div>
    <?php endif; ?>
    <?php if ($success): ?>
        <div class="card-panel green lighten-4 green-text text-darken-4"><?php echo esc($success); ?></div>
    <?php endif; ?>

    <form method="POST">
        <?php echo csrfInput(); ?>
        <input type="hidden" name="accion" value="guardar_config">

        <?php foreach ($featureLabels as $key => $meta): ?>
            <?php $current = $features[$key] ?? ['activo' => false, 'config' => []]; ?>
            <div class="card">
                <div class="card-content">
                    <label>
                        <input type="checkbox" name="activo_<?php echo esc($key); ?>" value="1" <?php echo $current['activo'] ? 'checked' : ''; ?>>
                        <span style="font-size: 1.1rem; font-weight: 600;"><?php echo esc($meta['nombre']); ?></span>
                    </label>
                    <p class="grey-text" style="margin-top: 8px;"><?php echo esc($meta['descripcion']); ?></p>
                    <p style="margin-top: 4px;"><strong>¿Requiere cuenta externa?</strong> <?php echo esc($meta['requiere_cuenta']); ?></p>

                    <?php if ($key === 'catalogo_feed' && $current['activo']): ?>
                        <p style="margin-top: 8px;"><strong>URL del feed:</strong> <code><?php echo esc(BASE_URL); ?>api/product_feed.php</code></p>
                    <?php endif; ?>

                    <?php if ($key === 'programa_referidos'): ?>
                        <div class="row" style="margin-top: 10px; margin-bottom: 0;">
                            <div class="input-field col s6 m4">
                                <input type="number" step="0.1" min="0" max="100" name="referidos_descuento_porcentaje" id="referidos_descuento_porcentaje" value="<?php echo esc((string) ($current['config']['descuento_porcentaje'] ?? 10)); ?>">
                                <label for="referidos_descuento_porcentaje" class="active">Descuento (%)</label>
                            </div>
                            <div class="input-field col s6 m4">
                                <input type="number" step="0.01" min="0" name="referidos_monto_minimo" id="referidos_monto_minimo" value="<?php echo esc((string) ($current['config']['monto_minimo_pedido'] ?? 0)); ?>">
                                <label for="referidos_monto_minimo" class="active">Pedido mínimo ($)</label>
                            </div>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        <?php endforeach; ?>

        <button type="submit" class="btn indigo darken-3 waves-effect waves-light">Guardar</button>
    </form>
</div>

<?php include __DIR__ . '/includes/footer.php'; ?>
