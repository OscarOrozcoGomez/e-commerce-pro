<?php
declare(strict_types=1);

// Uso: php scripts/e2e_permiso_override.php <email> <clave> <conceder|denegar|quitar>
//
// Solo para tests/e2e/permisos-en-vivo.staff.spec.ts: fija (o quita) un override individual de permiso
// (usuario_permisos) a una cuenta E2E, para comprobar que un cambio de permisos surte efecto sin re-login.
// Por seguridad SOLO acepta cuentas e2e-*@playwright.test y claves que existan en el catalogo.

require_once __DIR__ . '/../core/config.php';

if (!in_array(PHP_SAPI, ['cli', 'phpdbg'], true)) {
    http_response_code(403);
    echo "Este script solo se puede ejecutar por CLI o phpdbg.\n";
    exit(1);
}

[$_, $email, $clave, $efecto] = array_pad($argv, 4, '');

if (!preg_match('/^e2e-[a-z0-9-]+@playwright\.test$/', $email) || !in_array($efecto, ['conceder', 'denegar', 'quitar'], true)) {
    fwrite(STDERR, "Uso: php scripts/e2e_permiso_override.php <e2e-...@playwright.test> <clave> <conceder|denegar|quitar>\n");
    exit(2);
}

$pdo = getPDO();

$stmtIds = $pdo->prepare(
    'SELECT (SELECT id_usuario FROM usuarios WHERE email = :email) AS id_usuario,
            (SELECT id_permiso FROM permisos WHERE clave = :clave) AS id_permiso'
);
$stmtIds->execute(['email' => $email, 'clave' => $clave]);
$ids = $stmtIds->fetch(PDO::FETCH_ASSOC) ?: [];
if (empty($ids['id_usuario']) || empty($ids['id_permiso'])) {
    fwrite(STDERR, "No existe la cuenta '{$email}' o la clave '{$clave}'.\n");
    exit(3);
}

$pdo->prepare('DELETE FROM usuario_permisos WHERE id_usuario = :u AND id_permiso = :p')
    ->execute(['u' => $ids['id_usuario'], 'p' => $ids['id_permiso']]);

if ($efecto !== 'quitar') {
    $pdo->prepare(
        'INSERT INTO usuario_permisos (id_usuario, id_permiso, efecto, nota) VALUES (:u, :p, :efecto, "E2E Playwright: permisos en vivo")'
    )->execute(['u' => $ids['id_usuario'], 'p' => $ids['id_permiso'], 'efecto' => $efecto]);
}

echo "OK: {$email} {$efecto} {$clave}\n";
