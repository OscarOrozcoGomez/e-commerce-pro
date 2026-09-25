<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class AiAssistantPromptTest extends TestCase
{
    private function baseConfig(): array
    {
        return [
            'nombre_persona' => 'Alex',
            'tono_instrucciones' => '',
            'promocion_vigente_texto' => '',
            'politica_envio_texto' => '',
            'politica_pago_texto' => '',
            'ubicacion_texto' => '',
        ];
    }

    public function testSystemPromptIncludesPersonaAndHardRule(): void
    {
        $prompt = aiBuildSystemPrompt($this->baseConfig(), 'Ana');

        $this->assertStringContainsString('Eres Alex', $prompt);
        $this->assertStringContainsString('El nombre de perfil de WhatsApp del cliente es: Ana', $prompt);
        $this->assertStringContainsString('jamas menciones un precio, existencia o caracteristica', $prompt);
        $this->assertStringContainsString('consultar_inventario', $prompt);
        $this->assertStringContainsString('transferir_a_humano', $prompt);
    }

    public function testSystemPromptOmitsPromoWhenEmptyAndIncludesWhenSet(): void
    {
        $withoutPromo = aiBuildSystemPrompt($this->baseConfig(), null);
        $this->assertStringNotContainsString('Promocion vigente:', $withoutPromo);

        $config = $this->baseConfig();
        $config['promocion_vigente_texto'] = 'Envio gratis en compras mayores a $500 MXN';
        $withPromo = aiBuildSystemPrompt($config, null);
        $this->assertStringContainsString('Promocion vigente: Envio gratis en compras mayores a $500 MXN', $withPromo);
    }

    public function testSystemPromptIncludesShippingAndPaymentPoliciesOnlyWhenSet(): void
    {
        $config = $this->baseConfig();
        $config['politica_envio_texto'] = 'Entregamos en 24-48h en la ciudad.';
        $config['politica_pago_texto'] = 'Aceptamos tarjeta, transferencia y OXXO.';
        $prompt = aiBuildSystemPrompt($config, null);

        $this->assertStringContainsString('Entregamos en 24-48h', $prompt);
        $this->assertStringContainsString('Aceptamos tarjeta, transferencia y OXXO.', $prompt);
    }

    public function testSystemPromptIncludesLocationOnlyWhenSet(): void
    {
        $withoutUbicacion = aiBuildSystemPrompt($this->baseConfig(), null);
        $this->assertStringNotContainsString('Ubicacion del negocio', $withoutUbicacion);

        $config = $this->baseConfig();
        $config['ubicacion_texto'] = 'Tabachín 248, Bosques de Tonalá, 45400 Tonalá, Jal. Puedes ver el mapa aquí: https://maps.app.goo.gl/cBpMFXU27MXL4k9F6';
        $conUbicacion = aiBuildSystemPrompt($config, null);

        $this->assertStringContainsString('Tabachín 248, Bosques de Tonalá', $conUbicacion);
        $this->assertStringContainsString('https://maps.app.goo.gl/cBpMFXU27MXL4k9F6', $conUbicacion);
    }

    public function testSystemPromptIncludesClientProfileLineOnlyWhenSet(): void
    {
        $sinPerfil = aiBuildSystemPrompt($this->baseConfig(), null, [], [], null, null, null);
        $this->assertStringNotContainsString('Este cliente', $sinPerfil);

        $conPerfil = aiBuildSystemPrompt($this->baseConfig(), null, [], [], null, null, 'Este cliente ya compro antes: Magnesio Citrate 240.');
        $this->assertStringContainsString('Este cliente ya compro antes: Magnesio Citrate 240.', $conPerfil);
    }

    public function testSystemPromptInstructsPresentingMultiplePresentations(): void
    {
        $prompt = aiBuildSystemPrompt($this->baseConfig(), null);

        $this->assertStringContainsString('presentaciones', $prompt);
        $this->assertStringContainsString('categorias', $prompt);
    }

    public function testSystemPromptNeverContainsWebMarkdownOrHtml(): void
    {
        $config = $this->baseConfig();
        $config['tono_instrucciones'] = 'Se muy amable.';
        $prompt = aiBuildSystemPrompt($config, 'Cliente');

        $this->assertStringNotContainsString('**', $prompt);
        $this->assertStringNotContainsString('<', $prompt);
        $this->assertDoesNotMatchRegularExpression('/^#{1,6}\s/m', $prompt);
    }

    public function testSystemPromptAllowsWhatsappNativeFormattingInstruction(): void
    {
        $prompt = aiBuildSystemPrompt($this->baseConfig(), null);

        $this->assertStringContainsString('*negritas*', $prompt);
        $this->assertStringContainsString('_cursivas_', $prompt);
    }

    public function testSystemPromptNeverInventsTechnicalFailureWords(): void
    {
        $prompt = aiBuildSystemPrompt($this->baseConfig(), null);

        $this->assertStringContainsString('nunca uses las palabras "error", "falla" ni "sistema"', $prompt);
    }

    public function testSystemPromptTellsAlexHowToHandleNonTextMessages(): void
    {
        $prompt = aiBuildSystemPrompt($this->baseConfig(), null);

        $this->assertStringContainsString('no son texto', $prompt);
        $this->assertStringContainsString('nunca ignores ese mensaje', $prompt);
    }

    public function testSystemPromptIncludesContinuityRule(): void
    {
        $prompt = aiBuildSystemPrompt($this->baseConfig(), null);

        $this->assertStringContainsString('no repetir preguntas cuya respuesta el cliente ya te dio', $prompt);
    }

    public function testSystemPromptOmitsReactivationNoteWhenRecentOrUnknown(): void
    {
        $sinDato = aiBuildSystemPrompt($this->baseConfig(), null);
        $this->assertStringNotContainsString('no escribia desde hace', $sinDato);

        $reciente = aiBuildSystemPrompt($this->baseConfig(), null, [], [], 2.0);
        $this->assertStringNotContainsString('no escribia desde hace', $reciente);
    }

    public function testSystemPromptIncludesReactivationNoteAfterADayOfInactivity(): void
    {
        $prompt = aiBuildSystemPrompt($this->baseConfig(), null, [], [], 30.0);

        $this->assertStringContainsString('no escribia desde hace aproximadamente 1 dia(s)', $prompt);
        $this->assertStringContainsString('No lo saludes como si fuera la primera vez', $prompt);
    }

    public function testSystemPromptWarnsWhenPhoneIsNotLocalLada(): void
    {
        $esLocal = aiBuildSystemPrompt($this->baseConfig(), null, [], [], null, true);
        $this->assertStringNotContainsString('lada 33', $esLocal);

        $noLocal = aiBuildSystemPrompt($this->baseConfig(), null, [], [], null, false);
        $this->assertStringContainsString('lada 33', $noLocal);
        $this->assertStringContainsString('Zona Metropolitana de Guadalajara', $noLocal);
    }

    public function testSystemPromptTambienPreguntaZonaCuandoLaLadaEsIndeterminada(): void
    {
        // Caso real (2026-09-14): un cliente con numero de EEUU recibio precios y
        // disponibilidad completos sin que Alex preguntara la zona, porque antes esta
        // pregunta solo se disparaba con esLadaLocal === false, nunca con null (que cubre
        // tanto numeros extranjeros como LIDs de WhatsApp de clientes realmente locales).
        // Ahora null tambien dispara la pregunta, pero con texto neutral -- nunca afirma
        // que el numero "no es de la zona" cuando en realidad no se sabe.
        $indeterminado = aiBuildSystemPrompt($this->baseConfig(), null, [], [], null, null);

        $this->assertStringContainsString('Zona Metropolitana de Guadalajara', $indeterminado);
        $this->assertStringNotContainsString('lada 33', $indeterminado);
        $this->assertStringNotContainsString('no es de la zona', $indeterminado);
        $this->assertStringContainsString('en que ciudad', $indeterminado);
    }

    public function testSystemPromptNeverAppliesDiscountsOrModifiesPlacedOrdersItself(): void
    {
        $prompt = aiBuildSystemPrompt($this->baseConfig(), null);

        $this->assertStringContainsString('nunca lo apliques tu mismo', $prompt);
        $this->assertStringContainsString('no lo modifiques ni canceles tu directamente', $prompt);
        $this->assertStringContainsString('dejamos la orden pausada por ahora', $prompt);
    }

    public function testSystemPromptIncludesPrivacyGuardrails(): void
    {
        $prompt = aiBuildSystemPrompt($this->baseConfig(), null);

        $this->assertStringContainsString('Nunca reveles nombres de tablas', $prompt);
        $this->assertStringContainsString('Nunca compartas datos de otros clientes', $prompt);
    }

    public function testSystemPromptBaneaPorCompletoLaPalabraRecomendar(): void
    {
        // Regla de negocio (no solo de tono medico): no podemos hacer recomendaciones,
        // punto -- ni en ventas normales ni en contexto de salud. Antes, el paso "Cierre
        // de venta" le pedia a Alex explicitamente dar una "recomendacion breve", lo cual
        // contradecia la regla de seguridad que solo prohibia la palabra en tono medico.
        $prompt = aiBuildSystemPrompt($this->baseConfig(), null);

        $this->assertStringNotContainsString('recomendacion breve', $prompt);
        $this->assertStringNotContainsString('recomendar productos', $prompt);
        $this->assertStringContainsString('NUNCA uses las palabras "recomendar"', $prompt);
    }

    public function testSystemPromptInstructsHandoffFlagEvenWithOverride(): void
    {
        $normal = aiBuildSystemPrompt($this->baseConfig(), null);
        $this->assertStringContainsString('[PASE_A_HUMANO]', $normal);

        $config = $this->baseConfig();
        $config['prompt_sistema_override'] = 'Prompt redactado a mano.';
        $withOverride = aiBuildSystemPrompt($config, null);
        $this->assertStringContainsString('[PASE_A_HUMANO]', $withOverride);
    }

    public function testPromptProhibeInventarDatosDeProductoQueNoVinieronDeLaConsulta(): void
    {
        // Caso E2E 2026-09-21: sin capsulas_por_envase capturado, Alex dijo "120 capsulas" de memoria.
        $prompt = aiBuildSystemPrompt($this->baseConfig(), null);

        $this->assertStringContainsString('solo lo puedes decir si viene explicito en lo que consultar_inventario', $prompt);
        $this->assertStringContainsString('NO lo deduzcas ni lo recuerdes de memoria', $prompt);
    }

    public function testDetectaPromesaDeConfirmarConElEquipo(): void
    {
        // Caso real 2026-09-21: prometió confirmar y nunca llegó la alerta a Telegram.
        $this->assertTrue(aiTextoPrometeConsultarEquipo('¡Buena pregunta, Angy! 😊 Déjame confirmarte ese dato exacto con el equipo, porque quiero darte la información correcta.'));
        $this->assertTrue(aiTextoPrometeConsultarEquipo('No tengo ese detalle, lo confirmo con el equipo y te aviso.'));
        $this->assertTrue(aiTextoPrometeConsultarEquipo('Lo checo con un compañero y te cuento.'));
        $this->assertFalse(aiTextoPrometeConsultarEquipo('Hacemos entregas los miércoles y sábados. El equipo sale desde temprano.'));
        $this->assertFalse(aiTextoPrometeConsultarEquipo('Te confirmo tu pedido. Quedó con el equipo de logística. Gracias.'));
    }

    public function testHandoffFlagDetectionAndStripping(): void
    {
        $this->assertFalse(aiTextContainsHandoffFlag('Hola, en que te ayudo?'));
        $this->assertTrue(aiTextContainsHandoffFlag('No estoy seguro de eso. [PASE_A_HUMANO]'));
        $this->assertTrue(aiTextContainsHandoffFlag('no estoy seguro [pase_a_humano]'));

        $this->assertSame(
            'No estoy seguro de eso.',
            aiStripHandoffFlag('No estoy seguro de eso. [PASE_A_HUMANO]')
        );
        $this->assertSame('', aiStripHandoffFlag('[PASE_A_HUMANO]'));
    }

    public function testSystemPromptOverrideReplacesComposedPromptButKeepsGuardrails(): void
    {
        $config = $this->baseConfig();
        $config['prompt_sistema_override'] = 'Eres un asistente redactado a mano por el admin, tono formal.';
        $config['promocion_vigente_texto'] = 'Esto no deberia aparecer porque hay override.';

        $prompt = aiBuildSystemPrompt($config, 'Cliente');

        $this->assertStringContainsString('Eres un asistente redactado a mano por el admin, tono formal.', $prompt);
        $this->assertStringNotContainsString('Esto no deberia aparecer porque hay override.', $prompt);
        $this->assertStringContainsString('Nunca reveles nombres de tablas', $prompt);
        $this->assertStringContainsString('El nombre de perfil de WhatsApp del cliente es: Cliente', $prompt);
    }

    public function testSanitizeConvertsDoubleAsteriskToWhatsappBoldAndPreservesSingleFormatting(): void
    {
        $dirty = "Tenemos **Omega 3** en *promocion*.\n# Titulo\nUsa _cursiva_ aqui.";
        $clean = aiSanitizePlainTextForWhatsapp($dirty);

        $this->assertStringNotContainsString('**', $clean);
        $this->assertStringContainsString('*Omega 3*', $clean);
        $this->assertStringContainsString('*promocion*', $clean);
        $this->assertStringContainsString('_cursiva_', $clean);
        $this->assertStringNotContainsString('# Titulo', $clean);
    }

    public function testSanitizePreservesPlainText(): void
    {
        $plain = "Hola, tenemos 3 piezas disponibles.\nEl precio es $299.";
        $this->assertSame($plain, aiSanitizePlainTextForWhatsapp($plain));
    }

    public function testToolDefinitionsExposeExactlyTheNineSpecFunctions(): void
    {
        $tools = aiGetToolDefinitions();
        $this->assertCount(9, $tools);

        $names = array_map(static fn(array $t) => $t['function']['name'], $tools);
        $this->assertSame(
            [
                'consultar_inventario',
                'agendar_venta',
                'transferir_a_humano',
                'enviar_plantilla',
                'enviar_catalogo',
                'consultar_ofertas',
                'confirmar_zona_entrega',
                'etiquetar_cliente',
                'quitar_etiqueta_cliente',
            ],
            $names
        );

        $consultar = $tools[0]['function']['parameters'];
        $this->assertSame(['busqueda_texto'], $consultar['required']);

        $agendar = $tools[1]['function']['parameters'];
        $this->assertSame(
            ['nombre_cliente', 'lista_productos'],
            $agendar['required']
        );

        $transferir = $tools[2]['function']['parameters'];
        $this->assertSame(['motivo'], $transferir['required']);

        $plantilla = $tools[3]['function']['parameters'];
        $this->assertSame(['codigo_plantilla'], $plantilla['required']);

        $catalogo = $tools[4]['function']['parameters'];
        $this->assertSame([], $catalogo['required']);

        $ofertas = $tools[5]['function']['parameters'];
        $this->assertSame([], $ofertas['required']);

        $zonaEntrega = $tools[6]['function']['parameters'];
        $this->assertSame(['ciudad_o_direccion'], $zonaEntrega['required']);

        $etiquetar = $tools[7]['function']['parameters'];
        $this->assertSame(['nombre_etiqueta'], $etiquetar['required']);

        $quitarEtiqueta = $tools[8]['function']['parameters'];
        $this->assertSame(['nombre_etiqueta'], $quitarEtiqueta['required']);
    }

    public function testSystemPromptMentionsEnviarCatalogoForCatalogRequests(): void
    {
        $prompt = aiBuildSystemPrompt($this->baseConfig(), null);

        $this->assertStringContainsString('enviar_catalogo', $prompt);
    }

    public function testSystemPromptListsAvailableTagsWhenProvided(): void
    {
        $etiquetas = [
            ['id_etiqueta' => 1, 'nombre' => 'Mayoreo', 'color' => 'purple'],
            ['id_etiqueta' => 2, 'nombre' => 'Cliente Frecuente', 'color' => 'green'],
        ];

        $prompt = aiBuildSystemPrompt($this->baseConfig(), null, $etiquetas);

        $this->assertStringContainsString('Mayoreo', $prompt);
        $this->assertStringContainsString('Cliente Frecuente', $prompt);
        $this->assertStringContainsString('etiquetar_cliente', $prompt);
    }

    public function testSystemPromptOmitsTagSectionWhenNoTagsAvailable(): void
    {
        $prompt = aiBuildSystemPrompt($this->baseConfig(), null, []);

        $this->assertStringNotContainsString('Etiquetas de WhatsApp disponibles', $prompt);
    }

    public function testSystemPromptIncludesFewShotRulesWhenProvided(): void
    {
        $reglas = [
            [
                'contexto_o_pregunta' => 'Cliente pregunta si hacen envios a Cancun',
                'respuesta_o_accion_esperada' => 'Si, cubrimos todo Mexico via paqueteria.',
                'etiqueta_sugerida' => 'Entrega Pendiente',
            ],
        ];

        $prompt = aiBuildSystemPrompt($this->baseConfig(), null, [], $reglas);

        $this->assertStringContainsString('Cliente pregunta si hacen envios a Cancun', $prompt);
        $this->assertStringContainsString('cubrimos todo Mexico via paqueteria', $prompt);
        $this->assertStringContainsString('Entrega Pendiente', $prompt);
    }

    public function testBuildFewShotBlockReturnsEmptyStringWhenNoRules(): void
    {
        $this->assertSame('', aiBuildFewShotBlock([]));
    }

    public function testBuildFewShotBlockSkipsIncompleteRules(): void
    {
        $reglas = [
            ['contexto_o_pregunta' => '', 'respuesta_o_accion_esperada' => 'algo', 'etiqueta_sugerida' => null],
        ];

        $this->assertSame('', aiBuildFewShotBlock($reglas));
    }

    public function testSystemPromptHidesPedidoAgendadoTagFromAlex(): void
    {
        $etiquetas = [
            ['nombre' => 'Cliente Frecuente'],
            ['nombre' => AI_TAG_PEDIDO_AGENDADO],
            ['nombre' => 'Mayoreo'],
        ];

        $prompt = aiBuildSystemPrompt($this->baseConfig(), null, $etiquetas);

        // Alex ve las demas etiquetas pero NUNCA "Pedido Agendado": esa la pone
        // solo el codigo al confirmar un pedido real.
        $this->assertStringContainsString('Cliente Frecuente', $prompt);
        $this->assertStringContainsString('Mayoreo', $prompt);
        $this->assertStringNotContainsString(AI_TAG_PEDIDO_AGENDADO, $prompt);
    }
    public function testDatosClienteConocidoConUnaDireccionPideConfirmarlaSinPedirNombre(): void
    {
        $linea = aiBuildDatosClienteConocidoContextLine(
            ['nombre' => 'Margarita Lopez', 'telefono' => '3314972545', 'direcciones' => ['Calle Uno 1, Tlajomulco']],
            '3314972545'
        );

        $this->assertStringContainsString('NO le pidas su nombre ni su telefono', $linea);
        $this->assertStringContainsString('"Margarita Lopez"', $linea);
        $this->assertStringContainsString('no se lo digas', $linea);
        $this->assertStringContainsString('Calle Uno 1, Tlajomulco', $linea);
        $this->assertStringContainsString('(331) - 497 - 2545', $linea);
    }

    public function testDatosClienteConocidoConVariasDireccionesLasEnlistaYNoElige(): void
    {
        $linea = aiBuildDatosClienteConocidoContextLine(
            ['nombre' => 'Margarita', 'telefono' => '3314972545', 'direcciones' => ['Casa 1', 'Trabajo 2']],
            '3314972545'
        );

        $this->assertStringContainsString('2 direcciones', $linea);
        $this->assertStringContainsString('1) Casa 1 | 2) Trabajo 2', $linea);
        $this->assertStringContainsString('Nunca elijas tu la direccion', $linea);
    }

    public function testDatosClienteConocidoNoMuestraDireccionSiElTelefonoNoEsElDelChat(): void
    {
        // Ficha enlazada por un numero dictado: la direccion podria ser de otra persona.
        $linea = aiBuildDatosClienteConocidoContextLine(
            ['nombre' => 'Otra Persona', 'telefono' => '3311112222', 'direcciones' => ['Calle Secreta 9']],
            '3314972545'
        );

        $this->assertStringNotContainsString('Calle Secreta 9', $linea);
        $this->assertStringContainsString('pidesela completa', $linea);
        $this->assertSame('', aiBuildDatosClienteConocidoContextLine(['nombre' => '', 'telefono' => null, 'direcciones' => []], '3314972545'));
    }

    public function testSystemPromptIncluyeDatosClienteConocido(): void
    {
        $prompt = aiBuildSystemPrompt($this->baseConfig(), null, [], [], null, null, null, [], '3314972545', null, 'Cliente YA REGISTRADO con nosotros: prueba.');

        $this->assertStringContainsString('Cliente YA REGISTRADO con nosotros: prueba.', $prompt);
    }
}
