<?php
declare(strict_types=1);
require_once __DIR__ . '/../core/config.php';
require_once __DIR__ . '/../core/auth.php';
require_once __DIR__ . '/../core/finance_utils.php';
require_once __DIR__ . '/../core/settlement_utils.php';

header('Content-Type: application/json');
if (!isAuthenticated()) {
    echo json_encode(['success' => false, 'message' => 'No autorizado']);
    exit;
}

$pdo = getPDO();
$usuario = $_SESSION['usuario'];
$rol = $usuario['rol'];
$idAlmacen = $usuario['id_almacen'];
$idUsuario = $usuario['id_usuario'];
$comisionPorPieza = 50.0;

try {
    $stats = [];
    $hasVendedorLiquidaciones = false;
    $hasPickupNotificaciones = false;

    $stmtMeta = $pdo->prepare("SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'vendedor_liquidaciones'");
    $stmtMeta->execute();
    $hasVendedorLiquidaciones = ((int)$stmtMeta->fetchColumn()) > 0;

    $stmtMeta = $pdo->prepare("SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'pickup_notificaciones'");
    $stmtMeta->execute();
    $hasPickupNotificaciones = ((int)$stmtMeta->fetchColumn()) > 0;

    $scopeSql = '';
    $scopeParams = [];
    if ($rol === 'encargado') {
        $scopeSql = ' AND pe.id_almacen = ?';
        $scopeParams[] = $idAlmacen;
    } elseif ($rol === 'vendedor') {
        $scopeSql = ' AND pe.id_usuario = ?';
        $scopeParams[] = $idUsuario;
    }

    $financeSql = "
        SELECT DATE(pe.fecha_creacion) AS fecha,
               COALESCE(SUM(dp.subtotal), 0) AS ingresos,
               COALESCE(SUM(dp.cantidad * COALESCE(dp.costo_unitario, p.precio_costo, 0)), 0) AS costos
        FROM pedidos pe
        JOIN detalle_pedidos dp ON pe.id_pedido = dp.id_pedido
        JOIN productos p ON dp.id_producto = p.id_producto
        WHERE pe.estado != 'cancelado'
          AND YEAR(pe.fecha_creacion) = YEAR(NOW())
          AND MONTH(pe.fecha_creacion) = MONTH(NOW())
          {$scopeSql}
        GROUP BY DATE(pe.fecha_creacion)
        ORDER BY fecha ASC";

    $financeStmt = $pdo->prepare($financeSql);
    $financeStmt->execute($scopeParams);
    $financeRows = $financeStmt->fetchAll(PDO::FETCH_ASSOC);
    $monthStart = (new DateTimeImmutable('first day of this month'))->format('Y-m-d');
    $monthEnd = (new DateTimeImmutable('last day of this month'))->format('Y-m-d');
    $dailyFinance = financeBuildDailySeries($financeRows, $monthStart, $monthEnd);
    $monthIncome = 0.0;
    $monthCost = 0.0;
    foreach ($dailyFinance as $day) {
        $monthIncome += (float) $day['ingresos'];
        $monthCost += (float) $day['costos'];
    }
    $monthProfit = financeCalculateGrossProfit($monthIncome, $monthCost);

    $getPickupMetrics = static function (PDO $pdo, bool $hasPickupNotificaciones, ?int $scopeAlmacen = null): array {
        if ($hasPickupNotificaciones) {
            $where = '1=1';
            $params = [];
            if ($scopeAlmacen !== null && $scopeAlmacen > 0) {
                $where .= ' AND id_almacen = ?';
                $params[] = $scopeAlmacen;
            }

            $sql = "SELECT
                        COALESCE(SUM(CASE WHEN estado IN ('nueva','vista') THEN 1 ELSE 0 END), 0) AS pendientes,
                        COALESCE(SUM(CASE WHEN estado = 'nueva' THEN 1 ELSE 0 END), 0) AS nuevas,
                        COALESCE(SUM(CASE WHEN estado = 'vista' THEN 1 ELSE 0 END), 0) AS vistas,
                        COALESCE(SUM(CASE WHEN estado = 'atendida' THEN 1 ELSE 0 END), 0) AS atendidas,
                        COALESCE(SUM(CASE WHEN estado = 'atendida' AND DATE(fecha_atendida) = CURDATE() THEN 1 ELSE 0 END), 0) AS atendidas_hoy
                    FROM pickup_notificaciones
                    WHERE {$where}";
            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);
            $row = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];

            return [
                'pendientes' => (int)($row['pendientes'] ?? 0),
                'nuevas' => (int)($row['nuevas'] ?? 0),
                'vistas' => (int)($row['vistas'] ?? 0),
                'atendidas' => (int)($row['atendidas'] ?? 0),
                'atendidas_hoy' => (int)($row['atendidas_hoy'] ?? 0),
            ];
        }

        $where = "estado IN ('pendiente_pago','pagado') AND observaciones LIKE '%ENTREGA: Sucursal%'";
        $params = [];
        if ($scopeAlmacen !== null && $scopeAlmacen > 0) {
            $where .= ' AND id_almacen = ?';
            $params[] = $scopeAlmacen;
        }

        $stmt = $pdo->prepare("SELECT COUNT(*) AS total FROM pedidos WHERE {$where}");
        $stmt->execute($params);
        $total = (int)$stmt->fetchColumn();

        return [
            'pendientes' => $total,
            'nuevas' => $total,
            'vistas' => 0,
            'atendidas' => 0,
            'atendidas_hoy' => 0,
        ];
    };

    if ($rol === 'admin') {
        // Ventas hoy
        $stmt = $pdo->query("SELECT COUNT(*) as total, COALESCE(SUM(total), 0) as monto FROM pedidos WHERE DATE(fecha_creacion) = CURDATE() AND estado != 'cancelado'");
        $stats['ventas_hoy'] = $stmt->fetch();

        $pickupMetrics = $getPickupMetrics($pdo, $hasPickupNotificaciones);
        $stats['pickup_metrics'] = $pickupMetrics;
        $stats['pickup_pendientes'] = ['total' => $pickupMetrics['pendientes']];

        // Clientes y Usuarios
        $stats['clientes'] = $pdo->query("SELECT COUNT(*) as total FROM clientes WHERE estado = 'activo'")->fetch();
        $stats['usuarios'] = $pdo->query("SELECT COUNT(*) as total FROM usuarios WHERE estado = 'activo'")->fetch();
        $stats['productos'] = $pdo->query("SELECT COUNT(*) as total FROM productos WHERE estado = 'activo'")->fetch();
        
        $stats['finanzas_mes'] = [
            'ingresos' => $monthIncome,
            'costo' => $monthCost,
            'utilidad' => $monthProfit,
            'margen' => $monthIncome > 0 ? round(($monthProfit / $monthIncome) * 100, 2) : 0,
            'diario' => $dailyFinance,
        ];
        $stats['ingresos_mes'] = ['total' => $monthIncome];
        $stats['utilidad_mes'] = ['total' => $monthProfit];
        $stats['costo_mes'] = ['total' => $monthCost];
        
        // Stock bajo
        $stats['stock_bajo'] = $pdo->query("SELECT COUNT(*) as total FROM inventario_almacen ia JOIN productos p ON ia.id_producto = p.id_producto WHERE ia.cantidad_actual <= ia.stock_minimo AND p.estado = 'activo'")->fetch();
        
        // Auditoría
        $stats['incompletos'] = $pdo->query("SELECT COUNT(DISTINCT p.id_producto) as total FROM productos p LEFT JOIN inventario_almacen ia ON p.id_producto = ia.id_producto WHERE p.precio_venta <= 0 OR p.precio_costo <= 0 OR ia.id_producto IS NULL")->fetch();

        // Blogs
        $stats['blogs'] = $pdo->query("SELECT COUNT(*) as total FROM blogs")->fetch();

    } elseif ($rol === 'encargado') {
        $stmt = $pdo->prepare("SELECT COUNT(*) as total, COALESCE(SUM(total), 0) as monto FROM pedidos WHERE id_almacen = ? AND DATE(fecha_creacion) = CURDATE() AND estado != 'cancelado'");
        $stmt->execute([$idAlmacen]);
        $stats['ventas_hoy'] = $stmt->fetch();

        $pickupMetrics = $getPickupMetrics($pdo, $hasPickupNotificaciones, (int)$idAlmacen);
        $stats['pickup_metrics'] = $pickupMetrics;
        $stats['pickup_pendientes'] = ['total' => $pickupMetrics['pendientes']];

        $stmt = $pdo->prepare("SELECT COUNT(*) as total FROM inventario_almacen WHERE id_almacen = ?");
        $stmt->execute([$idAlmacen]);
        $stats['productos'] = $stmt->fetch();

        $stats['finanzas_mes'] = [
            'ingresos' => $monthIncome,
            'costo' => $monthCost,
            'utilidad' => $monthProfit,
            'margen' => $monthIncome > 0 ? round(($monthProfit / $monthIncome) * 100, 2) : 0,
            'diario' => $dailyFinance,
        ];
        $stats['ingresos_mes'] = ['total' => $monthIncome];
        $stats['utilidad_mes'] = ['total' => $monthProfit];
        $stats['costo_mes'] = ['total' => $monthCost];

        $stmt = $pdo->prepare("SELECT COUNT(*) as total FROM inventario_almacen ia JOIN productos p ON ia.id_producto = p.id_producto WHERE ia.id_almacen = ? AND ia.cantidad_actual <= ia.stock_minimo AND p.estado = 'activo'");
        $stmt->execute([$idAlmacen]);
        $stats['stock_bajo'] = $stmt->fetch();

        $stmt = $pdo->prepare("SELECT COUNT(*) as total FROM pedidos WHERE id_repartidor IS NOT NULL AND estado IN ('pendiente_pago','pagado','en_reparto') AND id_almacen = ?");
        $stmt->execute([$idAlmacen]);
        $stats['por_entregar'] = $stmt->fetch();

    } elseif ($rol === 'repartidor') {
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM pedidos WHERE id_repartidor = ? AND estado IN ('pendiente_pago','pagado','en_reparto')");
        $stmt->execute([$idUsuario]);
        $stats['entregas_hoy'] = ['total' => $stmt->fetchColumn()];

    } elseif ($rol === 'vendedor') {
        $fechaInicioVentas = trim((string)($_GET['ventas_fecha_inicio'] ?? ''));
        $fechaFinVentas = trim((string)($_GET['ventas_fecha_fin'] ?? ''));
        $estadoVentas = trim((string)($_GET['ventas_estado'] ?? ''));
        $allowedEstados = ['pendiente_pago', 'pagado', 'en_reparto', 'entregado', 'apartado', 'cancelado'];

        $fechaValida = static function (string $value): bool {
            if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) {
                return false;
            }
            $dt = DateTimeImmutable::createFromFormat('Y-m-d', $value);
            return $dt !== false && $dt->format('Y-m-d') === $value;
        };

        if ($fechaInicioVentas !== '' && !$fechaValida($fechaInicioVentas)) {
            $fechaInicioVentas = '';
        }
        if ($fechaFinVentas !== '' && !$fechaValida($fechaFinVentas)) {
            $fechaFinVentas = '';
        }

        if ($fechaInicioVentas !== '' && $fechaFinVentas !== '' && $fechaInicioVentas > $fechaFinVentas) {
            [$fechaInicioVentas, $fechaFinVentas] = [$fechaFinVentas, $fechaInicioVentas];
        }

        if ($estadoVentas !== '' && !in_array($estadoVentas, $allowedEstados, true)) {
            $estadoVentas = '';
        }

        $stmt = $pdo->prepare("SELECT COUNT(*) as total, COALESCE(SUM(total), 0) as monto FROM pedidos WHERE id_usuario = ? AND DATE(fecha_creacion) = CURDATE() AND estado != 'cancelado'");
        $stmt->execute([$idUsuario]);
        $stats['ventas_hoy'] = $stmt->fetch();

        $stmt = $pdo->prepare("SELECT COUNT(*) as total, COALESCE(SUM(total), 0) as monto FROM pedidos WHERE id_usuario = ? AND YEAR(fecha_creacion) = YEAR(NOW()) AND MONTH(fecha_creacion) = MONTH(NOW()) AND estado != 'cancelado'");
        $stmt->execute([$idUsuario]);
        $stats['ventas_mes'] = $stmt->fetch();

        $stmt = $pdo->prepare("SELECT COALESCE(SUM(dp.cantidad), 0) FROM pedidos pe JOIN detalle_pedidos dp ON pe.id_pedido = dp.id_pedido WHERE pe.id_usuario = ? AND DATE(pe.fecha_creacion) = CURDATE() AND pe.estado != 'cancelado'");
        $stmt->execute([$idUsuario]);
        $piezasHoy = (int)$stmt->fetchColumn();

        $stmt = $pdo->prepare("SELECT COALESCE(SUM(dp.cantidad), 0) FROM pedidos pe JOIN detalle_pedidos dp ON pe.id_pedido = dp.id_pedido WHERE pe.id_usuario = ? AND YEAR(pe.fecha_creacion) = YEAR(NOW()) AND MONTH(pe.fecha_creacion) = MONTH(NOW()) AND pe.estado != 'cancelado'");
        $stmt->execute([$idUsuario]);
        $piezasMes = (int)$stmt->fetchColumn();

        $montoHoy = (float)($stats['ventas_hoy']['monto'] ?? 0);
        $montoMes = (float)($stats['ventas_mes']['monto'] ?? 0);
        $comisionHoy = round($piezasHoy * $comisionPorPieza, 2);
        $comisionMes = round($piezasMes * $comisionPorPieza, 2);

        $stmt = $pdo->prepare("SELECT COALESCE(SUM(total), 0) FROM pedidos WHERE id_usuario = ? AND estado != 'cancelado'");
        $stmt->execute([$idUsuario]);
        $ventasAcumuladasDia = round((float)$stmt->fetchColumn(), 2);

        $stmt = $pdo->prepare("SELECT COALESCE(SUM(dp.cantidad), 0) FROM pedidos pe JOIN detalle_pedidos dp ON pe.id_pedido = dp.id_pedido WHERE pe.id_usuario = ? AND pe.estado != 'cancelado'");
        $stmt->execute([$idUsuario]);
        $piezasAcumuladasDia = (int)$stmt->fetchColumn();

        $comisionAcumuladaDia = round($piezasAcumuladasDia * $comisionPorPieza, 2);
        $montoBaseAcumuladoDia = settlementCalculateBaseAmount($ventasAcumuladasDia, $piezasAcumuladasDia, $comisionPorPieza);

        $entregadoAcumuladoDia = 0.0;
        if ($hasVendedorLiquidaciones) {
            $stmt = $pdo->prepare("SELECT COALESCE(SUM(monto_entregado), 0) FROM vendedor_liquidaciones WHERE id_vendedor = ? AND tipo_periodo = 'dia'");
            $stmt->execute([$idUsuario]);
            $entregadoAcumuladoDia = round((float)$stmt->fetchColumn(), 2);
        }

        $pendienteAcumuladoDia = settlementCalculatePendingAmount($montoBaseAcumuladoDia, $entregadoAcumuladoDia);

        $stats['comisiones'] = [
            'tarifa_por_pieza' => $comisionPorPieza,
            'piezas_hoy' => $piezasHoy,
            'piezas_mes' => $piezasMes,
            'piezas_base_corte_dia' => $piezasAcumuladasDia,
            'comision_hoy' => $comisionHoy,
            'comision_mes' => $comisionMes,
            'ventas_base_corte_dia' => $ventasAcumuladasDia,
            'comision_base_corte_dia' => $comisionAcumuladaDia,
            'monto_a_entregar_hoy' => $pendienteAcumuladoDia,
            'monto_a_entregar_mes' => round(max(0.0, $montoMes - $comisionMes), 2),
        ];

        $stats['liquidacion_hoy'] = null;
        $stats['liquidacion_mes'] = null;

        if ($hasVendedorLiquidaciones) {
            $stmt = $pdo->prepare("SELECT tipo_periodo, ventas_total, piezas_total, comision_total, monto_a_entregar, monto_entregado, fecha_declaracion, fecha_entrega_ganancias FROM vendedor_liquidaciones WHERE id_vendedor = ? AND tipo_periodo IN ('dia','mes') ORDER BY fecha_declaracion DESC");
            $stmt->execute([$idUsuario]);
            $liquidaciones = $stmt->fetchAll(PDO::FETCH_ASSOC);

            foreach ($liquidaciones as $liq) {
                if (($liq['tipo_periodo'] ?? '') === 'dia' && $stats['liquidacion_hoy'] === null) {
                    $stats['liquidacion_hoy'] = $liq;
                }
                if (($liq['tipo_periodo'] ?? '') === 'mes' && $stats['liquidacion_mes'] === null) {
                    $stats['liquidacion_mes'] = $liq;
                }
            }
        }

        $stmt = $pdo->prepare("SELECT COUNT(DISTINCT id_cliente) as total FROM pedidos WHERE id_usuario = ? AND id_cliente IS NOT NULL AND YEAR(fecha_creacion) = YEAR(NOW()) AND MONTH(fecha_creacion) = MONTH(NOW()) AND estado != 'cancelado'");
        $stmt->execute([$idUsuario]);
        $stats['clientes_mes'] = $stmt->fetch();

        $stmt = $pdo->prepare("SELECT COALESCE(SUM(total), 0) as total FROM pedidos WHERE id_usuario = ? AND YEAR(fecha_creacion) = YEAR(NOW()) AND MONTH(fecha_creacion) = MONTH(NOW()) AND estado != 'cancelado'");
        $stmt->execute([$idUsuario]);
        $stats['ingresos_mes'] = $stmt->fetch();
        $stats['utilidad_mes'] = ['total' => 0];
        $stats['costo_mes'] = ['total' => 0];

        $ventasWhere = "id_usuario = ?";
        $ventasParams = [$idUsuario];
        if ($fechaInicioVentas !== '') {
            $ventasWhere .= " AND DATE(fecha_creacion) >= ?";
            $ventasParams[] = $fechaInicioVentas;
        }
        if ($fechaFinVentas !== '') {
            $ventasWhere .= " AND DATE(fecha_creacion) <= ?";
            $ventasParams[] = $fechaFinVentas;
        }
        if ($estadoVentas !== '') {
            $ventasWhere .= " AND estado = ?";
            $ventasParams[] = $estadoVentas;
        }

        $stmt = $pdo->prepare("SELECT numero_pedido, total, fecha_creacion, estado, observaciones FROM pedidos WHERE {$ventasWhere} ORDER BY fecha_creacion DESC LIMIT 100");
        $stmt->execute($ventasParams);
        $ventasRecientes = $stmt->fetchAll(PDO::FETCH_ASSOC);

        foreach ($ventasRecientes as &$venta) {
            $obs = (string)($venta['observaciones'] ?? '');
            $clienteReferencia = 'Sin referencia';
            if (preg_match('/Cliente:\s*([^|\.]+)/u', $obs, $matches)) {
                $clienteReferencia = trim((string)($matches[1] ?? ''));
                if ($clienteReferencia === '') {
                    $clienteReferencia = 'Sin referencia';
                }
            }

            $venta['cliente_referencia'] = $clienteReferencia;
            unset($venta['observaciones']);
        }
        unset($venta);

        $stats['ventas_recientes_vendedor'] = $ventasRecientes;
    }

    if ($rol === 'admin') {
        $sqlDetalleVendedores = "SELECT
                u.id_usuario,
                u.nombre AS vendedor,
                u.id_almacen,
                a.nombre AS sucursal,
                COALESCE(vh.total_ventas_hoy, 0) AS ventas_hoy,
                COALESCE(vm.total_ventas_mes, 0) AS ventas_mes,
                COALESCE(pm.piezas_mes, 0) AS piezas_mes,
            COALESCE(lm.monto_entregado_mes, 0) AS entregado_mes,
                lm.fecha_entrega_ganancias
            FROM usuarios u
            INNER JOIN roles r ON u.id_rol = r.id_rol AND r.nombre = 'vendedor'
            LEFT JOIN almacenes a ON u.id_almacen = a.id_almacen
            LEFT JOIN (
                SELECT id_usuario, COALESCE(SUM(total), 0) AS total_ventas_hoy
                FROM pedidos
                WHERE DATE(fecha_creacion) = CURDATE() AND estado != 'cancelado'
                GROUP BY id_usuario
            ) vh ON vh.id_usuario = u.id_usuario
            LEFT JOIN (
                SELECT id_usuario, COALESCE(SUM(total), 0) AS total_ventas_mes
                FROM pedidos
                WHERE YEAR(fecha_creacion) = YEAR(NOW()) AND MONTH(fecha_creacion) = MONTH(NOW()) AND estado != 'cancelado'
                GROUP BY id_usuario
            ) vm ON vm.id_usuario = u.id_usuario
            LEFT JOIN (
                SELECT pe.id_usuario, COALESCE(SUM(dp.cantidad), 0) AS piezas_mes
                FROM pedidos pe
                JOIN detalle_pedidos dp ON pe.id_pedido = dp.id_pedido
                WHERE YEAR(pe.fecha_creacion) = YEAR(NOW()) AND MONTH(pe.fecha_creacion) = MONTH(NOW()) AND pe.estado != 'cancelado'
                GROUP BY pe.id_usuario
            ) pm ON pm.id_usuario = u.id_usuario
            LEFT JOIN (
                SELECT
                    id_vendedor,
                    COALESCE(SUM(monto_entregado), 0) AS monto_entregado_mes,
                    MAX(fecha_entrega_ganancias) AS fecha_entrega_ganancias
                FROM vendedor_liquidaciones
                WHERE (
                    tipo_periodo = 'dia'
                    AND periodo_inicio BETWEEN DATE_FORMAT(CURDATE(), '%Y-%m-01') AND LAST_DAY(CURDATE())
                )
                OR (
                    tipo_periodo = 'mes'
                    AND periodo_inicio = DATE_FORMAT(CURDATE(), '%Y-%m-01')
                )
                GROUP BY id_vendedor
            ) lm ON lm.id_vendedor = u.id_usuario
            WHERE u.estado = 'activo'
            ORDER BY a.nombre ASC, u.nombre ASC";
        if ($hasVendedorLiquidaciones) {
            $rowsVendedor = $pdo->query($sqlDetalleVendedores)->fetchAll(PDO::FETCH_ASSOC);
        } else {
            $sqlDetalleVendedoresFallback = "SELECT
                    u.id_usuario,
                    u.nombre AS vendedor,
                    u.id_almacen,
                    a.nombre AS sucursal,
                    COALESCE(vh.total_ventas_hoy, 0) AS ventas_hoy,
                    COALESCE(vm.total_ventas_mes, 0) AS ventas_mes,
                    COALESCE(pm.piezas_mes, 0) AS piezas_mes,
                    0 AS entregado_mes,
                    NULL AS fecha_entrega_ganancias
                FROM usuarios u
                INNER JOIN roles r ON u.id_rol = r.id_rol AND r.nombre = 'vendedor'
                LEFT JOIN almacenes a ON u.id_almacen = a.id_almacen
                LEFT JOIN (
                    SELECT id_usuario, COALESCE(SUM(total), 0) AS total_ventas_hoy
                    FROM pedidos
                    WHERE DATE(fecha_creacion) = CURDATE() AND estado != 'cancelado'
                    GROUP BY id_usuario
                ) vh ON vh.id_usuario = u.id_usuario
                LEFT JOIN (
                    SELECT id_usuario, COALESCE(SUM(total), 0) AS total_ventas_mes
                    FROM pedidos
                    WHERE YEAR(fecha_creacion) = YEAR(NOW()) AND MONTH(fecha_creacion) = MONTH(NOW()) AND estado != 'cancelado'
                    GROUP BY id_usuario
                ) vm ON vm.id_usuario = u.id_usuario
                LEFT JOIN (
                    SELECT pe.id_usuario, COALESCE(SUM(dp.cantidad), 0) AS piezas_mes
                    FROM pedidos pe
                    JOIN detalle_pedidos dp ON pe.id_pedido = dp.id_pedido
                    WHERE YEAR(pe.fecha_creacion) = YEAR(NOW()) AND MONTH(pe.fecha_creacion) = MONTH(NOW()) AND pe.estado != 'cancelado'
                    GROUP BY pe.id_usuario
                ) pm ON pm.id_usuario = u.id_usuario
                WHERE u.estado = 'activo'
                ORDER BY a.nombre ASC, u.nombre ASC";
            $rowsVendedor = $pdo->query($sqlDetalleVendedoresFallback)->fetchAll(PDO::FETCH_ASSOC);
        }
        foreach ($rowsVendedor as &$row) {
            $ventasMesVendedor = (float)($row['ventas_mes'] ?? 0);
            $piezasMesVendedor = (int)($row['piezas_mes'] ?? 0);
            $comisionMesVendedor = round($piezasMesVendedor * $comisionPorPieza, 2);
            $entregadoMesVendedor = (float)($row['entregado_mes'] ?? 0);
            $row['comision_mes'] = $comisionMesVendedor;
            $row['pendiente_mes'] = round(max(0.0, $ventasMesVendedor - $comisionMesVendedor - $entregadoMesVendedor), 2);
        }
        unset($row);
        $stats['detalle_vendedores_admin'] = $rowsVendedor;

        $resumenPorSucursal = [];
        foreach ($rowsVendedor as $row) {
            $key = (string)($row['id_almacen'] ?? 0);
            if (!isset($resumenPorSucursal[$key])) {
                $resumenPorSucursal[$key] = [
                    'id_almacen' => (int)($row['id_almacen'] ?? 0),
                    'sucursal' => $row['sucursal'] ?? 'Sin sucursal',
                    'vendedores' => 0,
                    'ventas_hoy' => 0.0,
                    'ventas_mes' => 0.0,
                    'piezas_mes' => 0,
                    'comision_mes' => 0.0,
                    'entregado_mes' => 0.0,
                    'pendiente_mes' => 0.0,
                ];
            }

            $resumenPorSucursal[$key]['vendedores']++;
            $resumenPorSucursal[$key]['ventas_hoy'] += (float)($row['ventas_hoy'] ?? 0);
            $resumenPorSucursal[$key]['ventas_mes'] += (float)($row['ventas_mes'] ?? 0);
            $resumenPorSucursal[$key]['piezas_mes'] += (int)($row['piezas_mes'] ?? 0);
            $resumenPorSucursal[$key]['comision_mes'] += (float)($row['comision_mes'] ?? 0);
            $resumenPorSucursal[$key]['entregado_mes'] += (float)($row['entregado_mes'] ?? 0);
            $resumenPorSucursal[$key]['pendiente_mes'] += (float)($row['pendiente_mes'] ?? 0);
        }

        $stats['resumen_vendedores_sucursal'] = array_values($resumenPorSucursal);
    }

    echo json_encode(['success' => true, 'data' => $stats]);
} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}