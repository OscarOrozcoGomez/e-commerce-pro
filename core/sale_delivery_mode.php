<?php
declare(strict_types=1);

/**
 * Modo de entrega de una venta/pedido capturado en api/ventas.php.
 *
 * Historicamente ese endpoint solo generaba pedidos "a domicilio" (reparto, cobro
 * contra entrega). El modo "en sucursal" (mostrador, cliente presente) reusa el mismo
 * endpoint pero: no pide domicilio, no calcula envio foraneo y nace ya cobrado.
 *
 * views/sales.php manda el valor exacto 'Domicilio' o 'Sucursal' desde un radio, pero
 * el bot y otras integraciones tambien postean a este endpoint, asi que la
 * normalizacion tolera mayusculas/espacios y CAE SIEMPRE a 'Domicilio' ante algo
 * vacio, ausente o desconocido -- ese es el comportamiento por defecto historico y
 * ninguna integracion previa mandaba 'tipo_entrega', asi que todas siguen igual.
 */

const SALE_DELIVERY_MODE_HOME = 'Domicilio';
const SALE_DELIVERY_MODE_COUNTER = 'Sucursal';

/**
 * @param mixed $raw Valor crudo, normalmente $_POST['tipo_entrega'].
 */
function saleDeliveryModeNormalize($raw): string
{
    if (is_bool($raw) || is_array($raw) || is_object($raw) || $raw === null) {
        return SALE_DELIVERY_MODE_HOME;
    }

    $value = strtolower(trim((string) $raw));

    return $value === 'sucursal' ? SALE_DELIVERY_MODE_COUNTER : SALE_DELIVERY_MODE_HOME;
}

/**
 * @param mixed $raw
 */
function saleDeliveryModeIsCounter($raw): bool
{
    return saleDeliveryModeNormalize($raw) === SALE_DELIVERY_MODE_COUNTER;
}

/** Prefijo del numero_pedido: MOS- para mostrador, DOM- para domicilio. */
function saleDeliveryModeFolioPrefix(bool $isCounter): string
{
    return $isCounter ? 'MOS-' : 'DOM-';
}

/** Estado inicial del pedido: en mostrador se cobra en el acto. */
function saleDeliveryModeInitialEstado(bool $isCounter): string
{
    return $isCounter ? 'pagado' : 'pendiente_pago';
}

/**
 * ¿El usuario puede registrar el modo de entrega solicitado?
 *
 * La venta de mostrador ('Sucursal') la puede hacer cualquiera que llegue al panel de
 * ventas. Agendar a domicilio exige poder programar entregas: el permiso
 * 'asignar_entregas' (o el respaldo de rol canManageDeliveryOrders()). Un modo
 * vacío/desconocido normaliza a 'Domicilio', así que a quien no puede tampoco le pasa.
 *
 * @param mixed $rawMode                Valor crudo de tipo_entrega.
 * @param bool  $canScheduleHomeDelivery hasPermission('asignar_entregas') || canManageDeliveryOrders().
 */
function saleDeliveryModeIsAllowedForUser($rawMode, bool $canScheduleHomeDelivery): bool
{
    return $canScheduleHomeDelivery || saleDeliveryModeIsCounter($rawMode);
}

/**
 * Primeras lineas del campo `observaciones` del pedido. Quien llama sigue anexando
 * despues los chunks que no dependen del modo (Maps, bypass de inventario, envio
 * foraneo). En mostrador se omite la linea "Dir:" y "Tel:" se omite si viene vacio.
 *
 * @return list<string>
 */
function saleDeliveryModeObservacionesChunks(
    bool $isCounter,
    string $clienteNombre,
    string $clienteTelefono,
    string $direccionEntrega,
    string $notas = ''
): array {
    $chunks = [
        'ENTREGA: ' . ($isCounter ? SALE_DELIVERY_MODE_COUNTER : SALE_DELIVERY_MODE_HOME),
        'Cliente: ' . $clienteNombre,
    ];

    if (trim($clienteTelefono) !== '') {
        $chunks[] = 'Tel: ' . $clienteTelefono;
    }

    if (!$isCounter) {
        $chunks[] = 'Dir: ' . $direccionEntrega;
    }

    if (trim($notas) !== '') {
        $chunks[] = 'Notas: ' . $notas;
    }

    return $chunks;
}
