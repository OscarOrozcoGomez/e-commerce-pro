<?php
declare(strict_types=1);

// Uso: php scripts/e2e_preparar_whatsapp.php
//
// Siembra conversaciones de WhatsApp "Playwright WA ..." para las pruebas de tests/e2e/whatsapp-seguimientos.staff.spec.ts
// (Seguimientos del mes y "Dar feedback" a Alex). Son SOLO filas en la BD: no manda nada por WhatsApp, no invoca a Alex ni
// al modelo. Es idempotente: borra las de la corrida anterior (y las reglas de aprendizaje "Playwright ...") y las
// recrea con fechas relativas a AHORA. Imprime un JSON con el mes (YYYY-MM) donde caen los seguimientos y los id.
//
// Un "seguimiento de 24h" es un mensaje de Alex (assistant) enviado >= 23 h despues de otro mensaje suyo ya enviado, ver
// waSeguimientosDelMes() en core/whatsapp_contactos_utils.php.

require_once __DIR__ . '/../core/config.php';

if (!in_array(PHP_SAPI, ['cli', 'phpdbg'], true)) {
    http_response_code(403);
    echo "Este script solo se puede ejecutar por CLI o phpdbg.\n";
    exit(1);
}

// Escribe/borra datos de prueba: NUNCA contra produccion (APP_ENV=production es el valor por defecto en el VPS).
if (IS_PRODUCTION) {
    fwrite(STDERR, "Rechazado: este script de pruebas no corre con APP_ENV=production." . PHP_EOL);
    exit(1);
}

$pdo = getPDO();
$pdo->beginTransaction();
try {
    // --- limpieza de la corrida anterior ---
    $ids = $pdo->query("SELECT id_conversacion FROM whatsapp_conversaciones WHERE nombre_perfil LIKE 'Playwright WA %'")->fetchAll(PDO::FETCH_COLUMN);
    if ($ids) {
        $in = implode(',', array_map('intval', $ids));
        $pdo->exec("DELETE FROM whatsapp_conversacion_etiquetas WHERE id_conversacion IN ($in)");
        $pdo->exec("DELETE FROM whatsapp_mensajes WHERE id_conversacion IN ($in)");
        $pdo->exec("DELETE FROM whatsapp_conversaciones WHERE id_conversacion IN ($in)");
    }
    $pdo->exec("DELETE FROM ai_reglas_aprendizaje WHERE contexto_o_pregunta LIKE 'Playwright%'");

    // Etiqueta que la lista debe mostrar junto al contacto.
    $pdo->exec("INSERT INTO whatsapp_etiquetas (id_etiqueta_wa, nombre, color) SELECT 'e2e-pregunton', 'Playwright Pregunton', 'blue' FROM DUAL
                WHERE NOT EXISTS (SELECT 1 FROM whatsapp_etiquetas WHERE id_etiqueta_wa = 'e2e-pregunton')");
    $idEtiqueta = (int) $pdo->query("SELECT id_etiqueta FROM whatsapp_etiquetas WHERE id_etiqueta_wa = 'e2e-pregunton'")->fetchColumn();

    $conv = static function (string $nombre, string $waId, ?string $telefono) use ($pdo): int {
        $pdo->prepare(
            "INSERT INTO whatsapp_conversaciones (wa_id, telefono_resuelto, nombre_perfil, estado_bot, ultimo_mensaje_en) VALUES (?, ?, ?, 'activo', NOW())"
        )->execute([$waId, $telefono, $nombre]);
        return (int) $pdo->lastInsertId();
    };
    // $horas = "hace N horas" (puede ser fraccion).
    $msg = static function (int $idConv, string $rol, string $texto, float $horas, bool $salio = true) use ($pdo): void {
        $pdo->prepare(
            "INSERT INTO whatsapp_mensajes (id_conversacion, rol, contenido, enviado_whatsapp, creado_en)
             VALUES (?, ?, ?, ?, NOW() - INTERVAL ? SECOND)"
        )->execute([$idConv, $rol, $texto, $salio ? 1 : 0, (int) round($horas * 3600)]);
    };

    // A: seguimiento SIN respuesta (con etiqueta).
    $a = $conv('Playwright WA Sin Respuesta', '5215510000001', '5510000001');
    $msg($a, 'user', 'Hola, quiero informes', 50);
    $msg($a, 'assistant', 'Claro, con gusto te ayudo.', 49);
    $msg($a, 'assistant', 'Hola de nuevo, ¿pudiste revisar la informacion?', 24.0);
    $pdo->prepare('INSERT INTO whatsapp_conversacion_etiquetas (id_conversacion, id_etiqueta) VALUES (?, ?)')->execute([$a, $idEtiqueta]);

    // B: seguimiento que SI respondio (3 h despues).
    $b = $conv('Playwright WA Respondio', '5215510000002', '5510000002');
    $msg($b, 'user', 'Buenas tardes', 50);
    $msg($b, 'assistant', 'Hola, ¿en que te ayudo?', 49);
    $msg($b, 'assistant', 'Te escribo de nuevo por si te quedo alguna duda.', 24.0);
    $msg($b, 'user', 'Si, gracias, ya voy a comprar', 21);

    // C: seguimiento que NO salio por WhatsApp (enviado_whatsapp = 0).
    $c = $conv('Playwright WA No Salio', '5215510000003', '5510000003');
    $msg($c, 'user', 'Precio del producto', 50);
    $msg($c, 'assistant', 'Cuesta $199.', 49);
    $msg($c, 'assistant', 'Seguimos a tus ordenes.', 24.0, false);

    // D: conversacion normal (Alex contesta 1 min despues del cliente): NO es seguimiento.
    $d = $conv('Playwright WA Normal', '5215510000004', '5510000004');
    $msg($d, 'user', 'Hola', 3);
    $msg($d, 'assistant', 'Hola, ¿que necesitas?', 3 - 1 / 60);

    // F: dos mensajes de Alex con menos de 23 h de diferencia: NO es seguimiento.
    $f = $conv('Playwright WA Dos Mensajes', '5215510000006', '5510000006');
    $msg($f, 'user', 'Quiero comprar', 6);
    $msg($f, 'assistant', 'Perfecto, te paso los datos.', 5);
    $msg($f, 'assistant', 'Aqui tienes el resumen.', 2);

    // G: contacto LID (sin numero real): aparece como "Sin numero" y sin enlace para abrir el chat.
    $g = $conv('Playwright WA Sin Numero', '198765432109876', null);
    $msg($g, 'user', 'Hola', 50);
    $msg($g, 'assistant', 'Hola, ¿en que te ayudo?', 49);
    $msg($g, 'assistant', 'Cualquier duda me dices.', 24.0);

    $mes = (string) $pdo->query("SELECT DATE_FORMAT(NOW() - INTERVAL 24 HOUR, '%Y-%m')")->fetchColumn();
    $pdo->commit();
    echo json_encode(
        ['mes' => $mes, 'sin_respuesta' => $a, 'respondio' => $b, 'no_salio' => $c, 'normal' => $d, 'dos_mensajes' => $f, 'sin_numero' => $g],
        JSON_UNESCAPED_UNICODE
    ), "\n";
} catch (Throwable $e) {
    $pdo->rollBack();
    fwrite(STDERR, 'Error: ' . $e->getMessage() . "\n");
    exit(1);
}
