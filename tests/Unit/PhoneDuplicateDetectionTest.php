<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class PhoneDuplicateDetectionTest extends TestCase
{
    private PDO $pdo;
    private string $originalEncryptionKey = '';

    protected function setUp(): void
    {
        parent::setUp();

        $this->originalEncryptionKey = (string) (getenv('PII_ENCRYPTION_KEY') ?: '');
        $this->pdo = new PDO('sqlite::memory:');
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
        $this->pdo->exec('CREATE TABLE clientes (id_cliente INTEGER PRIMARY KEY AUTOINCREMENT, id_usuario INTEGER NULL, telefono TEXT NULL, nombre TEXT NULL)');
    }

    protected function tearDown(): void
    {
        putenv('PII_ENCRYPTION_KEY=' . $this->originalEncryptionKey);
        if ($this->originalEncryptionKey !== '') {
            $_SERVER['PII_ENCRYPTION_KEY'] = $this->originalEncryptionKey;
            $_ENV['PII_ENCRYPTION_KEY'] = $this->originalEncryptionKey;
        } else {
            unset($_SERVER['PII_ENCRYPTION_KEY'], $_ENV['PII_ENCRYPTION_KEY']);
        }

        parent::tearDown();
    }

    public function testFindClienteByPhoneReturnsNullWhenNoMatch(): void
    {
        $this->assertNull(findClienteByPhone($this->pdo, '3318635185'));
    }

    public function testFindClienteByPhoneReturnsMatchForPlainStoredPhone(): void
    {
        $this->pdo->prepare('INSERT INTO clientes (id_cliente, id_usuario, telefono) VALUES (?, ?, ?)')->execute([1, null, '(331) - 863 - 5185']);

        $match = findClienteByPhone($this->pdo, '3318635185');

        $this->assertNotNull($match);
        $this->assertSame(1, (int) $match['id_cliente']);
        $this->assertSame('3318635185', $match['telefono_digitos']);
    }

    public function testFindClienteByPhoneIgnoresExcludedClient(): void
    {
        $this->pdo->prepare('INSERT INTO clientes (id_cliente, id_usuario, telefono) VALUES (?, ?, ?)')->execute([7, null, '(331) - 863 - 5185']);

        $this->assertNull(findClienteByPhone($this->pdo, '3318635185', 7));
    }

    public function testFindClienteByPhoneMatchesEncryptedPhoneWhenKeyIsConfigured(): void
    {
        putenv('PII_ENCRYPTION_KEY=test-phone-key-1234567890');
        $_SERVER['PII_ENCRYPTION_KEY'] = 'test-phone-key-1234567890';
        $_ENV['PII_ENCRYPTION_KEY'] = 'test-phone-key-1234567890';

        $encryptedPhone = piiEncryptValue('(331) - 863 - 5185');
        $this->pdo->prepare('INSERT INTO clientes (id_cliente, id_usuario, telefono) VALUES (?, ?, ?)')->execute([11, null, $encryptedPhone]);

        $match = findClienteByPhone($this->pdo, '3318635185');

        $this->assertNotNull($match);
        $this->assertSame(11, (int) $match['id_cliente']);
        $this->assertSame('3318635185', $match['telefono_digitos']);
    }
}