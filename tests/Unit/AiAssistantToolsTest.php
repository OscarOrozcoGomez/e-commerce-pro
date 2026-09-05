<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class AiAssistantToolsTest extends TestCase
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

    public function testAiSearchInventorySumsStockAcrossWarehouses(): void
    {
        $this->seedProducto(10, 'Omega 3', 'OMG3', null, 299.00);
        $this->seedInventario(10, 1, 3);
        $this->seedInventario(10, 2, 2);

        $resultados = aiSearchInventory($this->pdo, 'omega');

        $this->assertCount(1, $resultados);
        $this->assertSame(10, $resultados[0]['id_producto']);
        $this->assertSame(299.0, $resultados[0]['precio']);
        $this->assertSame(5, $resultados[0]['stock']);
    }

    public function testAiSearchInventoryExcludesInactiveProducts(): void
    {
        $this->seedProducto(11, 'Vitamina C', 'VITC', null, 150.00, 'inactivo');
        $this->seedInventario(11, 1, 10);

        $this->assertSame([], aiSearchInventory($this->pdo, 'vitamina'));
    }

    public function testAiSearchInventoryMatchesByVariantName(): void
    {
        $this->seedProducto(12, 'Shampoo', 'SHMP', 'Frasco 500ml', 89.90);
        $this->seedInventario(12, 1, 4);

        $resultados = aiSearchInventory($this->pdo, '500ml');

        $this->assertCount(1, $resultados);
        $this->assertStringContainsString('Frasco 500ml', $resultados[0]['nombre']);
    }

    public function testAiSearchInventoryMatchesByIngredientesOrBeneficios(): void
    {
        $this->seedProducto(13, 'Colageno Hidrolizado', 'COLG', null, 349.00, 'activo', null, 'colageno hidrolizado, acido hialuronico, vitamina C');
        $this->seedProducto(14, 'Multivitaminico Diario', 'MULTI', null, 199.00, 'activo', null, null, 'salud articular, firmeza en piel, fortalecimiento de unas y cabello');

        $porIngrediente = aiSearchInventory($this->pdo, 'acido hialuronico');
        $this->assertCount(1, $porIngrediente);
        $this->assertSame(13, $porIngrediente[0]['id_producto']);

        $porBeneficio = aiSearchInventory($this->pdo, 'firmeza en piel');
        $this->assertCount(1, $porBeneficio);
        $this->assertSame(14, $porBeneficio[0]['id_producto']);
    }

    public function testAiSearchInventoryReturnsEachPresentationAsASeparateResult(): void
    {
        // Mismo producto base, 3 presentaciones/tamanos distintos -- cada una con su propio
        // id_producto, precio y stock, tal como estan modelados en el catalogo real.
        $this->seedProducto(15, 'Citrate Mag', 'CIT120', '120', 600.00);
        $this->seedProducto(16, 'Citrate Mag', 'CIT240', '240', 649.00);
        $this->seedProducto(17, 'Citrate Mag', 'CIT500', '500', 200.00);
        $this->seedInventario(15, 1, 10);
        $this->seedInventario(16, 1, 5);
        $this->seedInventario(17, 1, 20);

        $resultados = aiSearchInventory($this->pdo, 'citrate mag');

        $this->assertCount(3, $resultados);
        $nombres = array_map(static fn(array $r) => $r['nombre'], $resultados);
        $this->assertContains('Citrate Mag - 120', $nombres);
        $this->assertContains('Citrate Mag - 240', $nombres);
        $this->assertContains('Citrate Mag - 500', $nombres);

        // Cada presentacion conserva su propio precio, no se mezclan.
        $porNombreVariante = [];
        foreach ($resultados as $r) {
            $porNombreVariante[$r['nombre']] = $r['precio'];
        }
        $this->assertSame(600.0, $porNombreVariante['Citrate Mag - 120']);
        $this->assertSame(649.0, $porNombreVariante['Citrate Mag - 240']);
        $this->assertSame(200.0, $porNombreVariante['Citrate Mag - 500']);
    }

    public function testAiSearchInventoryFindsProductsAcrossDifferentCategoriesByKeyword(): void
    {
        // "Categorias" en este catalogo se expresan como palabras clave en nombre/descripcion/
        // ingredientes/beneficios, no como un catalogo formal -- confirmar que una busqueda por
        // tema general (no el nombre exacto del producto) encuentra productos de distintas lineas.
        $this->seedProducto(70, 'Omega 3 Forte', 'OM70', null, 280.00, 'activo', 'Acidos grasos esenciales EPA y DHA');
        $this->seedProducto(71, 'Colageno Marino', 'COL71', null, 320.00, 'activo', null, null, 'piel, articulaciones, cabello');
        $this->seedProducto(72, 'Multivitaminico Senior', 'MUL72', null, 210.00, 'activo', 'Formula completa de vitaminas y minerales');

        $this->assertNotEmpty(aiSearchInventory($this->pdo, 'omega'));
        $this->assertNotEmpty(aiSearchInventory($this->pdo, 'colageno'));
        $this->assertNotEmpty(aiSearchInventory($this->pdo, 'vitaminico'));
    }

    public function testAiResolveDeepSeekEndpointUsesRelayWhenConfigured(): void
    {
        $result = aiResolveDeepSeekEndpoint('http://159.89.87.3:3000/deepseek-relay');

        $this->assertTrue($result['use_relay']);
        $this->assertSame('http://159.89.87.3:3000/deepseek-relay', $result['url']);
    }

    public function testAiResolveDeepSeekEndpointFallsBackToDirectWhenNotConfigured(): void
    {
        $sinValor = aiResolveDeepSeekEndpoint(null);
        $this->assertFalse($sinValor['use_relay']);
        $this->assertSame('https://api.deepseek.com/chat/completions', $sinValor['url']);

        $vacio = aiResolveDeepSeekEndpoint('   ');
        $this->assertFalse($vacio['use_relay']);
        $this->assertSame('https://api.deepseek.com/chat/completions', $vacio['url']);
    }

    public function testAiSearchInventoryEscapesLikeWildcardCharactersInUserInput(): void
    {
        // Un cliente que escribe literalmente "%" o "_" no debe convertir su busqueda en
        // un comodin de SQL que regrese practicamente todo el catalogo.
        $this->seedProducto(80, 'Omega 3 Forte', 'OM80', null, 280.00);
        $this->seedProducto(81, 'Colageno Marino', 'COL81', null, 320.00);
        $this->seedProducto(82, 'Multivitaminico Senior', 'MUL82', null, 210.00);

        $this->assertSame([], aiSearchInventory($this->pdo, '%'));
        $this->assertSame([], aiSearchInventory($this->pdo, '_'));
        $this->assertSame(0, aiCountInventoryMatches($this->pdo, '%'));
    }

    public function testAiSearchInventoryStillMatchesProductsContainingLiteralPercentOrUnderscore(): void
    {
        // El escape no debe romper la busqueda cuando el propio catalogo SI tiene esos
        // caracteres en el nombre (ej. "Omega 3 100% Puro").
        $this->seedProducto(83, 'Omega 3 100% Puro', 'OM83', null, 280.00);

        $resultados = aiSearchInventory($this->pdo, '100%');
        $this->assertCount(1, $resultados);
        $this->assertSame(83, $resultados[0]['id_producto']);
    }

    public function testAiEscapeLikeTermEscapesPercentUnderscoreAndTheEscapeCharItself(): void
    {
        $this->assertSame('50!%', aiEscapeLikeTerm('50%'));
        $this->assertSame('a!_b', aiEscapeLikeTerm('a_b'));
        $this->assertSame('c!!d', aiEscapeLikeTerm('c!d'));
    }

    public function testAiToolConsultarInventarioRejectsEmptySearch(): void
    {
        $result = aiToolConsultarInventario($this->pdo, ['busqueda_texto' => '  ']);
        $this->assertFalse($result['ok']);
    }

    public function testAiToolConsultarInventarioReportsNoMatches(): void
    {
        $result = aiToolConsultarInventario($this->pdo, ['busqueda_texto' => 'producto-inexistente']);
        $this->assertTrue($result['ok']);
        $this->assertSame([], $result['productos']);
        $this->assertSame(0, $result['total_encontrados']);
    }

    public function testAiToolConsultarInventarioReportsTotalCountEvenWhenTruncated(): void
    {
        // AI_INVENTORY_SEARCH_LIMIT es 12; se siembran 15 productos que matchean "vitamina"
        // para confirmar que el total real (15) se reporta aunque la lista se corte antes.
        for ($i = 100; $i < 115; $i++) {
            $this->seedProducto($i, "Vitamina Test {$i}", "VTEST{$i}", null, 100.00 + $i);
            $this->seedInventario($i, 1, 5);
        }

        $result = aiToolConsultarInventario($this->pdo, ['busqueda_texto' => 'vitamina test']);

        $this->assertTrue($result['ok']);
        $this->assertSame(15, $result['total_encontrados']);
        $this->assertLessThan(15, count($result['productos']));
        $this->assertArrayHasKey('message', $result);
        $this->assertStringContainsString('15 productos en total', $result['message']);
    }

    public function testAiToolConsultarInventarioOmitsMessageWhenNothingWasTruncated(): void
    {
        $this->seedProducto(80, 'Producto Unico', 'UNI80', null, 100.00);
        $this->seedInventario(80, 1, 5);

        $result = aiToolConsultarInventario($this->pdo, ['busqueda_texto' => 'Producto Unico']);

        $this->assertTrue($result['ok']);
        $this->assertSame(1, $result['total_encontrados']);
        $this->assertArrayNotHasKey('message', $result);
    }

    public function testAiCountInventoryMatchesMatchesActualRowCountIgnoringLimit(): void
    {
        for ($i = 200; $i < 220; $i++) {
            $this->seedProducto($i, "Producto Conteo {$i}", "CONT{$i}", null, 50.00);
        }

        $this->assertSame(20, aiCountInventoryMatches($this->pdo, 'Producto Conteo'));
    }

    public function testAiCountInventoryMatchesExcludesInactiveProducts(): void
    {
        $this->seedProducto(230, 'Producto Activo Conteo', 'PAC230', null, 50.00, 'activo');
        $this->seedProducto(231, 'Producto Inactivo Conteo', 'PIC231', null, 50.00, 'inactivo');

        $this->assertSame(1, aiCountInventoryMatches($this->pdo, 'Conteo'));
    }

    public function testAiResolveOrderItemsUsesDbPriceIgnoringSuppliedPrice(): void
    {
        $this->seedProducto(20, 'Creatina', 'CRT', null, 499.00);
        $this->seedInventario(20, 1, 5);

        $resolved = aiResolveOrderItems($this->pdo, [
            ['id_producto' => 20, 'cantidad' => 2, 'precio' => 1],
        ]);

        $this->assertSame([], $resolved['errores']);
        $this->assertCount(1, $resolved['items']);
        $this->assertSame(499.0, $resolved['items'][0]['precio']);
        $this->assertSame(2, $resolved['items'][0]['quantity']);
    }

    public function testAiResolveOrderItemsFailsGracefullyWhenStockInsufficient(): void
    {
        $this->seedProducto(21, 'Proteina', 'PRT', null, 899.00);
        $this->seedInventario(21, 1, 1);

        $resolved = aiResolveOrderItems($this->pdo, [
            ['id_producto' => 21, 'cantidad' => 5],
        ]);

        $this->assertSame([], $resolved['items']);
        $this->assertNotEmpty($resolved['errores']);
        $this->assertStringContainsString('Proteina', $resolved['errores'][0]);
    }

    public function testAiResolveOrderItemsFailsForUnknownProduct(): void
    {
        $resolved = aiResolveOrderItems($this->pdo, [
            ['id_producto' => 999999, 'cantidad' => 1],
        ]);

        $this->assertSame([], $resolved['items']);
        $this->assertNotEmpty($resolved['errores']);
    }

    public function testAiGetOrCreateConversationIsIdempotentByWaId(): void
    {
        $first = aiGetOrCreateConversation($this->pdo, '5215512340000', 'Cliente Uno');
        $second = aiGetOrCreateConversation($this->pdo, '5215512340000', 'Cliente Uno');

        $this->assertSame($first['id_conversacion'], $second['id_conversacion']);
        $count = (int) $this->pdo->query('SELECT COUNT(*) FROM whatsapp_conversaciones')->fetchColumn();
        $this->assertSame(1, $count);
    }

    public function testAiGetOrCreateConversationLinksClienteByPhone(): void
    {
        $this->seedCliente(7, '5512345678');

        $conversacion = aiGetOrCreateConversation($this->pdo, '5215512345678', 'Ana Ruiz');

        $this->assertSame(7, (int) $conversacion['id_cliente']);
    }

    public function testAppendMessageAndDedupeByWaMessageId(): void
    {
        $conversacion = aiGetOrCreateConversation($this->pdo, '5215500000001', null);
        $idConversacion = (int) $conversacion['id_conversacion'];

        $this->assertFalse(aiHasWaMessageBeenProcessed($this->pdo, 'wamid.111'));
        aiAppendMessage($this->pdo, $idConversacion, 'user', 'Hola', null, null, null, 'wamid.111');
        $this->assertTrue(aiHasWaMessageBeenProcessed($this->pdo, 'wamid.111'));
    }

    public function testLoadConversationHistoryReconstructsToolTurns(): void
    {
        $conversacion = aiGetOrCreateConversation($this->pdo, '5215500000002', null);
        $idConversacion = (int) $conversacion['id_conversacion'];

        aiAppendMessage($this->pdo, $idConversacion, 'user', 'Tienen omega 3?');
        aiAppendMessage($this->pdo, $idConversacion, 'assistant', null, [
            ['id' => 'call_1', 'type' => 'function', 'function' => ['name' => 'consultar_inventario', 'arguments' => '{"busqueda_texto":"omega 3"}']],
        ]);
        aiAppendMessage($this->pdo, $idConversacion, 'tool', '{"ok":true,"productos":[]}', null, 'call_1', 'consultar_inventario');
        aiAppendMessage($this->pdo, $idConversacion, 'assistant', 'No tenemos existencia por ahora.', null, null, null, null, true);

        $history = aiLoadConversationHistory($this->pdo, $idConversacion);

        $this->assertCount(4, $history);
        $this->assertSame('user', $history[0]['role']);
        $this->assertSame('assistant', $history[1]['role']);
        $this->assertArrayHasKey('tool_calls', $history[1]);
        $this->assertSame('call_1', $history[1]['tool_calls'][0]['id']);
        $this->assertSame('tool', $history[2]['role']);
        $this->assertSame('call_1', $history[2]['tool_call_id']);
        $this->assertSame('No tenemos existencia por ahora.', $history[3]['content']);
    }

    public function testTransferirAHumanoPausesConversation(): void
    {
        $conversacion = aiGetOrCreateConversation($this->pdo, '5215500000003', null);
        $idConversacion = (int) $conversacion['id_conversacion'];

        $context = ['wa_id' => '5215500000003', 'id_conversacion' => $idConversacion, 'nombre_perfil' => 'Cliente'];
        $result = aiToolTransferirHumano($this->pdo, ['motivo' => 'Quiere hablar con alguien'], $context);

        $this->assertTrue($result['ok']);
        $row = $this->pdo->query('SELECT estado_bot, motivo_transferencia FROM whatsapp_conversaciones WHERE id_conversacion = ' . $idConversacion)->fetch();
        $this->assertSame('pausado', $row['estado_bot']);
        $this->assertSame('Quiere hablar con alguien', $row['motivo_transferencia']);
    }

    public function testReactivatingConversationClearsPauseState(): void
    {
        $conversacion = aiGetOrCreateConversation($this->pdo, '5215500000004', null);
        $idConversacion = (int) $conversacion['id_conversacion'];

        aiSetConversationState($this->pdo, $idConversacion, 'pausado', 'Duda de facturacion');
        aiSetConversationState($this->pdo, $idConversacion, 'activo', null);

        $row = $this->pdo->query('SELECT estado_bot, motivo_transferencia FROM whatsapp_conversaciones WHERE id_conversacion = ' . $idConversacion)->fetch();
        $this->assertSame('activo', $row['estado_bot']);
        $this->assertNull($row['motivo_transferencia']);
    }

    public function testRateLimitTripsAfterThresholdWithinWindow(): void
    {
        $conversacion = aiGetOrCreateConversation($this->pdo, '5215500000005', null);
        $idConversacion = (int) $conversacion['id_conversacion'];

        $this->assertFalse(aiIsRateLimited($this->pdo, $idConversacion));

        for ($i = 0; $i < AI_ASSISTANT_RATE_LIMIT_MAX_MESSAGES; $i++) {
            aiAppendMessage($this->pdo, $idConversacion, 'user', 'mensaje ' . $i);
        }

        $this->assertTrue(aiIsRateLimited($this->pdo, $idConversacion));
    }

    public function testGetConfigReturnsDefaultsWhenNoRowStored(): void
    {
        $config = aiGetConfig($this->pdo);

        $this->assertSame('DEEPSEEK_AI_ASSISTANT', $config['api_key_variable']);
        $this->assertSame('deepseek-chat', $config['modelo_llm']);
        $this->assertSame(0.30, $config['temperatura']);
    }

    public function testGetConfigMergesStoredRowOverDefaults(): void
    {
        $this->pdo->exec(
            "INSERT INTO ai_asistente_config (id_config, activo, api_key_variable, modelo_llm, temperatura, prompt_sistema_override)
             VALUES (1, 1, 'MI_LLAVE_CUSTOM', 'deepseek-reasoner', 0.7, 'Prompt a la medida')"
        );

        $config = aiGetConfig($this->pdo);

        $this->assertSame('MI_LLAVE_CUSTOM', $config['api_key_variable']);
        $this->assertSame('deepseek-reasoner', $config['modelo_llm']);
        $this->assertSame('Prompt a la medida', $config['prompt_sistema_override']);
    }

    public function testIsAssistantGloballyActiveDefaultsToTrueWhenNoRowStored(): void
    {
        $this->assertTrue(aiIsAssistantGloballyActive($this->pdo));
    }

    public function testSetGlobalActiveCreatesRowWhenNoneExistsYet(): void
    {
        $this->assertSame(0, (int) $this->pdo->query('SELECT COUNT(*) FROM ai_asistente_config')->fetchColumn());

        $ok = aiSetGlobalActive($this->pdo, false);

        $this->assertTrue($ok);
        $this->assertFalse(aiIsAssistantGloballyActive($this->pdo));
        $this->assertSame(1, (int) $this->pdo->query('SELECT COUNT(*) FROM ai_asistente_config')->fetchColumn());
    }

    public function testSetGlobalActiveTogglesBothWaysOnExistingRow(): void
    {
        $this->pdo->exec('INSERT INTO ai_asistente_config (id_config, activo) VALUES (1, 1)');
        $this->assertTrue(aiIsAssistantGloballyActive($this->pdo));

        aiSetGlobalActive($this->pdo, false);
        $this->assertFalse(aiIsAssistantGloballyActive($this->pdo));

        aiSetGlobalActive($this->pdo, true);
        $this->assertTrue(aiIsAssistantGloballyActive($this->pdo));
    }

    public function testSetGlobalActiveToTheSameValueTwiceStaysConsistent(): void
    {
        // Regresion especifica: no debe depender de rowCount() del UPDATE (que en MySQL da 0
        // filas afectadas cuando el valor no cambia, lo cual no significa "no existe la fila").
        $this->pdo->exec('INSERT INTO ai_asistente_config (id_config, activo) VALUES (1, 1)');

        aiSetGlobalActive($this->pdo, true);
        aiSetGlobalActive($this->pdo, true);

        $this->assertTrue(aiIsAssistantGloballyActive($this->pdo));
        $this->assertSame(1, (int) $this->pdo->query('SELECT COUNT(*) FROM ai_asistente_config')->fetchColumn());
    }

    public function testEnviarPlantillaReturnsMediaForActiveTemplate(): void
    {
        $this->seedTemplate('catalogo_be_life', 'imagen', 'Catalogo Be Life 2026', 'https://cdn.example.com/catalogo.jpg');

        $result = aiToolEnviarPlantilla($this->pdo, ['codigo_plantilla' => 'catalogo_be_life']);

        $this->assertTrue($result['ok']);
        $this->assertSame('imagen', $result['tipo']);
        $this->assertSame('https://cdn.example.com/catalogo.jpg', $result['url']);
        $this->assertSame('Catalogo Be Life 2026', $result['texto']);
    }

    public function testEnviarPlantillaFailsForUnknownOrInactiveCode(): void
    {
        $this->seedTemplate('inactiva', 'texto', 'No deberia salir', null, 0);

        $this->assertFalse(aiToolEnviarPlantilla($this->pdo, ['codigo_plantilla' => 'no-existe'])['ok']);
        $this->assertFalse(aiToolEnviarPlantilla($this->pdo, ['codigo_plantilla' => 'inactiva'])['ok']);
    }

    public function testEnviarPlantillaFailsWhenMediaTemplateHasNoFile(): void
    {
        $this->seedTemplate('foto_sin_archivo', 'imagen', 'Caption sin url', null);

        $this->assertFalse(aiToolEnviarPlantilla($this->pdo, ['codigo_plantilla' => 'foto_sin_archivo'])['ok']);
    }

    public function testEnviarCatalogoReturnsTheCatalogoPdfTemplateWithFilename(): void
    {
        $this->seedTemplate(
            'catalogo_pdf',
            'documento',
            'Catálogo Be Life',
            'https://bellezaybienestar.com.mx/assets/catalogo_be_life.pdf',
            1,
            'Catalogo_Be_Life.pdf'
        );

        $result = aiToolEnviarCatalogo($this->pdo);

        $this->assertTrue($result['ok']);
        $this->assertSame('documento', $result['tipo']);
        $this->assertSame('https://bellezaybienestar.com.mx/assets/catalogo_be_life.pdf', $result['url']);
        $this->assertSame('Catalogo_Be_Life.pdf', $result['filename']);
    }

    public function testEnviarCatalogoFailsGracefullyWhenTemplateHasNoFileYet(): void
    {
        $this->seedTemplate('catalogo_pdf', 'documento', 'Catálogo Be Life', null);

        $result = aiToolEnviarCatalogo($this->pdo);

        $this->assertFalse($result['ok']);
    }

    public function testEnviarPlantillaReturnsEmptyFilenameWhenNotConfigured(): void
    {
        $this->seedTemplate('sin_nombre', 'imagen', 'Foto', 'https://cdn.example.com/foto.jpg');

        $result = aiToolEnviarPlantilla($this->pdo, ['codigo_plantilla' => 'sin_nombre']);

        $this->assertTrue($result['ok']);
        $this->assertSame('', $result['filename']);
    }

    public function testFindConversationsNeedingFollowupOnlyReturnsStaleActiveOnes(): void
    {
        // Numeros con lada 33 (Guadalajara): el follow-up proactivo solo aplica a
        // clientes locales, y aiFindConversationsNeedingFollowup ahora excluye a los
        // foraneos identificados. Ver testFindConversationsNeedingFollowupSkipsForeignLada.
        $stale = aiGetOrCreateConversation($this->pdo, '5213300020001', null);
        $this->seedBotMessage((int) $stale['id_conversacion'], 'Aqui tienes el precio.', '-30 hours');

        $reciente = aiGetOrCreateConversation($this->pdo, '5213300020002', null);
        $this->seedBotMessage((int) $reciente['id_conversacion'], 'Aqui tienes el precio.', '-2 hours');

        $yaConSeguimiento = aiGetOrCreateConversation($this->pdo, '5213300020003', null);
        $this->seedBotMessage((int) $yaConSeguimiento['id_conversacion'], 'Aqui tienes el precio.', '-30 hours');
        $this->pdo->prepare('UPDATE whatsapp_conversaciones SET seguimiento_enviado_en = ? WHERE id_conversacion = ?')
            ->execute([date('Y-m-d H:i:s', strtotime('-1 hour')), (int) $yaConSeguimiento['id_conversacion']]);

        $resultados = aiFindConversationsNeedingFollowup($this->pdo);
        $ids = array_map(static fn(array $r) => (int) $r['id_conversacion'], $resultados);

        $this->assertContains((int) $stale['id_conversacion'], $ids);
        $this->assertNotContains((int) $reciente['id_conversacion'], $ids);
        $this->assertNotContains((int) $yaConSeguimiento['id_conversacion'], $ids);
    }

    public function testFindConversationsNeedingFollowupSkipsForeignLada(): void
    {
        // Cliente local (lada 33): recibe follow-up.
        $local = aiGetOrCreateConversation($this->pdo, '5213311122233', null);
        $this->seedBotMessage((int) $local['id_conversacion'], 'Aqui tienes el precio.', '-30 hours');

        // Cliente foraneo confirmado (lada 55, CDMX): NO recibe follow-up -- no hay
        // entregas fuera de Guadalajara, insistirle seria molesto y gasto de recursos.
        $foraneo = aiGetOrCreateConversation($this->pdo, '5215511122233', null);
        $this->seedBotMessage((int) $foraneo['id_conversacion'], 'Aqui tienes el precio.', '-30 hours');

        // Telefono indeterminado (LID de WhatsApp): sigue el flujo normal, solo se
        // excluye lo que se identifica como foraneo.
        $lid = aiGetOrCreateConversation($this->pdo, '53236337742009', null);
        $this->seedBotMessage((int) $lid['id_conversacion'], 'Aqui tienes el precio.', '-30 hours');

        $ids = array_map(
            static fn(array $r) => (int) $r['id_conversacion'],
            aiFindConversationsNeedingFollowup($this->pdo)
        );

        $this->assertContains((int) $local['id_conversacion'], $ids);
        $this->assertNotContains((int) $foraneo['id_conversacion'], $ids);
        $this->assertContains((int) $lid['id_conversacion'], $ids);
    }

    public function testCustomerRepliedAfterFollowupDetectsNewerUserMessage(): void
    {
        $conversacion = aiGetOrCreateConversation($this->pdo, '5215500020004', null);
        $idConversacion = (int) $conversacion['id_conversacion'];

        $this->pdo->prepare('UPDATE whatsapp_conversaciones SET seguimiento_enviado_en = ? WHERE id_conversacion = ?')
            ->execute([date('Y-m-d H:i:s', strtotime('-10 hours')), $idConversacion]);

        $this->assertFalse(aiCustomerRepliedAfterFollowup($this->pdo, $idConversacion));

        $this->seedUserMessage($idConversacion, 'Ya regrese, sigo interesado', '-5 hours');

        $this->assertTrue(aiCustomerRepliedAfterFollowup($this->pdo, $idConversacion));
    }

    public function testHoursSinceFirstMessageComputesElapsedTime(): void
    {
        $conversacion = aiGetOrCreateConversation($this->pdo, '5215500020005', null);
        $idConversacion = (int) $conversacion['id_conversacion'];
        $this->seedUserMessage($idConversacion, 'Hola', '-50 hours');

        $horas = aiHoursSinceFirstMessage($this->pdo, $idConversacion);

        $this->assertNotNull($horas);
        $this->assertEqualsWithDelta(50.0, $horas, 0.1);
    }

    public function testHoursSinceFirstMessageIsNullWhenNoUserMessages(): void
    {
        $conversacion = aiGetOrCreateConversation($this->pdo, '5215500020006', null);

        $this->assertNull(aiHoursSinceFirstMessage($this->pdo, (int) $conversacion['id_conversacion']));
    }

    public function testHoursSinceLastMessageComputesElapsedTime(): void
    {
        $conversacion = aiGetOrCreateConversation($this->pdo, '5215500020008', null);
        $idConversacion = (int) $conversacion['id_conversacion'];
        $this->seedUserMessage($idConversacion, 'Hola', '-50 hours');
        $this->seedUserMessage($idConversacion, 'Sigo aqui', '-30 hours');

        $horas = aiHoursSinceLastMessage($this->pdo, $idConversacion);

        $this->assertNotNull($horas);
        $this->assertEqualsWithDelta(30.0, $horas, 0.1);
    }

    public function testHoursSinceLastMessageIsNullWhenNoMessages(): void
    {
        $conversacion = aiGetOrCreateConversation($this->pdo, '5215500020009', null);

        $this->assertNull(aiHoursSinceLastMessage($this->pdo, (int) $conversacion['id_conversacion']));
    }

    public function testAiPhoneHasLocalLadaMatchesLada33(): void
    {
        $this->assertTrue(aiPhoneHasLocalLada('5213312345678'));
    }

    public function testAiPhoneHasLocalLadaRejectsOtherLada(): void
    {
        $this->assertFalse(aiPhoneHasLocalLada('5215512345678'));
    }

    public function testAiPhoneHasLocalLadaIsNullWhenPhoneUnknown(): void
    {
        $this->assertNull(aiPhoneHasLocalLada('123'));
    }

    public function testAiPhoneHasLocalLadaIsNullForWhatsAppLid(): void
    {
        // Un "LID" de WhatsApp: 14+ digitos que no empiezan con 52. No se puede
        // derivar un telefono real, asi que la cobertura queda indeterminada (null),
        // no "fuera de zona" (false). Casos reales vistos en produccion.
        $this->assertNull(aiPhoneHasLocalLada('53236337742009'));
        $this->assertNull(aiPhoneHasLocalLada('120363402368777906'));
        $this->assertNull(aiPhoneHasLocalLada('8659190943912'));
    }

    public function testAiPhoneHasLocalLadaAcceptsMxNumberWithoutMobilePrefix(): void
    {
        // Forma <52><10 digitos>, sin el "1" movil legacy.
        $this->assertTrue(aiPhoneHasLocalLada('523312345678'));
        $this->assertFalse(aiPhoneHasLocalLada('525512345678'));
    }

    public function testAiWaIdToMxDigitsRejectsLidAndAcceptsRealNumber(): void
    {
        $this->assertSame('3312345678', aiWaIdToMxDigits('5213312345678'));
        $this->assertSame('3312345678', aiWaIdToMxDigits('523312345678'));
        $this->assertSame('3312345678', aiWaIdToMxDigits('5213312345678@s.whatsapp.net'));
        $this->assertNull(aiWaIdToMxDigits('53236337742009'));
        $this->assertNull(aiWaIdToMxDigits('53236337742009@lid'));
        $this->assertNull(aiWaIdToMxDigits('123'));
    }

    public function testCloseUnresponsiveConversationTagsAndClosesBot(): void
    {
        $conversacion = aiGetOrCreateConversation($this->pdo, '5215500020007', null);
        $idConversacion = (int) $conversacion['id_conversacion'];

        aiCloseUnresponsiveConversation($this->pdo, $idConversacion);

        $row = $this->pdo->query('SELECT estado_bot FROM whatsapp_conversaciones WHERE id_conversacion = ' . $idConversacion)->fetch();
        $this->assertSame('cerrado', $row['estado_bot']);

        $tags = aiGetConversationTags($this->pdo, $idConversacion);
        $names = array_map(static fn(array $t) => $t['nombre'], $tags);
        $this->assertContains('Preguntón', $names);
    }

    public function testGetFollowupTemplateTextFallsBackToDefaultWhenNoTemplate(): void
    {
        $texto = aiGetFollowupTemplateText($this->pdo);
        $this->assertNotSame('', trim($texto));
    }

    public function testGetFollowupTemplateTextUsesStoredTemplateWhenActive(): void
    {
        $this->seedTemplate('seguimiento_24h', 'texto', 'Mensaje de seguimiento personalizado', null);

        $this->assertSame('Mensaje de seguimiento personalizado', aiGetFollowupTemplateText($this->pdo));
    }

    public function testSendFollowupMessageLogsMessageAndMarksConversation(): void
    {
        $conversacion = aiGetOrCreateConversation($this->pdo, '5215500020008', null);

        // WA_BRIDGE_SEND_URL no esta configurado en el entorno de tests, asi que el envio
        // real falla (ok:false), pero el mensaje se debe seguir guardando y marcando.
        $ok = aiSendFollowupMessage($this->pdo, $conversacion);
        $this->assertFalse($ok);

        $row = $this->pdo->query("SELECT seguimiento_enviado_en FROM whatsapp_conversaciones WHERE id_conversacion = " . (int) $conversacion['id_conversacion'])->fetch();
        $this->assertNotNull($row['seguimiento_enviado_en']);

        $mensaje = $this->pdo->query('SELECT rol, enviado_whatsapp FROM whatsapp_mensajes ORDER BY id_mensaje DESC LIMIT 1')->fetch();
        $this->assertSame('assistant', $mensaje['rol']);
        $this->assertSame(1, (int) $mensaje['enviado_whatsapp']);
    }

    public function testLogDiagnosticErrorInsertsRowWithJsonContext(): void
    {
        $conversacion = aiGetOrCreateConversation($this->pdo, '5215500030001', null);
        $idConversacion = (int) $conversacion['id_conversacion'];

        aiLogDiagnosticError($this->pdo, $idConversacion, 'tool_excepcion', 'Quiero 5 shampoos', [
            'tool' => 'agendar_venta',
            'excepcion' => 'Stock insuficiente',
        ]);

        $row = $this->pdo->query('SELECT * FROM ai_errores_diagnostico ORDER BY id_error DESC LIMIT 1')->fetch();

        $this->assertSame($idConversacion, (int) $row['id_conversacion']);
        $this->assertSame('tool_excepcion', $row['tipo_error']);
        $this->assertSame('Quiero 5 shampoos', $row['mensaje_usuario']);
        $this->assertSame(0, (int) $row['resuelto']);

        $contexto = json_decode((string) $row['contexto_error'], true);
        $this->assertSame('agendar_venta', $contexto['tool']);
        $this->assertSame('Stock insuficiente', $contexto['excepcion']);
    }

    public function testLogDiagnosticErrorAllowsNullConversationId(): void
    {
        aiLogDiagnosticError($this->pdo, null, 'deepseek_conexion', null, []);

        $row = $this->pdo->query('SELECT id_conversacion FROM ai_errores_diagnostico ORDER BY id_error DESC LIMIT 1')->fetch();
        $this->assertNull($row['id_conversacion']);
    }

    public function testGetDiagnosticErrorsFiltersUnresolvedOnly(): void
    {
        $conversacion = aiGetOrCreateConversation($this->pdo, '5215500030002', 'Cliente Diag');
        $idConversacion = (int) $conversacion['id_conversacion'];

        aiLogDiagnosticError($this->pdo, $idConversacion, 'tool_datos_incompletos', 'msg 1', []);
        aiLogDiagnosticError($this->pdo, $idConversacion, 'pase_a_humano_incertidumbre', 'msg 2', []);

        $todos = aiGetDiagnosticErrors($this->pdo, false);
        $this->assertCount(2, $todos);

        $primerId = (int) $todos[0]['id_error'];
        aiMarkDiagnosticErrorResolved($this->pdo, $primerId);

        $soloPendientes = aiGetDiagnosticErrors($this->pdo, true);
        $this->assertCount(1, $soloPendientes);
        $this->assertNotSame($primerId, (int) $soloPendientes[0]['id_error']);

        // El join debe traer los datos del cliente para mostrarlos en el panel.
        $this->assertSame('Cliente Diag', $soloPendientes[0]['nombre_perfil']);
    }

    public function testCountUnresolvedDiagnosticErrors(): void
    {
        $this->assertSame(0, aiCountUnresolvedDiagnosticErrors($this->pdo));

        aiLogDiagnosticError($this->pdo, null, 'deepseek_conexion', null, []);
        aiLogDiagnosticError($this->pdo, null, 'deepseek_conexion', null, []);

        $this->assertSame(2, aiCountUnresolvedDiagnosticErrors($this->pdo));
    }

    public function testMarkDiagnosticErrorResolvedReturnsFalseForUnknownId(): void
    {
        $this->assertFalse(aiMarkDiagnosticErrorResolved($this->pdo, 999999));
    }

    private function seedBotMessage(int $idConversacion, string $texto, string $tiempoRelativo): void
    {
        $fecha = date('Y-m-d H:i:s', strtotime($tiempoRelativo));
        $this->pdo->prepare(
            'INSERT INTO whatsapp_mensajes (id_conversacion, rol, contenido, enviado_whatsapp, creado_en) VALUES (?, "assistant", ?, 1, ?)'
        )->execute([$idConversacion, $texto, $fecha]);
    }

    private function seedUserMessage(int $idConversacion, string $texto, string $tiempoRelativo): void
    {
        $fecha = date('Y-m-d H:i:s', strtotime($tiempoRelativo));
        $this->pdo->prepare(
            'INSERT INTO whatsapp_mensajes (id_conversacion, rol, contenido, creado_en) VALUES (?, "user", ?, ?)'
        )->execute([$idConversacion, $texto, $fecha]);
    }

    public function testNewConversationIsAutoTaggedAsClienteNuevo(): void
    {
        $conversacion = aiGetOrCreateConversation($this->pdo, '5215500009001', 'Cliente Nuevo Test');

        $tags = aiGetConversationTags($this->pdo, (int) $conversacion['id_conversacion']);
        $names = array_map(static fn(array $t) => $t['nombre'], $tags);

        $this->assertContains('Cliente Nuevo', $names);
    }

    public function testExistingConversationIsNotRetaggedOnSubsequentMessages(): void
    {
        $first = aiGetOrCreateConversation($this->pdo, '5215500009002', null);
        aiRemoveTag($this->pdo, (int) $first['id_conversacion'], $this->tagIdByName('Cliente Nuevo'));

        aiGetOrCreateConversation($this->pdo, '5215500009002', null);

        $tags = aiGetConversationTags($this->pdo, (int) $first['id_conversacion']);
        $this->assertSame([], $tags);
    }

    public function testAssignTagIsIdempotentAndFindOrCreatesByName(): void
    {
        $conversacion = aiGetOrCreateConversation($this->pdo, '5215500009003', null);
        $idConversacion = (int) $conversacion['id_conversacion'];

        $this->assertTrue(aiAssignTag($this->pdo, $idConversacion, 'Mayoreo'));
        $this->assertTrue(aiAssignTag($this->pdo, $idConversacion, 'Mayoreo'));

        $tags = aiGetConversationTags($this->pdo, $idConversacion);
        $mayoreoCount = count(array_filter($tags, static fn(array $t) => $t['nombre'] === 'Mayoreo'));

        $this->assertSame(1, $mayoreoCount);
    }

    public function testRemoveTagDeletesAssignment(): void
    {
        // aiGetOrCreateConversation ya asigna "Cliente Nuevo" automaticamente; se agrega
        // una segunda etiqueta y se verifica que solo esa se quite, no las demas.
        $conversacion = aiGetOrCreateConversation($this->pdo, '5215500009004', null);
        $idConversacion = (int) $conversacion['id_conversacion'];
        aiAssignTag($this->pdo, $idConversacion, 'Entrega Pendiente');

        $idEtiqueta = $this->tagIdByName('Entrega Pendiente');
        aiRemoveTag($this->pdo, $idConversacion, $idEtiqueta);

        $tags = aiGetConversationTags($this->pdo, $idConversacion);
        $names = array_map(static fn(array $t) => $t['nombre'], $tags);

        $this->assertNotContains('Entrega Pendiente', $names);
        $this->assertContains('Cliente Nuevo', $names);
    }

    public function testTagExists(): void
    {
        $this->assertFalse(aiTagExists($this->pdo, 'No Existe'));

        aiFindOrCreateTag($this->pdo, 'Mayoreo');

        $this->assertTrue(aiTagExists($this->pdo, 'Mayoreo'));
    }

    public function testUpsertWhatsAppLabelCreatesNewRowWithWaId(): void
    {
        $idEtiqueta = aiUpsertWhatsAppLabel($this->pdo, '7', 'VIP', '#ff00ff');

        $row = $this->pdo->query('SELECT * FROM whatsapp_etiquetas WHERE id_etiqueta = ' . $idEtiqueta)->fetch();
        $this->assertSame('7', $row['id_etiqueta_wa']);
        $this->assertSame('VIP', $row['nombre']);
        $this->assertSame('#ff00ff', $row['color']);
    }

    public function testUpsertWhatsAppLabelUpdatesExistingRowByWaId(): void
    {
        $idEtiqueta = aiUpsertWhatsAppLabel($this->pdo, '7', 'VIP', '#ff00ff');
        $idEtiquetaSegundaVez = aiUpsertWhatsAppLabel($this->pdo, '7', 'VIP Renombrado', '#00ffff');

        $this->assertSame($idEtiqueta, $idEtiquetaSegundaVez);

        $row = $this->pdo->query('SELECT * FROM whatsapp_etiquetas WHERE id_etiqueta = ' . $idEtiqueta)->fetch();
        $this->assertSame('VIP Renombrado', $row['nombre']);
        $this->assertSame('#00ffff', $row['color']);

        $count = (int) $this->pdo->query('SELECT COUNT(*) FROM whatsapp_etiquetas')->fetchColumn();
        $this->assertSame(1, $count);
    }

    public function testUpsertWhatsAppLabelAdoptsExistingInternalTagByName(): void
    {
        // Ya existia "Mayoreo" como etiqueta interna (sin id_etiqueta_wa); al sincronizar
        // una etiqueta de WhatsApp con el mismo nombre, se le asigna el id en vez de duplicarla.
        aiFindOrCreateTag($this->pdo, 'Mayoreo');

        $idEtiqueta = aiUpsertWhatsAppLabel($this->pdo, '9', 'Mayoreo', 'purple');

        $count = (int) $this->pdo->query('SELECT COUNT(*) FROM whatsapp_etiquetas WHERE nombre = "Mayoreo"')->fetchColumn();
        $this->assertSame(1, $count);

        $row = $this->pdo->query('SELECT id_etiqueta_wa FROM whatsapp_etiquetas WHERE id_etiqueta = ' . $idEtiqueta)->fetch();
        $this->assertSame('9', $row['id_etiqueta_wa']);
    }

    public function testAssignTagOnInternalTagDoesNotAttemptWhatsAppSync(): void
    {
        // AI_TAG_PREGUNTON no tiene id_etiqueta_wa: aiSyncTagToWhatsApp debe detectar esto
        // y no truena nada (comportamiento pre-existente para Cliente Nuevo/Preguntón intacto).
        $conversacion = aiGetOrCreateConversation($this->pdo, '5215500009010', null);
        $idConversacion = (int) $conversacion['id_conversacion'];

        $ok = aiAssignTag($this->pdo, $idConversacion, AI_TAG_PREGUNTON);

        $this->assertTrue($ok);
        $tags = aiGetConversationTags($this->pdo, $idConversacion);
        $names = array_map(static fn(array $t) => $t['nombre'], $tags);
        $this->assertContains(AI_TAG_PREGUNTON, $names);
    }

    public function testEtiquetarClienteRejectsUnknownTagWithoutCreatingIt(): void
    {
        $conversacion = aiGetOrCreateConversation($this->pdo, '5215500009011', null);
        $context = ['id_conversacion' => (int) $conversacion['id_conversacion']];

        $countAntes = (int) $this->pdo->query('SELECT COUNT(*) FROM whatsapp_etiquetas')->fetchColumn();

        $result = aiToolEtiquetarCliente($this->pdo, ['nombre_etiqueta' => 'Etiqueta Inventada Por El LLM'], $context);

        $this->assertFalse($result['ok']);
        $countDespues = (int) $this->pdo->query('SELECT COUNT(*) FROM whatsapp_etiquetas')->fetchColumn();
        $this->assertSame($countAntes, $countDespues, 'No debe crear etiquetas nuevas a partir de texto libre del LLM.');
    }

    public function testEtiquetarClienteAssignsKnownTag(): void
    {
        aiFindOrCreateTag($this->pdo, 'Mayoreo');
        $conversacion = aiGetOrCreateConversation($this->pdo, '5215500009012', null);
        $context = ['id_conversacion' => (int) $conversacion['id_conversacion']];

        $result = aiToolEtiquetarCliente($this->pdo, ['nombre_etiqueta' => 'Mayoreo'], $context);

        $this->assertTrue($result['ok']);
        $tags = aiGetConversationTags($this->pdo, (int) $conversacion['id_conversacion']);
        $names = array_map(static fn(array $t) => $t['nombre'], $tags);
        $this->assertContains('Mayoreo', $names);
    }

    public function testQuitarEtiquetaClienteRemovesKnownTag(): void
    {
        aiFindOrCreateTag($this->pdo, 'Mayoreo');
        $conversacion = aiGetOrCreateConversation($this->pdo, '5215500009013', null);
        $idConversacion = (int) $conversacion['id_conversacion'];
        aiAssignTag($this->pdo, $idConversacion, 'Mayoreo');

        $result = aiToolQuitarEtiquetaCliente($this->pdo, ['nombre_etiqueta' => 'Mayoreo'], ['id_conversacion' => $idConversacion]);

        $this->assertTrue($result['ok']);
        $tags = aiGetConversationTags($this->pdo, $idConversacion);
        $names = array_map(static fn(array $t) => $t['nombre'], $tags);
        $this->assertNotContains('Mayoreo', $names);
    }

    public function testQuitarEtiquetaClienteFailsForUnknownTag(): void
    {
        $conversacion = aiGetOrCreateConversation($this->pdo, '5215500009014', null);
        $result = aiToolQuitarEtiquetaCliente(
            $this->pdo,
            ['nombre_etiqueta' => 'No Existe'],
            ['id_conversacion' => (int) $conversacion['id_conversacion']]
        );

        $this->assertFalse($result['ok']);
    }

    public function testCreateAndListActiveLearningRules(): void
    {
        aiCreateLearningRule($this->pdo, 'Pregunta sobre garantia', 'Explicar politica de 30 dias', 'Cliente Frecuente');
        $idInactiva = aiCreateLearningRule($this->pdo, 'Pregunta rara', 'Respuesta rara', null);
        aiSetLearningRuleActive($this->pdo, $idInactiva, false);

        $activas = aiGetActiveLearningRules($this->pdo);
        $this->assertCount(1, $activas);
        $this->assertSame('Pregunta sobre garantia', $activas[0]['contexto_o_pregunta']);

        $todas = aiGetAllLearningRules($this->pdo);
        $this->assertCount(2, $todas);
    }

    public function testSetLearningRuleActiveTogglesBothWays(): void
    {
        $idRegla = aiCreateLearningRule($this->pdo, 'Contexto', 'Respuesta', null);

        $this->assertTrue(aiSetLearningRuleActive($this->pdo, $idRegla, false));
        $this->assertCount(0, aiGetActiveLearningRules($this->pdo));

        $this->assertTrue(aiSetLearningRuleActive($this->pdo, $idRegla, true));
        $this->assertCount(1, aiGetActiveLearningRules($this->pdo));
    }

    public function testHumanOutboundMessagePausesActiveBotAndLogsHumanRole(): void
    {
        aiHandleHumanOutboundMessage($this->pdo, '5215500009005', 'Ya te apoyo yo directo', 'fromme-1');

        $conv = $this->pdo->query("SELECT * FROM whatsapp_conversaciones WHERE wa_id = '5215500009005'")->fetch();
        $this->assertSame('pausado', $conv['estado_bot']);

        $mensaje = $this->pdo->query('SELECT rol, contenido, enviado_whatsapp FROM whatsapp_mensajes ORDER BY id_mensaje DESC LIMIT 1')->fetch();
        $this->assertSame('humano', $mensaje['rol']);
        $this->assertSame('Ya te apoyo yo directo', $mensaje['contenido']);
        $this->assertSame(1, (int) $mensaje['enviado_whatsapp']);
    }

    public function testHumanOutboundMessageDoesNotOverwriteExistingPauseReason(): void
    {
        $conversacion = aiGetOrCreateConversation($this->pdo, '5215500009006', null);
        aiSetConversationState($this->pdo, (int) $conversacion['id_conversacion'], 'pausado', 'Motivo original del cliente');

        aiHandleHumanOutboundMessage($this->pdo, '5215500009006', 'Aviso manual', null);

        $conv = $this->pdo->query('SELECT estado_bot, motivo_transferencia FROM whatsapp_conversaciones WHERE id_conversacion = ' . (int) $conversacion['id_conversacion'])->fetch();
        $this->assertSame('pausado', $conv['estado_bot']);
        $this->assertSame('Motivo original del cliente', $conv['motivo_transferencia']);
    }

    private function tagIdByName(string $nombre): int
    {
        $stmt = $this->pdo->prepare('SELECT id_etiqueta FROM whatsapp_etiquetas WHERE nombre = ?');
        $stmt->execute([$nombre]);

        return (int) $stmt->fetchColumn();
    }

    private function createSchema(): void
    {
        $this->pdo->exec(
            'CREATE TABLE productos (
                id_producto INTEGER PRIMARY KEY,
                id_padre INTEGER NULL,
                nombre TEXT NOT NULL,
                codigo_barras TEXT NOT NULL DEFAULT "",
                nombre_variante TEXT NULL,
                precio_venta REAL NOT NULL DEFAULT 0,
                estado TEXT NOT NULL DEFAULT "activo",
                descripcion TEXT NULL,
                ingredientes TEXT NULL,
                beneficios TEXT NULL
            )'
        );
        $this->pdo->exec(
            'CREATE TABLE inventario_almacen (
                id_producto INTEGER NOT NULL,
                id_almacen INTEGER NOT NULL,
                cantidad_actual INTEGER NOT NULL DEFAULT 0
            )'
        );
        $this->pdo->exec(
            'CREATE TABLE clientes (
                id_cliente INTEGER PRIMARY KEY,
                id_usuario INTEGER NULL,
                nombre TEXT NULL,
                telefono TEXT NULL,
                estado TEXT NOT NULL DEFAULT "activo"
            )'
        );
        $this->pdo->exec(
            'CREATE TABLE cliente_direcciones (
                id_direccion INTEGER PRIMARY KEY AUTOINCREMENT,
                id_cliente INTEGER NOT NULL,
                alias TEXT NULL,
                direccion TEXT NOT NULL,
                maps_link TEXT NULL,
                es_default INTEGER NOT NULL DEFAULT 0,
                latitud REAL NULL,
                longitud REAL NULL
            )'
        );
        $this->pdo->exec(
            'CREATE TABLE whatsapp_conversaciones (
                id_conversacion INTEGER PRIMARY KEY AUTOINCREMENT,
                wa_id TEXT NOT NULL UNIQUE,
                id_cliente INTEGER NULL,
                nombre_perfil TEXT NULL,
                estado_bot TEXT NOT NULL DEFAULT "activo",
                motivo_transferencia TEXT NULL,
                ultimo_mensaje_en TEXT NULL,
                seguimiento_enviado_en TEXT NULL,
                creado_en TEXT DEFAULT CURRENT_TIMESTAMP
            )'
        );
        $this->pdo->exec(
            'CREATE TABLE whatsapp_mensajes (
                id_mensaje INTEGER PRIMARY KEY AUTOINCREMENT,
                id_conversacion INTEGER NOT NULL,
                wa_message_id TEXT NULL UNIQUE,
                rol TEXT NOT NULL,
                contenido TEXT NULL,
                tool_calls_json TEXT NULL,
                tool_call_id TEXT NULL,
                tool_name TEXT NULL,
                enviado_whatsapp INTEGER NOT NULL DEFAULT 0,
                creado_en TEXT DEFAULT CURRENT_TIMESTAMP
            )'
        );
        $this->pdo->exec(
            'CREATE TABLE whatsapp_templates (
                id_plantilla INTEGER PRIMARY KEY AUTOINCREMENT,
                codigo TEXT NOT NULL UNIQUE,
                tipo TEXT NOT NULL DEFAULT "texto",
                texto TEXT NULL,
                url_archivo TEXT NULL,
                nombre_archivo TEXT NULL,
                activo INTEGER NOT NULL DEFAULT 1
            )'
        );
        $this->pdo->exec(
            'CREATE TABLE ai_errores_diagnostico (
                id_error INTEGER PRIMARY KEY AUTOINCREMENT,
                id_conversacion INTEGER NULL,
                tipo_error TEXT NOT NULL,
                mensaje_usuario TEXT NULL,
                contexto_error TEXT NULL,
                resuelto INTEGER NOT NULL DEFAULT 0,
                fecha_creacion TEXT DEFAULT CURRENT_TIMESTAMP
            )'
        );
        $this->pdo->exec(
            'CREATE TABLE ai_reglas_aprendizaje (
                id_regla INTEGER PRIMARY KEY AUTOINCREMENT,
                contexto_o_pregunta TEXT NOT NULL,
                respuesta_o_accion_esperada TEXT NOT NULL,
                etiqueta_sugerida TEXT NULL,
                activa INTEGER NOT NULL DEFAULT 1,
                fecha_creacion TEXT DEFAULT CURRENT_TIMESTAMP
            )'
        );
        $this->pdo->exec(
            'CREATE TABLE ai_asistente_config (
                id_config INTEGER PRIMARY KEY,
                activo INTEGER NOT NULL DEFAULT 1,
                nombre_persona TEXT NOT NULL DEFAULT "Alex",
                tono_instrucciones TEXT NULL,
                promocion_vigente_texto TEXT NULL,
                politica_envio_texto TEXT NULL,
                politica_pago_texto TEXT NULL,
                ubicacion_texto TEXT NULL,
                mensaje_bienvenida TEXT NULL,
                modelo_llm TEXT NOT NULL DEFAULT "deepseek-chat",
                temperatura REAL NOT NULL DEFAULT 0.30,
                prompt_sistema_override TEXT NULL,
                api_key_variable TEXT NOT NULL DEFAULT "DEEPSEEK_AI_ASSISTANT"
            )'
        );
        $this->pdo->exec(
            'CREATE TABLE whatsapp_etiquetas (
                id_etiqueta INTEGER PRIMARY KEY AUTOINCREMENT,
                id_etiqueta_wa TEXT NULL UNIQUE,
                nombre TEXT NOT NULL UNIQUE,
                color TEXT NOT NULL DEFAULT "grey"
            )'
        );
        $this->pdo->exec(
            'CREATE TABLE whatsapp_conversacion_etiquetas (
                id_conversacion INTEGER NOT NULL,
                id_etiqueta INTEGER NOT NULL,
                asignado_en TEXT DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (id_conversacion, id_etiqueta)
            )'
        );
    }

    private function seedTemplate(string $codigo, string $tipo, ?string $texto, ?string $urlArchivo, int $activo = 1, ?string $nombreArchivo = null): void
    {
        $this->pdo->prepare('INSERT INTO whatsapp_templates (codigo, tipo, texto, url_archivo, nombre_archivo, activo) VALUES (?, ?, ?, ?, ?, ?)')
            ->execute([$codigo, $tipo, $texto, $urlArchivo, $nombreArchivo, $activo]);
    }

    private function seedProducto(
        int $id,
        string $nombre,
        string $codigoBarras,
        ?string $variante,
        float $precio,
        string $estado = 'activo',
        ?string $descripcion = null,
        ?string $ingredientes = null,
        ?string $beneficios = null
    ): void {
        $this->pdo->prepare(
            'INSERT INTO productos (id_producto, nombre, codigo_barras, nombre_variante, precio_venta, estado, descripcion, ingredientes, beneficios)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)'
        )->execute([$id, $nombre, $codigoBarras, $variante, $precio, $estado, $descripcion, $ingredientes, $beneficios]);
    }

    private function seedInventario(int $idProducto, int $idAlmacen, int $cantidad): void
    {
        $this->pdo->prepare('INSERT INTO inventario_almacen (id_producto, id_almacen, cantidad_actual) VALUES (?, ?, ?)')
            ->execute([$idProducto, $idAlmacen, $cantidad]);
    }

    private function seedCliente(int $idCliente, string $telefono): void
    {
        $this->pdo->prepare('INSERT INTO clientes (id_cliente, telefono) VALUES (?, ?)')->execute([$idCliente, $telefono]);
    }

    /**
     * piiEncryptValue() lanza excepcion si PII_ENCRYPTION_KEY no esta configurada
     * (no ocurre en el entorno normal de pruebas). Se configura temporalmente solo
     * para las pruebas que necesitan de verdad cifrar/descifrar, igual que hace
     * PhoneDuplicateDetectionTest.
     */
    private function withPiiEncryptionKey(string $key, callable $fn): void
    {
        $original = (string) (getenv('PII_ENCRYPTION_KEY') ?: '');
        putenv('PII_ENCRYPTION_KEY=' . $key);
        $_SERVER['PII_ENCRYPTION_KEY'] = $key;
        $_ENV['PII_ENCRYPTION_KEY'] = $key;
        try {
            $fn();
        } finally {
            putenv('PII_ENCRYPTION_KEY=' . $original);
            if ($original !== '') {
                $_SERVER['PII_ENCRYPTION_KEY'] = $original;
                $_ENV['PII_ENCRYPTION_KEY'] = $original;
            } else {
                unset($_SERVER['PII_ENCRYPTION_KEY'], $_ENV['PII_ENCRYPTION_KEY']);
            }
        }
    }

    public function testAiFindOrCreateClienteReusesExistingMatchByPhone(): void
    {
        $this->withPiiEncryptionKey('test-key-1234567890', function (): void {
            $this->pdo->prepare('INSERT INTO clientes (id_cliente, nombre, telefono) VALUES (?, ?, ?)')
                ->execute([42, piiEncryptValue('Cliente Existente'), piiEncryptValue('(331) - 555 - 0100')]);

            $idCliente = aiFindOrCreateCliente($this->pdo, '5213315550100', 'Nombre Distinto En WhatsApp');

            $this->assertSame(42, $idCliente);
            $this->assertSame(1, (int) $this->pdo->query('SELECT COUNT(*) FROM clientes')->fetchColumn());
        });
    }

    public function testAiFindOrCreateClienteCreatesNewClienteWhenNoMatch(): void
    {
        $this->withPiiEncryptionKey('test-key-1234567890', function (): void {
            $idCliente = aiFindOrCreateCliente($this->pdo, '5213319998888', 'Cliente Nuevo WhatsApp');

            $this->assertGreaterThan(0, $idCliente);
            $row = $this->pdo->query('SELECT nombre, telefono FROM clientes WHERE id_cliente = ' . $idCliente)->fetch();
            $this->assertSame('Cliente Nuevo WhatsApp', piiDecryptValue($row['nombre']));
            $this->assertSame('(331) - 999 - 8888', piiDecryptValue($row['telefono']));
        });
    }

    public function testAiSaveClienteDireccionMarksFirstAddressAsDefault(): void
    {
        $this->withPiiEncryptionKey('test-key-1234567890', function (): void {
            $this->seedCliente(9, '3310000000');

            aiSaveClienteDireccion($this->pdo, 9, 'Calle Falsa 123, Colonia Centro, CP 44100, Guadalajara');

            $row = $this->pdo->query('SELECT direccion, es_default FROM cliente_direcciones WHERE id_cliente = 9')->fetch();
            $this->assertSame(1, (int) $row['es_default']);
            $this->assertSame('Calle Falsa 123, Colonia Centro, CP 44100, Guadalajara', piiDecryptValue($row['direccion']));
        });
    }

    public function testAiSaveClienteDireccionSecondAddressIsNotDefault(): void
    {
        $this->withPiiEncryptionKey('test-key-1234567890', function (): void {
            $this->seedCliente(10, '3310000001');

            aiSaveClienteDireccion($this->pdo, 10, 'Primera direccion 1');
            aiSaveClienteDireccion($this->pdo, 10, 'Segunda direccion 2');

            $this->assertSame(2, (int) $this->pdo->query('SELECT COUNT(*) FROM cliente_direcciones WHERE id_cliente = 10')->fetchColumn());
            $segunda = $this->pdo->query("SELECT es_default FROM cliente_direcciones WHERE id_cliente = 10 ORDER BY id_direccion DESC LIMIT 1")->fetch();
            $this->assertSame(0, (int) $segunda['es_default']);
        });
    }

    public function testAiToolAgendarVentaCreatesClienteAndTransfersWhenDireccionMissing(): void
    {
        $this->withPiiEncryptionKey('test-key-1234567890', function (): void {
            $this->seedProducto(600, 'Producto Envio', 'PE600', null, 199.00);
            $this->seedInventario(600, 1, 5);
            $conversacion = aiGetOrCreateConversation($this->pdo, '5213317778888', null);
            $idConversacion = (int) $conversacion['id_conversacion'];

            $args = [
                'nombre_cliente' => 'Cliente Sin Direccion',
                'telefono' => '3317778888',
                'direccion_envio' => '',
                'metodo_pago_preferido' => 'Efectivo',
                'lista_productos' => [['id_producto' => 600, 'cantidad' => 1]],
            ];
            $context = ['wa_id' => '5213317778888', 'id_conversacion' => $idConversacion];

            $result = aiToolAgendarVenta($this->pdo, $args, $context);

            $this->assertFalse($result['ok']);
            $this->assertSame(1, (int) $this->pdo->query('SELECT COUNT(*) FROM clientes')->fetchColumn());

            $estado = $this->pdo->query('SELECT estado_bot FROM whatsapp_conversaciones WHERE id_conversacion = ' . $idConversacion)->fetchColumn();
            $this->assertSame('pausado', $estado);
        });
    }
}
