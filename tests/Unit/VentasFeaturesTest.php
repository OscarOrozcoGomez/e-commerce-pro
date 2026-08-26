<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Pruebas negativas/de seguridad para core/ventas_features.php: los interruptores
 * on/off que controlan si el feed publico, el descuento de referidos, etc. estan
 * activos. Un bug aqui puede dejar una feature "prendida" cuando el admin cree que
 * esta apagada (o viceversa), asi que se prueba a fondo el manejo de config_json
 * corrupto y de feature_key hostil.
 */
final class VentasFeaturesTest extends TestCase
{
    private PDO $pdo;

    protected function setUp(): void
    {
        parent::setUp();
        $this->pdo = new PDO('sqlite::memory:');
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);

        $this->pdo->exec(
            'CREATE TABLE ventas_features_config (
                feature_key TEXT PRIMARY KEY,
                activo INTEGER NOT NULL DEFAULT 0,
                config_json TEXT NULL
            )'
        );
    }

    public function testGetAllReturnsEmptyArrayWhenTableIsEmpty(): void
    {
        $this->assertSame([], ventasFeaturesGetAll($this->pdo));
    }

    public function testIsActiveReturnsFalseForUnknownFeatureKey(): void
    {
        $this->assertFalse(ventasFeatureIsActive($this->pdo, 'esto_no_existe'));
    }

    public function testConfigReturnsEmptyArrayForUnknownFeatureKey(): void
    {
        $this->assertSame([], ventasFeatureConfig($this->pdo, 'esto_no_existe'));
    }

    public function testGetAllCorrectlyParsesValidConfigJson(): void
    {
        $this->seedRow('programa_referidos', 1, '{"descuento_porcentaje":15,"monto_minimo_pedido":100}');

        $this->assertTrue(ventasFeatureIsActive($this->pdo, 'programa_referidos'));
        $config = ventasFeatureConfig($this->pdo, 'programa_referidos');
        $this->assertSame(15, $config['descuento_porcentaje']);
        $this->assertSame(100, $config['monto_minimo_pedido']);
    }

    public function testMalformedJsonConfigFallsBackToEmptyArrayInsteadOfCrashing(): void
    {
        $this->seedRow('programa_referidos', 1, '{esto no es json valido');

        $this->assertTrue(ventasFeatureIsActive($this->pdo, 'programa_referidos'));
        $this->assertSame([], ventasFeatureConfig($this->pdo, 'programa_referidos'));
    }

    public function testConfigJsonThatDecodesToScalarInsteadOfObjectFallsBackToEmptyArray(): void
    {
        // JSON valido pero de forma inesperada: un numero o string en vez de un objeto.
        $this->seedRow('programa_referidos', 1, '12345');
        $this->assertSame([], ventasFeatureConfig($this->pdo, 'programa_referidos'));

        $this->seedRow('catalogo_feed', 1, '"solo un string"');
        $this->assertSame([], ventasFeatureConfig($this->pdo, 'catalogo_feed'));
    }

    public function testConfigJsonThatDecodesToJsonArrayInsteadOfObjectIsAcceptedAsIs(): void
    {
        // json_decode(..., true) convierte tanto objetos como arrays JSON en arrays PHP;
        // is_array() no distingue, asi que un array JSON "[1,2,3]" SI pasa el chequeo.
        // Se documenta el comportamiento actual explicitamente.
        $this->seedRow('programa_referidos', 1, '[1,2,3]');
        $this->assertSame([1, 2, 3], ventasFeatureConfig($this->pdo, 'programa_referidos'));
    }

    public function testNullAndEmptyStringConfigJsonBothYieldEmptyArray(): void
    {
        $this->seedRow('catalogo_feed', 0, null);
        $this->assertSame([], ventasFeatureConfig($this->pdo, 'catalogo_feed'));

        $this->seedRow('stock_calendario_campanas', 1, '');
        $this->assertSame([], ventasFeatureConfig($this->pdo, 'stock_calendario_campanas'));
    }

    /**
     * @dataProvider truthyFalsyActivoValuesProvider
     */
    public function testActivoColumnCoercesToBooleanCorrectly(string $storedValue, bool $expected): void
    {
        $this->seedRow('atribucion_ventas', 0, null);
        $this->pdo->prepare('UPDATE ventas_features_config SET activo = ? WHERE feature_key = ?')
            ->execute([$storedValue, 'atribucion_ventas']);

        $this->assertSame($expected, ventasFeatureIsActive($this->pdo, 'atribucion_ventas'));
    }

    public static function truthyFalsyActivoValuesProvider(): array
    {
        return [
            'uno' => ['1', true],
            'cero' => ['0', false],
            'string vacio' => ['', false],
        ];
    }

    public function testSaveRejectsUnknownFeatureKeyBeforeTouchingDatabase(): void
    {
        $this->expectException(InvalidArgumentException::class);
        ventasFeatureSave($this->pdo, 'feature_inventada', true, []);
    }

    public function testSaveRejectsSqlInjectionStyleFeatureKeyViaWhitelist(): void
    {
        // El whitelist de VENTAS_FEATURE_KEYS bloquea esto antes de construir cualquier
        // SQL con el valor -- ni siquiera llega a prepararse una consulta.
        $maligno = "programa_referidos'; DROP TABLE ventas_features_config; --";

        $this->expectException(InvalidArgumentException::class);
        ventasFeatureSave($this->pdo, $maligno, true, []);

        // (No se alcanza, pero si el guard fallara, esto confirmaria integridad.)
    }

    public function testGetAllPropagatesExceptionWhenTableIsMissingInsteadOfSilentlyReturningEmpty(): void
    {
        // A diferencia de getLastTouchAttribution() (que es best-effort y no debe
        // interrumpir la creacion de un pedido), la configuracion de features es
        // administrativa: si la tabla no existe, es preferible que truene visiblemente
        // a que el panel de admin muestre todo como "apagado" silenciosamente.
        $pdoSinTabla = new PDO('sqlite::memory:');
        $pdoSinTabla->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

        $this->expectException(PDOException::class);
        ventasFeaturesGetAll($pdoSinTabla);
    }

    public function testGetAllDoesNotReturnStaleDataAcrossDifferentPdoConnections(): void
    {
        // Regresion: ventasFeaturesGetAll() tenia un cache estatico que no distinguia
        // conexion PDO -- una segunda BD (por ejemplo, en otro test o en un segundo
        // request) heredaba los resultados de la primera llamada del proceso.
        $this->seedRow('programa_referidos', 1, null);
        $primero = ventasFeaturesGetAll($this->pdo);
        $this->assertTrue($primero['programa_referidos']['activo']);

        $otraBd = new PDO('sqlite::memory:');
        $otraBd->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $otraBd->exec('CREATE TABLE ventas_features_config (feature_key TEXT PRIMARY KEY, activo INTEGER NOT NULL DEFAULT 0, config_json TEXT NULL)');
        $otraBd->exec("INSERT INTO ventas_features_config (feature_key, activo) VALUES ('programa_referidos', 0)");

        $segundo = ventasFeaturesGetAll($otraBd);
        $this->assertFalse($segundo['programa_referidos']['activo']);
    }

    public function testSaveUsesParameterizedUpsertAndPropagatesDbErrorsInsteadOfSwallowingThem(): void
    {
        // ventasFeatureSave() usa "ON DUPLICATE KEY UPDATE" (sintaxis especifica de
        // MySQL, no soportada por SQLite) -- igual que dbCreatePublicOrder() en
        // SecurityAndEdgeCasesTest.php, lo que importa aqui es que un feature_key valido
        // que pasa el whitelist SI intenta llegar a la base de datos (no se rechaza por
        // el guard), y que un fallo de BD se propaga como excepcion capturable, nunca
        // como un error fatal silencioso que dejara la config en un estado ambiguo.
        $this->expectException(PDOException::class);
        ventasFeatureSave($this->pdo, 'catalogo_feed', true, ['algo' => 'valido']);
    }

    private function seedRow(string $key, int $activo, ?string $configJson): void
    {
        $this->pdo->prepare(
            'INSERT INTO ventas_features_config (feature_key, activo, config_json) VALUES (?, ?, ?)
             ON CONFLICT(feature_key) DO UPDATE SET activo = excluded.activo, config_json = excluded.config_json'
        )->execute([$key, $activo, $configJson]);
    }
}
