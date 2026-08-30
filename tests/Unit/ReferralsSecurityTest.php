<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Pruebas negativas/de seguridad para el programa de referidos (core/referrals.php):
 * el objetivo es intentar romper el descuento (dinero real) via codigos invalidos,
 * auto-referidos, reutilizacion del mismo telefono, e inyeccion SQL/tipo confuso en
 * los campos que vienen directo del checkout publico (no confiables).
 */
final class ReferralsSecurityTest extends TestCase
{
    private PDO $pdo;

    protected function setUp(): void
    {
        parent::setUp();

        $this->pdo = new PDO('sqlite::memory:');
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);

        $this->createSchema();
        $this->seedFeatureConfig(10.0, 0.0);
    }

    /* =====================================================================
     * CODIGO GENERATION
     * ===================================================================== */

    public function testGetOrCreateCodeIsIdempotentForTheSameClient(): void
    {
        $codigo1 = referralGetOrCreateCode($this->pdo, 1);
        $codigo2 = referralGetOrCreateCode($this->pdo, 1);

        $this->assertSame($codigo1, $codigo2);
        $count = (int) $this->pdo->query('SELECT COUNT(*) FROM codigos_referido WHERE id_cliente = 1')->fetchColumn();
        $this->assertSame(1, $count);
    }

    public function testGetOrCreateCodeProducesDistinctCodesForDifferentClients(): void
    {
        $codigo1 = referralGetOrCreateCode($this->pdo, 1);
        $codigo2 = referralGetOrCreateCode($this->pdo, 2);

        $this->assertNotSame($codigo1, $codigo2);
    }

    public function testGeneratedCodeNeverContainsAmbiguousCharacters(): void
    {
        // El alfabeto excluye 0/O/1/I a proposito (para que se pueda dictar sin
        // confusiones); confirmar que nunca se cuela ninguno de esos caracteres.
        for ($i = 1; $i <= 30; $i++) {
            $codigo = referralGenerateCode();
            $this->assertDoesNotMatchRegularExpression('/[01OI]/', $codigo);
            $this->assertSame(6, strlen($codigo));
        }
    }

    /* =====================================================================
     * VALIDACION -- ENTRADAS HOSTILES
     * ===================================================================== */

    public function testValidateRejectsSqlInjectionStyleCodeWithoutCrashingOrMatching(): void
    {
        $this->seedCode(1, 'REAL01');

        $maligno = "REAL01' OR '1'='1";
        $result = referralValidate($this->pdo, $maligno, 2, '3311110000', 500.0);

        $this->assertFalse($result['valido']);
        $this->assertSame('codigo_no_existe', $result['motivo']);

        // La tabla de codigos sigue intacta.
        $count = (int) $this->pdo->query('SELECT COUNT(*) FROM codigos_referido')->fetchColumn();
        $this->assertSame(1, $count);
    }

    public function testValidateRejectsCodeWithSqlInjectionAttemptingToDropTable(): void
    {
        $this->seedCode(1, 'REAL02');

        $result = referralValidate($this->pdo, "x'; DROP TABLE codigos_referido; --", 2, '3311110001', 500.0);
        $this->assertFalse($result['valido']);

        // Confirmar que la tabla sigue existiendo y con su fila.
        $count = (int) $this->pdo->query('SELECT COUNT(*) FROM codigos_referido')->fetchColumn();
        $this->assertSame(1, $count);
    }

    public function testValidateHandlesExtremelyLongCodeWithoutCrashing(): void
    {
        $result = referralValidate($this->pdo, str_repeat('A', 100000), 2, '3311110002', 500.0);
        $this->assertFalse($result['valido']);
        $this->assertSame('codigo_no_existe', $result['motivo']);
    }

    public function testValidateRejectsEmptyOrWhitespaceOnlyCode(): void
    {
        $r1 = referralValidate($this->pdo, '', 2, '3311110003', 500.0);
        $r2 = referralValidate($this->pdo, '   ', 2, '3311110004', 500.0);
        $r3 = referralValidate($this->pdo, "\t\n", 2, '3311110005', 500.0);

        $this->assertFalse($r1['valido']);
        $this->assertFalse($r2['valido']);
        $this->assertFalse($r3['valido']);
    }

    public function testValidateHandlesNullBytesAndUnicodeInCodeWithoutCrashing(): void
    {
        $result = referralValidate($this->pdo, "AB\0CD😀", 2, '3311110006', 500.0);
        $this->assertFalse($result['valido']);
        $this->assertSame('codigo_no_existe', $result['motivo']);
    }

    public function testValidateIsCaseInsensitiveOnMatch(): void
    {
        $this->seedCode(1, 'ABCDEF');

        $result = referralValidate($this->pdo, 'abcdef', 2, '3311110007', 500.0);
        $this->assertTrue($result['valido']);
        $this->assertSame(1, $result['id_cliente_referidor']);
    }

    public function testValidateTrimsSurroundingWhitespace(): void
    {
        $this->seedCode(1, 'GHIJKL');

        $result = referralValidate($this->pdo, '  GHIJKL  ', 2, '3311110008', 500.0);
        $this->assertTrue($result['valido']);
    }

    /* =====================================================================
     * REGLAS ANTI-ABUSO
     * ===================================================================== */

    public function testCannotUseYourOwnCode(): void
    {
        $this->seedCode(5, 'SELF01');

        $result = referralValidate($this->pdo, 'SELF01', 5, '3311110009', 500.0);

        $this->assertFalse($result['valido']);
        $this->assertSame('no_puedes_usar_tu_propio_codigo', $result['motivo']);
    }

    public function testGuestCheckoutWithoutClientIdCanStillUseSomeoneElsesCode(): void
    {
        // Un invitado (sin cuenta, id_cliente_comprador = null) SI puede usar el codigo de
        // alguien mas -- el auto-referido solo se puede detectar cuando hay un comprador
        // identificado.
        $this->seedCode(1, 'GUEST1');

        $result = referralValidate($this->pdo, 'GUEST1', null, '3311110010', 500.0);
        $this->assertTrue($result['valido']);
    }

    public function testEmptyPhoneIsRejectedInsteadOfBypassingAbuseCheck(): void
    {
        // Hallazgo de seguridad corregido: antes, un telefono vacio se saltaba por
        // completo el control de "un codigo por telefono", permitiendo redimir el mismo
        // codigo un numero ilimitado de veces con solo omitir el telefono. Ahora se
        // rechaza explicitamente.
        $this->seedCode(1, 'NOPHON');

        $result = referralValidate($this->pdo, 'NOPHON', 2, '', 500.0);
        $this->assertFalse($result['valido']);
        $this->assertSame('telefono_requerido', $result['motivo']);
    }

    public function testSamePhoneCannotRedeemTwoDifferentCodes(): void
    {
        $this->seedCode(1, 'CODEA1');
        $this->seedCode(2, 'CODEB1');

        $primero = referralValidate($this->pdo, 'CODEA1', 3, '3311119999', 500.0);
        $this->assertTrue($primero['valido']);
        referralRecordUsage($this->pdo, 100, 'CODEA1', 1, 3, '3311119999', $primero['descuento']);

        // Mismo telefono, codigo DISTINTO: tambien debe rechazarse (una redencion de por
        // vida, sin importar cual codigo haya sido).
        $segundo = referralValidate($this->pdo, 'CODEB1', 3, '3311119999', 500.0);
        $this->assertFalse($segundo['valido']);
        $this->assertSame('telefono_ya_redimio_un_codigo', $segundo['motivo']);
    }

    public function testMinimumOrderAmountBlocksDiscountBelowThreshold(): void
    {
        $this->seedFeatureConfig(10.0, 100.0);
        $this->seedCode(1, 'MINORD');

        $bajoMinimo = referralValidate($this->pdo, 'MINORD', 2, '3311110011', 99.99);
        $this->assertFalse($bajoMinimo['valido']);
        $this->assertSame('monto_minimo_no_alcanzado', $bajoMinimo['motivo']);

        $enElMinimo = referralValidate($this->pdo, 'MINORD', 2, '3311110012', 100.0);
        $this->assertTrue($enElMinimo['valido']);
    }

    /* =====================================================================
     * CALCULO DE DESCUENTO -- LIMITES
     * ===================================================================== */

    public function testDiscountPercentageIsClampedToZeroAndHundredEvenWithHostileConfig(): void
    {
        $this->seedCode(1, 'NEGPCT');

        // Configuracion corrupta/hostil: porcentaje negativo no debe generar un
        // "descuento negativo" (que subiria el total en vez de bajarlo).
        $this->seedFeatureConfig(-500.0, 0.0);
        $resultNegativo = referralValidate($this->pdo, 'NEGPCT', 2, '3311110013', 1000.0);
        $this->assertTrue($resultNegativo['valido']);
        $this->assertSame(0.0, $resultNegativo['descuento']);

        // Porcentaje absurdamente alto no debe descontar mas del 100% del subtotal.
        $this->seedFeatureConfig(99999.0, 0.0);
        $resultExcesivo = referralValidate($this->pdo, 'NEGPCT', 2, '3311110014', 1000.0);
        $this->assertTrue($resultExcesivo['valido']);
        $this->assertSame(1000.0, $resultExcesivo['descuento']);
    }

    public function testNonNumericDiscountConfigFallsBackSafelyInsteadOfCrashing(): void
    {
        $this->pdo->prepare('UPDATE ventas_features_config SET config_json = ? WHERE feature_key = ?')
            ->execute(['{"descuento_porcentaje": "no-es-un-numero", "monto_minimo_pedido": 0}', 'programa_referidos']);
        $this->seedCode(1, 'BADCFG');

        $result = referralValidate($this->pdo, 'BADCFG', 2, '3311110015', 500.0);

        $this->assertTrue($result['valido']);
        $this->assertSame(0.0, $result['descuento']);
    }

    public function testMalformedJsonConfigFallsBackToDefaultDiscountInsteadOfCrashing(): void
    {
        $this->pdo->prepare('UPDATE ventas_features_config SET config_json = ? WHERE feature_key = ?')
            ->execute(['{esto no es json valido', 'programa_referidos']);
        $this->seedCode(1, 'BADJSON');

        $result = referralValidate($this->pdo, 'BADJSON', 2, '3311110016', 500.0);

        // ventasFeatureConfig() ignora JSON invalido y regresa [] -> se usan los defaults
        // hardcodeados de referralValidate() (10%).
        $this->assertTrue($result['valido']);
        $this->assertSame(50.0, $result['descuento']);
    }

    public function testValidateHandlesNegativeAndZeroSubtotalWithoutCrashing(): void
    {
        $this->seedCode(1, 'ZEROSUB');

        $resultZero = referralValidate($this->pdo, 'ZEROSUB', 2, '3311110017', 0.0);
        $this->assertTrue($resultZero['valido']);
        $this->assertSame(0.0, $resultZero['descuento']);

        $resultNeg = referralValidate($this->pdo, 'ZEROSUB', 3, '3311110018', -50.0);
        // Subtotal negativo (dato corrupto/manipulado): al ser menor que el monto minimo
        // (0 por defecto), se rechaza por esa via -- no debe generar un "descuento
        // negativo" (que subiria el total en vez de bajarlo) ni tronar.
        $this->assertFalse($resultNeg['valido']);
        $this->assertSame('monto_minimo_no_alcanzado', $resultNeg['motivo']);
        $this->assertSame(0.0, $resultNeg['descuento']);
    }

    /* =====================================================================
     * REGISTRO DE USO -- INTEGRIDAD DE DATOS
     * ===================================================================== */

    public function testRecordUsageAlwaysStoresCodeInUppercase(): void
    {
        $this->seedCode(1, 'UPPER1');
        referralRecordUsage($this->pdo, 200, 'upper1', 1, 2, '3311110019', 50.0);

        $stored = $this->pdo->query('SELECT codigo FROM referidos_usos WHERE id_pedido = 200')->fetch();
        $this->assertSame('UPPER1', $stored['codigo']);
    }

    public function testRecordUsageStoresHostileTextVerbatimViaBoundParameters(): void
    {
        // Si el bind no fuera seguro, este INSERT ya habria corrompido la tabla o
        // ejecutado la sub-sentencia maliciosa.
        $this->seedCode(1, 'BINDT1');
        $codigoHostil = "BINDT1'; DROP TABLE referidos_usos; --";

        referralRecordUsage($this->pdo, 300, $codigoHostil, 1, 2, '3311110020', 50.0);

        $count = (int) $this->pdo->query('SELECT COUNT(*) FROM referidos_usos')->fetchColumn();
        $this->assertSame(1, $count);
    }

    /* =====================================================================
     * Fixtures
     * ===================================================================== */

    private function createSchema(): void
    {
        $this->pdo->exec(
            'CREATE TABLE codigos_referido (
                id_cliente INTEGER PRIMARY KEY,
                codigo TEXT NOT NULL UNIQUE,
                creado_en TEXT DEFAULT CURRENT_TIMESTAMP
            )'
        );
        $this->pdo->exec(
            'CREATE TABLE referidos_usos (
                id_uso INTEGER PRIMARY KEY AUTOINCREMENT,
                id_pedido INTEGER NOT NULL,
                codigo TEXT NOT NULL,
                id_cliente_referidor INTEGER NOT NULL,
                id_cliente_referido INTEGER NULL,
                telefono_referido_digits TEXT NULL,
                descuento_aplicado REAL NOT NULL DEFAULT 0,
                creado_en TEXT DEFAULT CURRENT_TIMESTAMP
            )'
        );
        $this->pdo->exec(
            'CREATE TABLE ventas_features_config (
                feature_key TEXT PRIMARY KEY,
                activo INTEGER NOT NULL DEFAULT 0,
                config_json TEXT NULL
            )'
        );
    }

    private function seedCode(int $idCliente, string $codigo): void
    {
        $this->pdo->prepare('INSERT INTO codigos_referido (id_cliente, codigo) VALUES (?, ?)')
            ->execute([$idCliente, $codigo]);
    }

    private function seedFeatureConfig(float $descuentoPorcentaje, float $montoMinimo): void
    {
        $configJson = json_encode(['descuento_porcentaje' => $descuentoPorcentaje, 'monto_minimo_pedido' => $montoMinimo]);
        $this->pdo->exec('DELETE FROM ventas_features_config WHERE feature_key = "programa_referidos"');
        $this->pdo->prepare('INSERT INTO ventas_features_config (feature_key, activo, config_json) VALUES ("programa_referidos", 1, ?)')
            ->execute([$configJson]);
    }
}
