<?php
declare(strict_types=1);

// Backfill UNICO (no es un cron): etiqueta como "Fuera de Cobertura" conversaciones VIEJAS
// que quedaron sin marcar porque confirmar_zona_entrega() no existia todavia (ver PR #194).
// Antes de esa fecha, Alex decidia la cobertura en texto libre sin dejar ningun rastro en
// codigo cuando el cliente nunca llegaba a pedir nada -- por eso no se puede confiar en
// ninguna tabla/flag, hay que leer la conversacion misma.
//
// OJO -- la lada del telefono (aiPhoneHasLocalLada) NO SIRVE para esto en la practica: en
// produccion, 170 de 171 conversaciones tienen un wa_id tipo LID (WhatsApp oculta el numero
// real, probablemente porque la mayoria del trafico viene de anuncios de Facebook/Instagram)
// y aiWaIdToMxDigits() no puede sacarles ningun numero. Y aunque se pudiera, la lada tampoco
// diria nada confiable: gente que vive en Guadalajara conserva numeros de otros estados todo
// el tiempo -- por eso el diseño ya establecido nunca la trata como fuente de verdad.
//
// Estrategia (2 senales, con evidencia citada para que un humano confirme antes de aplicar):
//
// 1) SEÑAL PRIMARIA -- Alex mismo declinando: se buscan frases de "no hacemos envios fuera/
//    foraneos" en sus propios mensajes (rol assistant/humano). Mucho mas confiable que
//    adivinar que pregunto Alex o que ciudad menciono el cliente -- si Alex ya dijo que no,
//    es la fuente de verdad. Se descarta cualquier mensaje que TAMBIEN contenga una frase de
//    confirmacion ("si esta dentro de...", "si hacemos...") para no marcar por error una
//    conversacion donde Alex referencio el tema de cobertura pero SI confirmo que si aplica.
// 2) SEÑAL SECUNDARIA -- el cliente mencionando una ciudad conocida fuera de la ZMG (lista
//    abajo, no exhaustiva) en su propio texto, para conversaciones que la señal 1 no atrape
//    (ej. si el cliente nunca llego a que Alex se lo confirmara).
//
// Sigue siendo heuristico -- por default este script SOLO REPORTA candidatos, nunca etiqueta
// nada, hasta que se le pase --aplicar con los ids ya revisados a mano (lee la cita completa
// en el reporte, o el chat en el panel, antes de aplicar -- en particular cualquier mensaje
// que mencione una colonia periferica conocida como "Arvento"/"Hacienda Santa Fe"/etc. puede
// ser en realidad FORANEO entregable con cargo de $40, no "sin cobertura").
//
// Uso:
//   C:\xampp\php\php.exe scripts/backfill_fuera_de_cobertura.php                     (reporte)
//   C:\xampp\php\php.exe scripts/backfill_fuera_de_cobertura.php --aplicar=12,45,90  (etiqueta esos ids)

require_once __DIR__ . '/../core/config.php';
require_once __DIR__ . '/../core/ai_assistant.php';

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, 'Este script solo se puede ejecutar por CLI.' . PHP_EOL);
    exit(1);
}

// Frases (sin acentos, minusculas) que Alex usa para DECLINAR cobertura -- ver el prompt en
// aiBuildSystemPrompt() y los mensajes de aiToolConfirmarZonaEntrega()/aiToolAgendarVenta().
const FRASES_DECLINA_COBERTURA = [
    'no hacemos envios foran', 'no hacemos envios fuera', 'no hacemos envio fuera',
    'no manejamos envios foran', 'lo sentimos no hacemos envio', 'lamentablemente no hacemos envio',
    'fuera de la zona metropolitana', 'fuera del estado', 'no hacemos entregas fuera',
    'no hacemos envios a ', 'no podriamos hacerte llegar', 'no tenemos cobertura',
];
// Si el mismo mensaje TAMBIEN trae una de estas, se descarta (mezcla confirmacion, no es un
// "no" limpio -- ej. Alex explicando la politica en general pero confirmando que ESTE cliente
// SI esta en cobertura).
const FRASES_CONFIRMA_COBERTURA = [
    'si esta dentro', 'si hacemos', 'dentro de nuestra cobertura', 'si esta en cobertura', 'si tenemos cobertura',
];
// Ciudades/estados mexicanos claramente FUERA de la ZMG y su periferia -- no es exhaustiva,
// solo las mas comunes a nivel nacional mas las que ya salieron en incidentes reales
// (Autlan de Navarro y Puerto Vallarta, ver CLAUDE.md).
const CIUDADES_CONOCIDAS_FUERA_DE_COBERTURA = [
    'puerto vallarta', 'autlan', 'tepatitlan', 'ciudad guzman', 'ocotlan', 'lagos de moreno',
    'cdmx', 'ciudad de mexico', 'monterrey', 'tijuana', 'leon, gto', 'leon guanajuato',
    'culiacan', 'cancun', 'merida', 'puebla', 'queretaro', 'colima', 'manzanillo',
    'aguascalientes', 'mazatlan', 'chihuahua', 'hermosillo', 'toluca', 'morelia',
    'veracruz', 'oaxaca', 'acapulco', 'los cabos', 'la paz, bcs', 'saltillo', 'torreon',
    'reynosa', 'matamoros', 'nuevo laredo', 'zacatecas', 'san luis potosi', 'durango',
    'tepic', 'villahermosa', 'tuxtla gutierrez', 'campeche', 'chetumal', 'ensenada',
    'mexicali', 'irapuato', 'celaya', 'tampico', 'xalapa', 'pachuca', 'cuernavaca',
];

$options = getopt('', ['aplicar::']);
$idsAAplicar = [];
if (array_key_exists('aplicar', $options) && is_string($options['aplicar']) && trim($options['aplicar']) !== '') {
    $idsAAplicar = array_values(array_filter(array_map('intval', explode(',', $options['aplicar']))));
}

$pdo = getPDO();

if ($idsAAplicar !== []) {
    $aplicados = 0;
    foreach ($idsAAplicar as $idConversacion) {
        if ($idConversacion <= 0) {
            continue;
        }
        if (aiAssignTag($pdo, $idConversacion, AI_TAG_FUERA_COBERTURA)) {
            $aplicados++;
            fwrite(STDOUT, "Etiquetada conversacion #{$idConversacion} como \"" . AI_TAG_FUERA_COBERTURA . "\".\n");
        } else {
            fwrite(STDOUT, "AVISO: no se pudo etiquetar la conversacion #{$idConversacion} (revisa que exista).\n");
        }
    }
    fwrite(STDOUT, "Listo: {$aplicados} conversacion(es) etiquetada(s).\n");
    exit(0);
}

// --- Modo reporte (default): nunca escribe nada ---

$stmtConversaciones = $pdo->prepare(
    "SELECT c.id_conversacion, c.wa_id, c.nombre_perfil
     FROM whatsapp_conversaciones c
     WHERE NOT EXISTS (
         SELECT 1 FROM whatsapp_conversacion_etiquetas ce
         JOIN whatsapp_etiquetas e ON e.id_etiqueta = ce.id_etiqueta
         WHERE ce.id_conversacion = c.id_conversacion AND e.nombre = ?
     )
     ORDER BY c.id_conversacion ASC"
);
$stmtConversaciones->execute([AI_TAG_FUERA_COBERTURA]);
$conversaciones = $stmtConversaciones->fetchAll(PDO::FETCH_ASSOC) ?: [];
$conversacionesPorId = array_column($conversaciones, null, 'id_conversacion');

$candidatos = [];

// --- Senal 1: Alex declinando cobertura (assistant/humano) ---
$stmtAlex = $pdo->prepare(
    "SELECT m.id_conversacion, m.contenido FROM whatsapp_mensajes m
     WHERE m.rol IN ('assistant', 'humano') AND m.contenido IS NOT NULL AND m.contenido <> ''
       AND m.id_conversacion IN (SELECT id_conversacion FROM whatsapp_conversaciones)
     ORDER BY m.id_mensaje ASC"
);
$stmtAlex->execute();
foreach ($stmtAlex->fetchAll(PDO::FETCH_ASSOC) as $row) {
    $idConversacion = (int) $row['id_conversacion'];
    if (!isset($conversacionesPorId[$idConversacion]) || isset($candidatos[$idConversacion])) {
        continue; // ya etiquetada, o ya tiene candidato de esta misma senal
    }
    $normalizado = aiStripAccentsLower((string) $row['contenido']);

    $declina = false;
    foreach (FRASES_DECLINA_COBERTURA as $frase) {
        if (mb_strpos($normalizado, $frase) !== false) {
            $declina = true;
            break;
        }
    }
    if (!$declina) {
        continue;
    }
    foreach (FRASES_CONFIRMA_COBERTURA as $frase) {
        if (mb_strpos($normalizado, $frase) !== false) {
            $declina = false; // mensaje mixto, se descarta
            break;
        }
    }
    if (!$declina) {
        continue;
    }

    $candidatos[$idConversacion] = [
        'senal' => 'Alex declino cobertura',
        'evidencia' => trim((string) $row['contenido']),
    ];
}

// --- Senal 2: cliente menciono una ciudad conocida fuera de cobertura (solo si la senal 1
// no encontro nada para esa conversacion) ---
$stmtCliente = $pdo->prepare(
    "SELECT contenido FROM whatsapp_mensajes
     WHERE id_conversacion = ? AND rol = 'user' AND contenido IS NOT NULL AND contenido <> ''
     ORDER BY id_mensaje ASC"
);
foreach ($conversaciones as $conv) {
    $idConversacion = (int) $conv['id_conversacion'];
    if (isset($candidatos[$idConversacion])) {
        continue;
    }
    $stmtCliente->execute([$idConversacion]);
    foreach ($stmtCliente->fetchAll(PDO::FETCH_COLUMN) as $texto) {
        $normalizado = aiStripAccentsLower((string) $texto);
        foreach (CIUDADES_CONOCIDAS_FUERA_DE_COBERTURA as $ciudad) {
            if (mb_strpos($normalizado, $ciudad) !== false) {
                $candidatos[$idConversacion] = [
                    'senal' => "cliente menciono \"{$ciudad}\"",
                    'evidencia' => trim((string) $texto),
                ];
                break 2;
            }
        }
    }
}

if ($candidatos === []) {
    fwrite(STDOUT, "No se encontraron candidatos (de " . count($conversaciones) . " conversaciones sin etiquetar).\n");
    exit(0);
}

fwrite(STDOUT, "Candidatos a \"" . AI_TAG_FUERA_COBERTURA . "\" (revisa la evidencia -- ojo con colonias PERIFERICAS conocidas como Arvento/Hacienda Santa Fe/Chula Vista, esas SI son entregables con cargo de $40, no son \"fuera de cobertura\"):\n\n");
foreach ($candidatos as $idConversacion => $info) {
    $meta = $conversacionesPorId[$idConversacion] ?? [];
    fwrite(STDOUT, "#{$idConversacion} | " . ($meta['nombre_perfil'] ?? '') . " | senal: {$info['senal']}\n");
    fwrite(STDOUT, "  \"" . mb_substr($info['evidencia'], 0, 220) . "\"\n\n");
}

$listaIds = implode(',', array_keys($candidatos));
fwrite(STDOUT, "Total candidatos: " . count($candidatos) . " (de " . count($conversaciones) . " conversaciones revisadas).\n");
fwrite(STDOUT, "Para etiquetar TODOS los de arriba (revisalos primero, sobre todo los de colonia periferica):\n");
fwrite(STDOUT, "  C:\\xampp\\php\\php.exe scripts/backfill_fuera_de_cobertura.php --aplicar={$listaIds}\n");
fwrite(STDOUT, "O pasa solo los ids que confirmes, separados por coma.\n");
