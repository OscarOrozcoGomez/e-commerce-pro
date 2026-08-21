<?php
declare(strict_types=1);

/**
 * Descifra el nombre de un cliente si viene cifrado (PII), con un valor de respaldo. Punto de
 * entrada unico para este dato en este archivo: nunca debe mostrarse el valor crudo de SQL.
 */
function alexInsightsDecryptClienteNombre(?string $raw, string $fallback = 'Cliente'): string
{
    $valor = trim((string)$raw);
    if ($valor === '') {
        return $fallback;
    }
    if (function_exists('piiIsEncryptedValue') && function_exists('piiDecryptValue') && piiIsEncryptedValue($valor)) {
        $valor = trim((string)piiDecryptValue($valor));
    }
    return $valor !== '' ? $valor : $fallback;
}

/**
 * Cancelaciones hechas por el repartidor ("No pude entregar"), guardadas como texto libre en
 * pedidos.observaciones (no hay tabla dedicada, a diferencia de la cancelacion del cliente en
 * pedido_cancelaciones). Excluye pedidos que ya tengan fila ahi, para no duplicar si algun dia
 * ambos flujos coinciden en el mismo pedido.
 */
function alexInsightsGetRepartidorCancellations(PDO $pdo): array
{
    $stmt = $pdo->query(
        "SELECT p.id_pedido, p.numero_pedido, p.total, p.fecha_creacion, cl.nombre AS cliente_nombre_raw,
                SUBSTRING(p.observaciones, LOCATE('ENTREGA_NO_REALIZADA:', p.observaciones) + LENGTH('ENTREGA_NO_REALIZADA:')) AS motivo_raw
         FROM pedidos p
         LEFT JOIN clientes cl ON cl.id_cliente = p.id_cliente
         LEFT JOIN pedido_cancelaciones pc ON pc.id_pedido = p.id_pedido
         WHERE p.estado = 'cancelado'
           AND p.observaciones LIKE '%ENTREGA_NO_REALIZADA:%'
           AND pc.id_pedido IS NULL
         ORDER BY p.fecha_creacion DESC"
    );
    $rows = $stmt ? $stmt->fetchAll(PDO::FETCH_ASSOC) : [];

    foreach ($rows as &$row) {
        $motivo = trim((string)$row['motivo_raw']);
        // El marcador se agrega siempre al final de observaciones hoy, pero por si acaso algo
        // se concatenara despues, se corta en el siguiente separador " | ".
        $pipePos = strpos($motivo, ' | ');
        if ($pipePos !== false) {
            $motivo = trim(substr($motivo, 0, $pipePos));
        }
        $row['motivo'] = $motivo !== '' ? $motivo : 'Sin detalle';
        $row['cliente_nombre'] = alexInsightsDecryptClienteNombre($row['cliente_nombre_raw'] ?? null);
        unset($row['motivo_raw'], $row['cliente_nombre_raw']);
    }
    unset($row);

    return $rows;
}

/**
 * Extrae una referencia de pedido de un texto libre: numero_pedido tipo "WEB-XXXX", o un
 * id_pedido suelto escrito como "#45". Regresa null si no se encuentra nada reconocible.
 *
 * @return array{tipo: string, valor: string}|null
 */
function alexInsightsExtractOrderReference(string $texto): ?array
{
    if (preg_match('/WEB-[A-Za-z0-9]+/', $texto, $m)) {
        return ['tipo' => 'numero', 'valor' => $m[0]];
    }
    if (preg_match('/#(\d+)/', $texto, $m)) {
        return ['tipo' => 'id', 'valor' => $m[1]];
    }
    return null;
}

/**
 * Busca el pedido referido por una referencia ya extraida (ver alexInsightsExtractOrderReference).
 *
 * @return array{id_pedido: int, numero_pedido: string, estado: string}|null
 */
function alexInsightsResolveOrderReference(PDO $pdo, array $referencia): ?array
{
    if ($referencia['tipo'] === 'numero') {
        $stmt = $pdo->prepare('SELECT id_pedido, numero_pedido, estado FROM pedidos WHERE numero_pedido = ? LIMIT 1');
        $stmt->execute([$referencia['valor']]);
    } else {
        $stmt = $pdo->prepare('SELECT id_pedido, numero_pedido, estado FROM pedidos WHERE id_pedido = ? LIMIT 1');
        $stmt->execute([(int)$referencia['valor']]);
    }
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return $row ?: null;
}

/**
 * Palabras clave que sugieren que un motivo_transferencia es sobre CANCELAR un pedido.
 * Deteccion simple en PHP (nunca se llama a DeepSeek de nuevo) sobre texto que Alex ya
 * escribio al usar la tool transferir_a_humano.
 */
function alexInsightsLooksLikeCancellation(string $texto): bool
{
    $normalizado = mb_strtolower($texto);
    // motivo_transferencia lo escribe Alex resumiendo la intencion del cliente, casi siempre en
    // tercera persona ("Cliente solicita cancelar...", "ya no quiere el pedido"), por eso se
    // cubren ambas formas (quiero/quiere) en vez de asumir una sola persona gramatical.
    foreach (['cancel', 'anular', 'ya no quiero', 'ya no lo quiero', 'ya no quiere', 'ya no lo quiere'] as $palabra) {
        if (mb_strpos($normalizado, $palabra) !== false) {
            return true;
        }
    }
    return false;
}

/**
 * Nombre para mostrar de una conversacion: nombre_perfil de WhatsApp si viene lleno, si no el
 * wa_id. nombre_perfil puede venir NULL (no solo string vacio), por eso no basta comparar con ''.
 */
function alexInsightsContactoLabel(array $conv): string
{
    $nombrePerfil = trim((string)($conv['nombre_perfil'] ?? ''));
    return $nombrePerfil !== '' ? $nombrePerfil : (string)($conv['wa_id'] ?? 'N/D');
}

/**
 * Trae todas las conversaciones con un motivo_transferencia guardado. OJO: esta columna se
 * sobreescribe con NULL cuando un admin reactiva el bot (ver api/ai_assistant_admin.php), asi
 * que esta es siempre una vista parcial -- solo conversaciones que siguen pausadas/sin atender
 * conservan el motivo.
 */
function alexInsightsGetConversationsWithTransferReason(PDO $pdo): array
{
    $stmt = $pdo->query(
        "SELECT id_conversacion, wa_id, nombre_perfil, motivo_transferencia, actualizado_en
         FROM whatsapp_conversaciones
         WHERE motivo_transferencia IS NOT NULL AND motivo_transferencia <> ''
         ORDER BY actualizado_en DESC"
    );
    return $stmt ? $stmt->fetchAll(PDO::FETCH_ASSOC) : [];
}

/**
 * Separa las senales de cancelacion detectadas por Alex en 3 grupos:
 * - confirmadas: se identifico el pedido y SI quedo cancelado (se muestran junto al historial
 *   normal de cancelaciones.pedido, sin sumarse al total duro por separado).
 * - resueltas_no_canceladas: se identifico el pedido pero NO quedo cancelado (posible "salvada",
 *   vale la pena que el admin lo vea aunque no cuente como cancelacion real).
 * - sin_confirmar: no se pudo identificar un pedido real -- solo la intencion expresada.
 */
function alexInsightsGetAlexCancellationSignals(PDO $pdo): array
{
    $confirmadas = [];
    $resueltasNoCanceladas = [];
    $sinConfirmar = [];

    foreach (alexInsightsGetConversationsWithTransferReason($pdo) as $conv) {
        $motivo = (string)$conv['motivo_transferencia'];
        if (!alexInsightsLooksLikeCancellation($motivo)) {
            continue;
        }

        $referencia = alexInsightsExtractOrderReference($motivo);
        $pedido = $referencia !== null ? alexInsightsResolveOrderReference($pdo, $referencia) : null;

        $item = [
            'id_conversacion' => (int)$conv['id_conversacion'],
            'contacto' => alexInsightsContactoLabel($conv),
            'motivo' => $motivo,
            'fecha' => $conv['actualizado_en'],
            'pedido' => $pedido,
        ];

        if ($pedido !== null && (string)$pedido['estado'] === 'cancelado') {
            $confirmadas[] = $item;
        } elseif ($pedido !== null) {
            $resueltasNoCanceladas[] = $item;
        } else {
            $sinConfirmar[] = $item;
        }
    }

    return [
        'confirmadas' => $confirmadas,
        'resueltas_no_canceladas' => $resueltasNoCanceladas,
        'sin_confirmar' => $sinConfirmar,
    ];
}

/**
 * Motivos de transferencia a humano que NO parecen ser sobre cancelar un pedido, agrupados por
 * categoria simple via palabras clave (nunca se llama a DeepSeek para esto). El orden de
 * evaluacion importa: "sistema"/"seguridad" se revisan primero porque son inequivocos y no deben
 * confundirse con una senal real de venta/objecion del cliente.
 *
 * @return array<string, array<int, array{contacto: string, motivo: string, fecha: mixed}>>
 */
function alexInsightsGetNonCancellationSignals(PDO $pdo): array
{
    $agrupado = [
        'sistema' => [],
        'seguridad' => [],
        'descuento' => [],
        'friccion_checkout' => [],
        'otro' => [],
    ];

    foreach (alexInsightsGetConversationsWithTransferReason($pdo) as $conv) {
        $motivo = (string)$conv['motivo_transferencia'];
        if (alexInsightsLooksLikeCancellation($motivo)) {
            continue; // ya se muestra en cancelaciones_pedidos.php
        }

        $agrupado[alexInsightsClassifyNonCancellationReason($motivo)][] = [
            'contacto' => alexInsightsContactoLabel($conv),
            'motivo' => $motivo,
            'fecha' => $conv['actualizado_en'],
        ];
    }

    return $agrupado;
}

/**
 * Clasifica un motivo_transferencia (que ya se determino NO es sobre cancelar) en una
 * categoria simple por palabras clave. Separada de alexInsightsGetNonCancellationSignals()
 * para poder probarla sin base de datos. El orden de evaluacion importa: "sistema"/"seguridad"
 * se revisan primero porque son inequivocos y no deben confundirse con una senal real de
 * venta/objecion del cliente.
 */
function alexInsightsClassifyNonCancellationReason(string $motivo): string
{
    $categorias = [
        'sistema' => ['alex incluyo la bandera', 'intervencion manual detectada', 'cerrado automaticamente'],
        'seguridad' => ['administrador', 'credenciales', 'contrasena', 'contraseña', 'acceso al sistema'],
        'descuento' => ['descuento', 'mayoreo', 'oferta'],
        'friccion_checkout' => ['falta su direccion', 'falta la direccion', 'direccion completa', 'no pudo pagar'],
    ];

    $normalizado = mb_strtolower($motivo);
    foreach ($categorias as $categoria => $palabras) {
        foreach ($palabras as $palabra) {
            if (mb_strpos($normalizado, $palabra) !== false) {
                return $categoria;
            }
        }
    }

    return 'otro';
}

/**
 * Terminos de busqueda mas frecuentes que los clientes le piden a Alex via la tool
 * consultar_inventario -- senal de demanda, extraida de tool_calls_json que DeepSeek ya
 * regreso al ejecutar la conversacion (cero llamadas nuevas a la API).
 *
 * @return array<string, int> termino => conteo, ordenado de mayor a menor
 */
function alexInsightsGetTopInventoryQueries(PDO $pdo, int $limite = 15): array
{
    $stmt = $pdo->query(
        "SELECT tool_calls_json FROM whatsapp_mensajes
         WHERE rol = 'assistant' AND tool_calls_json LIKE '%consultar_inventario%'"
    );
    $rows = $stmt ? $stmt->fetchAll(PDO::FETCH_ASSOC) : [];

    $conteos = [];
    foreach ($rows as $row) {
        $llamadas = json_decode((string)$row['tool_calls_json'], true);
        if (!is_array($llamadas)) {
            continue;
        }
        foreach ($llamadas as $llamada) {
            if (($llamada['function']['name'] ?? '') !== 'consultar_inventario') {
                continue;
            }
            $args = json_decode((string)($llamada['function']['arguments'] ?? '{}'), true);
            $termino = is_array($args) ? trim(mb_strtolower((string)($args['busqueda_texto'] ?? ''))) : '';
            if ($termino !== '') {
                $conteos[$termino] = ($conteos[$termino] ?? 0) + 1;
            }
        }
    }

    arsort($conteos);
    return array_slice($conteos, 0, $limite, true);
}

/**
 * Embudo de ventas hechas por Alex: intentos/exitos/fallos de la tool agendar_venta, usando
 * whatsapp_mensajes como fuente primaria (pedidos.creado_por_ia NO es confiable: se confirmo
 * que varias ventas reales de Alex no quedaron marcadas con esa columna). Cruza los exitos
 * contra el estado actual del pedido para saber si la venta se sostuvo o se cancelo despues.
 *
 * @return array{intentos: int, exitos: array<int, array<string, mixed>>, fallos: array<string, int>}
 */
function alexInsightsGetSalesFunnel(PDO $pdo): array
{
    $stmt = $pdo->query(
        "SELECT contenido FROM whatsapp_mensajes WHERE rol = 'tool' AND tool_name = 'agendar_venta'"
    );
    $rows = $stmt ? $stmt->fetchAll(PDO::FETCH_ASSOC) : [];

    $exitosIds = [];
    $fallos = [];
    foreach ($rows as $row) {
        $data = json_decode((string)$row['contenido'], true);
        if (!is_array($data)) {
            continue;
        }
        if (!empty($data['ok']) && !empty($data['id_pedido'])) {
            $exitosIds[] = (int)$data['id_pedido'];
        } elseif (empty($data['ok'])) {
            $mensaje = trim((string)($data['message'] ?? 'Sin detalle'));
            $fallos[$mensaje] = ($fallos[$mensaje] ?? 0) + 1;
        }
    }

    $pedidosExito = [];
    if (!empty($exitosIds)) {
        $placeholders = implode(',', array_fill(0, count($exitosIds), '?'));
        $stmt = $pdo->prepare("SELECT id_pedido, numero_pedido, estado, total FROM pedidos WHERE id_pedido IN ($placeholders)");
        $stmt->execute($exitosIds);
        $pedidosExito = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    arsort($fallos);

    return [
        'intentos' => count($rows),
        'exitos' => $pedidosExito,
        'fallos' => $fallos,
    ];
}

/**
 * Distribucion de etiquetas que Alex asigna solo (tool etiquetar_cliente) a las conversaciones
 * de WhatsApp -- una segmentacion de comportamiento de clientes ya lista, sin costo extra.
 */
function alexInsightsGetTagDistribution(PDO $pdo): array
{
    $stmt = $pdo->query(
        "SELECT e.nombre, e.color, COUNT(*) AS total
         FROM whatsapp_conversacion_etiquetas ce
         JOIN whatsapp_etiquetas e ON e.id_etiqueta = ce.id_etiqueta
         GROUP BY e.id_etiqueta, e.nombre, e.color
         ORDER BY total DESC"
    );
    return $stmt ? $stmt->fetchAll(PDO::FETCH_ASSOC) : [];
}
