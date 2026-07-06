<?php
declare(strict_types=1);

require_once __DIR__ . '/../core/config.php';
require_once __DIR__ . '/../core/auth.php';
require_once __DIR__ . '/../core/settlement_utils.php';

header('Content-Type: application/json');

if (!isAuthenticated()) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'No autorizado']);
    exit;
}

if (!isVendedor()) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Solo vendedores pueden declarar liquidaciones.']);
    exit;
}

if (strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Metodo no permitido']);
    exit;
}

if (!validateCsrfToken($_POST['csrf_token'] ?? '')) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Token CSRF invalido o expirado.']);
    exit;
}

$tipoPeriodo = trim((string)($_POST['periodo'] ?? ''));
if (!in_array($tipoPeriodo, ['dia', 'mes'], true)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Periodo invalido.']);
    exit;
}

$pdo = getPDO();
$usuario = $_SESSION['usuario'];
$idVendedor = (int)($usuario['id_usuario'] ?? 0);
$idAlmacen = (int)($usuario['id_almacen'] ?? 0);
$comisionPorPieza = 50.0;

$stmtMeta = $pdo->prepare("SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'vendedor_liquidaciones'");
$stmtMeta->execute();
if (((int)$stmtMeta->fetchColumn()) <= 0) {
    http_response_code(409);
    echo json_encode([
        'success' => false,
        'message' => 'Aun no esta habilitado el modulo de liquidaciones. Ejecuta migraciones pendientes.',
    ]);
    exit;
}

$hoy = new DateTimeImmutable('today');
if ($tipoPeriodo === 'dia') {
    $periodoInicio = $hoy;
    $periodoFin = $hoy;
} else {
    $periodoInicio = new DateTimeImmutable($hoy->format('Y-m-01'));
    $periodoFin = new DateTimeImmutable($hoy->format('Y-m-t'));
}

$inicioSql = $periodoInicio->format('Y-m-d') . ' 00:00:00';
$finSql = $periodoFin->format('Y-m-d') . ' 23:59:59';

try {
    if ($tipoPeriodo === 'dia') {
        $stmtVentas = $pdo->prepare("SELECT COALESCE(SUM(total), 0) FROM pedidos WHERE id_usuario = ? AND estado != 'cancelado'");
        $stmtVentas->execute([$idVendedor]);
        $ventasTotal = round((float)$stmtVentas->fetchColumn(), 2);

        $stmtPiezas = $pdo->prepare("SELECT COALESCE(SUM(dp.cantidad), 0) FROM pedidos pe JOIN detalle_pedidos dp ON pe.id_pedido = dp.id_pedido WHERE pe.id_usuario = ? AND pe.estado != 'cancelado'");
        $stmtPiezas->execute([$idVendedor]);
        $piezasTotal = (int)$stmtPiezas->fetchColumn();

        $comisionTotal = round($piezasTotal * $comisionPorPieza, 2);
        $montoAEntregar = settlementCalculateBaseAmount($ventasTotal, $piezasTotal, $comisionPorPieza);

        $stmtEntregadoAcum = $pdo->prepare("SELECT COALESCE(SUM(monto_entregado), 0) FROM vendedor_liquidaciones WHERE id_vendedor = ? AND tipo_periodo = 'dia'");
        $stmtEntregadoAcum->execute([$idVendedor]);
        $montoEntregadoAcumulado = round((float)$stmtEntregadoAcum->fetchColumn(), 2);

        $stmtHoy = $pdo->prepare("SELECT COALESCE(monto_entregado, 0) FROM vendedor_liquidaciones WHERE id_vendedor = ? AND tipo_periodo = 'dia' AND periodo_inicio = ? LIMIT 1");
        $stmtHoy->execute([$idVendedor, $periodoInicio->format('Y-m-d')]);
        $montoEntregadoHoyPrevio = round((float)$stmtHoy->fetchColumn(), 2);

        $montoPendiente = settlementCalculatePendingAmount($montoAEntregar, $montoEntregadoAcumulado);
    } else {
        $stmtVentas = $pdo->prepare("SELECT COALESCE(SUM(total), 0) FROM pedidos WHERE id_usuario = ? AND estado != 'cancelado' AND fecha_creacion BETWEEN ? AND ?");
        $stmtVentas->execute([$idVendedor, $inicioSql, $finSql]);
        $ventasTotal = round((float)$stmtVentas->fetchColumn(), 2);

        $stmtPiezas = $pdo->prepare("SELECT COALESCE(SUM(dp.cantidad), 0) FROM pedidos pe JOIN detalle_pedidos dp ON pe.id_pedido = dp.id_pedido WHERE pe.id_usuario = ? AND pe.estado != 'cancelado' AND pe.fecha_creacion BETWEEN ? AND ?");
        $stmtPiezas->execute([$idVendedor, $inicioSql, $finSql]);
        $piezasTotal = (int)$stmtPiezas->fetchColumn();

        $comisionTotal = round($piezasTotal * $comisionPorPieza, 2);
        $montoAEntregar = settlementCalculateBaseAmount($ventasTotal, $piezasTotal, $comisionPorPieza);

        $stmtPrev = $pdo->prepare("SELECT COALESCE(monto_entregado, 0) FROM vendedor_liquidaciones WHERE id_vendedor = ? AND tipo_periodo = ? AND periodo_inicio = ? LIMIT 1");
        $stmtPrev->execute([$idVendedor, $tipoPeriodo, $periodoInicio->format('Y-m-d')]);
        $montoEntregadoPrevio = round((float)$stmtPrev->fetchColumn(), 2);
        $montoPendiente = settlementCalculatePendingAmount($montoAEntregar, $montoEntregadoPrevio);
    }

    $montoEntregadoRaw = isset($_POST['monto_entregado']) ? trim((string)$_POST['monto_entregado']) : '';
    $montoEntregadoInput = null;
    if ($montoEntregadoRaw !== '') {
        if (!is_numeric($montoEntregadoRaw)) {
            http_response_code(400);
            echo json_encode([
                'success' => false,
                'message' => 'El monto entregado no es valido.',
            ]);
            exit;
        }
        $montoEntregadoInput = (float)$montoEntregadoRaw;
    }

    // Si el vendedor manda vacio o 0/negativo, usar automaticamente el pendiente real del periodo.
    $montoDeclarado = settlementResolveDeclaredAmount($montoEntregadoInput, $montoPendiente);
    if ($tipoPeriodo === 'dia') {
        $montoEntregado = round($montoEntregadoHoyPrevio + $montoDeclarado, 2);
    } else {
        $montoEntregado = round(min($montoAEntregar, $montoEntregadoPrevio + $montoDeclarado), 2);
    }
    $observaciones = trim((string)($_POST['observaciones'] ?? ''));

    $pdo->beginTransaction();

    $sql = "INSERT INTO vendedor_liquidaciones
            (id_vendedor, id_almacen, tipo_periodo, periodo_inicio, periodo_fin, ventas_total, piezas_total, comision_total, monto_a_entregar, monto_entregado, entregado, fecha_declaracion, fecha_entrega_ganancias, observaciones)
            VALUES
            (:id_vendedor, :id_almacen, :tipo_periodo, :periodo_inicio, :periodo_fin, :ventas_total, :piezas_total, :comision_total, :monto_a_entregar, :monto_entregado, 1, NOW(), NOW(), :observaciones)
            ON DUPLICATE KEY UPDATE
                id_almacen = VALUES(id_almacen),
                ventas_total = VALUES(ventas_total),
                piezas_total = VALUES(piezas_total),
                comision_total = VALUES(comision_total),
                monto_a_entregar = VALUES(monto_a_entregar),
                monto_entregado = VALUES(monto_entregado),
                entregado = 1,
                fecha_declaracion = NOW(),
                fecha_entrega_ganancias = NOW(),
                observaciones = VALUES(observaciones)";

    $stmt = $pdo->prepare($sql);
    $stmt->execute([
        ':id_vendedor' => $idVendedor,
        ':id_almacen' => $idAlmacen,
        ':tipo_periodo' => $tipoPeriodo,
        ':periodo_inicio' => $periodoInicio->format('Y-m-d'),
        ':periodo_fin' => $periodoFin->format('Y-m-d'),
        ':ventas_total' => $ventasTotal,
        ':piezas_total' => $piezasTotal,
        ':comision_total' => $comisionTotal,
        ':monto_a_entregar' => $montoAEntregar,
        ':monto_entregado' => $montoEntregado,
        ':observaciones' => $observaciones !== '' ? $observaciones : null,
    ]);

    $pdo->commit();

    logAudit(
        'LIQUIDACION_VENDEDOR_DECLARADA',
        'vendedor_liquidaciones',
        null,
        sprintf(
            'Vendedor %d declaro liquidacion %s. Ventas: %.2f, piezas: %d, comision: %.2f, entregado: %.2f',
            $idVendedor,
            $tipoPeriodo,
            $ventasTotal,
            $piezasTotal,
            $comisionTotal,
            $montoDeclarado
        )
    );

    echo json_encode([
        'success' => true,
        'message' => 'Liquidacion declarada correctamente.',
        'data' => [
            'tipo_periodo' => $tipoPeriodo,
            'ventas_total' => $ventasTotal,
            'piezas_total' => $piezasTotal,
            'comision_total' => $comisionTotal,
            'monto_a_entregar' => $montoAEntregar,
            'monto_entregado_declarado' => $montoDeclarado,
            'monto_entregado_acumulado' => $montoEntregado,
            'monto_pendiente' => $tipoPeriodo === 'dia'
                ? round(max(0.0, $montoPendiente - $montoDeclarado), 2)
                : round(max(0.0, $montoAEntregar - $montoEntregado), 2),
        ],
    ]);
} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }

    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'No se pudo declarar la liquidacion: ' . $e->getMessage(),
    ]);
}
