<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Pruebas negativas/de seguridad para core/geo_lookup.php y el lector MMDB puro-PHP
 * (core/lib/MaxMindDb/Reader.php, escrito a mano porque el deploy no corre "composer
 * install"). El riesgo principal aqui no es "el pais sale mal" -- es que un archivo
 * .mmdb corrupto, truncado, o simplemente ausente tumbe el registro de la visita
 * completo. lookupGeo() nunca debe lanzar; el Reader de bajo nivel si puede lanzar,
 * pero siempre con una excepcion capturable, nunca un error fatal/loop infinito.
 */
final class GeoLookupSecurityTest extends TestCase
{
    /** @var string[] */
    private array $tempFiles = [];

    protected function tearDown(): void
    {
        foreach ($this->tempFiles as $file) {
            if (is_file($file)) {
                @unlink($file);
            }
        }
        $this->tempFiles = [];
        parent::tearDown();
    }

    /* =====================================================================
     * lookupGeo() -- nunca debe lanzar, pase lo que pase
     * ===================================================================== */

    public function testReturnsEmptyForLoopbackAndPrivateAddressesWithoutTouchingFilesystem(): void
    {
        foreach (['127.0.0.1', '::1', '0.0.0.0', ''] as $ip) {
            $resultado = lookupGeo($ip, '/ruta/que/no/existe/nunca.mmdb');
            $this->assertNull($resultado['pais'], "IP: $ip");
            $this->assertNull($resultado['region'], "IP: $ip");
        }
    }

    public function testReturnsEmptyWhenDbFileDoesNotExist(): void
    {
        $resultado = lookupGeo('8.8.8.8', '/ruta/que/no/existe/GeoLite2-Country.mmdb');
        $this->assertNull($resultado['pais']);
        $this->assertNull($resultado['region']);
    }

    public function testReturnsEmptyWhenDbFileIsEmpty(): void
    {
        $path = $this->writeTempFile('');
        $resultado = lookupGeo('8.8.8.8', $path);
        $this->assertNull($resultado['pais']);
    }

    public function testReturnsEmptyWhenDbFileIsGarbageWithNoMetadataMarker(): void
    {
        $path = $this->writeTempFile(str_repeat('basura-no-es-un-mmdb-', 200));
        $resultado = lookupGeo('8.8.8.8', $path);
        $this->assertNull($resultado['pais']);
    }

    public function testReturnsEmptyWhenDbFileHasMarkerButTruncatedMetadata(): void
    {
        // El marcador esta, pero no hay nada legible despues -- decode() intentaria leer
        // mas alla del archivo.
        $path = $this->writeTempFile("\xAB\xCD\xEFMaxMind.com");
        $resultado = lookupGeo('8.8.8.8', $path);
        $this->assertNull($resultado['pais']);
    }

    public function testReturnsEmptyForMalformedIpAddressAgainstAValidDb(): void
    {
        $path = $this->writeTempFile($this->buildMinimalValidMmdb());

        foreach (['no-es-una-ip', '999.999.999.999', '', str_repeat('1', 5000)] as $ipHostil) {
            $resultado = lookupGeo($ipHostil, $path);
            $this->assertNull($resultado['pais'], "IP hostil: $ipHostil");
        }
    }

    public function testNullByteInIpIsTruncatedByThePhpCLayerRatherThanRejected(): void
    {
        // Comportamiento documentado, no una falla nuestra: inet_pton() en PHP delega a
        // la funcion C subyacente, que trabaja con strings terminados en null -- un byte
        // nulo embebido trunca la cadena ahi, asi que "1.2.3.4\0lo-que-sea" se interpreta
        // como la IP valida "1.2.3.4", no se rechaza. Se deja documentado para que no
        // sorprenda a futuro.
        $path = $this->writeTempFile($this->buildMinimalValidMmdb());
        $resultado = lookupGeo("1.2.3.4\0maligno", $path);
        $this->assertSame('MX', $resultado['pais']);
    }

    /* =====================================================================
     * lookupGeo() -- camino feliz con un .mmdb minimo pero real
     * ===================================================================== */

    public function testResolvesCountryForAMatchingIpUsingAMinimalRealMmdbFile(): void
    {
        $path = $this->writeTempFile($this->buildMinimalValidMmdb());

        // El arbol de 1 nodo de esta fixture resuelve a "MX" para cualquier IP cuyo
        // primer bit sea 0 (por ejemplo, cualquier direccion en 1.0.0.0/1).
        $resultado = lookupGeo('1.2.3.4', $path);
        $this->assertSame('MX', $resultado['pais']);
    }

    public function testReturnsNullCountryForAnUnmappedIpUsingTheSameMmdbFile(): void
    {
        $path = $this->writeTempFile($this->buildMinimalValidMmdb());

        // Direcciones cuyo primer bit es 1 (129.0.0.0/1 en adelante) no tienen datos en
        // esta fixture -- deben resolver a "no encontrado", no a un valor inventado.
        $resultado = lookupGeo('129.0.0.1', $path);
        $this->assertNull($resultado['pais']);
    }

    /* =====================================================================
     * MaxMindDb\Reader -- el parser de bajo nivel debe fallar limpio, nunca
     * con un error fatal / bucle infinito / lectura fuera de rango silenciosa
     * ===================================================================== */

    public function testReaderThrowsRuntimeExceptionForNonExistentFile(): void
    {
        $this->expectException(RuntimeException::class);
        new \MaxMindDb\Reader('/ruta/absolutamente/inexistente.mmdb');
    }

    public function testReaderThrowsRuntimeExceptionForEmptyFile(): void
    {
        $path = $this->writeTempFile('');
        $this->expectException(RuntimeException::class);
        new \MaxMindDb\Reader($path);
    }

    public function testReaderThrowsRuntimeExceptionWhenMetadataMarkerIsMissing(): void
    {
        $path = $this->writeTempFile(str_repeat("\x00", 500));
        $this->expectException(RuntimeException::class);
        new \MaxMindDb\Reader($path);
    }

    public function testReaderThrowsRuntimeExceptionWhenMetadataIsIncomplete(): void
    {
        // Marcador presente + un byte de "metadata" que no alcanza a describir un mapa
        // valido (falta node_count/record_size).
        $path = $this->writeTempFile("\xAB\xCD\xEFMaxMind.com" . "\xE0");
        $this->expectException(RuntimeException::class);
        new \MaxMindDb\Reader($path);
    }

    public function testReaderThrowsInsteadOfReadingPastEndOfFileOnCorruptedPointer(): void
    {
        // Arbol valido pero cuyo puntero de datos apunta fuera del archivo -- el read()
        // interno debe detectarlo (offset + length > fileSize) y lanzar, no devolver
        // basura ni intentar leer memoria/archivo fuera de rango.
        $bytes = $this->buildMinimalValidMmdb();
        // Corrompe el registro "left" del (unico) nodo para que apunte a un offset de
        // datos absurdamente grande (0xFFFFFF en vez de 0x000011).
        $corrupto = $bytes;
        $corrupto[0] = "\xFF";
        $corrupto[1] = "\xFF";
        $corrupto[2] = "\xFF";

        $path = $this->writeTempFile($corrupto);
        $reader = new \MaxMindDb\Reader($path);

        $this->expectException(RuntimeException::class);
        $reader->lookup('1.2.3.4');
    }

    public function testLookupGeoCatchesReaderExceptionFromCorruptedPointerInsteadOfPropagating(): void
    {
        // Mismo archivo corrupto que la prueba anterior, pero via lookupGeo(): la capa
        // publica debe atrapar la excepcion y responder vacio, nunca dejarla escapar
        // hacia api/log_activity.php (lo que tumbaria el registro completo de la visita).
        $bytes = $this->buildMinimalValidMmdb();
        $corrupto = $bytes;
        $corrupto[0] = "\xFF";
        $corrupto[1] = "\xFF";
        $corrupto[2] = "\xFF";

        $path = $this->writeTempFile($corrupto);
        $resultado = lookupGeo('1.2.3.4', $path);
        $this->assertNull($resultado['pais']);
    }

    public function testReaderHandlesUnsupportedRecordSizeAsCatchableException(): void
    {
        $bytes = $this->buildMinimalValidMmdb(recordSize: 20); // 20 no es 24/28/32.
        $path = $this->writeTempFile($bytes);

        $this->expectException(RuntimeException::class);
        (new \MaxMindDb\Reader($path))->lookup('1.2.3.4');
    }

    /* =====================================================================
     * Fixtures
     * ===================================================================== */

    private function writeTempFile(string $contents): string
    {
        $path = tempnam(sys_get_temp_dir(), 'mmdb_test_');
        file_put_contents($path, $contents);
        $this->tempFiles[] = $path;
        return $path;
    }

    /**
     * Construye a mano un archivo .mmdb minimo pero valido: un arbol de un solo nodo
     * (record_size configurable, 24 por defecto), donde cualquier IP cuyo primer bit
     * sea 0 resuelve a {"country":{"iso_code":"MX"}}, y cualquier IP cuyo primer bit
     * sea 1 no tiene datos. Formato: https://maxmind.github.io/MaxMind-DB/
     */
    private function buildMinimalValidMmdb(int $recordSize = 24): string
    {
        $recordBytes = intdiv($recordSize * 2, 8);

        // --- arbol de busqueda: 1 nodo, 2 registros de $recordBytes cada uno ---
        // left (bit=0)  -> pointer a offset 0 de la seccion de datos: valor = nodeCount(1) + 16 + 0 = 17
        // right (bit=1) -> valor = nodeCount(1) -> "sin datos"
        if ($recordSize === 24) {
            $tree = "\x00\x00\x11" . "\x00\x00\x01";
        } else {
            // Para tamanos de record no estandar (usado solo en el test de "record size
            // no soportado"), el contenido exacto no importa -- nunca se llega a leer.
            $tree = str_repeat("\x00", $recordBytes * 2);
        }

        $separator = str_repeat("\x00", 16);

        // --- seccion de datos: {"country": {"iso_code": "MX"}} ---
        $data = "\xE1"
            . "\x47" . 'country'
            . "\xE1"
            . "\x48" . 'iso_code'
            . "\x42" . 'MX';

        // --- metadata: marcador + mapa {node_count, record_size, ip_version} ---
        $metadata = "\xE3"
            . "\x4A" . 'node_count' . "\xC1" . "\x01"
            . "\x4B" . 'record_size' . "\xA1" . chr($recordSize)
            . "\x4A" . 'ip_version' . "\xA1" . "\x04";

        return $tree . $separator . $data . "\xAB\xCD\xEFMaxMind.com" . $metadata;
    }
}
