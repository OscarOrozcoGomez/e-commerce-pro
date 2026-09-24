<?php

declare(strict_types=1);

require_once __DIR__ . '/../../core/whatsapp_link_utils.php';

use PHPUnit\Framework\TestCase;

final class WhatsAppLinkUtilsTest extends TestCase
{
    public function testWaBuildBusinessLinkPhonePrependsLadaAndStripsFormatting(): void
    {
        $this->assertSame('523311458245', waBuildBusinessLinkPhone('331-145-8245'));
        $this->assertSame('523311458245', waBuildBusinessLinkPhone('(33) 1145 8245'));
        $this->assertSame('523311458245', waBuildBusinessLinkPhone('3311458245'));
    }

    public function testWaBuildBusinessLinkPhoneNoDuplicaLada(): void
    {
        $this->assertSame('523311119215', waBuildBusinessLinkPhone('+52 33 1111 9215'));
        $this->assertSame('523311119215', waBuildBusinessLinkPhone('521 331 111 9215'));
    }

    public function testWaBuildBusinessLinkPhoneReturnsEmptyStringForNoDigits(): void
    {
        $this->assertSame('', waBuildBusinessLinkPhone(''));
        $this->assertSame('', waBuildBusinessLinkPhone(null));
        $this->assertSame('', waBuildBusinessLinkPhone('N/A'));
        $this->assertSame('', waBuildBusinessLinkPhone('---'));
    }
}
