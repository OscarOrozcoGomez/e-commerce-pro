<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class PhonePIIEncryptionTest extends TestCase
{
    private array $originalServer = [];
    private array $originalEnv = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->originalServer = $_SERVER;
        $this->originalEnv = $_ENV;
    }

    protected function tearDown(): void
    {
        $_SERVER = $this->originalServer;
        $_ENV = $this->originalEnv;
        putenv('PII_ENCRYPTION_KEY');

        parent::tearDown();
    }

    public function testPhoneEncryptionRoundTripReturnsSameDigits(): void
    {
        putenv('PII_ENCRYPTION_KEY=test-phone-key-1234567890');
        $_SERVER['PII_ENCRYPTION_KEY'] = 'test-phone-key-1234567890';
        $_ENV['PII_ENCRYPTION_KEY'] = 'test-phone-key-1234567890';

        $encrypted = piiEncryptValue('3318635185');
        $this->assertIsString($encrypted);
        $this->assertStringStartsWith(PII_CIPHER_PREFIX, $encrypted);
        $this->assertSame('3318635185', piiDecryptValue($encrypted));
    }

    public function testPhoneStoredValueToDigitsReturnsNullForEncryptedValueWithoutKey(): void
    {
        $cipher = PII_CIPHER_PREFIX . base64_encode("\x01" . 'nonce' . 'cipher');
        $this->assertNull(phoneStoredValueToDigits($cipher));
    }
}