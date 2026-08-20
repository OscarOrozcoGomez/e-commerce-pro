<?php
declare(strict_types=1);

require_once __DIR__ . '/../core/config.php';
require_once __DIR__ . '/../core/ai_assistant.php';

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "Este script solo se puede ejecutar por CLI (cron). Uso: C:\\xampp\\php\\php.exe scripts/whatsapp_analizar_historial_cron.php [--dry-run]" . PHP_EOL);
    exit(1);
}

$options = getopt('', ['dry-run']);
$isDryRun = array_key_exists('dry-run', $options);

$pdo = getPDO();

// Analisis 100% en codigo (SQL/PHP): no llama a DeepSeek en ningun punto, asi que no
// depende del interruptor general de Alex ni tiene costo de tokens que controlar.
$progreso = aiGetAnalysisProgress($pdo);
$pendientes = aiGetMessagesPendingAnalysis($pdo, $progreso);
$catalogTerms = aiGetCatalogTermsForTopicDetection($pdo);

$mensajesAnalizados = 0;
$temasDetectados = 0;
$ultimoIdHistorial = $progreso['ultimo_id_historial_procesado'];
$ultimoIdMensaje = $progreso['ultimo_id_mensaje_procesado'];

foreach ($pendientes['historial'] as $fila) {
    $topics = aiDetectTopicsInMessage((string)$fila['mensaje'], $catalogTerms);
    foreach ($topics as $topic) {
        if (!$isDryRun) {
            aiUpsertHistorialTema($pdo, (string)$fila['wa_id'], $topic['tipo'], $topic['valor'], (string)$fila['fecha_mensaje']);
        }
        $temasDetectados++;
    }
    $mensajesAnalizados++;
    $ultimoIdHistorial = max($ultimoIdHistorial, (int)$fila['id']);
}

foreach ($pendientes['mensajes'] as $fila) {
    $topics = aiDetectTopicsInMessage((string)$fila['mensaje'], $catalogTerms);
    foreach ($topics as $topic) {
        if (!$isDryRun) {
            aiUpsertHistorialTema($pdo, (string)$fila['wa_id'], $topic['tipo'], $topic['valor'], (string)$fila['fecha_mensaje']);
        }
        $temasDetectados++;
    }
    $mensajesAnalizados++;
    $ultimoIdMensaje = max($ultimoIdMensaje, (int)$fila['id']);
}

if (!$isDryRun) {
    aiSetAnalysisProgress($pdo, $ultimoIdHistorial, $ultimoIdMensaje);
}

fwrite(
    STDOUT,
    sprintf(
        "RUN %s | dry-run=%s | mensajes_analizados=%d | temas_detectados=%d | ultimo_id_historial=%d | ultimo_id_mensaje=%d%s",
        date('Y-m-d H:i:s'),
        $isDryRun ? 'yes' : 'no',
        $mensajesAnalizados,
        $temasDetectados,
        $ultimoIdHistorial,
        $ultimoIdMensaje,
        PHP_EOL
    )
);
