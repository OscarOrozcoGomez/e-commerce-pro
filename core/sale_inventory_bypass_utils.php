<?php
declare(strict_types=1);

/**
 * Decide si una venta debe omitir el descuento de inventario: solo cuando quien la captura
 * tiene permiso (admin/encargado) y sus notas contienen la palabra clave configurada. Si aplica,
 * devuelve las notas ya sin la palabra clave para no guardarla en el pedido.
 *
 * @return array{sin_inventario: bool, observaciones: string}
 */
function resolveVentaSinInventario(string $observaciones, bool $usuarioPuedeOmitirInventario, string $keyword): array
{
    $keyword = trim($keyword);

    if ($keyword === '' || !$usuarioPuedeOmitirInventario || stripos($observaciones, $keyword) === false) {
        return ['sin_inventario' => false, 'observaciones' => $observaciones];
    }

    return [
        'sin_inventario' => true,
        'observaciones' => trim((string) str_ireplace($keyword, '', $observaciones)),
    ];
}
