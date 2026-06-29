<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../core/google_secret_manager.php';

final class GoogleSecretManagerCacheTest extends TestCase
{
    private array $originalSession = [];
    private array $originalServer = [];
    private array $originalEnv = [];

    protected function setUp(): void
    {
        parent::setUp();

        if (session_status() === PHP_SESSION_NONE) {
            @session_start();
        }

        $this->originalSession = $_SESSION ?? [];
        $this->originalServer = $_SERVER;
        $this->originalEnv = $_ENV;

        $_SESSION = [];

        putenv('GCP_PROJECT_ID');
        putenv('GOOGLE_CLOUD_PROJECT');
        putenv('GCLOUD_PROJECT');
        putenv('PROJECT_ID');
    }

    protected function tearDown(): void
    {
        $_SESSION = $this->originalSession;
        $_SERVER = $this->originalServer;
        $_ENV = $this->originalEnv;

        parent::tearDown();
    }

    public function testGetSessionSecretsCacheReturnsNullWhenMissing(): void
    {
        $this->assertNull(gsmGetSessionSecretsCache());
    }

    public function testSetAndGetSessionSecretsCacheRoundTrip(): void
    {
        $secrets = [
            'DB_HOST' => '127.0.0.1',
            'DB_NAME' => 'beautyandwell_prod',
        ];

        gsmSetSessionSecretsCache($secrets);

        $this->assertSame($secrets, gsmGetSessionSecretsCache());
        $this->assertArrayHasKey('app_secrets_cached_at', $_SESSION);
        $this->assertIsInt($_SESSION['app_secrets_cached_at']);
    }

    public function testSetSessionSecretsCacheIgnoresEmptyArrayEdgeCase(): void
    {
        gsmSetSessionSecretsCache([]);

        $this->assertArrayNotHasKey('app_secrets', $_SESSION);
        $this->assertNull(gsmGetSessionSecretsCache());
    }

    public function testClearSecretsCacheRemovesSessionEntries(): void
    {
        gsmSetSessionSecretsCache(['DB_HOST' => 'localhost']);
        $this->assertNotNull(gsmGetSessionSecretsCache());

        clear_secrets_cache();

        $this->assertNull(gsmGetSessionSecretsCache());
        $this->assertArrayNotHasKey('app_secrets_cached_at', $_SESSION);
    }

    public function testNormalizeMappingSortsKeysAndFiltersInvalidValues(): void
    {
        $mapping = [
            'MAPS_KEY' => [' MAPS_KEY ', '', 123, 'GOOGLE_MAPS_API_KEY'],
            '' => ['INVALID'],
            'DB_HOST' => ['DB_HOST', '  '],
            'DB_PASSWORD' => 'not-an-array',
        ];

        $normalized = gsmNormalizeMapping($mapping);

        $this->assertSame([
            'DB_HOST' => ['DB_HOST'],
            'MAPS_KEY' => ['MAPS_KEY', 'GOOGLE_MAPS_API_KEY'],
        ], $normalized);
    }

    public function testBuildCacheKeyIsStableForEquivalentMappings(): void
    {
        putenv('PROJECT_ID=test-project');

        $mappingA = [
            'DB_NAME' => ['DB_NAME'],
            'DB_HOST' => ['DB_HOST'],
        ];

        $mappingB = [
            'DB_HOST' => ['DB_HOST'],
            'DB_NAME' => ['DB_NAME'],
        ];

        $this->assertSame(gsmBuildCacheKey($mappingA), gsmBuildCacheKey($mappingB));
    }

    public function testLoadSecretsReturnsSessionCacheWithoutProjectId(): void
    {
        $_SESSION['app_secrets'] = [
            'DB_HOST' => '127.0.0.1',
            'DB_NAME' => 'local_db',
        ];

        $debug = [];
        $result = gsmLoadSecrets([
            'DB_HOST' => ['DB_HOST'],
            'DB_NAME' => ['DB_NAME'],
        ], $debug);

        $this->assertSame($_SESSION['app_secrets'], $result);
        $this->assertSame('cache:session', $debug['token_source'] ?? null);
        $this->assertTrue((bool)($debug['from_cache'] ?? false));
    }

    public function testLoadSecretsCachedReturnsSessionCacheAsPriority(): void
    {
        $_SESSION['app_secrets'] = [
            'DB_USER' => 'root',
            'DB_PASSWORD' => 'secret',
        ];

        $debug = [];
        $result = gsmLoadSecretsCached([
            'DB_USER' => ['DB_USER'],
            'DB_PASSWORD' => ['DB_PASSWORD'],
        ], $debug, 300);

        $this->assertSame($_SESSION['app_secrets'], $result);
        $this->assertSame('cache:session', $debug['token_source'] ?? null);
        $this->assertTrue((bool)($debug['from_cache'] ?? false));
    }

    public function testLoadSecretsReturnsEmptyWhenProjectIdMissingAndNoSessionCache(): void
    {
        $debug = [];
        $result = gsmLoadSecrets([
            'DB_HOST' => ['DB_HOST'],
        ], $debug);

        $this->assertSame([], $result);
        $this->assertNotEmpty($debug['errors'] ?? []);
    }
}
