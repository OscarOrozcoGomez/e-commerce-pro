<?php

declare(strict_types=1);

require_once __DIR__ . '/../../core/entrega_publicacion_utils.php';

use PHPUnit\Framework\TestCase;

final class EntregaPublicacionUtilsTest extends TestCase
{
    public function testNormalizeFotoUploadsDevuelveVacioSiNoHayCampo(): void
    {
        $this->assertSame([], epNormalizeFotoUploads(null));
        $this->assertSame([], epNormalizeFotoUploads([]));
    }

    public function testNormalizeFotoUploadsInputSimple(): void
    {
        $campo = [
            'name' => 'entrega.jpg',
            'type' => 'image/jpeg',
            'tmp_name' => '/tmp/php123',
            'error' => UPLOAD_ERR_OK,
            'size' => 5000,
        ];

        $out = epNormalizeFotoUploads($campo);

        $this->assertCount(1, $out);
        $this->assertSame('/tmp/php123', $out[0]['tmp_name']);
        $this->assertSame(5000, $out[0]['size']);
    }

    public function testNormalizeFotoUploadsInputSimpleSinArchivo(): void
    {
        $campo = [
            'name' => '',
            'type' => '',
            'tmp_name' => '',
            'error' => UPLOAD_ERR_NO_FILE,
            'size' => 0,
        ];

        $this->assertSame([], epNormalizeFotoUploads($campo));
    }

    public function testNormalizeFotoUploadsInputMultipleFiltraRanurasVacias(): void
    {
        $campo = [
            'name' => ['a.jpg', 'b.png', ''],
            'type' => ['image/jpeg', 'image/png', ''],
            'tmp_name' => ['/tmp/a', '/tmp/b', ''],
            'error' => [UPLOAD_ERR_OK, UPLOAD_ERR_OK, UPLOAD_ERR_NO_FILE],
            'size' => [10, 20, 0],
        ];

        $out = epNormalizeFotoUploads($campo);

        $this->assertCount(2, $out);
        $this->assertSame(['/tmp/a', '/tmp/b'], array_column($out, 'tmp_name'));
        $this->assertSame('image/png', $out[1]['type']);
    }

    public function testNormalizeFotoUploadsRecortaAlMaximo(): void
    {
        $total = ENTREGA_PUBLICACION_MAX_FOTOS + 5;
        $campo = [
            'name' => [],
            'type' => [],
            'tmp_name' => [],
            'error' => [],
            'size' => [],
        ];
        for ($i = 0; $i < $total; $i++) {
            $campo['name'][] = "f{$i}.jpg";
            $campo['type'][] = 'image/jpeg';
            $campo['tmp_name'][] = "/tmp/f{$i}";
            $campo['error'][] = UPLOAD_ERR_OK;
            $campo['size'][] = 100;
        }

        $out = epNormalizeFotoUploads($campo);

        $this->assertCount(ENTREGA_PUBLICACION_MAX_FOTOS, $out);
        $this->assertSame('/tmp/f0', $out[0]['tmp_name']);
    }

    public function testNormalizeIdPublicacionesCombinaListaYSuelto(): void
    {
        $this->assertSame([3, 5, 7], epNormalizeIdPublicaciones([3, '5', 7]));
        $this->assertSame([9], epNormalizeIdPublicaciones(null, '9'));
        $this->assertSame([3, 4], epNormalizeIdPublicaciones([3, 4], 3));
    }

    public function testNormalizeIdPublicacionesDescartaNoPositivosYNoNumericos(): void
    {
        $this->assertSame([2], epNormalizeIdPublicaciones([0, -1, 'x', 2, null]));
        $this->assertSame([], epNormalizeIdPublicaciones('no-es-lista'));
    }

    public function testBuildAttachedMediaFieldsIndexaYSerializa(): void
    {
        $fields = epBuildAttachedMediaFields(['111', 222, ' 333 ']);

        $this->assertSame(
            [
                'attached_media[0]' => '{"media_fbid":"111"}',
                'attached_media[1]' => '{"media_fbid":"222"}',
                'attached_media[2]' => '{"media_fbid":"333"}',
            ],
            $fields
        );
    }

    public function testBuildAttachedMediaFieldsIgnoraVacios(): void
    {
        $fields = epBuildAttachedMediaFields(['', '  ', '555']);

        $this->assertSame(['attached_media[0]' => '{"media_fbid":"555"}'], $fields);
    }
}
