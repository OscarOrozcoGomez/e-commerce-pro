<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class WhatsAppHelperTest extends TestCase
{
    public function testVerifyWebhookTokenRejectsWhenMissingOrMismatched(): void
    {
        $this->assertFalse(waVerifyWebhookToken('abc', null));
        $this->assertFalse(waVerifyWebhookToken('abc', ''));
        $this->assertFalse(waVerifyWebhookToken(null, 'secreto'));
        $this->assertFalse(waVerifyWebhookToken('otro', 'secreto'));
    }

    public function testVerifyWebhookTokenAcceptsExactMatch(): void
    {
        $this->assertTrue(waVerifyWebhookToken('secreto', 'secreto'));
    }

    public function testParseBridgePayloadExtractsPhoneDigitsAndMessage(): void
    {
        $result = waParseBridgePayload([
            'sender_phone' => '52133 1234 567',
            'message' => 'Hola, buscan omega 3?',
            'message_id' => 'ABCD1234',
        ]);

        $this->assertNotNull($result);
        $this->assertSame('521331234567', $result['wa_id']);
        $this->assertSame('Hola, buscan omega 3?', $result['texto']);
        $this->assertSame('ABCD1234', $result['wa_message_id']);
        $this->assertFalse($result['from_me']);
    }

    public function testParseBridgePayloadAllowsMissingMessageId(): void
    {
        $result = waParseBridgePayload(['sender_phone' => '5213312345', 'message' => 'Hola']);

        $this->assertNotNull($result);
        $this->assertNull($result['wa_message_id']);
    }

    public function testParseBridgePayloadExtractsFromMeFlag(): void
    {
        $result = waParseBridgePayload(['sender_phone' => '5213312345', 'message' => 'Hola, ya te apoyo yo', 'from_me' => true]);

        $this->assertNotNull($result);
        $this->assertTrue($result['from_me']);
    }

    public function testParseBridgePayloadReturnsNullWhenPhoneOrMessageMissing(): void
    {
        $this->assertNull(waParseBridgePayload(['message' => 'Hola']));
        $this->assertNull(waParseBridgePayload(['sender_phone' => '5213312345']));
        $this->assertNull(waParseBridgePayload(['sender_phone' => '5213312345', 'message' => '   ']));
        $this->assertNull(waParseBridgePayload([]));
    }

    public function testParseLabelsSyncPayloadExtractsValidLabels(): void
    {
        $result = waParseLabelsSyncPayload([
            'labels' => [
                ['id' => '1', 'name' => 'Nuevo cliente', 'color' => '#FF0000'],
                ['id' => '2', 'name' => 'Pago pendiente', 'color' => '#00FF00'],
            ],
        ]);

        $this->assertNotNull($result);
        $this->assertCount(2, $result);
        $this->assertSame('1', $result[0]['id_etiqueta_wa']);
        $this->assertSame('Nuevo cliente', $result[0]['nombre']);
        $this->assertSame('#FF0000', $result[0]['color']);
    }

    public function testParseLabelsSyncPayloadSkipsIncompleteEntriesWithoutFailingTheWholeBatch(): void
    {
        $result = waParseLabelsSyncPayload([
            'labels' => [
                ['id' => '1', 'name' => 'Valida'],
                ['id' => '', 'name' => 'Sin id'],
                ['name' => 'Sin id tampoco'],
                'no es un arreglo',
            ],
        ]);

        $this->assertNotNull($result);
        $this->assertCount(1, $result);
        $this->assertSame('Valida', $result[0]['nombre']);
    }

    public function testParseLabelsSyncPayloadReturnsNullWhenLabelsMissing(): void
    {
        $this->assertNull(waParseLabelsSyncPayload([]));
        $this->assertNull(waParseLabelsSyncPayload(['labels' => 'no es un arreglo']));
    }

    public function testSyncChatLabelReturnsFalseWhenBridgeNotConfigured(): void
    {
        // WA_BRIDGE_LABEL_URL no esta configurado en el entorno de tests (y AI_ASSISTANT_TEST_MODE
        // tampoco), asi que debe fallar de forma segura sin intentar una llamada de red real.
        $result = waSyncChatLabel('5215512345678', '3', 'add');

        $this->assertFalse($result['ok']);
    }

    public function testParseHistoryImportPayloadExtractsValidMessages(): void
    {
        $result = waParseHistoryImportPayload([
            'lote' => '2026-08-21T10:00:00Z',
            'messages' => [
                ['wa_id' => '5213312345678', 'nombre_perfil' => 'Sofia', 'message' => 'Hola, tienen omega 3?', 'from_me' => false, 'timestamp' => '2026-01-05T12:00:00Z'],
                ['wa_id' => '5213312345678', 'message' => 'Claro, con gusto te apoyo', 'from_me' => true, 'timestamp' => '2026-01-05T12:01:00Z'],
            ],
        ]);

        $this->assertNotNull($result);
        $this->assertCount(2, $result);
        $this->assertSame('5213312345678', $result[0]['wa_id']);
        $this->assertSame('Sofia', $result[0]['nombre_perfil']);
        $this->assertSame('Hola, tienen omega 3?', $result[0]['mensaje']);
        $this->assertFalse($result[0]['from_me']);
        $this->assertTrue($result[1]['from_me']);
    }

    public function testParseHistoryImportPayloadSkipsIncompleteEntriesWithoutFailingTheWholeBatch(): void
    {
        $result = waParseHistoryImportPayload([
            'messages' => [
                ['wa_id' => '5213312345678', 'message' => 'Valido', 'timestamp' => '2026-01-05T12:00:00Z'],
                ['wa_id' => '', 'message' => 'Sin wa_id', 'timestamp' => '2026-01-05T12:00:00Z'],
                ['wa_id' => '5213312345678', 'message' => '', 'timestamp' => '2026-01-05T12:00:00Z'],
                ['wa_id' => '5213312345678', 'message' => 'Sin timestamp'],
                ['wa_id' => '5213312345678', 'message' => 'Timestamp invalido', 'timestamp' => 'no-es-una-fecha'],
                'no es un arreglo',
            ],
        ]);

        $this->assertNotNull($result);
        $this->assertCount(1, $result);
        $this->assertSame('Valido', $result[0]['mensaje']);
    }

    public function testParseHistoryImportPayloadRejectsNonScalarTypeConfusion(): void
    {
        $result = waParseHistoryImportPayload([
            'messages' => [
                ['wa_id' => ['array-en-vez-de-string'], 'message' => 'Hola', 'timestamp' => '2026-01-05T12:00:00Z'],
                ['wa_id' => '5213312345678', 'message' => ['tambien-array'], 'timestamp' => '2026-01-05T12:00:00Z'],
            ],
        ]);

        $this->assertNotNull($result);
        $this->assertCount(0, $result);
    }

    public function testParseHistoryImportPayloadRejectsOversizedMessage(): void
    {
        $result = waParseHistoryImportPayload([
            'messages' => [
                ['wa_id' => '5213312345678', 'message' => str_repeat('a', 65537), 'timestamp' => '2026-01-05T12:00:00Z'],
            ],
        ]);

        $this->assertNotNull($result);
        $this->assertCount(0, $result);
    }

    public function testParseHistoryImportPayloadReturnsNullWhenMessagesMissing(): void
    {
        $this->assertNull(waParseHistoryImportPayload([]));
        $this->assertNull(waParseHistoryImportPayload(['messages' => 'no es un arreglo']));
    }
}
