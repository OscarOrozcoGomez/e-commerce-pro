<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class ClienteDireccionUtilsTest extends TestCase
{
    private PDO $pdo;

    protected function setUp(): void
    {
        parent::setUp();

        $this->pdo = new PDO('sqlite::memory:');
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);

        $this->pdo->exec('CREATE TABLE cliente_direcciones (id_direccion INTEGER PRIMARY KEY, id_cliente INTEGER NOT NULL, alias TEXT NOT NULL, direccion TEXT NOT NULL, confirmada_cliente INTEGER NOT NULL DEFAULT 0, confirmada_en TEXT NULL, confirmada_por INTEGER NULL)');
        $this->seedDireccion(1, 10, 'Casa', 'Calle Falsa 123');
        $this->seedDireccion(2, 20, 'Oficina', 'Av. Siempre Viva 742');
    }

    public function testMarksAddressAsConfirmedWithTimestampAndUser(): void
    {
        $result = dbConfirmarDireccionCliente($this->pdo, 10, 1, 7);

        $this->assertTrue($result['success']);
        $this->assertStringContainsString('confirmada', $result['message']);

        $row = $this->pdo->query('SELECT confirmada_cliente, confirmada_en, confirmada_por FROM cliente_direcciones WHERE id_direccion = 1')->fetch();
        $this->assertSame(1, (int) $row['confirmada_cliente']);
        $this->assertNotEmpty($row['confirmada_en']);
        $this->assertSame(7, (int) $row['confirmada_por']);
    }

    public function testAllowsNullUserWhenConfirmingWithoutSession(): void
    {
        $result = dbConfirmarDireccionCliente($this->pdo, 10, 1, null);

        $this->assertTrue($result['success']);
        $row = $this->pdo->query('SELECT confirmada_por FROM cliente_direcciones WHERE id_direccion = 1')->fetch();
        $this->assertNull($row['confirmada_por']);
    }

    public function testTreatsNonPositiveUserIdAsNull(): void
    {
        $result = dbConfirmarDireccionCliente($this->pdo, 10, 1, 0);

        $this->assertTrue($result['success']);
        $row = $this->pdo->query('SELECT confirmada_por FROM cliente_direcciones WHERE id_direccion = 1')->fetch();
        $this->assertNull($row['confirmada_por']);
    }

    public function testFailsWhenAddressDoesNotBelongToClient(): void
    {
        // La direccion 2 es del cliente 20, no del 10.
        $result = dbConfirmarDireccionCliente($this->pdo, 10, 2, 7);

        $this->assertFalse($result['success']);
        $row = $this->pdo->query('SELECT confirmada_cliente FROM cliente_direcciones WHERE id_direccion = 2')->fetch();
        $this->assertSame(0, (int) $row['confirmada_cliente']);
    }

    public function testReturnsFailureForInvalidIds(): void
    {
        $result = dbConfirmarDireccionCliente($this->pdo, 0, 1, 7);
        $this->assertFalse($result['success']);

        $result = dbConfirmarDireccionCliente($this->pdo, 10, 0, 7);
        $this->assertFalse($result['success']);
    }

    private function seedDireccion(int $idDireccion, int $idCliente, string $alias, string $direccion): void
    {
        $this->pdo->prepare('INSERT INTO cliente_direcciones (id_direccion, id_cliente, alias, direccion) VALUES (?, ?, ?, ?)')
            ->execute([$idDireccion, $idCliente, $alias, $direccion]);
    }
}
