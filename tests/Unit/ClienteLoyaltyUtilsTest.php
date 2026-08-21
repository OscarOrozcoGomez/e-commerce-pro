<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class ClienteLoyaltyUtilsTest extends TestCase
{
    private PDO $pdo;

    protected function setUp(): void
    {
        parent::setUp();

        $this->pdo = new PDO('sqlite::memory:');
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);

        $this->pdo->exec(
            "CREATE TABLE pedidos (id_pedido INTEGER PRIMARY KEY AUTOINCREMENT, id_cliente INTEGER NULL, estado TEXT NOT NULL DEFAULT 'pendiente_pago')"
        );
    }

    private function seedPedido(?int $idCliente, string $estado): void
    {
        $stmt = $this->pdo->prepare('INSERT INTO pedidos (id_cliente, estado) VALUES (?, ?)');
        $stmt->execute([$idCliente, $estado]);
    }

    public function testClienteFrecuenteGetIdsRequiresMinimumThreshold(): void
    {
        // Cliente 10: 2 pedidos reales -> si califica (umbral = 2).
        $this->seedPedido(10, 'pagado');
        $this->seedPedido(10, 'entregado');
        // Cliente 20: 1 solo pedido real -> no califica.
        $this->seedPedido(20, 'entregado');

        $this->assertSame([10], clienteFrecuenteGetIds($this->pdo));
    }

    public function testClienteFrecuenteGetIdsIgnoresCancelledOrders(): void
    {
        // Cliente 30 tiene 3 pedidos en total, pero 2 estan cancelados -> solo 1 real, no califica.
        $this->seedPedido(30, 'cancelado');
        $this->seedPedido(30, 'cancelado');
        $this->seedPedido(30, 'entregado');

        $this->assertSame([], clienteFrecuenteGetIds($this->pdo));
    }

    public function testClienteFrecuenteGetIdsIgnoresOrdersWithoutClient(): void
    {
        // Pedidos de mostrador/invitado sin id_cliente nunca deben contar para nadie.
        $this->seedPedido(null, 'entregado');
        $this->seedPedido(null, 'entregado');

        $this->assertSame([], clienteFrecuenteGetIds($this->pdo));
    }

    public function testClienteFrecuenteGetIdsReturnsMultipleQualifyingClients(): void
    {
        $this->seedPedido(1, 'pagado');
        $this->seedPedido(1, 'pagado');
        $this->seedPedido(1, 'pagado');
        $this->seedPedido(2, 'entregado');
        $this->seedPedido(2, 'entregado');
        $this->seedPedido(3, 'entregado');

        $frecuentes = clienteFrecuenteGetIds($this->pdo);
        sort($frecuentes);
        $this->assertSame([1, 2], $frecuentes);
    }

    public function testClienteEsFrecuenteMatchesGetIds(): void
    {
        $this->seedPedido(10, 'pagado');
        $this->seedPedido(10, 'entregado');
        $this->seedPedido(20, 'entregado');

        $this->assertTrue(clienteEsFrecuente($this->pdo, 10));
        $this->assertFalse(clienteEsFrecuente($this->pdo, 20));
    }

    public function testClienteEsFrecuenteHandlesNullOrInvalidId(): void
    {
        $this->assertFalse(clienteEsFrecuente($this->pdo, null));
        $this->assertFalse(clienteEsFrecuente($this->pdo, 0));
        $this->assertFalse(clienteEsFrecuente($this->pdo, -5));
    }

    public function testClienteEsFrecuenteReturnsFalseForUnknownClient(): void
    {
        $this->seedPedido(10, 'pagado');
        $this->assertFalse(clienteEsFrecuente($this->pdo, 999999));
    }

    public function testClienteFrecuenteBadgeHtmlContainsExpectedLabel(): void
    {
        $html = clienteFrecuenteBadgeHtml();
        $this->assertStringContainsString('Frecuente', $html);
        $this->assertStringContainsString('<span', $html);
    }
}
