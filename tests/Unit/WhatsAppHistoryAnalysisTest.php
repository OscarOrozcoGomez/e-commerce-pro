<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class WhatsAppHistoryAnalysisTest extends TestCase
{
    private PDO $pdo;

    protected function setUp(): void
    {
        parent::setUp();

        $this->pdo = new PDO('sqlite::memory:');
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);

        $this->createSchema();
    }

    /* -------------------- aiStoreHistoryImportBatch -------------------- */

    public function testAiStoreHistoryImportBatchInsertsAllRows(): void
    {
        $mensajes = [
            ['wa_id' => '5213312345678', 'nombre_perfil' => 'Sofia', 'mensaje' => 'Hola', 'from_me' => false, 'fecha_mensaje' => '2026-01-01 10:00:00'],
            ['wa_id' => '5213312345678', 'nombre_perfil' => '', 'mensaje' => 'Con gusto te apoyo', 'from_me' => true, 'fecha_mensaje' => '2026-01-01 10:01:00'],
        ];

        $insertados = aiStoreHistoryImportBatch($this->pdo, $mensajes, 'lote-1');

        $this->assertSame(2, $insertados);
        $total = (int)$this->pdo->query('SELECT COUNT(*) FROM whatsapp_historial_importado')->fetchColumn();
        $this->assertSame(2, $total);
    }

    public function testAiStoreHistoryImportBatchWithEmptyArrayInsertsNothing(): void
    {
        $this->assertSame(0, aiStoreHistoryImportBatch($this->pdo, [], 'lote-vacio'));
    }

    /* -------------------- watermark de progreso -------------------- */

    public function testGetAnalysisProgressDefaultsToZeroWhenNoRowStored(): void
    {
        $progreso = aiGetAnalysisProgress($this->pdo);

        $this->assertSame(0, $progreso['ultimo_id_historial_procesado']);
        $this->assertSame(0, $progreso['ultimo_id_mensaje_procesado']);
    }

    public function testSetAnalysisProgressCreatesThenUpdatesRow(): void
    {
        aiSetAnalysisProgress($this->pdo, 10, 20);
        $this->assertSame(['ultimo_id_historial_procesado' => 10, 'ultimo_id_mensaje_procesado' => 20], aiGetAnalysisProgress($this->pdo));

        aiSetAnalysisProgress($this->pdo, 15, 20);
        $this->assertSame(['ultimo_id_historial_procesado' => 15, 'ultimo_id_mensaje_procesado' => 20], aiGetAnalysisProgress($this->pdo));
    }

    /* -------------------- aiGetMessagesPendingAnalysis -------------------- */

    public function testGetMessagesPendingAnalysisRespectsWatermark(): void
    {
        $this->seedHistorialImportado(1, '5213312345678', 'Buscan omega 3', '2026-01-01 10:00:00');
        $this->seedHistorialImportado(2, '5213312345678', 'Buscan colageno', '2026-01-01 10:05:00');
        $this->seedConversacion(1, '5213312345679');
        $this->seedMensaje(1, 1, 'user', 'Cuanto cuesta el multivitaminico', '2026-01-01 11:00:00');

        $progreso = ['ultimo_id_historial_procesado' => 1, 'ultimo_id_mensaje_procesado' => 0];
        $pendientes = aiGetMessagesPendingAnalysis($this->pdo, $progreso);

        $this->assertCount(1, $pendientes['historial']);
        $this->assertSame('Buscan colageno', $pendientes['historial'][0]['mensaje']);
        $this->assertCount(1, $pendientes['mensajes']);
        $this->assertSame('Cuanto cuesta el multivitaminico', $pendientes['mensajes'][0]['mensaje']);
    }

    public function testGetMessagesPendingAnalysisIgnoresFromMeAndNonUserRows(): void
    {
        $this->seedHistorialImportadoFromMe(1, '5213312345678', 'Ya te apoyo', '2026-01-01 10:00:00');
        $this->seedConversacion(1, '5213312345679');
        $this->seedMensaje(1, 1, 'assistant', 'Con gusto', '2026-01-01 11:00:00');

        $progreso = ['ultimo_id_historial_procesado' => 0, 'ultimo_id_mensaje_procesado' => 0];
        $pendientes = aiGetMessagesPendingAnalysis($this->pdo, $progreso);

        $this->assertCount(0, $pendientes['historial']);
        $this->assertCount(0, $pendientes['mensajes']);
    }

    /* -------------------- aiDetectTopicsInMessage (pura) -------------------- */

    public function testDetectTopicsInMessageFindsCatalogProduct(): void
    {
        $topics = aiDetectTopicsInMessage('Oye tienen Magnesio Citrate disponible?', ['Magnesio Citrate', 'Omega 3'], []);

        $this->assertCount(1, $topics);
        $this->assertSame('producto', $topics[0]['tipo']);
        $this->assertSame('Magnesio Citrate', $topics[0]['valor']);
    }

    public function testDetectTopicsInMessageFindsBusinessKeyword(): void
    {
        $topics = aiDetectTopicsInMessage('Cuanto tarda el envio a Monterrey?', [], ['envio', 'garantia']);

        $this->assertCount(1, $topics);
        $this->assertSame('tema_general', $topics[0]['tipo']);
        $this->assertSame('envio', $topics[0]['valor']);
    }

    public function testDetectTopicsInMessageIsCaseInsensitive(): void
    {
        $topics = aiDetectTopicsInMessage('busco OMEGA 3 para mi mama', ['Omega 3'], []);

        $this->assertCount(1, $topics);
        $this->assertSame('Omega 3', $topics[0]['valor']);
    }

    public function testDetectTopicsInMessageReturnsEmptyArrayWhenNoMatch(): void
    {
        $this->assertSame([], aiDetectTopicsInMessage('Buenos dias', ['Omega 3'], ['envio']));
        $this->assertSame([], aiDetectTopicsInMessage('   ', ['Omega 3'], ['envio']));
    }

    public function testDetectTopicsInMessageDoesNotDuplicateMatchesWithinSameMessage(): void
    {
        $topics = aiDetectTopicsInMessage('Omega 3, si Omega 3 el de pescado', ['Omega 3'], []);

        $this->assertCount(1, $topics);
    }

    /* -------------------- aiUpsertHistorialTema / consultas de temas -------------------- */

    public function testUpsertHistorialTemaCreatesRowOnFirstMention(): void
    {
        aiUpsertHistorialTema($this->pdo, '5213312345678', 'producto', 'Omega 3', '2026-01-01 10:00:00');

        $fila = $this->pdo->query('SELECT * FROM whatsapp_historial_temas')->fetch(PDO::FETCH_ASSOC);
        $this->assertSame(1, (int)$fila['veces_mencionado']);
        $this->assertSame('2026-01-01 10:00:00', $fila['primera_mencion']);
    }

    public function testUpsertHistorialTemaIncrementsCounterOnRepeatedMention(): void
    {
        aiUpsertHistorialTema($this->pdo, '5213312345678', 'producto', 'Omega 3', '2026-01-01 10:00:00');
        aiUpsertHistorialTema($this->pdo, '5213312345678', 'producto', 'Omega 3', '2026-01-05 09:00:00');

        $fila = $this->pdo->query('SELECT * FROM whatsapp_historial_temas')->fetch(PDO::FETCH_ASSOC);
        $this->assertSame(2, (int)$fila['veces_mencionado']);
        $this->assertSame('2026-01-01 10:00:00', $fila['primera_mencion']);
        $this->assertSame('2026-01-05 09:00:00', $fila['ultima_mencion']);
    }

    public function testGetTopHistorialTemasOrdersByMentionsDescending(): void
    {
        aiUpsertHistorialTema($this->pdo, '5213312345678', 'producto', 'Omega 3', '2026-01-01 10:00:00');
        aiUpsertHistorialTema($this->pdo, '5213312345678', 'producto', 'Colageno', '2026-01-01 10:00:00');
        aiUpsertHistorialTema($this->pdo, '5213312345678', 'producto', 'Colageno', '2026-01-02 10:00:00');

        $top = aiGetTopHistorialTemas($this->pdo, '5213312345678');

        $this->assertSame('Colageno', $top[0]['valor']);
        $this->assertSame(2, (int)$top[0]['veces_mencionado']);
    }

    public function testGetTopHistorialTemasGlobalAggregatesAcrossClients(): void
    {
        aiUpsertHistorialTema($this->pdo, '5213312345678', 'producto', 'Omega 3', '2026-01-01 10:00:00');
        aiUpsertHistorialTema($this->pdo, '5213312345679', 'producto', 'Omega 3', '2026-01-01 10:00:00');

        $global = aiGetTopHistorialTemasGlobal($this->pdo);

        $this->assertSame('Omega 3', $global[0]['valor']);
        $this->assertSame(2, (int)$global[0]['total_menciones']);
        $this->assertSame(2, (int)$global[0]['total_clientes']);
    }

    /* -------------------- aiGetClientPurchaseProfile -------------------- */

    public function testGetClientPurchaseProfileReturnsPurchasedProducts(): void
    {
        $this->seedProducto(1, 'Magnesio Citrate', '240');
        $this->seedPedido(1, 100, 'entregado');
        $this->seedDetallePedido(1, 1, 2);

        $perfil = aiGetClientPurchaseProfile($this->pdo, 100);

        $this->assertCount(1, $perfil);
        $this->assertSame('Magnesio Citrate', $perfil[0]['nombre']);
    }

    public function testGetClientPurchaseProfileExcludesCancelledOrders(): void
    {
        $this->seedProducto(1, 'Magnesio Citrate', '240');
        $this->seedPedido(1, 100, 'cancelado');
        $this->seedDetallePedido(1, 1, 1);

        $this->assertSame([], aiGetClientPurchaseProfile($this->pdo, 100));
    }

    public function testGetClientPurchaseProfileWithoutClientIdReturnsEmpty(): void
    {
        $this->assertSame([], aiGetClientPurchaseProfile($this->pdo, 0));
    }

    /* -------------------- aiBuildClientProfileContextLine (pura) -------------------- */

    public function testBuildClientProfileContextLineWithPurchasesAndTopics(): void
    {
        $linea = aiBuildClientProfileContextLine(
            [['nombre' => 'Magnesio Citrate', 'nombre_variante' => '240']],
            [
                ['tipo' => 'producto', 'valor' => 'Colageno'],
                ['tipo' => 'tema_general', 'valor' => 'envio'],
            ]
        );

        $this->assertStringContainsString('Magnesio Citrate 240', $linea);
        $this->assertStringContainsString('Colageno', $linea);
        $this->assertStringContainsString('envio', $linea);
    }

    public function testBuildClientProfileContextLineWithNothingReturnsEmptyString(): void
    {
        $this->assertSame('', aiBuildClientProfileContextLine([], []));
    }

    /* -------------------- fixtures -------------------- */

    private function createSchema(): void
    {
        $this->pdo->exec(
            'CREATE TABLE productos (
                id_producto INTEGER PRIMARY KEY,
                nombre TEXT NOT NULL,
                nombre_variante TEXT NULL,
                estado TEXT NOT NULL DEFAULT "activo"
            )'
        );
        $this->pdo->exec(
            'CREATE TABLE pedidos (
                id_pedido INTEGER PRIMARY KEY,
                id_cliente INTEGER NULL,
                estado TEXT NOT NULL DEFAULT "pendiente_pago",
                fecha_creacion TEXT DEFAULT CURRENT_TIMESTAMP
            )'
        );
        $this->pdo->exec(
            'CREATE TABLE detalle_pedidos (
                id_detalle INTEGER PRIMARY KEY AUTOINCREMENT,
                id_pedido INTEGER NOT NULL,
                id_producto INTEGER NOT NULL,
                cantidad INTEGER NOT NULL DEFAULT 1
            )'
        );
        $this->pdo->exec(
            'CREATE TABLE whatsapp_conversaciones (
                id_conversacion INTEGER PRIMARY KEY AUTOINCREMENT,
                wa_id TEXT NOT NULL UNIQUE
            )'
        );
        $this->pdo->exec(
            'CREATE TABLE whatsapp_mensajes (
                id_mensaje INTEGER PRIMARY KEY AUTOINCREMENT,
                id_conversacion INTEGER NOT NULL,
                rol TEXT NOT NULL,
                contenido TEXT NULL,
                creado_en TEXT DEFAULT CURRENT_TIMESTAMP
            )'
        );
        $this->pdo->exec(
            'CREATE TABLE whatsapp_historial_importado (
                id_historial INTEGER PRIMARY KEY AUTOINCREMENT,
                wa_id TEXT NOT NULL,
                nombre_perfil TEXT NULL,
                mensaje TEXT NOT NULL,
                from_me INTEGER NOT NULL DEFAULT 0,
                fecha_mensaje TEXT NOT NULL,
                lote_importacion TEXT NULL,
                creado_en TEXT DEFAULT CURRENT_TIMESTAMP
            )'
        );
        $this->pdo->exec(
            'CREATE TABLE whatsapp_historial_temas (
                id_tema INTEGER PRIMARY KEY AUTOINCREMENT,
                wa_id TEXT NOT NULL,
                tipo TEXT NOT NULL,
                valor TEXT NOT NULL,
                veces_mencionado INTEGER NOT NULL DEFAULT 1,
                primera_mencion TEXT NOT NULL,
                ultima_mencion TEXT NOT NULL
            )'
        );
        $this->pdo->exec(
            'CREATE TABLE whatsapp_analisis_progreso (
                id_progreso INTEGER PRIMARY KEY,
                ultimo_id_historial_procesado INTEGER NOT NULL DEFAULT 0,
                ultimo_id_mensaje_procesado INTEGER NOT NULL DEFAULT 0
            )'
        );
    }

    private function seedHistorialImportado(int $id, string $waId, string $mensaje, string $fecha): void
    {
        $this->pdo->prepare('INSERT INTO whatsapp_historial_importado (id_historial, wa_id, mensaje, from_me, fecha_mensaje) VALUES (?, ?, ?, 0, ?)')
            ->execute([$id, $waId, $mensaje, $fecha]);
    }

    private function seedHistorialImportadoFromMe(int $id, string $waId, string $mensaje, string $fecha): void
    {
        $this->pdo->prepare('INSERT INTO whatsapp_historial_importado (id_historial, wa_id, mensaje, from_me, fecha_mensaje) VALUES (?, ?, ?, 1, ?)')
            ->execute([$id, $waId, $mensaje, $fecha]);
    }

    private function seedConversacion(int $id, string $waId): void
    {
        $this->pdo->prepare('INSERT INTO whatsapp_conversaciones (id_conversacion, wa_id) VALUES (?, ?)')->execute([$id, $waId]);
    }

    private function seedMensaje(int $id, int $idConversacion, string $rol, string $contenido, string $fecha): void
    {
        $this->pdo->prepare('INSERT INTO whatsapp_mensajes (id_mensaje, id_conversacion, rol, contenido, creado_en) VALUES (?, ?, ?, ?, ?)')
            ->execute([$id, $idConversacion, $rol, $contenido, $fecha]);
    }

    private function seedProducto(int $id, string $nombre, ?string $variante): void
    {
        $this->pdo->prepare('INSERT INTO productos (id_producto, nombre, nombre_variante) VALUES (?, ?, ?)')
            ->execute([$id, $nombre, $variante]);
    }

    private function seedPedido(int $id, int $idCliente, string $estado): void
    {
        $this->pdo->prepare('INSERT INTO pedidos (id_pedido, id_cliente, estado) VALUES (?, ?, ?)')
            ->execute([$id, $idCliente, $estado]);
    }

    private function seedDetallePedido(int $idPedido, int $idProducto, int $cantidad): void
    {
        $this->pdo->prepare('INSERT INTO detalle_pedidos (id_pedido, id_producto, cantidad) VALUES (?, ?, ?)')
            ->execute([$idPedido, $idProducto, $cantidad]);
    }
}
