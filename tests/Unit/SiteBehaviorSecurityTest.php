<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Pruebas negativas/de seguridad para core/site_behavior.php: las cuatro funciones
 * de aqui procesan datos que vienen directo del body JSON publico de
 * api/log_activity.php (sin autenticacion, sin CSRF -- es un beacon de tracking) o de
 * la URL que el propio navegador reporto. Ninguna debe lanzar ni comportarse de forma
 * explotable ante tipos inesperados, valores extremos, o payloads maliciosos.
 */
final class SiteBehaviorSecurityTest extends TestCase
{
    /* =====================================================================
     * extractProductIdFromUrl() -- camino feliz
     * ===================================================================== */

    public function testExtractsIdFromValidProductDetailUrl(): void
    {
        $this->assertSame(42, extractProductIdFromUrl('https://midominio.com/product_detail.php?id=42'));
    }

    public function testExtractsIdWhenOtherQueryParamsPresent(): void
    {
        $this->assertSame(7, extractProductIdFromUrl('https://midominio.com/product_detail.php?utm_source=fb&id=7&ref=x'));
    }

    public function testMatchesRegardlessOfPathCase(): void
    {
        $this->assertSame(1, extractProductIdFromUrl('https://midominio.com/Product_Detail.PHP?id=1'));
    }

    /* =====================================================================
     * extractProductIdFromUrl() -- entradas hostiles / no aplican
     * ===================================================================== */

    public function testReturnsNullForNonProductPages(): void
    {
        $this->assertNull(extractProductIdFromUrl('https://midominio.com/views/catalogo.php?id=42'));
        $this->assertNull(extractProductIdFromUrl('https://midominio.com/views/detalle_compra.php?id=42'));
    }

    public function testReturnsNullWithoutIdParam(): void
    {
        $this->assertNull(extractProductIdFromUrl('https://midominio.com/product_detail.php'));
        $this->assertNull(extractProductIdFromUrl('https://midominio.com/product_detail.php?otro=1'));
    }

    public function testReturnsNullForNonNumericOrZeroOrNegativeId(): void
    {
        $this->assertNull(extractProductIdFromUrl('https://midominio.com/product_detail.php?id=abc'));
        $this->assertNull(extractProductIdFromUrl('https://midominio.com/product_detail.php?id=0'));
        $this->assertNull(extractProductIdFromUrl('https://midominio.com/product_detail.php?id=-5'));
        $this->assertNull(extractProductIdFromUrl('https://midominio.com/product_detail.php?id=1e5'));
    }

    public function testReturnsNullForArrayInjectionInIdParam(): void
    {
        // ?id[]=1 hace que PHP parsee 'id' como array -- is_scalar() lo rechaza antes
        // de que preg_match reciba algo que no sea string y emita un warning/TypeError.
        $this->assertNull(extractProductIdFromUrl('https://midominio.com/product_detail.php?id[]=1'));
    }

    public function testDoesNotCrashOnMalformedUrl(): void
    {
        $this->assertNull(extractProductIdFromUrl('::::not a url::::'));
        $this->assertNull(extractProductIdFromUrl(''));
    }

    /* =====================================================================
     * sanitizeExplicitProductId()
     * ===================================================================== */

    public function testAcceptsPositiveIntegersInVariousScalarForms(): void
    {
        $this->assertSame(5, sanitizeExplicitProductId(5));
        $this->assertSame(5, sanitizeExplicitProductId('5'));
        $this->assertSame(5, sanitizeExplicitProductId(' 5 '));
    }

    public function testRejectsNonPositiveOrNonNumericValues(): void
    {
        $this->assertNull(sanitizeExplicitProductId(0));
        $this->assertNull(sanitizeExplicitProductId(-1));
        $this->assertNull(sanitizeExplicitProductId('abc'));
        $this->assertNull(sanitizeExplicitProductId(null));
        $this->assertNull(sanitizeExplicitProductId(['id' => 1]));
        $this->assertNull(sanitizeExplicitProductId(new stdClass()));
    }

    /* =====================================================================
     * sanitizePageviewId() -- mismo formato que visitor_id (32 hex)
     * ===================================================================== */

    public function testAcceptsWellFormedPageviewId(): void
    {
        $this->assertSame('a1b2c3d4e5f6a1b2c3d4e5f6a1b2c3d4', sanitizePageviewId('a1b2c3d4e5f6a1b2c3d4e5f6a1b2c3d4'));
    }

    public function testRejectsWrongLengthOrNonHexCharacters(): void
    {
        $this->assertNull(sanitizePageviewId('a1b2')); // muy corto
        $this->assertNull(sanitizePageviewId(str_repeat('a', 33))); // muy largo
        $this->assertNull(sanitizePageviewId(str_repeat('z', 32))); // no es hex
        $this->assertNull(sanitizePageviewId(str_repeat('A', 32))); // mayusculas no aceptadas
    }

    public function testRejectsSqlInjectionAttemptAndNonScalarTypes(): void
    {
        $this->assertNull(sanitizePageviewId("' OR '1'='1"));
        $this->assertNull(sanitizePageviewId(null));
        $this->assertNull(sanitizePageviewId(['x' => 1]));
    }

    /* =====================================================================
     * clampDurationSeconds() -- valor client-side, sin ninguna garantia
     * ===================================================================== */

    public function testAcceptsAndRoundsReasonableValues(): void
    {
        $this->assertSame(45, clampDurationSeconds(45));
        $this->assertSame(46, clampDurationSeconds(45.6));
    }

    public function testClampsExtremeValuesToThirtyMinuteCeiling(): void
    {
        // Una pestaña dejada abierta horas no debe inflar el promedio del reporte.
        $this->assertSame(1800, clampDurationSeconds(999999));
        $this->assertSame(1800, clampDurationSeconds(1800));
        $this->assertSame(1799, clampDurationSeconds(1799));
    }

    public function testRejectsZeroNegativeOrNonNumericValues(): void
    {
        $this->assertNull(clampDurationSeconds(0));
        $this->assertNull(clampDurationSeconds(-30));
        $this->assertNull(clampDurationSeconds('no soy un numero'));
        $this->assertNull(clampDurationSeconds(null));
        $this->assertNull(clampDurationSeconds([]));
    }

    public function testAcceptsNumericStringsFromJsonDecode(): void
    {
        $this->assertSame(12, clampDurationSeconds('12'));
    }
}
