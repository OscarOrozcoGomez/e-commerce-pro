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
}
