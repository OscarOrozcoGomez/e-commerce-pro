<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class AuthUtilsTest extends TestCase
{
    /** @var string|null */
    private $hostBackup;

    protected function setUp(): void
    {
        // buildNewOrderNotificationHtml() arma URLs absolutas desde HTTP_HOST
        // (contexto web/webhook real). En los tests lo fijamos para poder
        // verificar el enlace de /wa.php.
        $this->hostBackup = $_SERVER['HTTP_HOST'] ?? null;
        $_SERVER['HTTP_HOST'] = 'bellezaybienestar.com.mx';
    }

    protected function tearDown(): void
    {
        if ($this->hostBackup === null) {
            unset($_SERVER['HTTP_HOST']);
        } else {
            $_SERVER['HTTP_HOST'] = $this->hostBackup;
        }
    }

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

    public function testAppOutboundEmailDomainUsaHttpHostYQuitaWww(): void
    {
        $_SERVER['HTTP_HOST'] = 'www.bellezaybienestar.com.mx';
        $this->assertSame('bellezaybienestar.com.mx', appOutboundEmailDomain());
    }

    public function testAppOutboundEmailDomainCaeAlEnvCuandoNoHayHttpHost(): void
    {
        unset($_SERVER['HTTP_HOST']); // contexto CLI/cron
        $_SERVER['APP_EMAIL_FROM_DOMAIN'] = 'ejemplo.mx';
        try {
            $this->assertSame('ejemplo.mx', appOutboundEmailDomain());
        } finally {
            unset($_SERVER['APP_EMAIL_FROM_DOMAIN']);
        }
    }

    public function testAppOutboundEmailDomainVacioSinHostNiEnv(): void
    {
        unset($_SERVER['HTTP_HOST'], $_SERVER['APP_EMAIL_FROM_DOMAIN'], $_SERVER['APP_PRIMARY_DOMAIN'], $_SERVER['MAIL_DOMAIN']);
        $this->assertSame('', appOutboundEmailDomain());
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

    public function testNewOrderNotificationHtmlDerivesWhatsAppLinkFromPhone(): void
    {
        $html = buildNewOrderNotificationHtml([
            'numero_pedido' => 'WEB-TEST',
            'cliente_nombre' => 'Gpe Elizabeth',
            'telefono' => '3312345678',
            'total' => 100.0,
        ], []);

        // El telefono se vuelve un enlace (via /wa.php, que fuerza WhatsApp
        // Business en Android) y aparece el boton verde.
        $this->assertStringContainsString('/wa.php?p=523312345678', $html);
        $this->assertStringContainsString('Escribir al cliente por WhatsApp', $html);
    }

    public function testNewOrderNotificationHtmlPrefersExplicitWhatsAppLink(): void
    {
        $html = buildNewOrderNotificationHtml([
            'numero_pedido' => 'WEB-TEST',
            'cliente_nombre' => 'Cliente',
            'telefono' => '3312345678',
            'whatsapp_link' => 'https://wa.me/5219998887766',
            'total' => 50.0,
        ], []);

        $this->assertStringContainsString('/wa.php?p=5219998887766', $html);
        $this->assertStringNotContainsString('523312345678', $html);
    }

    public function testNewOrderNotificationHtmlOmitsWhatsAppButtonWithoutUsablePhone(): void
    {
        $html = buildNewOrderNotificationHtml([
            'numero_pedido' => 'WEB-TEST',
            'cliente_nombre' => 'Cliente LID',
            'telefono' => '',
            'total' => 25.0,
        ], []);

        $this->assertStringNotContainsString('Escribir al cliente por WhatsApp', $html);
        $this->assertStringNotContainsString('wa.php?p=', $html);
        // En vez de omitir el link en silencio, el correo avisa que no hay numero.
        $this->assertStringContainsString('Sin número de WhatsApp para este pedido', $html);
    }

    public function testNewOrderNotificationHtmlHidesMissingWhatsAppNoteWhenLinkExists(): void
    {
        $html = buildNewOrderNotificationHtml([
            'numero_pedido' => 'WEB-TEST',
            'cliente_nombre' => 'Cliente',
            'telefono' => '3312345678',
            'total' => 10.0,
        ], []);

        $this->assertStringNotContainsString('Sin número de WhatsApp para este pedido', $html);
    }
}
