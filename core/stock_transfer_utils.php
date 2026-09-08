<?php

declare(strict_types=1);

/**
 * Lógica de "Transferencia entre Almacenes" (views/transfer_stock.php + api/transfer_stock.php).
 *
 * Se aísla aquí para poder probarla con SQLite en memoria, igual que
 * purchaseOrderProcessSingleInbound(). El endpoint queda como una capa fina que
 * sólo resuelve auth + CSRF y traduce las excepciones a JSON.
 *
 * Garantías:
 *  - Atómica: todo ocurre dentro de una transacción (la abre si el llamador no lo hizo).
 *  - Sin condiciones de carrera: bloquea con SELECT ... FOR UPDATE las filas de
 *    inventario de origen y destino en MySQL (no-op en SQLite de pruebas).
 *  - Sólo mueve stock realmente disponible: cantidad_actual - cantidad_reservada.
 *  - Reasigna lotes FEFO (el más próximo a caducar primero) cuando existe la tabla
 *    lotes_inventario; un lote que no cabe entero se queda en origen (la restricción
 *    uq_lote_producto_codigo impide partirlo).
 */

/**
 * @internal Comprueba si una tabla existe sin ensuciar el manejo de errores del PDO.
 *           El nombre de tabla SIEMPRE es un literal del código, nunca entrada del usuario.
 */
function stockTransferTableExists(PDO $pdo, string $tabla): bool
{
    static $cache = [];
    if (array_key_exists($tabla, $cache)) {
        return $cache[$tabla];
    }
    try {
        $pdo->query("SELECT 1 FROM {$tabla} LIMIT 1");
        return $cache[$tabla] = true;
    } catch (Throwable $e) {
        return $cache[$tabla] = false;
    }
}

/**
 * Mueve $datos['cantidad'] unidades de un almacén a otro.
 *
 * @param array{
 *   id_producto?: int|string,
 *   id_origen?: int|string,
 *   id_destino?: int|string,
 *   cantidad?: int|string,
 *   observacion?: string
 * } $datos
 *
 * @return array{
 *   cantidad: int,
 *   disponible_origen: int,
 *   lotes_movidos: int,
 *   unidades_sin_lote: int
 * }
 *
 * @throws InvalidArgumentException  Datos mal formados (el endpoint responde 400 lógico).
 * @throws RuntimeException          Regla de negocio incumplida (stock insuficiente, almacén inactivo…).
 */
function stockTransferExecute(PDO $pdo, array $datos, int $userId): array
{
    // Los campos numéricos deben ser escalares. Sin esto, un `(int)` sobre un array
    // devuelve 1 en PHP (con warning) y podría "colarse" como id de producto/almacén.
    foreach (['id_producto', 'id_origen', 'id_destino', 'cantidad'] as $campoNumerico) {
        if (isset($datos[$campoNumerico]) && !is_scalar($datos[$campoNumerico])) {
            throw new InvalidArgumentException('Datos de transferencia inválidos.');
        }
    }

    $idProducto = (int) ($datos['id_producto'] ?? 0);
    $idOrigen   = (int) ($datos['id_origen'] ?? 0);
    $idDestino  = (int) ($datos['id_destino'] ?? 0);
    $cantidad   = (int) ($datos['cantidad'] ?? 0);

    $observacion = trim((string) ($datos['observacion'] ?? ''));
    if ($observacion === '') {
        $observacion = 'Sin nota';
    }
    // Cota defensiva: movimientos_inventario.observacion es TEXT, pero no hay razón
    // para aceptar un blob arbitrario desde el formulario.
    if (function_exists('mb_substr')) {
        $observacion = mb_substr($observacion, 0, 240);
    } else {
        $observacion = substr($observacion, 0, 240);
    }

    if ($idProducto <= 0 || $idOrigen <= 0 || $idDestino <= 0 || $cantidad <= 0) {
        throw new InvalidArgumentException('Datos de transferencia inválidos.');
    }
    if ($idOrigen === $idDestino) {
        throw new InvalidArgumentException('El almacén de origen y el de destino no pueden ser el mismo.');
    }
    if ($userId <= 0) {
        throw new InvalidArgumentException('No se pudo identificar al usuario que realiza la transferencia.');
    }

    $driver   = strtolower((string) $pdo->getAttribute(PDO::ATTR_DRIVER_NAME));
    $forUpdate = $driver === 'sqlite' ? '' : ' FOR UPDATE';

    $abriTx = !$pdo->inTransaction();
    if ($abriTx) {
        $pdo->beginTransaction();
    }

    try {
        // 1. Los almacenes deben existir y estar activos.
        if (stockTransferTableExists($pdo, 'almacenes')) {
            $stmt = $pdo->prepare('SELECT id_almacen, estado FROM almacenes WHERE id_almacen IN (?, ?)');
            $stmt->execute([$idOrigen, $idDestino]);
            $estados = [];
            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $fila) {
                $estados[(int) $fila['id_almacen']] = (string) $fila['estado'];
            }
            if (!isset($estados[$idOrigen], $estados[$idDestino])) {
                throw new RuntimeException('El almacén de origen o el de destino no existe.');
            }
            if ($estados[$idOrigen] !== 'activo' || $estados[$idDestino] !== 'activo') {
                throw new RuntimeException('No se puede transferir desde o hacia un almacén inactivo.');
            }
        }

        // 2. El producto debe existir.
        if (stockTransferTableExists($pdo, 'productos')) {
            $stmt = $pdo->prepare('SELECT 1 FROM productos WHERE id_producto = ?');
            $stmt->execute([$idProducto]);
            if ($stmt->fetchColumn() === false) {
                throw new RuntimeException('El producto indicado no existe.');
            }
        }

        // 3. Bloquear la fila de ORIGEN y validar stock DISPONIBLE (descontando apartados).
        $stmt = $pdo->prepare(
            'SELECT cantidad_actual, cantidad_reservada FROM inventario_almacen
             WHERE id_producto = ? AND id_almacen = ?' . $forUpdate
        );
        $stmt->execute([$idProducto, $idOrigen]);
        $origen = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($origen === false) {
            throw new RuntimeException('El producto no tiene inventario registrado en el almacén de origen.');
        }

        $actualOrigen    = (int) $origen['cantidad_actual'];
        // Un `cantidad_reservada` negativo (dato corrupto) no debe "regalar" disponible
        // por encima de lo que realmente hay en existencia.
        $reservadaOrigen  = max(0, (int) ($origen['cantidad_reservada'] ?? 0));
        $disponibleOrigen = $actualOrigen - $reservadaOrigen;

        if ($disponibleOrigen < $cantidad) {
            throw new RuntimeException(sprintf(
                'Stock disponible insuficiente en origen. Disponible: %d (en existencia %d, apartado %d).',
                max(0, $disponibleOrigen),
                $actualOrigen,
                $reservadaOrigen
            ));
        }

        // 4. Bloquear la fila de DESTINO si ya existe y leer su valor actual.
        $stmt = $pdo->prepare(
            'SELECT cantidad_actual FROM inventario_almacen
             WHERE id_producto = ? AND id_almacen = ?' . $forUpdate
        );
        $stmt->execute([$idProducto, $idDestino]);
        $actualDestino = $stmt->fetchColumn();
        $tieneFilaDestino = $actualDestino !== false;

        // 5. Descontar de ORIGEN. Se escribe el valor absoluto ya validado: con el
        //    SELECT ... FOR UPDATE anterior nadie más puede tocar la fila hasta el
        //    commit, así que $actualOrigen sigue vigente. (No dependemos de rowCount(),
        //    que el driver PDO_SQLITE de las pruebas no reporta para UPDATE.)
        $pdo->prepare(
            'UPDATE inventario_almacen SET cantidad_actual = ?
              WHERE id_producto = ? AND id_almacen = ?'
        )->execute([$actualOrigen - $cantidad, $idProducto, $idOrigen]);

        // 6. Sumar a DESTINO.
        if ($tieneFilaDestino) {
            $pdo->prepare(
                'UPDATE inventario_almacen SET cantidad_actual = ?
                  WHERE id_producto = ? AND id_almacen = ?'
            )->execute([(int) $actualDestino + $cantidad, $idProducto, $idDestino]);
        } else {
            $pdo->prepare(
                'INSERT INTO inventario_almacen (id_producto, id_almacen, cantidad_actual)
                 VALUES (?, ?, ?)'
            )->execute([$idProducto, $idDestino, $cantidad]);
        }

        // 7. Reasignar lotes FEFO (opcional: sólo si el módulo de caducidades está activo).
        $lotesMovidos    = 0;
        $unidadesConLote = 0;
        if (stockTransferTableExists($pdo, 'lotes_inventario')) {
            $stmt = $pdo->prepare(
                "SELECT id_lote, cantidad_restante FROM lotes_inventario
                  WHERE id_producto = ? AND id_almacen = ? AND estado = 'activo' AND cantidad_restante > 0
                  ORDER BY fecha_caducidad ASC, id_lote ASC" . $forUpdate
            );
            $stmt->execute([$idProducto, $idOrigen]);
            $lotes = $stmt->fetchAll(PDO::FETCH_ASSOC);

            $porMover = $cantidad;
            $updLote  = $pdo->prepare('UPDATE lotes_inventario SET id_almacen = ? WHERE id_lote = ?');
            foreach ($lotes as $lote) {
                if ($porMover <= 0) {
                    break;
                }
                $restante = (int) $lote['cantidad_restante'];
                // No se puede partir un lote (uq_lote_producto_codigo es por producto+código,
                // sin almacén): si no cabe entero, se queda en origen y esas unidades viajan
                // "sin lote". El operador puede corregir el detalle desde Caducidades.
                if ($restante > 0 && $restante <= $porMover) {
                    $updLote->execute([$idDestino, (int) $lote['id_lote']]);
                    $porMover       -= $restante;
                    $unidadesConLote += $restante;
                    $lotesMovidos++;
                }
            }
        }

        $unidadesSinLote = max(0, $cantidad - $unidadesConLote);

        // 8. Registrar el movimiento.
        $nota = 'Transferencia: ' . $observacion;
        if ($lotesMovidos > 0 && $unidadesSinLote > 0) {
            $nota .= sprintf(' [%d u. sin detalle de lote]', $unidadesSinLote);
        }
        $pdo->prepare(
            "INSERT INTO movimientos_inventario
                (id_producto, tipo_movimiento, id_almacen_origen, id_almacen_destino, cantidad, id_usuario, observacion)
             VALUES (?, 'transferencia', ?, ?, ?, ?, ?)"
        )->execute([$idProducto, $idOrigen, $idDestino, $cantidad, $userId, $nota]);

        if ($abriTx) {
            $pdo->commit();
        }

        return [
            'cantidad'          => $cantidad,
            'disponible_origen' => $disponibleOrigen - $cantidad,
            'lotes_movidos'     => $lotesMovidos,
            'unidades_sin_lote' => $lotesMovidos > 0 ? $unidadesSinLote : 0,
        ];
    } catch (Throwable $e) {
        if ($abriTx && $pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }
}

/**
 * Transfiere varios productos entre el MISMO par de almacenes en una sola operación
 * atómica: si una línea falla, no se aplica ninguna.
 *
 * Las líneas del mismo producto se consolidan en un único movimiento.
 *
 * @param list<array{id_producto?: int|string, cantidad?: int|string}> $items
 *
 * @return array{
 *   lineas: int,
 *   unidades_totales: int,
 *   unidades_sin_lote: int,
 *   detalle: array<int, array{cantidad:int, disponible_origen:int, lotes_movidos:int, unidades_sin_lote:int}>
 * }
 *
 * @throws InvalidArgumentException
 * @throws RuntimeException
 */
function stockTransferExecuteBatch(
    PDO $pdo,
    int $idOrigen,
    int $idDestino,
    array $items,
    int $userId,
    string $observacion = ''
): array {
    if ($items === []) {
        throw new InvalidArgumentException('La lista de transferencia está vacía.');
    }
    if (count($items) > 200) {
        throw new InvalidArgumentException('Demasiadas líneas en una sola transferencia (máximo 200).');
    }

    // Consolidar líneas repetidas: {id_producto => cantidad total}.
    $porProducto = [];
    foreach ($items as $linea) {
        if (!is_array($linea)) {
            throw new InvalidArgumentException('Formato de línea inválido.');
        }
        $idRaw  = $linea['id_producto'] ?? null;
        $qtyRaw = $linea['cantidad'] ?? null;
        if (!is_scalar($idRaw) || !is_scalar($qtyRaw)) {
            throw new InvalidArgumentException('Cada línea necesita un producto válido y una cantidad mayor a cero.');
        }
        $idProducto = (int) $idRaw;
        $cantidad   = (int) $qtyRaw;
        if ($idProducto <= 0 || $cantidad <= 0) {
            throw new InvalidArgumentException('Cada línea necesita un producto válido y una cantidad mayor a cero.');
        }
        $porProducto[$idProducto] = ($porProducto[$idProducto] ?? 0) + $cantidad;
    }

    // Tope de seguridad al total consolidado: evita desbordes por sumas absurdas.
    foreach ($porProducto as $total) {
        if ($total > 1_000_000_000) {
            throw new InvalidArgumentException('La cantidad total por producto es demasiado grande.');
        }
    }

    $abriTx = !$pdo->inTransaction();
    if ($abriTx) {
        $pdo->beginTransaction();
    }

    try {
        $detalle = [];
        foreach ($porProducto as $idProducto => $cantidad) {
            $detalle[$idProducto] = stockTransferExecute($pdo, [
                'id_producto' => $idProducto,
                'id_origen'   => $idOrigen,
                'id_destino'  => $idDestino,
                'cantidad'    => $cantidad,
                'observacion' => $observacion,
            ], $userId);
        }

        if ($abriTx) {
            $pdo->commit();
        }

        return [
            'lineas'            => count($detalle),
            'unidades_totales'  => array_sum(array_column($detalle, 'cantidad')),
            'unidades_sin_lote' => array_sum(array_column($detalle, 'unidades_sin_lote')),
            'detalle'           => $detalle,
        ];
    } catch (Throwable $e) {
        if ($abriTx && $pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }
}
