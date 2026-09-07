<?php
declare(strict_types=1);

/**
 * Utilidades para la vista "Contactos de WhatsApp por dia" (views/whatsapp_contactos.php):
 * agrupa por dia quien nos escribio por WhatsApp (Alex) y deja leer el hilo completo,
 * para poder cotejar un pedido con lo que el cliente realmente dijo.
 *
 * Las funciones de presentacion son puras (testeables sin BD). Las de consulta reciben
 * el PDO y solo leen.
 */

require_once __DIR__ . '/ai_assistant.php';

/**
 * Nombre a mostrar para un contacto: primero el nombre de cliente ya ligado (si lo hay),
 * si no el nombre de perfil de WhatsApp, y si no hay ninguno un texto generico.
 * Recibe valores YA en claro (el caller descifra el nombre del cliente).
 */
function waContactoNombre(?string $nombrePerfil, ?string $clienteNombre): string
{
    $cliente = trim((string) $clienteNombre);
    if ($cliente !== '') {
        return $cliente;
    }
    $perfil = trim((string) $nombrePerfil);
    if ($perfil !== '') {
        return $perfil;
    }
    return 'Contacto sin nombre';
}

/**
 * Subtitulo de un contacto: el telefono formateado, o el aviso de que WhatsApp no
 * comparte el numero (conversaciones "LID").
 */
function waContactoSubtitulo(string $waId): string
{
    $telefono = aiWaIdToDisplayPhone($waId);
    return $telefono ?? 'Sin numero (WhatsApp no lo comparte)';
}

/**
 * Etiqueta legible del rol de un mensaje del historial.
 */
function waRolEtiqueta(string $rol): string
{
    switch ($rol) {
        case 'user':
            return 'Cliente';
        case 'assistant':
            return 'Alex';
        case 'humano':
            return 'Asesor';
        case 'tool':
            return 'Herramienta';
        case 'system':
            return 'Sistema';
        default:
            return $rol;
    }
}

/**
 * True si el mensaje lo escribio el cliente (para alinearlo a un lado en el hilo).
 */
function waRolEsCliente(string $rol): bool
{
    return $rol === 'user';
}

/**
 * Normaliza el rango de fechas de los filtros. Devuelve [desde, hasta] en Y-m-d.
 * Si no vienen o son invalidas, usa los ultimos 7 dias. Tope de 92 dias para no
 * barrer toda la tabla de mensajes.
 */
function waContactosRangoFechas(?string $desde, ?string $hasta): array
{
    $hoy = new DateTimeImmutable('today');

    $d = waContactosParseFecha($desde);
    $h = waContactosParseFecha($hasta);

    if ($h === null) {
        $h = $hoy;
    }
    if ($d === null) {
        $d = $h->sub(new DateInterval('P6D'));
    }
    if ($d > $h) {
        [$d, $h] = [$h, $d];
    }
    if ($d < $h->sub(new DateInterval('P92D'))) {
        $d = $h->sub(new DateInterval('P92D'));
    }

    return [$d->format('Y-m-d'), $h->format('Y-m-d')];
}

function waContactosParseFecha(?string $valor): ?DateTimeImmutable
{
    $valor = trim((string) $valor);
    if ($valor === '' || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $valor)) {
        return null;
    }
    $d = DateTimeImmutable::createFromFormat('!Y-m-d', $valor);
    return $d ?: null;
}

/**
 * Un renglon por (conversacion, dia) en que el cliente escribio al menos un mensaje,
 * dentro del rango [desde, hasta] (Y-m-d, inclusivo). Ordenado por dia desc y, dentro
 * del dia, por hora del primer mensaje.
 *
 * $q filtra por nombre de perfil o por digitos del numero.
 */
function waContactosPorDia(PDO $pdo, string $desde, string $hasta, string $q = '', int $limite = 800): array
{
    $sql = "SELECT
                DATE(m.creado_en)   AS dia,
                c.id_conversacion,
                c.wa_id,
                c.nombre_perfil,
                c.id_cliente,
                c.estado_bot,
                c.motivo_transferencia,
                cl.nombre           AS cliente_nombre_cifrado,
                MIN(m.creado_en)    AS primer_mensaje,
                MAX(m.creado_en)    AS ultimo_mensaje,
                COUNT(*)            AS mensajes_cliente
            FROM whatsapp_mensajes m
            INNER JOIN whatsapp_conversaciones c ON c.id_conversacion = m.id_conversacion
            LEFT JOIN clientes cl ON cl.id_cliente = c.id_cliente
            WHERE m.rol = 'user'
              AND m.creado_en BETWEEN :desde AND :hasta";

    $params = [
        ':desde' => $desde . ' 00:00:00',
        ':hasta' => $hasta . ' 23:59:59',
    ];

    $q = trim($q);
    if ($q !== '') {
        $digits = preg_replace('/\D+/', '', $q) ?? '';
        if ($digits !== '') {
            $sql .= ' AND (c.nombre_perfil LIKE :q OR c.wa_id LIKE :qd)';
            $params[':q'] = '%' . $q . '%';
            $params[':qd'] = '%' . $digits . '%';
        } else {
            $sql .= ' AND c.nombre_perfil LIKE :q';
            $params[':q'] = '%' . $q . '%';
        }
    }

    $sql .= " GROUP BY dia, c.id_conversacion, c.wa_id, c.nombre_perfil, c.id_cliente,
                       c.estado_bot, c.motivo_transferencia, cl.nombre
              ORDER BY dia DESC, primer_mensaje ASC
              LIMIT " . max(1, min(5000, $limite));

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);

    return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

/**
 * Agrupa las filas de waContactosPorDia() en un mapa 'Y-m-d' => filas[].
 */
function waAgruparPorDia(array $filas): array
{
    $porDia = [];
    foreach ($filas as $fila) {
        $dia = (string) ($fila['dia'] ?? '');
        if ($dia === '') {
            continue;
        }
        $porDia[$dia][] = $fila;
    }
    return $porDia;
}

/**
 * Datos de cabecera de una conversacion para el modo detalle. null si no existe.
 */
function waConversacionInfo(PDO $pdo, int $idConversacion): ?array
{
    if ($idConversacion <= 0) {
        return null;
    }
    $stmt = $pdo->prepare(
        'SELECT c.id_conversacion, c.wa_id, c.nombre_perfil, c.id_cliente, c.estado_bot,
                c.motivo_transferencia, c.creado_en, c.ultimo_mensaje_en, cl.nombre AS cliente_nombre_cifrado
         FROM whatsapp_conversaciones c
         LEFT JOIN clientes cl ON cl.id_cliente = c.id_cliente
         WHERE c.id_conversacion = ?'
    );
    $stmt->execute([$idConversacion]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    return $row ?: null;
}

/**
 * Historial completo de una conversacion, en orden cronologico.
 */
function waConversacionMensajes(PDO $pdo, int $idConversacion): array
{
    if ($idConversacion <= 0) {
        return [];
    }
    $stmt = $pdo->prepare(
        'SELECT id_mensaje, rol, contenido, tool_name, enviado_whatsapp, creado_en
         FROM whatsapp_mensajes
         WHERE id_conversacion = ?
         ORDER BY id_mensaje ASC'
    );
    $stmt->execute([$idConversacion]);

    return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
}
