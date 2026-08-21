<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Las pruebas usan CLIENTE_FRECUENTE_MIN_PEDIDOS en vez de un numero fijo, para no romperse
 * cada vez que el dueño del negocio ajuste el umbral (ver core/cliente_loyalty_utils.php).
 */
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

    private function seedPedidos(?int $idCliente, int $cantidad, string $estado = 'entregado'): void
    {
        for ($i = 0; $i < $cantidad; $i++) {
            $this->seedPedido($idCliente, $estado);
        }
    }

    public function testClienteFrecuenteGetIdsRequiresMinimumThreshold(): void
    {
        // Cliente 10: justo en el umbral -> si califica.
        $this->seedPedidos(10, CLIENTE_FRECUENTE_MIN_PEDIDOS);
        // Cliente 20: uno menos que el umbral -> no califica.
        $this->seedPedidos(20, CLIENTE_FRECUENTE_MIN_PEDIDOS - 1);

        $this->assertSame([10], clienteFrecuenteGetIds($this->pdo));
    }

    public function testClienteFrecuenteGetIdsIgnoresCancelledOrders(): void
    {
        // Cliente 30 tiene varios pedidos cancelados (aunque alcancen el umbral en cantidad) y
        // solo 1 real -> no debe calificar.
        $this->seedPedidos(30, CLIENTE_FRECUENTE_MIN_PEDIDOS, 'cancelado');
        $this->seedPedido(30, 'entregado');

        $this->assertSame([], clienteFrecuenteGetIds($this->pdo));
    }

    public function testClienteFrecuenteGetIdsIgnoresOrdersWithoutClient(): void
    {
        // Pedidos de mostrador/invitado sin id_cliente nunca deben contar para nadie.
        $this->seedPedidos(null, CLIENTE_FRECUENTE_MIN_PEDIDOS + 5);

        $this->assertSame([], clienteFrecuenteGetIds($this->pdo));
    }

    public function testClienteFrecuenteGetIdsReturnsMultipleQualifyingClients(): void
    {
        $this->seedPedidos(1, CLIENTE_FRECUENTE_MIN_PEDIDOS);
        $this->seedPedidos(2, CLIENTE_FRECUENTE_MIN_PEDIDOS);
        $this->seedPedidos(3, CLIENTE_FRECUENTE_MIN_PEDIDOS - 1);

        $frecuentes = clienteFrecuenteGetIds($this->pdo);
        sort($frecuentes);
        $this->assertSame([1, 2], $frecuentes);
    }

    public function testClienteEsFrecuenteMatchesGetIds(): void
    {
        $this->seedPedidos(10, CLIENTE_FRECUENTE_MIN_PEDIDOS);
        $this->seedPedidos(20, CLIENTE_FRECUENTE_MIN_PEDIDOS - 1);

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
