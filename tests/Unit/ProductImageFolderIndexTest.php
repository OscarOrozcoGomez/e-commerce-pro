<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class ProductImageFolderIndexTest extends TestCase
{
    /** @var string[] */
    private array $tempDirs = [];

    /** @var string[] */
    private array $tempFiles = [];

    protected function tearDown(): void
    {
        foreach ($this->tempFiles as $file) {
            if (is_file($file)) {
                unlink($file);
            }
        }
        foreach ($this->tempDirs as $dir) {
            $this->removeDirRecursive($dir);
        }
        $this->tempFiles = [];
        $this->tempDirs = [];

        parent::tearDown();
    }

    public function testBuildsIndexFromScratchAndPersistsCacheFile(): void
    {
        $baseDir = $this->makeTempProductsDir([
            'producto-uno-10',
            'producto-dos-20',
        ]);
        $cacheFile = $this->tempCacheFilePath();

        $map = productImageFolderIndex($baseDir, $cacheFile);

        $this->assertSame(['producto-uno-10'], $map[10]);
        $this->assertSame(['producto-dos-20'], $map[20]);

        $this->assertFileExists($cacheFile);
        $decoded = json_decode((string) file_get_contents($cacheFile), true);
        $this->assertIsArray($decoded);
        $this->assertSame(filemtime($baseDir), $decoded['dir_mtime']);
        $this->assertEqualsWithDelta(time(), $decoded['built_at'], 5);
        $this->assertSame(['producto-uno-10'], $decoded['map']['10']);
    }

    public function testIgnoresNonMatchingFoldersAndPlainFiles(): void
    {
        $baseDir = $this->makeTempProductsDir([
            'producto-valido-5',
            'carpeta-sin-sufijo-numerico',
        ]);
        // Un archivo suelto (no carpeta) directo en el directorio base debe ignorarse.
        file_put_contents($baseDir . DIRECTORY_SEPARATOR . 'nota-7.txt', 'no es una carpeta de producto');

        $map = productImageFolderIndex($baseDir, $this->tempCacheFilePath());

        $this->assertSame([5], array_keys($map));
        $this->assertArrayNotHasKey(7, $map);
    }

    public function testGroupsMultipleFoldersUnderTheSameProductId(): void
    {
        $baseDir = $this->makeTempProductsDir([
            'variante-a-9',
            'variante-b-9',
        ]);

        $map = productImageFolderIndex($baseDir, $this->tempCacheFilePath());

        $this->assertCount(2, $map[9]);
        $this->assertContains('variante-a-9', $map[9]);
        $this->assertContains('variante-b-9', $map[9]);
    }

    public function testReturnsEmptyArrayWhenBaseDirDoesNotExist(): void
    {
        $map = productImageFolderIndex(sys_get_temp_dir() . '/no-existe-' . uniqid(), $this->tempCacheFilePath());

        $this->assertSame([], $map);
    }

    public function testReusesFreshCacheFileInsteadOfRescanningDisk(): void
    {
        $baseDir = $this->makeTempProductsDir(['producto-real-1']);
        $cacheFile = $this->tempCacheFilePath();

        // Se escribe un cache "fresco" (mtime correcto, recien construido) pero con
        // datos fabricados que no corresponden al disco real, para comprobar que la
        // funcion confia en el cache en vez de volver a escanear.
        file_put_contents($cacheFile, json_encode([
            'dir_mtime' => filemtime($baseDir),
            'built_at' => time(),
            'map' => ['999' => ['carpeta-inventada-999']],
        ]));

        $map = productImageFolderIndex($baseDir, $cacheFile);

        $this->assertSame(['carpeta-inventada-999'], $map[999]);
        $this->assertArrayNotHasKey(1, $map);
    }

    public function testRebuildsWhenDirectoryMtimeChanged(): void
    {
        $baseDir = $this->makeTempProductsDir(['producto-real-2']);
        $cacheFile = $this->tempCacheFilePath();

        // dir_mtime fabricado y a proposito distinto del real: debe forzar reconstruccion.
        file_put_contents($cacheFile, json_encode([
            'dir_mtime' => 1,
            'built_at' => time(),
            'map' => ['999' => ['carpeta-inventada-999']],
        ]));

        $map = productImageFolderIndex($baseDir, $cacheFile);

        $this->assertArrayNotHasKey(999, $map);
        $this->assertSame(['producto-real-2'], $map[2]);
    }

    public function testRebuildsWhenCacheIsOlderThanTtl(): void
    {
        $baseDir = $this->makeTempProductsDir(['producto-real-3']);
        $cacheFile = $this->tempCacheFilePath();

        // dir_mtime correcto pero built_at de hace mas de 15 minutos: debe expirar por TTL.
        file_put_contents($cacheFile, json_encode([
            'dir_mtime' => filemtime($baseDir),
            'built_at' => time() - 1000,
            'map' => ['999' => ['carpeta-inventada-999']],
        ]));

        $map = productImageFolderIndex($baseDir, $cacheFile);

        $this->assertArrayNotHasKey(999, $map);
        $this->assertSame(['producto-real-3'], $map[3]);
    }

    public function testDoesNotLeakCacheBetweenDifferentBaseDirs(): void
    {
        $baseDirA = $this->makeTempProductsDir(['producto-a-1']);
        $baseDirB = $this->makeTempProductsDir(['producto-b-2']);

        $mapA = productImageFolderIndex($baseDirA, $this->tempCacheFilePath());
        $mapB = productImageFolderIndex($baseDirB, $this->tempCacheFilePath());

        $this->assertSame(['producto-a-1'], $mapA[1]);
        $this->assertArrayNotHasKey(2, $mapA);
        $this->assertSame(['producto-b-2'], $mapB[2]);
        $this->assertArrayNotHasKey(1, $mapB);
    }

    /**
     * @param string[] $folderNames
     */
    private function makeTempProductsDir(array $folderNames): string
    {
        $dir = sys_get_temp_dir() . '/pifi_test_' . uniqid('', true);
        mkdir($dir, 0755, true);
        $this->tempDirs[] = $dir;

        foreach ($folderNames as $name) {
            mkdir($dir . DIRECTORY_SEPARATOR . $name, 0755, true);
        }

        return $dir;
    }

    private function tempCacheFilePath(): string
    {
        $file = sys_get_temp_dir() . '/pifi_cache_' . uniqid('', true) . '.json';
        $this->tempFiles[] = $file;

        return $file;
    }

    private function removeDirRecursive(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }

        $items = scandir($dir);
        if ($items === false) {
            return;
        }

        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            $path = $dir . DIRECTORY_SEPARATOR . $item;
            if (is_dir($path)) {
                $this->removeDirRecursive($path);
            } else {
                unlink($path);
            }
        }

        rmdir($dir);
    }
}
