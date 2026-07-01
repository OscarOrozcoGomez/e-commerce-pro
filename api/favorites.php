<?php
declare(strict_types=1);

require_once __DIR__ . '/../core/config.php';
require_once __DIR__ . '/../core/auth.php';

header('Content-Type: application/json');

if (!isAuthenticated()) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'Debes iniciar sesion']);
    exit;
}

$pdo = getPDO();
$userId = (int)($_SESSION['usuario']['id_usuario'] ?? 0);

if ($userId <= 0) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'Sesion invalida']);
    exit;
}

function ensureFavoritesTable(PDO $pdo): void
{
    $sql = "CREATE TABLE IF NOT EXISTS favoritos_usuarios (
        id_favorito INT UNSIGNED NOT NULL AUTO_INCREMENT,
        id_usuario INT UNSIGNED NOT NULL,
        id_producto INT UNSIGNED NOT NULL,
        fecha_creacion TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (id_favorito),
        UNIQUE KEY uq_favoritos_usuario_producto (id_usuario, id_producto),
        INDEX idx_favoritos_usuario (id_usuario),
        INDEX idx_favoritos_producto (id_producto),
        CONSTRAINT fk_favoritos_usuario FOREIGN KEY (id_usuario) REFERENCES usuarios (id_usuario) ON DELETE CASCADE ON UPDATE CASCADE,
        CONSTRAINT fk_favoritos_producto FOREIGN KEY (id_producto) REFERENCES productos (id_producto) ON DELETE CASCADE ON UPDATE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_spanish_ci";

    $pdo->exec($sql);
}

function favoritesCount(PDO $pdo, int $userId): int
{
    $stmt = $pdo->prepare('SELECT COUNT(*) FROM favoritos_usuarios WHERE id_usuario = ?');
    $stmt->execute([$userId]);
    return (int)$stmt->fetchColumn();
}

ensureFavoritesTable($pdo);

$method = $_SERVER['REQUEST_METHOD'];

try {
    if ($method === 'GET') {
        $mode = $_GET['mode'] ?? 'list';

        if ($mode === 'count') {
            echo json_encode([
                'success' => true,
                'count' => favoritesCount($pdo, $userId),
            ]);
            exit;
        }

        if ($mode === 'status') {
            $productId = (int)($_GET['id_producto'] ?? 0);
            if ($productId <= 0) {
                http_response_code(400);
                echo json_encode(['success' => false, 'error' => 'id_producto invalido']);
                exit;
            }

            $stmt = $pdo->prepare('SELECT COUNT(*) FROM favoritos_usuarios WHERE id_usuario = ? AND id_producto = ?');
            $stmt->execute([$userId, $productId]);
            $isFavorite = ((int)$stmt->fetchColumn()) > 0;

            echo json_encode([
                'success' => true,
                'is_favorite' => $isFavorite,
                'count' => favoritesCount($pdo, $userId),
            ]);
            exit;
        }

        $sql = "SELECT f.id_producto, p.nombre, p.nombre_variante, p.precio_venta,
                       (
                           SELECT pi.ruta_archivo
                           FROM producto_imagenes pi
                           WHERE pi.id_producto = p.id_producto
                           ORDER BY pi.orden ASC
                           LIMIT 1
                       ) AS imagen_galeria
                FROM favoritos_usuarios f
                JOIN productos p ON p.id_producto = f.id_producto
                WHERE f.id_usuario = ? AND p.estado = 'activo'
                ORDER BY f.fecha_creacion DESC";

        $stmt = $pdo->prepare($sql);
        $stmt->execute([$userId]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

        $items = array_map(static function (array $row): array {
            $name = (string)($row['nombre'] ?? 'Producto');
            $variant = trim((string)($row['nombre_variante'] ?? ''));
            $fullName = $variant !== '' ? ($name . ' | ' . $variant) : $name;

            $rawImage = (string)($row['imagen_galeria'] ?: '');
            $imageUrl = $rawImage !== '' ? (getProductImageUrl($rawImage) ?: '') : '';

            return [
                'id_producto' => (int)$row['id_producto'],
                'nombre' => $fullName,
                'precio' => (float)$row['precio_venta'],
                'imagen' => $imageUrl,
            ];
        }, $rows);

        echo json_encode([
            'success' => true,
            'items' => $items,
            'count' => count($items),
        ]);
        exit;
    }

    if ($method === 'POST') {
        $payload = json_decode(file_get_contents('php://input'), true) ?: [];
        $action = (string)($payload['action'] ?? 'toggle');

        if ($action !== 'toggle' && $action !== 'remove' && $action !== 'sync') {
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => 'Accion invalida']);
            exit;
        }

        if ($action === 'sync') {
            $items = $payload['items'] ?? [];
            if (!is_array($items)) {
                http_response_code(400);
                echo json_encode(['success' => false, 'error' => 'items invalido']);
                exit;
            }

            $insert = $pdo->prepare('INSERT IGNORE INTO favoritos_usuarios (id_usuario, id_producto) VALUES (?, ?)');
            foreach ($items as $entry) {
                $productIdSync = 0;
                if (is_array($entry)) {
                    $productIdSync = (int)($entry['id_producto'] ?? $entry['id'] ?? 0);
                } else {
                    $productIdSync = (int)$entry;
                }
                if ($productIdSync > 0) {
                    $insert->execute([$userId, $productIdSync]);
                }
            }

            echo json_encode([
                'success' => true,
                'count' => favoritesCount($pdo, $userId),
            ]);
            exit;
        }

        $productId = (int)($payload['id_producto'] ?? 0);
        if ($productId <= 0) {
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => 'id_producto invalido']);
            exit;
        }

        $stmtCheck = $pdo->prepare('SELECT COUNT(*) FROM favoritos_usuarios WHERE id_usuario = ? AND id_producto = ?');
        $stmtCheck->execute([$userId, $productId]);
        $alreadyFavorite = ((int)$stmtCheck->fetchColumn()) > 0;

        if ($action === 'remove') {
            if ($alreadyFavorite) {
                $stmtDelete = $pdo->prepare('DELETE FROM favoritos_usuarios WHERE id_usuario = ? AND id_producto = ?');
                $stmtDelete->execute([$userId, $productId]);
            }

            echo json_encode([
                'success' => true,
                'is_favorite' => false,
                'count' => favoritesCount($pdo, $userId),
            ]);
            exit;
        }

        if ($alreadyFavorite) {
            $stmtDelete = $pdo->prepare('DELETE FROM favoritos_usuarios WHERE id_usuario = ? AND id_producto = ?');
            $stmtDelete->execute([$userId, $productId]);
            $isFavorite = false;
        } else {
            $stmtInsert = $pdo->prepare('INSERT INTO favoritos_usuarios (id_usuario, id_producto) VALUES (?, ?)');
            $stmtInsert->execute([$userId, $productId]);
            $isFavorite = true;
        }

        echo json_encode([
            'success' => true,
            'is_favorite' => $isFavorite,
            'count' => favoritesCount($pdo, $userId),
        ]);
        exit;
    }

    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'Metodo no permitido']);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Error al procesar favoritos']);
}
