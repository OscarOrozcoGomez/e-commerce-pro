<?php

declare(strict_types=1);

require_once __DIR__ . '/../../core/social_post_utils.php';

use PHPUnit\Framework\TestCase;

final class SocialPostUtilsTest extends TestCase
{
    public function testExtractColoniaFromAddressFindsKeywordColonia(): void
    {
        $this->assertSame(
            'Chapalita',
            extractColoniaFromAddress('Av. Patria 456, Colonia Chapalita, Zapopan, Jal.')
        );
    }

    public function testExtractColoniaFromAddressFindsAbbreviatedCol(): void
    {
        $this->assertSame(
            'Jardines Del Bosque',
            extractColoniaFromAddress('Calle Ejemplo 123 Col. Jardines del Bosque, Guadalajara')
        );
    }

    public function testExtractColoniaFromAddressFindsFraccionamiento(): void
    {
        $this->assertSame(
            'Las Palmas',
            extractColoniaFromAddress('Privada 12, Fraccionamiento Las Palmas, Tlaquepaque')
        );
    }

    public function testExtractColoniaFromAddressStopsAtPostalCode(): void
    {
        $this->assertSame(
            'Centro',
            extractColoniaFromAddress('Calle 5 de Mayo 10, Colonia Centro CP 44100, Guadalajara')
        );
    }

    public function testExtractColoniaFromAddressFallsBackToSecondCommaSegment(): void
    {
        $this->assertSame(
            'Providencia',
            extractColoniaFromAddress('Av. Naciones Unidas 500, Providencia, Guadalajara')
        );
    }

    public function testExtractColoniaFromAddressReturnsEmptyWhenNothingUsable(): void
    {
        $this->assertSame('', extractColoniaFromAddress(''));
        $this->assertSame('', extractColoniaFromAddress(null));
        $this->assertSame('', extractColoniaFromAddress('Calle Ejemplo 123'));
        $this->assertSame('', extractColoniaFromAddress('Calle Ejemplo 123, 44100'));
    }

    public function testSlugifyHashtagStripsAccentsSpacesAndSigns(): void
    {
        $this->assertSame('JardinesDelBosque', slugifyHashtag('Jardines del Bosque'));
        $this->assertSame('ChapalitaNorte', slugifyHashtag('Chapalita Norte'));
        $this->assertSame('Nino', slugifyHashtag('Niño'));
    }

    public function testBuildDeliveryPostTextIncludesColoniaHashtag(): void
    {
        $this->assertSame(
            '¡Pedido entregado! 📦🚚 #EntregaExpress #Chapalita',
            buildDeliveryPostText('Chapalita')
        );
    }

    public function testBuildDeliveryPostTextOmitsHashtagWhenColoniaIsEmpty(): void
    {
        $this->assertSame('¡Pedido entregado! 📦🚚 #EntregaExpress', buildDeliveryPostText(''));
    }

    public function testBuildDeliveryPostTextIncludesOrdinalWhenNumeroEntregaGiven(): void
    {
        $this->assertSame(
            '📦🚚 ¡Primera entrega del dia completada! #EntregaExpress #Chapalita',
            buildDeliveryPostText('Chapalita', 1)
        );
        $this->assertSame(
            '📦🚚 ¡Cuarta entrega del dia completada! #EntregaExpress',
            buildDeliveryPostText('', 4)
        );
    }

    public function testBuildDeliveryPostTextFallsBackToNumericOrdinalBeyondThirty(): void
    {
        $this->assertSame(
            '📦🚚 ¡Entrega #31 del dia completada! #EntregaExpress',
            buildDeliveryPostText('', 31)
        );
    }

    public function testBuildDeliveryPostTextIgnoresNonPositiveNumeroEntrega(): void
    {
        $this->assertSame('¡Pedido entregado! 📦🚚 #EntregaExpress', buildDeliveryPostText('', 0));
        $this->assertSame('¡Pedido entregado! 📦🚚 #EntregaExpress', buildDeliveryPostText('', null));
    }

    public function testOrdinalFemeninoEntregaCoversFirstAndLastOfEachDecade(): void
    {
        $this->assertSame('Primera', ordinalFemeninoEntrega(1));
        $this->assertSame('Decima', ordinalFemeninoEntrega(10));
        $this->assertSame('Undecima', ordinalFemeninoEntrega(11));
        $this->assertSame('Vigesima', ordinalFemeninoEntrega(20));
        $this->assertSame('Vigesima primera', ordinalFemeninoEntrega(21));
        $this->assertSame('Trigesima', ordinalFemeninoEntrega(30));
    }

    public function testOrdinalFemeninoEntregaReturnsNullOutsideSupportedRange(): void
    {
        $this->assertNull(ordinalFemeninoEntrega(31));
    }
}
