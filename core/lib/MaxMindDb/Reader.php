<?php
declare(strict_types=1);

namespace MaxMindDb;

/**
 * Lector minimo, dependencia-cero, del formato binario MaxMind DB (.mmdb),
 * usado por las bases GeoLite2. Implementado a mano porque el deploy de este
 * proyecto es un sync FTP plano sin "composer install", así que una libreria
 * que solo se instale via Composer (geoip2/geoip2) nunca llegaria a produccion.
 *
 * Referencia del formato: https://maxmind.github.io/MaxMind-DB/
 */
final class Reader
{
    private const DATA_SECTION_SEPARATOR_SIZE = 16;
    private const METADATA_START_MARKER = "\xAB\xCD\xEFMaxMind.com";

    /** @var resource */
    private $handle;
    private int $fileSize;
    private array $metadata;
    private int $searchTreeSize;

    public function __construct(string $filePath)
    {
        if (!is_readable($filePath)) {
            throw new \RuntimeException('No se puede leer el archivo MMDB: ' . $filePath);
        }

        $handle = fopen($filePath, 'rb');
        if ($handle === false) {
            throw new \RuntimeException('No se pudo abrir el archivo MMDB: ' . $filePath);
        }
        $this->handle = $handle;

        $fileSize = filesize($filePath);
        $this->fileSize = $fileSize !== false ? $fileSize : 0;

        $this->metadata = $this->readMetadata();
        $this->searchTreeSize = ((int) $this->metadata['node_count']) * $this->recordBytes();
    }

    public function __destruct()
    {
        if (is_resource($this->handle)) {
            fclose($this->handle);
        }
    }

    /**
     * @return array<string, mixed>|null
     */
    public function lookup(string $ip): ?array
    {
        $packedIp = @inet_pton($ip);
        if ($packedIp === false) {
            return null;
        }

        $nodeCount = (int) $this->metadata['node_count'];
        $ipVersion = (int) ($this->metadata['ip_version'] ?? 4);
        $node = 0;

        // Las bases GeoLite2 modernas son ip_version=6 (soportan IPv4 e IPv6 en el mismo
        // arbol via el prefijo ::/96). Para una IP v4 hay que recorrer primero esos 96
        // niveles "en cero" antes de usar los bits reales de la direccion.
        if ($ipVersion === 6 && strlen($packedIp) === 4) {
            for ($i = 0; $i < 96; $i++) {
                if ($node >= $nodeCount) {
                    break;
                }
                $node = $this->readNode($node, 0);
            }
        }

        if ($node < $nodeCount) {
            foreach ($this->ipToBits($packedIp) as $bit) {
                if ($node >= $nodeCount) {
                    break;
                }
                $node = $this->readNode($node, $bit);
            }
        }

        if ($node <= $nodeCount) {
            // node === nodeCount: sin datos para esa IP. node < nodeCount: no deberia
            // pasar (arbol mal formado o IP mas corta de lo esperado); tratar como no encontrado.
            return null;
        }

        $dataOffset = $node - $nodeCount - self::DATA_SECTION_SEPARATOR_SIZE;
        $dataSectionStart = $this->searchTreeSize + self::DATA_SECTION_SEPARATOR_SIZE;
        [$value] = $this->decode($dataSectionStart + $dataOffset);

        return is_array($value) ? $value : null;
    }

    private function recordBytes(): int
    {
        return (int) (((int) $this->metadata['record_size'] * 2) / 8);
    }

    private function readMetadata(): array
    {
        $markerLen = strlen(self::METADATA_START_MARKER);
        $searchWindow = min($this->fileSize, 128 * 1024);
        if ($searchWindow <= 0) {
            throw new \RuntimeException('Archivo MMDB vacio o ilegible.');
        }

        fseek($this->handle, $this->fileSize - $searchWindow);
        $tail = fread($this->handle, $searchWindow);
        if ($tail === false) {
            throw new \RuntimeException('No se pudo leer el archivo MMDB.');
        }

        $markerPos = strrpos($tail, self::METADATA_START_MARKER);
        if ($markerPos === false) {
            throw new \RuntimeException('Archivo MMDB invalido: no se encontro el marcador de metadata.');
        }

        $metadataOffset = $this->fileSize - $searchWindow + $markerPos + $markerLen;
        [$metadata] = $this->decode($metadataOffset);
        if (!is_array($metadata) || !isset($metadata['node_count'], $metadata['record_size'])) {
            throw new \RuntimeException('Metadata de MMDB invalida.');
        }

        return $metadata;
    }

    /**
     * @return int[]
     */
    private function ipToBits(string $packedIp): array
    {
        $bits = [];
        foreach (str_split($packedIp) as $byte) {
            $ord = ord($byte);
            for ($i = 7; $i >= 0; $i--) {
                $bits[] = ($ord >> $i) & 1;
            }
        }
        return $bits;
    }

    private function readNode(int $nodeNumber, int $index): int
    {
        $recordSize = (int) $this->metadata['record_size'];
        $baseOffset = $nodeNumber * $this->recordBytes();

        if ($recordSize === 24) {
            $offset = $baseOffset + ($index === 0 ? 0 : 3);
            return $this->bytesToUint($this->read($offset, 3));
        }

        if ($recordSize === 28) {
            $middleByte = ord($this->read($baseOffset + 3, 1));
            if ($index === 0) {
                $prefix = $middleByte >> 4;
                return ($prefix << 24) | $this->bytesToUint($this->read($baseOffset, 3));
            }
            $prefix = $middleByte & 0x0F;
            return ($prefix << 24) | $this->bytesToUint($this->read($baseOffset + 4, 3));
        }

        if ($recordSize === 32) {
            $offset = $baseOffset + ($index === 0 ? 0 : 4);
            return $this->bytesToUint($this->read($offset, 4));
        }

        throw new \RuntimeException('Tamano de record no soportado en MMDB: ' . $recordSize);
    }

    private function bytesToUint(string $bytes): int
    {
        $value = 0;
        foreach (str_split($bytes) as $byte) {
            $value = ($value << 8) | ord($byte);
        }
        return $value;
    }

    private function read(int $offset, int $length): string
    {
        if ($offset < 0 || $length <= 0 || $offset + $length > $this->fileSize) {
            throw new \RuntimeException('Lectura fuera de rango en archivo MMDB.');
        }
        fseek($this->handle, $offset);
        $data = fread($this->handle, $length);
        if ($data === false || strlen($data) !== $length) {
            throw new \RuntimeException('Error de lectura en archivo MMDB.');
        }
        return $data;
    }

    /**
     * Decodifica un valor de la seccion de datos a partir de $offset.
     *
     * @return array{0: mixed, 1: int} [valor_decodificado, offset_despues_del_valor]
     */
    private function decode(int $offset): array
    {
        $controlByte = ord($this->read($offset, 1));
        $offset++;

        $type = $controlByte >> 5;

        if ($type === 1) {
            // Pointer: formato especial, no usa readSize().
            [$pointer, $offset] = $this->readPointer($controlByte, $offset);
            [$value] = $this->decode($pointer);
            return [$value, $offset];
        }

        if ($type === 0) {
            $nextByte = ord($this->read($offset, 1));
            $offset++;
            $type = 7 + $nextByte;
        }

        [$size, $offset] = $this->readSize($controlByte, $offset);

        switch ($type) {
            case 2: // utf8_string
            case 4: // bytes
                $bytes = $size > 0 ? $this->read($offset, $size) : '';
                return [$bytes, $offset + $size];

            case 3: // double
                $unpacked = unpack('E', $this->read($offset, 8));
                return [$unpacked[1], $offset + 8];

            case 5: // uint16
            case 6: // uint32
            case 9: // uint64 (best-effort, sin overflow real esperado en esta DB)
            case 10: // uint128 (idem)
                $bytes = $size > 0 ? $this->read($offset, $size) : '';
                return [$this->bytesToUint($bytes), $offset + $size];

            case 7: // map
                $map = [];
                $currentOffset = $offset;
                for ($i = 0; $i < $size; $i++) {
                    [$key, $currentOffset] = $this->decode($currentOffset);
                    [$value, $currentOffset] = $this->decode($currentOffset);
                    $map[(string) $key] = $value;
                }
                return [$map, $currentOffset];

            case 8: // int32
                $bytes = $size > 0 ? $this->read($offset, $size) : '';
                $value = $this->bytesToUint($bytes);
                if ($size > 0) {
                    $bitLength = $size * 8;
                    $signBit = 1 << ($bitLength - 1);
                    if ($value & $signBit) {
                        $value -= (1 << $bitLength);
                    }
                }
                return [$value, $offset + $size];

            case 11: // array
                $array = [];
                $currentOffset = $offset;
                for ($i = 0; $i < $size; $i++) {
                    [$value, $currentOffset] = $this->decode($currentOffset);
                    $array[] = $value;
                }
                return [$array, $currentOffset];

            case 14: // boolean: sin payload, el valor es el propio "size" (0 o 1)
                return [$size !== 0, $offset];

            case 15: // float
                $unpacked = unpack('G', $this->read($offset, 4));
                return [$unpacked[1], $offset + 4];

            default:
                throw new \RuntimeException('Tipo de dato MMDB no soportado: ' . $type);
        }
    }

    /**
     * @return array{0: int, 1: int} [size, offset_despues_del_size]
     */
    private function readSize(int $controlByte, int $offset): array
    {
        $size = $controlByte & 0x1F;

        if ($size < 29) {
            return [$size, $offset];
        }

        if ($size === 29) {
            $extra = ord($this->read($offset, 1));
            return [29 + $extra, $offset + 1];
        }

        if ($size === 30) {
            $extra = $this->bytesToUint($this->read($offset, 2));
            return [285 + $extra, $offset + 2];
        }

        // size === 31
        $extra = $this->bytesToUint($this->read($offset, 3));
        return [65821 + $extra, $offset + 3];
    }

    /**
     * @return array{0: int, 1: int} [offset_apuntado, offset_despues_del_pointer]
     */
    private function readPointer(int $controlByte, int $offset): array
    {
        $pointerSize = ($controlByte >> 3) & 0x3;
        $valuePart = $controlByte & 0x7;

        if ($pointerSize === 0) {
            $byte = ord($this->read($offset, 1));
            return [($valuePart << 8) | $byte, $offset + 1];
        }

        if ($pointerSize === 1) {
            $bytes = $this->bytesToUint($this->read($offset, 2));
            return [(($valuePart << 16) | $bytes) + 2048, $offset + 2];
        }

        if ($pointerSize === 2) {
            $bytes = $this->bytesToUint($this->read($offset, 3));
            return [(($valuePart << 24) | $bytes) + 526336, $offset + 3];
        }

        // pointerSize === 3: puntero absoluto de 4 bytes, ignora valuePart.
        $bytes = $this->bytesToUint($this->read($offset, 4));
        return [$bytes, $offset + 4];
    }
}
