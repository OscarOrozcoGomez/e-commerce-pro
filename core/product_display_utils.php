<?php
declare(strict_types=1);

function normalizePresentationText(?string $value): string
{
    $text = trim((string) $value);
    if ($text === '') {
        return '';
    }

    if (function_exists('mb_strtolower')) {
        return mb_strtolower($text) === 'unidades' ? '' : $text;
    }

    return strtolower($text) === 'unidades' ? '' : $text;
}

function buildProductDisplayName(string $name, ?string $variant = null, ?string $unit = null): string
{
    $displayVariant = normalizePresentationText($variant);
    $displayUnit = normalizePresentationText($unit);

    if ($displayVariant !== '') {
        return $name . ' (' . $displayVariant . ')';
    }

    if ($displayUnit !== '') {
        return $name . ' (' . $displayUnit . ')';
    }

    return $name;
}

function normalizeProductDisplayRow(array $row): array
{
    $row['display_nombre_variante'] = normalizePresentationText($row['nombre_variante'] ?? null);
    $row['display_unidad'] = normalizePresentationText($row['unidad'] ?? null);
    $row['display_cart_name'] = buildProductDisplayName(
        (string) ($row['nombre'] ?? ''),
        $row['display_nombre_variante'],
        $row['display_unidad']
    );

    return $row;
}