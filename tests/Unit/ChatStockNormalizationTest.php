<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class ChatStockNormalizationTest extends TestCase
{
    public function testNormalizeChatProductStockUsesTotalStockWhenWarehouseStockIsZero(): void
    {
        $product = normalizeChatProductStock([
            'id_producto' => 135,
            'cantidad_actual' => 0,
            'total_stock' => '7',
        ]);

        $this->assertSame(7, $product['chat_stock']);
    }

    public function testNormalizeChatProductStockFallsBackToWarehouseStockWhenTotalStockIsMissing(): void
    {
        $product = normalizeChatProductStock([
            'id_producto' => 135,
            'cantidad_actual' => '3',
        ]);

        $this->assertSame(3, $product['chat_stock']);
    }

    public function testNormalizeChatProductStockClampsNegativeValuesToZero(): void
    {
        $product = normalizeChatProductStock([
            'id_producto' => 135,
            'cantidad_actual' => -4,
            'total_stock' => -2,
        ]);

        $this->assertSame(0, $product['chat_stock']);
    }

    public function testNormalizeChatProductListAppliesNormalizationToEveryItem(): void
    {
        $products = normalizeChatProductList([
            ['id_producto' => 1, 'cantidad_actual' => 0, 'total_stock' => 2],
            ['id_producto' => 2, 'cantidad_actual' => 5],
        ]);

        $this->assertSame(2, $products[0]['chat_stock']);
        $this->assertSame(5, $products[1]['chat_stock']);
    }
}