<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class MigrationsNamingTest extends TestCase
{
    public function testParseMigrationVersionReturnsVersionFromValidFileName(): void
    {
        $version = parseMigrationVersion('20260628_120001_create_users_table.sql');

        $this->assertSame('20260628_120001', $version);
    }

    public function testParseMigrationVersionThrowsForInvalidFileName(): void
    {
        $this->expectException(RuntimeException::class);

        parseMigrationVersion('invalid_migration_name.sql');
    }

    public function testGetMigrationFilesReturnsSortedSqlFiles(): void
    {
        $dir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'migrations_test_' . uniqid('', true);
        mkdir($dir, 0777, true);

        file_put_contents($dir . DIRECTORY_SEPARATOR . '20260628_120002_second.sql', 'SELECT 2;');
        file_put_contents($dir . DIRECTORY_SEPARATOR . '20260628_120001_first.sql', 'SELECT 1;');
        file_put_contents($dir . DIRECTORY_SEPARATOR . 'notes.txt', 'ignore');

        $files = getMigrationFiles($dir);

        $this->assertCount(2, $files);
        $this->assertSame('20260628_120001_first.sql', basename($files[0]));
        $this->assertSame('20260628_120002_second.sql', basename($files[1]));

        unlink($dir . DIRECTORY_SEPARATOR . '20260628_120002_second.sql');
        unlink($dir . DIRECTORY_SEPARATOR . '20260628_120001_first.sql');
        unlink($dir . DIRECTORY_SEPARATOR . 'notes.txt');
        rmdir($dir);
    }
}
