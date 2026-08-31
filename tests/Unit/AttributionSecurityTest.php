<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Pruebas negativas/de seguridad para core/attribution.php: classifyPlatform()
 * recibe datos directo del body JSON publico de api/log_activity.php (no confiable),
 * y getLastTouchAttribution() alimenta la atribucion que se congela en pedidos reales
 * -- ambas deben resistir entradas hostiles/corruptas sin tronar ni comportarse de
 * forma explotable.
 */
final class AttributionSecurityTest extends TestCase
{
    private ?string $originalHttpHost;

    protected function setUp(): void
    {
        parent::setUp();
        $this->originalHttpHost = $_SERVER['HTTP_HOST'] ?? null;
        $_SERVER['HTTP_HOST'] = 'midominio.com';
    }

    protected function tearDown(): void
    {
        if ($this->originalHttpHost === null) {
            unset($_SERVER['HTTP_HOST']);
        } else {
            $_SERVER['HTTP_HOST'] = $this->originalHttpHost;
        }
        parent::tearDown();
    }

    /* =====================================================================
     * classifyPlatform() -- camino feliz (referencia rapida, ya cubierto
     * indirectamente por los tests negativos, pero se deja explicito)
     * ===================================================================== */

    public function testClassifiesGoogleAdsByGclid(): void
    {
        $this->assertSame('google_ads', classifyPlatform(['gclid' => 'abc123']));
    }

    public function testClassifiesGoogleAdsByUtmSourceVariants(): void
    {
        $this->assertSame('google_ads', classifyPlatform(['utm_source' => 'google']));
        $this->assertSame('google_ads', classifyPlatform(['utm_source' => 'Google_Ads']));
        $this->assertSame('google_ads', classifyPlatform(['utm_source' => 'ADWORDS']));
    }

    public function testClassifiesFacebookAdsByUtmSourceOrPaidSocialMedium(): void
    {
        $this->assertSame('facebook_ads', classifyPlatform(['utm_source' => 'facebook']));
        $this->assertSame('facebook_ads', classifyPlatform(['utm_source' => 'instagram']));
        $this->assertSame('facebook_ads', classifyPlatform(['utm_medium' => 'paid_social']));
    }

    public function testClassifiesOrganicFromKnownSearchEngineReferrer(): void
    {
        $this->assertSame('organic', classifyPlatform(['referrer' => 'https://www.google.com/search?q=x']));
        $this->assertSame('organic', classifyPlatform(['referrer' => 'https://www.bing.com/search?q=x']));
    }

    public function testClassifiesDirectWhenReferrerIsSameHost(): void
    {
        $this->assertSame('direct', classifyPlatform(['referrer' => 'https://midominio.com/catalogo']));
    }

    public function testClassifiesReferralForUnknownExternalReferrer(): void
    {
        $this->assertSame('referral', classifyPlatform(['referrer' => 'https://algunblog.com/post']));
    }

    public function testClassifiesDirectWithNoDataAtAll(): void
    {
        $this->assertSame('direct', classifyPlatform([]));
    }

    /* =====================================================================
     * classifyPlatform() -- entradas hostiles / type confusion
     * ===================================================================== */

    public function testHandlesArrayInsteadOfStringForUtmSourceWithoutCrashing(): void
    {
        // Type confusion: "utm_source": ["google"] en vez de "google". Un array no es
        // escalar -> se trata como ausente (no como "Array" via cast implicito), y sin
        // ningun otro dato termina en 'direct'. No debe emitir warnings ni tronar.
        $resultado = classifyPlatform(['utm_source' => ['google']]);
        $this->assertSame('direct', $resultado);
    }

    public function testHandlesArrayInsteadOfStringForReferrerWithoutCrashing(): void
    {
        $resultado = classifyPlatform(['referrer' => ['https://google.com']]);
        $this->assertSame('direct', $resultado);
    }

    public function testHandlesNestedArrayForGclidWithoutCrashing(): void
    {
        // Un gclid que en realidad es un array/objeto (payload manipulado) NO debe
        // contar como "tiene click id de Google" -- antes de la version defensiva de
        // attributionExtractScalarString(), !empty() aceptaba cualquier array no vacio
        // y esto se colaba como 'google_ads' falso positivo.
        $resultado = classifyPlatform(['gclid' => ['a' => 'b']]);
        $this->assertNotSame('google_ads', $resultado);
        $this->assertSame('direct', $resultado);
    }

    public function testHandlesExtremelyLongUtmSourceWithoutCrashing(): void
    {
        $resultado = classifyPlatform(['utm_source' => str_repeat('a', 100000)]);
        $this->assertIsString($resultado);
        $this->assertSame(100000, strlen($resultado));
    }

    public function testSqlInjectionStyleUtmSourceIsTreatedAsPlainTextLabel(): void
    {
        // No hay SQL involucrado aqui (es clasificacion en memoria), pero se documenta que
        // un utm_source hostil se guarda tal cual como "plataforma" -- el llamador
        // (api/log_activity.php) es responsable de que el INSERT sea parametrizado.
        $malicioso = "'; DROP TABLE logs_actividad; --";
        // classifyPlatform() normaliza a minusculas (para las comparaciones de plataformas
        // conocidas); un utm_source que no matchea nada conocido se regresa tal cual, en
        // minusculas.
        $this->assertSame(strtolower($malicioso), classifyPlatform(['utm_source' => $malicioso]));
    }

    public function testHandlesNullBytesAndUnicodeInReferrerWithoutCrashing(): void
    {
        $resultado = classifyPlatform(['referrer' => "https://algo\0raro.com/x 😀"]);
        $this->assertIsString($resultado);
    }

    public function testHandlesMalformedUrlReferrerWithoutCrashing(): void
    {
        // parse_url() puede devolver false ante URLs severamente malformadas; el codigo
        // debe tratarlo igual que "sin host" (direct), no tronar con TypeError.
        $resultado = classifyPlatform(['referrer' => 'http:///:::///malformed']);
        $this->assertIsString($resultado);
    }

    public function testHandlesReferrerWithNoSchemeOrHostGracefully(): void
    {
        $this->assertSame('direct', classifyPlatform(['referrer' => 'javascript:alert(1)']));
        $this->assertSame('direct', classifyPlatform(['referrer' => '/relative/path']));
    }

    public function testUtmMediumTypeConfusionDoesNotFalselyMatchPaidSocial(): void
    {
        $resultado = classifyPlatform(['utm_medium' => ['paid_social']]);
        $this->assertNotSame('facebook_ads', $resultado);
    }

    public function testEmptyStringValuesForEverythingReturnsDirect(): void
    {
        $resultado = classifyPlatform([
            'utm_source' => '', 'utm_medium' => '', 'gclid' => '', 'wbraid' => '', 'gbraid' => '', 'referrer' => '',
        ]);
        $this->assertSame('direct', $resultado);
    }

    public function testWhitespaceOnlyUtmSourceIsTreatedAsEmpty(): void
    {
        $this->assertSame('direct', classifyPlatform(['utm_source' => '   ']));
    }

    /* =====================================================================
     * getLastTouchAttribution() -- entradas hostiles
     * ===================================================================== */

    public function testRejectsVisitorIdWithSqlInjectionWithoutTouchingDatabase(): void
    {
        $pdo = $this->createPdoWithTable();

        $resultado = getLastTouchAttribution($pdo, "abc' OR '1'='1");
        $this->assertNull($resultado['visitor_id']);
        $this->assertNull($resultado['plataforma']);
    }

    public function testRejectsVisitorIdWithWrongLength(): void
    {
        $pdo = $this->createPdoWithTable();

        $this->assertNull(getLastTouchAttribution($pdo, 'a')['visitor_id']);
        $this->assertNull(getLastTouchAttribution($pdo, str_repeat('a', 31))['visitor_id']);
        $this->assertNull(getLastTouchAttribution($pdo, str_repeat('a', 33))['visitor_id']);
    }

    public function testRejectsVisitorIdWithNonHexCharacters(): void
    {
        $pdo = $this->createPdoWithTable();
        $casiValido = str_repeat('g', 32); // 32 caracteres pero 'g' no es hex.
        $this->assertNull(getLastTouchAttribution($pdo, $casiValido)['visitor_id']);
    }

    public function testRejectsNullVisitorId(): void
    {
        $pdo = $this->createPdoWithTable();
        $resultado = getLastTouchAttribution($pdo, null);
        $this->assertNull($resultado['visitor_id']);
        $this->assertNull($resultado['plataforma']);
        $this->assertNull($resultado['utm_source']);
        $this->assertNull($resultado['utm_campaign']);
    }

    public function testReturnsEmptyWhenNoVisitsMatchTheVisitorId(): void
    {
        $pdo = $this->createPdoWithTable();
        $visitorId = str_repeat('a', 32);

        // Un visitor_id con formato valido pero sin ninguna visita registrada: no hay
        // nada que atribuir, se regresa todo null (incluido visitor_id) en vez de
        // "inventar" una atribucion vacia con ese id.
        $resultado = getLastTouchAttribution($pdo, $visitorId);
        $this->assertNull($resultado['visitor_id']);
        $this->assertNull($resultado['plataforma']);
    }

    public function testReturnsMostRecentVisitWhenMultipleExist(): void
    {
        $pdo = $this->createPdoWithTable();
        $visitorId = str_repeat('b', 32);

        $this->seedVisit($pdo, $visitorId, 'organic', 'google', null, '-2 days');
        $this->seedVisit($pdo, $visitorId, 'google_ads', 'google', 'campana_vieja', '-1 day');
        $this->seedVisit($pdo, $visitorId, 'facebook_ads', 'facebook', 'campana_nueva', '-1 hour');

        $resultado = getLastTouchAttribution($pdo, $visitorId);
        $this->assertSame('facebook_ads', $resultado['plataforma']);
        $this->assertSame('campana_nueva', $resultado['utm_campaign']);
    }

    public function testIgnoresVisitsWithNullPlatformEvenIfMoreRecent(): void
    {
        $pdo = $this->createPdoWithTable();
        $visitorId = str_repeat('c', 32);

        $this->seedVisit($pdo, $visitorId, 'google_ads', 'google', 'campana_valida', '-1 day');
        // Una visita mas reciente pero sin plataforma clasificada (por ejemplo, un click
        // en vez de un visit, o una fila corrupta) no debe pisar la atribucion valida.
        $this->seedVisit($pdo, $visitorId, null, null, null, '-1 minute');

        $resultado = getLastTouchAttribution($pdo, $visitorId);
        $this->assertSame('google_ads', $resultado['plataforma']);
    }

    public function testReturnsEmptyGracefullyWhenTableIsMissingInsteadOfThrowing(): void
    {
        // BD/tabla no disponible (por ejemplo, entre migraciones): la atribucion es
        // informativa, no debe romper la creacion del pedido.
        $pdo = new PDO('sqlite::memory:');
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

        $resultado = getLastTouchAttribution($pdo, str_repeat('d', 32));
        $this->assertNull($resultado['plataforma']);
        $this->assertNull($resultado['visitor_id']);
    }

    /* =====================================================================
     * Fixtures
     * ===================================================================== */

    private function createPdoWithTable(): PDO
    {
        $pdo = new PDO('sqlite::memory:');
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);

        $pdo->exec(
            'CREATE TABLE logs_actividad (
                id_log INTEGER PRIMARY KEY AUTOINCREMENT,
                tipo_accion TEXT NOT NULL,
                visitor_id TEXT NULL,
                plataforma TEXT NULL,
                utm_source TEXT NULL,
                utm_campaign TEXT NULL,
                fecha_creacion TEXT DEFAULT CURRENT_TIMESTAMP
            )'
        );

        return $pdo;
    }

    private function seedVisit(PDO $pdo, string $visitorId, ?string $plataforma, ?string $utmSource, ?string $utmCampaign, string $tiempoRelativo): void
    {
        $fecha = date('Y-m-d H:i:s', strtotime($tiempoRelativo));
        $pdo->prepare(
            'INSERT INTO logs_actividad (tipo_accion, visitor_id, plataforma, utm_source, utm_campaign, fecha_creacion)
             VALUES ("visit", ?, ?, ?, ?, ?)'
        )->execute([$visitorId, $plataforma, $utmSource, $utmCampaign, $fecha]);
    }
}
