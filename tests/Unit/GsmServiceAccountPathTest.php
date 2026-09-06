<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../core/google_secret_manager.php';

final class GsmServiceAccountPathTest extends TestCase
{
    private array $originalServer = [];
    /** @var array<string, string|false> */
    private array $originalEnv = [];

    private const ENV_KEYS = [
        'HOME', 'GCP_SA_KEY_FILE', 'GOOGLE_APPLICATION_CREDENTIALS', 'GCP_SERVICE_ACCOUNT_FILE',
    ];

    protected function setUp(): void
    {
        parent::setUp();
        $this->originalServer = $_SERVER;
        foreach (self::ENV_KEYS as $k) {
            $this->originalEnv[$k] = getenv($k);
            putenv($k);
            unset($_SERVER[$k], $_ENV[$k]);
        }
    }

    protected function tearDown(): void
    {
        $_SERVER = $this->originalServer;
        foreach (self::ENV_KEYS as $k) {
            $val = $this->originalEnv[$k] ?? false;
            if ($val === false) {
                putenv($k);
            } else {
                putenv($k . '=' . $val);
            }
        }
        parent::tearDown();
    }

    public function testConfiguredEnvPathIsFirstCandidate(): void
    {
        putenv('GCP_SA_KEY_FILE=/etc/secrets/sa.json');

        $candidates = gsmServiceAccountPathCandidates();

        $this->assertSame('/etc/secrets/sa.json', $candidates[0]);
    }

    public function testHomeEnvProducesDotGcpCandidate(): void
    {
        putenv('HOME=/home/beautyandwell');

        $candidates = gsmServiceAccountPathCandidates();

        $this->assertContains('/home/beautyandwell/.gcp/sa.json', $candidates);
    }

    public function testHomeIsDerivedFromDocumentRootWhenHomeEnvMissing(): void
    {
        // cPanel: HOME no expuesta al proceso web, sa.json un nivel arriba de public_html.
        $_SERVER['DOCUMENT_ROOT'] = '/home/beautyandwell/public_html';

        $candidates = gsmServiceAccountPathCandidates();

        $this->assertContains('/home/beautyandwell/.gcp/sa.json', $candidates);
    }

    public function testDerivedHomeHandlesOtherWebrootDirNames(): void
    {
        $_SERVER['DOCUMENT_ROOT'] = '/var/www/mysite/htdocs';

        $candidates = gsmServiceAccountPathCandidates();

        $this->assertContains('/var/www/mysite/.gcp/sa.json', $candidates);
    }

    public function testDocumentRootWithoutKnownWebrootSuffixDoesNotDeriveHome(): void
    {
        $_SERVER['DOCUMENT_ROOT'] = '/srv/app/current';

        $candidates = gsmServiceAccountPathCandidates();

        // Sin HOME, sin env path y sin sufijo de webroot reconocido -> nada que probar.
        $this->assertSame([], $candidates);
    }

    public function testCandidatesAreDeduplicated(): void
    {
        putenv('HOME=/home/beautyandwell');
        $_SERVER['DOCUMENT_ROOT'] = '/home/beautyandwell/public_html';

        $candidates = gsmServiceAccountPathCandidates();

        $this->assertSame($candidates, array_values(array_unique($candidates)));
    }

    public function testGetServiceAccountPathReturnsNullWhenNothingReadable(): void
    {
        putenv('GCP_SA_KEY_FILE=/nonexistent/path/definitely/not/here/sa.json');
        $_SERVER['DOCUMENT_ROOT'] = '/nonexistent/home/public_html';

        $this->assertNull(gsmGetServiceAccountPath());
    }
}
