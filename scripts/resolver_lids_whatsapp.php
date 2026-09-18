<?php
declare(strict_types=1);

// Resuelve, cuando sea posible, el telefono real detras de un wa_id tipo LID (WhatsApp
// oculta el numero real -- ver core/ai_assistant.php::aiWaIdToDisplayPhoneConResuelto() y
// la migracion 20260918_000001). Usa el mapeo que Baileys (el puente) ya guarda localmente
// cada vez que decodifica un mensaje de ese contacto -- es una consulta 100% local del lado
// del puente (getPNsForLIDs() de Baileys solo lee su cache/almacen de sesion en disco), NUNCA
// manda nada a WhatsApp ni hace ninguna llamada de red hacia el protocolo -- cero riesgo de
// rafaga. Puede correrse las veces que haga falta (idempotente: solo procesa las
// conversaciones que todavia no tienen telefono_resuelto).
//
// No todas se van a poder resolver: si la conversacion nunca tuvo un mensaje decodificado
// mientras la sesion actual del puente estaba activa (ej. si hubo un re-escaneo de QR
// despues), Baileys no tiene ese mapeo guardado y simplemente no aparece en el resultado.
//
// Uso: C:\xampp\php\php.exe scripts/resolver_lids_whatsapp.php

require_once __DIR__ . '/../core/config.php';
require_once __DIR__ . '/../core/ai_assistant.php';

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, 'Este script solo se puede ejecutar por CLI.' . PHP_EOL);
    exit(1);
}

$pdo = getPDO();

$stmt = $pdo->query(
    "SELECT id_conversacion, wa_id FROM whatsapp_conversaciones
     WHERE telefono_resuelto IS NULL
     ORDER BY id_conversacion ASC"
);
$conversaciones = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

// Solo las que de verdad son LID (un wa_id que ya es un telefono real no necesita
// resolverse -- aiWaIdToDisplayPhone() ya lo formatea directo).
$candidatas = array_values(array_filter($conversaciones, static fn(array $c): bool => aiWaIdToMxDigits((string) $c['wa_id']) === null));

if ($candidatas === []) {
    fwrite(STDOUT, "No hay conversaciones pendientes de resolver (de " . count($conversaciones) . " revisadas).\n");
    exit(0);
}

fwrite(STDOUT, "Consultando el puente por " . count($candidatas) . " LID(s)...\n");

$actualizados = 0;
$stmtUpdate = $pdo->prepare('UPDATE whatsapp_conversaciones SET telefono_resuelto = ? WHERE id_conversacion = ?');

// Lotes de 100 para no mandar una peticion gigante de un jalon.
foreach (array_chunk($candidatas, 100) as $lote) {
    $lids = array_map(static fn(array $c): string => (string) $c['wa_id'], $lote);
    $mapeo = waResolverLidsATelefono($lids);

    foreach ($lote as $conv) {
        $telefono = $mapeo[(string) $conv['wa_id']] ?? null;
        if ($telefono === null) {
            continue;
        }
        $stmtUpdate->execute([$telefono, (int) $conv['id_conversacion']]);
        $actualizados++;
        fwrite(STDOUT, "  #{$conv['id_conversacion']}: {$conv['wa_id']} -> {$telefono}\n");
    }
}

fwrite(STDOUT, "\nListo: {$actualizados} de " . count($candidatas) . " LID(s) resueltos.\n");
if ($actualizados < count($candidatas)) {
    fwrite(STDOUT, "El resto se puede volver a intentar mas adelante (ej. si el cliente vuelve a escribir, Baileys aprende el mapeo en ese momento) corriendo este mismo script otra vez.\n");
}
