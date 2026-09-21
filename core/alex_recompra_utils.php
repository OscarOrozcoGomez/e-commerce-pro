<?php
declare(strict_types=1);

/**
 * Recompra proactiva: a un cliente que ya compro un producto que HOY esta en oferta por
 * caducidad, y al que segun la dosis sugerida ya se le esta terminando el envase, Alex le
 * avisa por WhatsApp. Es la forma mas natural de mover producto por caducar: un cliente que
 * ya conoce el producto y va a necesitarlo.
 *
 * ATENCION: esto es CONTACTO NO SOLICITADO por el puente no oficial de WhatsApp (ver la
 * seccion "WhatsApp/Alex: nunca rafagas" de CLAUDE.md). Por eso:
 *   - va APAGADO por defecto: solo corre si el cron se invoca con --recompra-ofertas;
 *   - comparte el cupo del seguimiento de 24h (aiPuedeEnviarProactivoAhora(): maximo UN
 *     mensaje proactivo por hora entre los dos) y el cron manda como maximo uno por corrida;
 *   - tiene su propio tope diario (AI_RECOMPRA_MAX_POR_DIA) y horario diurno;
 *   - jamas repite un texto: lo genera el modelo con el historial real, y si la generacion
 *     falla NO se manda un texto fijo de respaldo (un mensaje identico a varios clientes es
 *     justo el patron que bloqueo la cuenta el 2026-09-13);
 *   - nunca le escribe a alguien con quien se hablo en los ultimos AI_RECOMPRA_SILENCIO_DIAS
 *     dias, ni dos veces en AI_RECOMPRA_REPETIR_DIAS dias.
 */

require_once __DIR__ . '/ai_assistant.php';

const AI_RECOMPRA_MAX_POR_DIA = 4;
const AI_RECOMPRA_HORA_INICIO = 9;   // 9:00 am (mas estricto que el horario de atencion de Alex)
const AI_RECOMPRA_HORA_FIN = 20;     // 8:00 pm (exclusivo)
const AI_RECOMPRA_ANTICIPACION_DIAS = 10; // avisar hasta 10 dias antes de que se le termine
const AI_RECOMPRA_GRACIA_DIAS = 30;       // y hasta 30 dias despues
const AI_RECOMPRA_REPETIR_DIAS = 45;      // maximo una recompra por cliente en este lapso
const AI_RECOMPRA_SILENCIO_DIAS = 7;      // conversacion sin ningun mensaje en este lapso
const AI_RECOMPRA_INTENTOS_POR_CORRIDA = 3; // candidatos a probar si el modelo no genera texto valido

/** True si $ahora cae en la ventana diurna de recompras. */
function aiRecompraEnHorario(?DateTimeImmutable $ahora = null): bool
{
    $ahora ??= new DateTimeImmutable('now');
    $hora = (int)$ahora->format('G');

    return $hora >= AI_RECOMPRA_HORA_INICIO && $hora < AI_RECOMPRA_HORA_FIN;
}

/**
 * Fecha estimada en que se le termina lo que compro: fecha de compra + dias por envase * piezas.
 * Pura (sin DB) para probarse. Solo se llama con productos que tienen capsulas por envase Y
 * porcion capturadas: nunca se asume una dosis.
 */
function aiRecompraFechaFinTratamiento(string $fechaCompra, int $unidades, int $diasPorEnvase): string
{
    $dias = max(1, $unidades) * max(1, $diasPorEnvase);

    return (new DateTimeImmutable(substr($fechaCompra, 0, 10)))->modify("+{$dias} days")->format('Y-m-d');
}

/** True si $hoy cae en [fin - anticipacion, fin + gracia]. Pura. */
function aiRecompraEnVentana(string $fechaFin, DateTimeImmutable $hoy): bool
{
    $fin = new DateTimeImmutable($fechaFin);
    $desde = $fin->modify('-' . AI_RECOMPRA_ANTICIPACION_DIAS . ' days')->setTime(0, 0);
    $hasta = $fin->modify('+' . AI_RECOMPRA_GRACIA_DIAS . ' days')->setTime(23, 59, 59);

    return $hoy >= $desde && $hoy <= $hasta;
}

/**
 * True si Alex puede mandar una recompra AHORA: ventana diurna, cupo de 1/hora libre (el mismo
 * del seguimiento de 24h) y por debajo del tope diario.
 */
function aiPuedeEnviarRecompraAhora(PDO $pdo, ?DateTimeImmutable $ahora = null): bool
{
    return aiRecompraEnHorario($ahora)
        && aiPuedeEnviarProactivoAhora($pdo, $ahora)
        && alexOfertaContarHoy($pdo, ALEX_OFERTA_EVENTO_RECOMPRA) < AI_RECOMPRA_MAX_POR_DIA;
}

/**
 * Candidatos de recompra, el mas conveniente primero (oferta mas urgente, luego al que primero
 * se le termina). Cada uno trae: id_cliente, id_conversacion, wa_id, nombre_perfil, oferta (fila
 * de aiListarOfertasVigentes), fecha_ultima_compra, fecha_fin_tratamiento, unidades.
 *
 * Solo entran ofertas con un motivo real de caducidad (urgencia no nula): la recompra existe
 * para mover producto por caducar, no para promocionar cualquier descuento.
 *
 * @return array<int,array<string,mixed>>
 */
function aiFindRecompraCandidatos(PDO $pdo, ?DateTimeImmutable $ahora = null): array
{
    $ahora ??= new DateTimeImmutable('now');

    $ofertas = [];
    foreach (aiListarOfertasVigentes($pdo) as $oferta) {
        if (($oferta['urgencia'] ?? null) !== null) {
            $ofertas[(int)$oferta['id_producto']] = $oferta;
        }
    }
    if ($ofertas === []) {
        return [];
    }

    $ids = array_keys($ofertas);
    $ph = implode(',', array_fill(0, count($ids), '?'));

    // Dias que rinde un envase, solo si la dosis esta capturada (capsulas Y porcion).
    $stmt = $pdo->prepare("SELECT id_producto, capsulas_por_envase, porcion_capsulas FROM productos WHERE id_producto IN ($ph)");
    $stmt->execute($ids);
    $diasPorEnvase = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $p) {
        $caps = (int)($p['capsulas_por_envase'] ?? 0);
        $porcion = (int)($p['porcion_capsulas'] ?? 0);
        if ($caps > 0 && $porcion > 0) {
            $diasPorEnvase[(int)$p['id_producto']] = intdiv($caps, $porcion);
        }
    }
    if ($diasPorEnvase === []) {
        return [];
    }

    $where = ["dp.id_producto IN ($ph)", 'pe.id_cliente IS NOT NULL', "pe.estado <> 'cancelado'"];
    if (loteColumnaExiste($pdo, 'detalle_pedidos', 'estado_entrega')) {
        $where[] = "dp.estado_entrega = 'entregado'";
    }
    $stmt = $pdo->prepare(
        'SELECT pe.id_cliente, dp.id_producto, pe.id_pedido, pe.fecha_creacion, dp.cantidad
         FROM detalle_pedidos dp JOIN pedidos pe ON pe.id_pedido = dp.id_pedido
         WHERE ' . implode(' AND ', $where) . '
         ORDER BY pe.fecha_creacion DESC, pe.id_pedido DESC'
    );
    $stmt->execute($ids);

    // Ultima compra de cada (cliente, producto): la primera fila vista (orden DESC) y las demas
    // lineas de ese mismo pedido.
    $ultimas = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $fila) {
        $idProducto = (int)$fila['id_producto'];
        if (!isset($diasPorEnvase[$idProducto])) {
            continue;
        }
        $llave = (int)$fila['id_cliente'] . ':' . $idProducto;
        if (!isset($ultimas[$llave])) {
            $ultimas[$llave] = ['id_cliente' => (int)$fila['id_cliente'], 'id_producto' => $idProducto, 'id_pedido' => (int)$fila['id_pedido'], 'fecha' => (string)$fila['fecha_creacion'], 'unidades' => 0];
        }
        if ($ultimas[$llave]['id_pedido'] === (int)$fila['id_pedido']) {
            $ultimas[$llave]['unidades'] += max(0, (int)$fila['cantidad']);
        }
    }

    $candidatos = [];
    foreach ($ultimas as $u) {
        $fin = aiRecompraFechaFinTratamiento($u['fecha'], $u['unidades'], $diasPorEnvase[$u['id_producto']]);
        if (!aiRecompraEnVentana($fin, $ahora)) {
            continue;
        }

        $conv = aiRecompraConversacionElegible($pdo, $u['id_cliente'], $u['id_producto'], $ahora);
        if ($conv === null) {
            continue;
        }

        $candidatos[] = [
            'id_cliente' => $u['id_cliente'],
            'id_conversacion' => (int)$conv['id_conversacion'],
            'wa_id' => (string)$conv['wa_id'],
            'nombre_perfil' => $conv['nombre_perfil'] ?? null,
            'oferta' => $ofertas[$u['id_producto']],
            'fecha_ultima_compra' => substr($u['fecha'], 0, 10),
            'fecha_fin_tratamiento' => $fin,
            'unidades' => $u['unidades'],
        ];
    }

    // Un cliente puede calificar por varios productos: solo se le escribe por el mas urgente.
    usort($candidatos, static fn(array $a, array $b): int => [ofertaCadUrgenciaRank($b['oferta']['urgencia'] ?? null), $a['fecha_fin_tratamiento']] <=> [ofertaCadUrgenciaRank($a['oferta']['urgencia'] ?? null), $b['fecha_fin_tratamiento']]);
    $vistos = [];

    return array_values(array_filter($candidatos, static function (array $c) use (&$vistos): bool {
        if (isset($vistos[$c['id_cliente']])) {
            return false;
        }
        $vistos[$c['id_cliente']] = true;

        return true;
    }));
}

/**
 * La conversacion de WhatsApp del cliente si (y solo si) es seguro escribirle por recompra:
 * bot activo, sin seguimiento en curso, fuera de "Fuera de Cobertura", lada local, conversacion
 * en silencio hace AI_RECOMPRA_SILENCIO_DIAS dias o mas y sin otra recompra en
 * AI_RECOMPRA_REPETIR_DIAS dias.
 *
 * @return array{id_conversacion:int,wa_id:string,nombre_perfil:?string}|null
 */
function aiRecompraConversacionElegible(PDO $pdo, int $idCliente, int $idProducto, DateTimeImmutable $ahora): ?array
{
    $stmt = $pdo->prepare(
        "SELECT c.id_conversacion, c.wa_id, c.nombre_perfil,
                (SELECT MAX(m.creado_en) FROM whatsapp_mensajes m WHERE m.id_conversacion = c.id_conversacion) AS ultimo_mensaje
         FROM whatsapp_conversaciones c
         WHERE c.id_cliente = ? AND c.estado_bot = 'activo' AND c.seguimiento_enviado_en IS NULL
           AND NOT EXISTS (
               SELECT 1 FROM whatsapp_conversacion_etiquetas ce
               INNER JOIN whatsapp_etiquetas e ON e.id_etiqueta = ce.id_etiqueta
               WHERE ce.id_conversacion = c.id_conversacion AND e.nombre = ?
           )
         ORDER BY c.id_conversacion"
    );
    $stmt->execute([$idCliente, AI_TAG_FUERA_COBERTURA]);

    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $conv) {
        if (aiPhoneHasLocalLada((string)($conv['wa_id'] ?? '')) === false) {
            continue; // foraneo confirmado: no hacemos entregas fuera de la ZMG
        }

        $ultimo = strtotime((string)($conv['ultimo_mensaje'] ?? ''));
        if ($ultimo !== false && $ultimo > $ahora->getTimestamp() - AI_RECOMPRA_SILENCIO_DIAS * 86400) {
            continue; // se hablo hace poco: no es momento de escribirle por otro motivo
        }

        if (aiRecompraEnviadaRecientemente($pdo, $idCliente, (int)$conv['id_conversacion'], $ahora)) {
            continue;
        }

        return ['id_conversacion' => (int)$conv['id_conversacion'], 'wa_id' => (string)$conv['wa_id'], 'nombre_perfil' => $conv['nombre_perfil'] ?? null];
    }

    return null;
}

/** True si a este cliente/conversacion ya se le mando una recompra en los ultimos AI_RECOMPRA_REPETIR_DIAS dias. */
function aiRecompraEnviadaRecientemente(PDO $pdo, int $idCliente, int $idConversacion, DateTimeImmutable $ahora): bool
{
    if (!loteTablaExiste($pdo, 'alex_oferta_eventos')) {
        return false;
    }

    $desde = $ahora->modify('-' . AI_RECOMPRA_REPETIR_DIAS . ' days')->format('Y-m-d H:i:s');
    $stmt = $pdo->prepare(
        'SELECT 1 FROM alex_oferta_eventos
         WHERE tipo = ? AND creado_en >= ? AND (id_cliente = ? OR id_conversacion = ?) LIMIT 1'
    );
    $stmt->execute([ALEX_OFERTA_EVENTO_RECOMPRA, $desde, $idCliente, $idConversacion]);

    return $stmt->fetchColumn() !== false;
}

/**
 * Texto de la recompra, generado por el modelo con el historial real y los datos verificados de
 * la oferta. Regresa '' si el modelo no responde o si el texto no menciona el precio (un aviso
 * sin el dato no sirve): en ese caso NO se manda nada, nunca un texto fijo de respaldo.
 */
function aiGenerarTextoRecompraUnico(PDO $pdo, array $candidato, array $config): string
{
    try {
        $oferta = $candidato['oferta'];
        $historial = aiLoadConversationHistory($pdo, (int)$candidato['id_conversacion']);

        $modelo = trim((string)($config['modelo_llm'] ?? '')) !== '' ? (string)$config['modelo_llm'] : 'deepseek-chat';
        $apiKeyVariable = trim((string)($config['api_key_variable'] ?? '')) !== '' ? (string)$config['api_key_variable'] : 'DEEPSEEK_AI_ASSISTANT';
        $persona = trim((string)($config['nombre_persona'] ?? '')) !== '' ? trim((string)$config['nombre_persona']) : 'Alex';

        $instruccion = [
            'role' => 'system',
            'content' => "Eres {$persona}, asistente de ventas de WhatsApp de Belleza y Bienestar. Este cliente ya nos compro antes \"{$oferta['nombre']}\" (el " . $candidato['fecha_ultima_compra'] . ") y, con la dosis sugerida por la marca, su envase esta por terminarse o ya se termino. Hoy ese mismo producto esta en oferta. Escribe UN mensaje breve (2-3 lineas), calido y sin presion, como un aviso util para que no se quede sin su producto: dile que ese producto esta en oferta y ofrecele apartarselo. Incluye el precio de oferta."
                . aiBuildDatoOfertaParaMensaje($oferta)
                . ' Nunca uses las palabras "recomendar" ni "te recomiendo". No afirmes que ya se le acabo (solo que puede estar por terminarse). Varia la redaccion cada vez. No uses markdown web ni firmes el mensaje. Responde SOLO con el texto del mensaje, nada mas.',
        ];

        // La instruccion va primero (como en el seguimiento de 24h) pero se cierra con una indicacion
        // al final: en prueba real, con un historial que terminaba en un "de nada, aqui estoy", el
        // modelo a veces seguia esa charla en vez de escribir el aviso (y sin precio se descarta).
        $cierre = ['role' => 'user', 'content' => '[Indicacion interna, no es un mensaje del cliente] Escribe ahora el aviso de recompra para este cliente, siguiendo tus instrucciones: menciona que el producto esta en oferta e incluye el precio. Responde solo con el texto del mensaje.'];
        $respuesta = aiCallDeepSeek(array_merge([$instruccion], $historial, [$cierre]), [], $modelo, 0.9, $apiKeyVariable);
        $texto = trim((string)($respuesta['message']['content'] ?? ''));
        if ($texto === '' || mb_strpos($texto, (string)(int)round((float)$oferta['precio_oferta'])) === false) {
            return '';
        }

        return aiSanitizePlainTextForWhatsapp($texto);
    } catch (Throwable $e) {
        error_log('WARNING: no se pudo generar el texto de recompra: ' . $e->getMessage());

        return '';
    }
}

/**
 * Manda la recompra a un candidato y la deja registrada (historial de la conversacion + evento).
 * Regresa true si el mensaje salio de verdad. Con texto vacio no manda nada y regresa false.
 * Quien la llama registra el cupo proactivo (aiRegistrarEnvioProactivo()).
 */
function aiSendRecompraMessage(PDO $pdo, array $candidato, string $texto): bool
{
    $texto = trim($texto);
    $idConversacion = (int)($candidato['id_conversacion'] ?? 0);
    $waId = (string)($candidato['wa_id'] ?? '');
    if ($texto === '' || $idConversacion <= 0 || $waId === '') {
        return false;
    }

    $resultado = waSendOutboundMessage($waId, [['type' => 'text', 'text' => $texto]]);
    $ok = (bool)($resultado['ok'] ?? false);

    aiAppendMessage($pdo, $idConversacion, 'assistant', $texto, null, null, null, null, $ok);

    if ($ok) {
        $oferta = $candidato['oferta'];
        alexOfertaRegistrarEvento($pdo, ALEX_OFERTA_EVENTO_RECOMPRA, (int)$oferta['id_producto'], [
            'id_conversacion' => $idConversacion,
            'id_cliente' => $candidato['id_cliente'],
            'precio_unitario' => $oferta['precio_oferta'],
            'precio_normal' => $oferta['precio_normal'],
            'severidad' => $oferta['_severidad'] ?? null,
        ]);
    }

    return $ok;
}
