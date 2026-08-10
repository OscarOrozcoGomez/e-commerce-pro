<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "Este script solo puede ejecutarse desde CLI.\n");
    exit(1);
}

$iniPath = 'C:\xampp\mysql\bin\my.ini';
if (!is_readable($iniPath)) {
    fwrite(STDERR, "No se encontró my.ini en {$iniPath}.\n");
    exit(1);
}

$contents = file_get_contents($iniPath);
if ($contents === false) {
    fwrite(STDERR, "No se pudo leer my.ini.\n");
    exit(1);
}

$backupPath = $iniPath . '.bak.' . date('Ymd_His');
if (file_put_contents($backupPath, $contents) === false) {
    fwrite(STDERR, "No se pudo crear respaldo en {$backupPath}.\n");
    exit(1);
}

$replacements = [
    '/^\s*max_allowed_packet\s*=.*$/mi' => 'max_allowed_packet=64M',
    '/^\s*wait_timeout\s*=.*$/mi' => 'wait_timeout=28800',
    '/^\s*interactive_timeout\s*=.*$/mi' => 'interactive_timeout=28800',
];

foreach ($replacements as $pattern => $replacement) {
    if (preg_match($pattern, $contents)) {
        $contents = preg_replace($pattern, $replacement, $contents, 1) ?? $contents;
    } else {
        $contents .= PHP_EOL . $replacement . PHP_EOL;
    }
}

if (file_put_contents($iniPath, $contents) === false) {
    fwrite(STDERR, "No se pudo actualizar my.ini.\n");
    exit(1);
}

fwrite(STDOUT, "my.ini actualizado. Respaldo: {$backupPath}\n");
fwrite(STDOUT, "Reinicia MySQL de XAMPP para que tome max_allowed_packet=64M.\n");