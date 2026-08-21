<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class AuthUtilsTest extends TestCase
{
    public function testIsPasswordSecureReturnsTrueForStrongPassword(): void
    {
        $this->assertTrue(isPasswordSecure('Abcd1234!@'));
    }

    public function testIsPasswordSecureReturnsFalseForShortPassword(): void
    {
        $this->assertFalse(isPasswordSecure('Ab1!short'));
    }

    public function testIsPasswordSecureReturnsFalseWithoutUppercase(): void
    {
        $this->assertFalse(isPasswordSecure('abcd1234!@zz'));
    }

    public function testIsPasswordSecureReturnsFalseWithoutSymbol(): void
    {
        $this->assertFalse(isPasswordSecure('Abcd12345678'));
    }

    public function testSlugifyCreatesUrlLikeSlug(): void
    {
        $this->assertSame('mi-producto-500mg', slugify('Mi producto 500mg'));
    }

    public function testSlugifyFallbacksToProductoWhenInputIsNotUsable(): void
    {
        $this->assertSame('producto', slugify('***'));
    }

    public function testResetPasswordWithTokenRejectsWeakPasswordBeforeTokenValidation(): void
    {
        $error = null;
        $result = resetPasswordWithToken('test@example.com', '123456', 'weakpass', $error);

        $this->assertFalse($result);
        $this->assertSame(
            'La nueva contraseña debe tener al menos 10 caracteres, incluir mayúsculas, minúsculas, números y un símbolo.',
            $error
        );
    }

    public function testResetPasswordWithTokenRejectsTooShortPassword(): void
    {
        $error = null;
        $result = resetPasswordWithToken('test@example.com', '123456', 'Ab1!short', $error);

        $this->assertFalse($result);
        $this->assertSame(
            'La nueva contraseña debe tener al menos 10 caracteres, incluir mayúsculas, minúsculas, números y un símbolo.',
            $error
        );
    }

    public function testResolveCheckoutWarehouseDefaultsToAlmacenCentral(): void
    {
        // Los pedidos web a domicilio siempre se surten del Almacen Central (id 1); ya no se
        // reparte entre sucursales segun quien tenga stock (ver core/auth.php).
        $this->assertSame(1, resolveCheckoutWarehouse());
        $this->assertSame(1, resolveCheckoutWarehouse(null));
        $this->assertSame(1, resolveCheckoutWarehouse(0));
    }

    public function testResolveCheckoutWarehouseRespectsExplicitRequest(): void
    {
        $this->assertSame(3, resolveCheckoutWarehouse(3));
        $this->assertSame(3, resolveCheckoutWarehouse('3'));
    }
}
