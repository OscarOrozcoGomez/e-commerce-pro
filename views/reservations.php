<?php
declare(strict_types=1);

require_once __DIR__ . '/../core/config.php';
require_once __DIR__ . '/../core/auth.php';

requireAuth();
requirePermission('apartar_productos', BASE_URL . 'views/dashboard.php');

$pageTitle = 'Mis Apartados';
$pdo = getPDO();
$usuario = $_SESSION['usuario'];

// Obtener apartados (pedidos pendientes del usuario actual)
try {
    $sql = "SELECT p.id_pedido, p.numero_pedido, p.total, p.estado, p.fecha_creacion,
                   c.nombre as cliente, c.telefono
            FROM pedidos p
            LEFT JOIN clientes c ON p.id_cliente = c.id_cliente
            WHERE p.id_usuario = :usuario
            AND p.estado IN ('pendiente_pago', 'pendiente_entrega')
            ORDER BY p.fecha_creacion DESC";
    $stmt = $pdo->prepare($sql);
    $stmt->execute([':usuario' => $usuario['id_usuario']]);
    $apartados = $stmt->fetchAll();
} catch (PDOException $e) {
    $apartados = [];
}

include __DIR__ . '/includes/header.php';
?>

<div class="container">
    <div class="row">
        <div class="col s12">
            <h4>Mis Apartados</h4>
        </div>
    </div>

    <div class="row">
        <div class="col s12">
            <div class="card">
                <div class="card-content">
                    <span class="card-title">Productos Apartados</span>
                    
                    <?php if (empty($apartados)): ?>
                        <p class="center-align">No hay apartados registrados.</p>
                    <?php else: ?>
                        <div style="overflow-x: auto;">
                            <table class="striped">
                                <thead>
                                    <tr>
                                        <th>Número de Apartado</th>
                                        <th>Cliente</th>
                                        <th>Teléfono</th>
                                        <th>Total</th>
                                        <th>Estado</th>
                                        <th>Fecha</th>
                                        <th>Acciones</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($apartados as $apt): ?>
                                        <tr>
                                            <td><?php echo esc($apt['numero_pedido']); ?></td>
                                            <td><?php echo esc($apt['cliente'] ?? 'Cliente General'); ?></td>
                                            <td><?php echo esc($apt['telefono'] ?? 'N/A'); ?></td>
                                            <td>$<?php echo number_format($apt['total'], 2); ?></td>
                                            <td><?php echo esc($apt['estado']); ?></td>
                                            <td><?php echo date('d/m/Y', strtotime($apt['fecha_creacion'])); ?></td>
                                            <td>
                                                <a href="#modal-<?php echo $apt['id_pedido']; ?>" class="btn-small blue modal-trigger">Ver</a>
                                            </td>
                                        </tr>

                                        <!-- Modal para ver detalles -->
                                        <div id="modal-<?php echo $apt['id_pedido']; ?>" class="modal">
                                            <div class="modal-content">
                                                <h4><?php echo esc($apt['numero_pedido']); ?></h4>
                                                <p><strong>Cliente:</strong> <?php echo esc($apt['cliente'] ?? 'Cliente General'); ?></p>
                                                <p><strong>Total:</strong> $<?php echo number_format($apt['total'], 2); ?></p>
                                                <p><strong>Estado:</strong> <?php echo esc($apt['estado']); ?></p>
                                            </div>
                                            <div class="modal-footer">
                                                <a href="#!" class="modal-close waves-effect waves-green btn-flat">Cerrar</a>
                                            </div>
                                        </div>
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

<script>
    document.addEventListener('DOMContentLoaded', function() {
        M.Modal.init(document.querySelectorAll('.modal'));
    });
</script>

<?php include __DIR__ . '/includes/footer.php'; ?>
