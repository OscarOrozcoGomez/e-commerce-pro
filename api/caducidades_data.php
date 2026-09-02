<?php
declare(strict_types=1);

require_once __DIR__ . '/../core/config.php';
require_once __DIR__ . '/../core/auth.php';
require_once __DIR__ . '/../core/lote_caducidad_utils.php';

header('Content-Type: application/json');

if (!isAuthenticated() || (!isAdmin() && !isEncargado())) {
    echo json_encode(['success' => false, 'message' => 'No autorizado']);
    exit;
}

$pdo = getPDO();

try {
    $accion = (string) ($_GET['accion'] ?? 'listar');

    if ($accion === 'buscar_por_codigo') {
        $prod = loteBuscarProductoPorCodigoBarras($pdo, (string) ($_GET['codigo'] ?? ''));
        echo json_encode(['success' => true, 'data' => $prod]);
        exit;
    }

    if (!loteTablaExiste($pdo, 'lotes_inventario')) {
        echo json_encode([
            'success' => true,
            'data' => ['lotes' => [], 'resumen' => loteContarAlertas($pdo), 'ventana_dias' => LOTE_VENTANA_DIAS],
            'pendiente_migracion' => true,
        ]);
        exit;
    }

    $filtros = [
        'severidad' => trim((string) ($_GET['severidad'] ?? '')),
        'id_almacen' => (int) ($_GET['id_almacen'] ?? 0),
        'categoria' => trim((string) ($_GET['categoria'] ?? '')),
        'q' => trim((string) ($_GET['q'] ?? '')),
        'solo_con_excedente' => !empty($_GET['solo_con_excedente']),
    ];

    $proy = loteFetchProyecciones($pdo, $filtros);

    echo json_encode([
        'success' => true,
        'data' => [
            'lotes' => $proy['lotes'],
            'resumen' => loteContarAlertas($pdo),
            'ventana_dias' => $proy['ventana_dias'],
        ],
    ], JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    error_log('caducidades_data: ' . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'No se pudo cargar el control de caducidades.']);
}
