<?php
declare(strict_types=1);

/**
 * Telefonos de clientes y de pedidos: validacion, deteccion de numeros de relleno, que numero
 * usa un pedido creado por Alex, y sincronizacion de pedidos abiertos cuando se corrige el
 * telefono de un cliente.
 *
 * Incidente que motivo esto (2026-09-16, pedido WEB-6AAB146BF1B21): el modelo de Alex relleno
 * el parametro opcional `telefono` de agendar_venta con un numero de ejemplo (3312345678) y ese
 * valor gano sobre el numero REAL del chat (telefono_resuelto). La ruta de entrega usa el
 * telefono del pedido antes que el del cliente, asi que el aviso "hora estimada por WhatsApp"
 * iba a un numero ajeno; y corregir despues el telefono del cliente no cambiaba el del pedido.
 */

require_once __DIR__ . '/pii_crypto.php';
require_once __DIR__ . '/phone_utils.php';

/**
 * Los 10 digitos de un telefono mexicano ("(331) - 030 - 1214", "3310301214", "+52 1 331 030
 * 1214"), o null si no son 10 digitos (con lada 52/521 opcional). Nunca devuelve ''.
 */
function telefonoDigitos10(?string $valor): ?string
{
    $digitos = preg_replace('/\D+/', '', (string) ($valor ?? '')) ?? '';
    if ($digitos === '') {
        return null;
    }
    if (preg_match('/^521?(\d{10})$/', $digitos, $m) === 1) {
        return $m[1];
    }

    return strlen($digitos) === 10 ? $digitos : null;
}

/**
 * ¿Parece un numero inventado o de ejemplo (3312345678, 3300000000, 5555555555)? Siete o mas
 * digitos iguales seguidos, o siete consecutivos ascendentes/descendentes. Un numero real casi
 * nunca cumple esto; un modelo de lenguaje rellenando un campo, casi siempre.
 */
function telefonoParecePlaceholder(?string $valor): bool
{
    $d = telefonoDigitos10($valor);
    if ($d === null) {
        return false;
    }

    if (preg_match('/(\d)\1{6,}/', $d) === 1) {
        return true;
    }

    for ($inicio = 0; $inicio <= 3; $inicio++) {
        $ventana = substr($d, $inicio, 7);
        $asciende = true;
        $desciende = true;
        for ($i = 1; $i < 7; $i++) {
            $diff = (int) $ventana[$i] - (int) $ventana[$i - 1];
            $asciende = $asciende && $diff === 1;
            $desciende = $desciende && $diff === -1;
        }
        if ($asciende || $desciende) {
            return true;
        }
    }

    return false;
}

/**
 * Que telefono lleva un pedido que crea Alex.
 *
 * - Si se conoce el numero REAL del chat (verificado por WhatsApp), ese es el del pedido: un
 *   numero dictado por el modelo/cliente nunca lo reemplaza.
 * - Si el numero dictado es valido y distinto, se devuelve como "alterno" (para avisarle al
 *   equipo), no como el del pedido.
 * - EXCEPCION: si el cliente PIDIO usar otro numero y el modelo lo marco ($alternoConfirmado), ese
 *   numero (valido y que no parezca de relleno) es el del pedido; el del chat queda como "alterno"
 *   informativo. Sin esa confirmacion explicita un numero dictado jamas le gana al del chat.
 * - Sin numero del chat, se usa el dictado solo si es valido y no parece de relleno.
 * - Si no hay ninguno utilizable, el telefono queda vacio: no se agenda a ciegas.
 *
 * @return array{telefono:string, origen:string, alterno:?string, descartado:?string}
 *         origen: 'chat' | 'dictado' | 'dictado_confirmado' | 'ninguno'. alterno: el otro numero conocido
 *         que NO se uso. descartado: numero dictado que parecia de relleno.
 */
function telefonoResolverParaPedido(?string $dictado, ?string $telefonoChat, bool $alternoConfirmado = false): array
{
    $chat = telefonoDigitos10($telefonoChat);
    $dictadoDigitos = telefonoDigitos10($dictado);
    $dictadoUtil = $dictadoDigitos !== null && !telefonoParecePlaceholder($dictadoDigitos);
    $descartado = ($dictadoDigitos !== null && !$dictadoUtil) ? $dictadoDigitos : null;

    if ($chat !== null && $alternoConfirmado && $dictadoUtil && $dictadoDigitos !== $chat) {
        return ['telefono' => (string) $dictadoDigitos, 'origen' => 'dictado_confirmado', 'alterno' => $chat, 'descartado' => null];
    }

    if ($chat !== null) {
        return [
            'telefono' => $chat,
            'origen' => 'chat',
            'alterno' => ($dictadoUtil && $dictadoDigitos !== $chat) ? $dictadoDigitos : null,
            'descartado' => $descartado,
        ];
    }

    if ($dictadoUtil) {
        return ['telefono' => (string) $dictadoDigitos, 'origen' => 'dictado', 'alterno' => null, 'descartado' => null];
    }

    return ['telefono' => '', 'origen' => 'ninguno', 'alterno' => null, 'descartado' => $descartado];
}

/**
 * Telefono actual de un cliente en claro (descifrado), o null si no tiene.
 */
function clienteObtenerTelefonoPlano(PDO $pdo, int $idCliente): ?string
{
    $stmt = $pdo->prepare('SELECT telefono FROM clientes WHERE id_cliente = ? LIMIT 1');
    $stmt->execute([$idCliente]);
    $valor = $stmt->fetchColumn();
    if ($valor === false || $valor === null || trim((string) $valor) === '') {
        return null;
    }

    return telefonoDescifrar((string) $valor);
}

function telefonoDescifrar(string $valor): string
{
    if (function_exists('piiIsEncryptedValue') && function_exists('piiDecryptValue') && piiIsEncryptedValue($valor)) {
        return trim((string) piiDecryptValue($valor));
    }

    return trim($valor);
}

/**
 * Cuando se corrige el telefono de un cliente, actualiza el telefono de entrega de sus pedidos
 * ABIERTOS (pendiente_pago, pagado, en_reparto) que quedaron con un numero que ya no sirve:
 *   - era una copia del telefono anterior del cliente,
 *   - no es un telefono valido de 10 digitos, o
 *   - parece de relleno (ver telefonoParecePlaceholder()).
 * Un pedido con OTRO numero valido (contacto de entrega distinto a proposito) no se toca. Los
 * pedidos entregados/cancelados son historia y tampoco. Nunca lanza: si la columna
 * pedidos.telefono_entrega no existe (deploy en curso) devuelve [].
 *
 * @return list<array{id_pedido:int, anterior:string, nuevo:string}> pedidos actualizados
 */
function clienteSincronizarTelefonoPedidosAbiertos(PDO $pdo, int $idCliente, ?string $telefonoAnterior, ?string $telefonoNuevo): array
{
    $nuevo = telefonoDigitos10($telefonoNuevo);
    if ($idCliente <= 0 || $nuevo === null) {
        return [];
    }
    $anterior = telefonoDigitos10($telefonoAnterior);
    if ($anterior === $nuevo) {
        return [];
    }

    $actualizados = [];
    try {
        $stmt = $pdo->prepare(
            "SELECT id_pedido, telefono_entrega FROM pedidos
             WHERE id_cliente = ? AND estado IN ('pendiente_pago', 'pagado', 'en_reparto')
               AND telefono_entrega IS NOT NULL AND TRIM(telefono_entrega) <> ''"
        );
        $stmt->execute([$idCliente]);
        $update = $pdo->prepare('UPDATE pedidos SET telefono_entrega = ? WHERE id_pedido = ?');

        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $fila) {
            $enPedido = telefonoDescifrar((string) $fila['telefono_entrega']);
            $digitos = telefonoDigitos10($enPedido);

            if ($digitos === $nuevo) {
                continue;
            }
            $obsoleto = $digitos === null || $digitos === $anterior || telefonoParecePlaceholder($digitos);
            if (!$obsoleto) {
                continue;
            }

            $update->execute([$nuevo, (int) $fila['id_pedido']]);
            $actualizados[] = ['id_pedido' => (int) $fila['id_pedido'], 'anterior' => $enPedido, 'nuevo' => $nuevo];
        }
    } catch (Throwable $e) {
        error_log('WARNING: no se pudo sincronizar el telefono de los pedidos abiertos del cliente #' . $idCliente . ': ' . $e->getMessage());
    }

    return $actualizados;
}

/**
 * Deja en la bitacora de auditoria cada pedido cuyo telefono se sincronizo (con los numeros
 * enmascarados). Nunca lanza.
 *
 * @param list<array{id_pedido:int, anterior:string, nuevo:string}> $sincronizados
 */
function clienteAuditarSincronizacionTelefono(int $idCliente, array $sincronizados): void
{
    if (!function_exists('logAudit') || !function_exists('auditEnmascararPii')) {
        return;
    }

    foreach ($sincronizados as $s) {
        logAudit(
            'PEDIDO_TELEFONO_SINCRONIZADO',
            'pedidos',
            $s['id_pedido'],
            'Telefono de entrega actualizado al nuevo telefono del cliente #' . $idCliente,
            ['telefono_entrega' => auditEnmascararPii('telefono', $s['anterior'])],
            ['telefono_entrega' => auditEnmascararPii('telefono', $s['nuevo'])],
            ['severidad' => 'aviso']
        );
    }
}
