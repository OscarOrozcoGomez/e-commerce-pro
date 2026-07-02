<?php
declare(strict_types=1);

/**
 * Normaliza el stock visible para el chat.
 * Prioriza el stock total agregado cuando existe y cae al stock del almacén actual.
 */
function normalizeChatProductStock(array $product): array
{
    $totalStock = array_key_exists('total_stock', $product) && $product['total_stock'] !== null && $product['total_stock'] !== ''
        ? (int) $product['total_stock']
        : null;

    $warehouseStock = array_key_exists('cantidad_actual', $product) && $product['cantidad_actual'] !== null && $product['cantidad_actual'] !== ''
        ? (int) $product['cantidad_actual']
        : null;

    $product['chat_stock'] = max(0, $totalStock ?? $warehouseStock ?? 0);

    return $product;
}

/**
 * Aplica la normalización de stock a una lista de productos.
 */
function normalizeChatProductList(array $products): array
{
    return array_map('normalizeChatProductStock', $products);
}