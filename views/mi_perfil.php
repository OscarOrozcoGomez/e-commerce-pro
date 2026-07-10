<?php
declare(strict_types=1);

require_once __DIR__ . '/../core/config.php';
require_once __DIR__ . '/../core/auth.php';

requireAuth();
if (!isCliente()) {
    header('Location: dashboard.php');
    exit;
}

$pdo = getPDO();
$error = '';
$success = '';

function isLikelyDeliverableEmailProfile(string $email): bool
{
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        return false;
    }

    $parts = explode('@', $email);
    $domain = strtolower(trim((string)($parts[1] ?? '')));
    if ($domain === '' || strpos($domain, '.') === false) {
        return false;
    }

    $blockedDomains = [
        'mailinator.com',
        'tempmail.com',
        '10minutemail.com',
        'guerrillamail.com',
        'yopmail.com',
        'fakeinbox.com'
    ];
    if (in_array($domain, $blockedDomains, true)) {
        return false;
    }

    if (!function_exists('checkdnsrr')) {
        return true;
    }

    return checkdnsrr($domain, 'MX') || checkdnsrr($domain, 'A') || checkdnsrr($domain, 'AAAA');
}

function normalizeMxPhone(string $phone): ?string
{
    $digits = preg_replace('/\D+/', '', $phone);
    if (!is_string($digits)) {
        return null;
    }
    if ($digits === '') {
        return '';
    }
    if (strlen($digits) !== 10) {
        return null;
    }

    return sprintf('(%s) - %s - %s', substr($digits, 0, 3), substr($digits, 3, 3), substr($digits, 6, 4));
}

function clientesHasAliasPerfil(PDO $pdo): bool
{
    try {
        $stmt = $pdo->query("SHOW COLUMNS FROM clientes LIKE 'alias_perfil'");
        return (bool)$stmt->fetch(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {
        return false;
    }
}

$hasAliasPerfil = clientesHasAliasPerfil($pdo);
$userId = (int)($_SESSION['usuario']['id_usuario'] ?? 0);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!validateCsrfToken($_POST['csrf_token'] ?? '')) {
        $error = 'Token CSRF inválido.';
    } else {
        $nombre = trim((string)($_POST['nombre'] ?? ''));
        $email = strtolower(trim((string)($_POST['email'] ?? '')));
        $telefonoRaw = trim((string)($_POST['telefono'] ?? ''));
        $aliasPerfil = trim((string)($_POST['alias_perfil'] ?? ''));
        $telefono = normalizeMxPhone($telefonoRaw);

        if ($nombre === '' || $email === '') {
            $error = 'Nombre y correo son obligatorios.';
        } elseif (!isLikelyDeliverableEmailProfile($email)) {
            $error = 'No pudimos validar el dominio del correo. Usa un correo real y verificable.';
        } elseif ($telefono === null) {
            $error = 'Si capturas teléfono, debe tener 10 dígitos con formato (331) - 863 - 5185.';
        } elseif ($hasAliasPerfil && mb_strlen($aliasPerfil) > 80) {
            $error = 'El alias no puede exceder 80 caracteres.';
        } else {
            try {
                $stmtEmail = $pdo->prepare('SELECT id_usuario FROM usuarios WHERE email = :email AND id_usuario <> :id LIMIT 1');
                $stmtEmail->execute([':email' => $email, ':id' => $userId]);
                if ($stmtEmail->fetch(PDO::FETCH_ASSOC)) {
                    throw new Exception('Ese correo ya está registrado por otro usuario.');
                }

                $pdo->beginTransaction();

                $stmtUser = $pdo->prepare('UPDATE usuarios SET nombre = :nombre, email = :email WHERE id_usuario = :id');
                $stmtUser->execute([
                    ':nombre' => $nombre,
                    ':email' => $email,
                    ':id' => $userId,
                ]);

                $stmtCliente = $pdo->prepare('SELECT id_cliente FROM clientes WHERE id_usuario = :id LIMIT 1');
                $stmtCliente->execute([':id' => $userId]);
                $clienteRow = $stmtCliente->fetch(PDO::FETCH_ASSOC);

                $nombreCliente = function_exists('piiEncryptValue') ? piiEncryptValue($nombre) : $nombre;

                if ($clienteRow) {
                    if ($hasAliasPerfil) {
                        $stmtUpd = $pdo->prepare('UPDATE clientes SET nombre = :nombre, email = :email, telefono = :telefono, alias_perfil = :alias WHERE id_usuario = :id');
                        $stmtUpd->execute([
                            ':nombre' => $nombreCliente,
                            ':email' => $email,
                            ':telefono' => $telefono === '' ? null : (function_exists('piiEncryptValue') ? piiEncryptValue($telefono) : $telefono),
                            ':alias' => $aliasPerfil === '' ? null : (function_exists('piiEncryptValue') ? piiEncryptValue($aliasPerfil) : $aliasPerfil),
                            ':id' => $userId,
                        ]);
                    } else {
                        $stmtUpd = $pdo->prepare('UPDATE clientes SET nombre = :nombre, email = :email, telefono = :telefono WHERE id_usuario = :id');
                        $stmtUpd->execute([
                            ':nombre' => $nombreCliente,
                            ':email' => $email,
                            ':telefono' => $telefono === '' ? null : (function_exists('piiEncryptValue') ? piiEncryptValue($telefono) : $telefono),
                            ':id' => $userId,
                        ]);
                    }
                } else {
                    if ($hasAliasPerfil) {
                        $stmtIns = $pdo->prepare('INSERT INTO clientes (nombre, email, telefono, id_usuario, alias_perfil) VALUES (:nombre, :email, :telefono, :id, :alias)');
                        $stmtIns->execute([
                            ':nombre' => $nombreCliente,
                            ':email' => $email,
                            ':telefono' => $telefono === '' ? null : (function_exists('piiEncryptValue') ? piiEncryptValue($telefono) : $telefono),
                            ':id' => $userId,
                            ':alias' => $aliasPerfil === '' ? null : (function_exists('piiEncryptValue') ? piiEncryptValue($aliasPerfil) : $aliasPerfil),
                        ]);
                    } else {
                        $stmtIns = $pdo->prepare('INSERT INTO clientes (nombre, email, telefono, id_usuario) VALUES (:nombre, :email, :telefono, :id)');
                        $stmtIns->execute([
                            ':nombre' => $nombreCliente,
                            ':email' => $email,
                            ':telefono' => $telefono === '' ? null : (function_exists('piiEncryptValue') ? piiEncryptValue($telefono) : $telefono),
                            ':id' => $userId,
                        ]);
                    }
                }

                $pdo->commit();

                $_SESSION['usuario']['nombre'] = $nombre;
                $_SESSION['usuario']['email'] = $email;
                $_SESSION['usuario']['telefono_cliente'] = $telefono === '' ? null : $telefono;

                $success = 'Perfil actualizado correctamente.';
            } catch (Throwable $e) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                $error = $e instanceof Exception ? $e->getMessage() : 'No se pudo actualizar el perfil.';
            }
        }
    }
}

$selectAlias = $hasAliasPerfil ? 'c.alias_perfil' : 'NULL AS alias_perfil';
$stmt = $pdo->prepare("SELECT u.nombre, u.email, c.telefono, {$selectAlias} FROM usuarios u LEFT JOIN clientes c ON c.id_usuario = u.id_usuario WHERE u.id_usuario = :id LIMIT 1");
$stmt->execute([':id' => $userId]);
$perfil = $stmt->fetch(PDO::FETCH_ASSOC) ?: [
    'nombre' => $_SESSION['usuario']['nombre'] ?? '',
    'email' => $_SESSION['usuario']['email'] ?? '',
    'telefono' => '',
    'alias_perfil' => '',
];

if (isset($perfil['telefono']) && is_string($perfil['telefono']) && function_exists('piiIsEncryptedValue') && function_exists('piiDecryptValue') && piiIsEncryptedValue($perfil['telefono'])) {
    $perfil['telefono'] = (string)piiDecryptValue($perfil['telefono']);
}
if (isset($perfil['alias_perfil']) && is_string($perfil['alias_perfil']) && function_exists('piiIsEncryptedValue') && function_exists('piiDecryptValue') && piiIsEncryptedValue($perfil['alias_perfil'])) {
    $perfil['alias_perfil'] = (string)piiDecryptValue($perfil['alias_perfil']);
}

$displayName = trim((string)($perfil['alias_perfil'] ?? '')) !== ''
    ? trim((string)$perfil['alias_perfil'])
    : trim((string)($perfil['nombre'] ?? ''));

$avatarInitials = 'U';
if ($displayName !== '') {
    $nameParts = preg_split('/\s+/u', $displayName, -1, PREG_SPLIT_NO_EMPTY);
    if (is_array($nameParts) && count($nameParts) > 0) {
        $firstInitial = mb_substr($nameParts[0], 0, 1, 'UTF-8');
        $lastInitial = count($nameParts) > 1 ? mb_substr($nameParts[count($nameParts) - 1], 0, 1, 'UTF-8') : '';
        $avatarInitials = mb_strtoupper($firstInitial . $lastInitial, 'UTF-8');
    }
}

$pageTitle = 'Mi Perfil';
include __DIR__ . '/includes/header.php';
?>

<div class="container" style="margin-top: 30px; margin-bottom: 30px;">
    <div class="row">
        <div class="col s12 m8 offset-m2 l6 offset-l3">
            <div class="card">
                <div class="card-content">
                    <div class="profile-avatar-preview-wrap">
                        <span class="profile-avatar-preview">
                            <i class="material-icons avatar-person">person</i>
                            <span id="profile-avatar-initials"><?php echo esc($avatarInitials); ?></span>
                        </span>
                        <p id="profile-avatar-display-name" class="grey-text text-darken-1" style="margin: 10px 0 0 0; font-weight: 600;">
                            <?php echo esc($displayName !== '' ? $displayName : 'Cliente'); ?>
                        </p>
                    </div>
                    <span class="card-title"><i class="material-icons left">person</i>Mi Perfil</span>
                    <p class="grey-text" style="margin-bottom: 20px;">Actualiza tus datos personales cuando lo necesites.</p>

                    <?php if ($error): ?>
                        <div class="card-panel red lighten-4 red-text text-darken-3"><?php echo esc($error); ?></div>
                    <?php endif; ?>

                    <?php if ($success): ?>
                        <div class="card-panel green lighten-4 green-text text-darken-3"><?php echo esc($success); ?></div>
                    <?php endif; ?>

                    <?php if (!$hasAliasPerfil): ?>
                        <div class="card-panel orange lighten-5 orange-text text-darken-4" style="font-size: 0.9rem;">
                            El campo alias estará disponible cuando se aplique la migración más reciente de base de datos.
                        </div>
                    <?php endif; ?>

                    <form method="post" novalidate>
                        <?php echo csrfInput(); ?>

                        <div class="input-field">
                            <i class="material-icons prefix">badge</i>
                            <input id="nombre" name="nombre" type="text" required value="<?php echo esc((string)($perfil['nombre'] ?? '')); ?>">
                            <label for="nombre" class="active">Nombre completo</label>
                        </div>

                        <div class="input-field">
                            <i class="material-icons prefix">alternate_email</i>
                            <input id="alias_perfil" name="alias_perfil" type="text" maxlength="80" value="<?php echo esc((string)($perfil['alias_perfil'] ?? '')); ?>" <?php echo $hasAliasPerfil ? '' : 'disabled'; ?>>
                            <label for="alias_perfil" class="active">Alias (opcional)</label>
                        </div>

                        <div class="input-field">
                            <i class="material-icons prefix">email</i>
                            <input id="email" name="email" type="email" required value="<?php echo esc((string)($perfil['email'] ?? '')); ?>">
                            <label for="email" class="active">Correo electrónico</label>
                        </div>

                        <div class="input-field">
                            <i class="material-icons prefix">phone</i>
                            <input id="telefono" name="telefono" type="tel" value="<?php echo esc((string)($perfil['telefono'] ?? '')); ?>" placeholder="Ej: (331) - 863 - 5185" maxlength="19" inputmode="numeric" autocomplete="tel-national">
                            <label for="telefono" class="active">Teléfono (opcional)</label>
                            <span class="helper-text">Si capturas teléfono, debe tener 10 dígitos.</span>
                        </div>

                        <div class="right-align">
                            <button type="submit" class="btn blue darken-3 waves-effect waves-light">
                                Guardar Cambios
                                <i class="material-icons right">save</i>
                            </button>
                        </div>
                    </form>
                </div>
                <div class="card-action">
                    <a href="<?php echo BASE_URL; ?>views/mis_direcciones.php" class="blue-text text-darken-3">
                        <i class="material-icons tiny" style="vertical-align: middle;">place</i>
                        Administrar direcciones
                    </a>
                </div>
            </div>
        </div>
    </div>
</div>

<style>
    .profile-avatar-preview-wrap {
        display: flex;
        flex-direction: column;
        align-items: center;
        margin-bottom: 14px;
    }
    .profile-avatar-preview {
        position: relative;
        width: 86px;
        height: 86px;
        border-radius: 50%;
        display: inline-flex;
        align-items: center;
        justify-content: center;
        background: radial-gradient(circle at 25% 20%, #42a5f5 0%, #1565c0 65%, #0d47a1 100%);
        border: 3px solid rgba(255,255,255,0.85);
        box-shadow: 0 8px 18px rgba(0,0,0,0.2);
        overflow: hidden;
    }
    .profile-avatar-preview .avatar-person {
        position: absolute;
        bottom: -4px;
        left: 4px;
        font-size: 52px;
        color: rgba(255, 255, 255, 0.38);
        pointer-events: none;
    }
    #profile-avatar-initials {
        position: relative;
        z-index: 1;
        font-size: 1.45rem;
        font-weight: 800;
        letter-spacing: 1px;
        color: #ffffff;
        text-shadow: 0 2px 3px rgba(0,0,0,0.35);
    }
</style>

<script>
function formatPhone(digits) {
    if (!digits) return '';
    if (digits.length <= 3) return `(${digits}`;
    if (digits.length <= 6) return `(${digits.slice(0, 3)}) - ${digits.slice(3)}`;
    return `(${digits.slice(0, 3)}) - ${digits.slice(3, 6)} - ${digits.slice(6, 10)}`;
}

(function bindPhoneMask() {
    const input = document.getElementById('telefono');
    if (!input) return;

    const validate = () => {
        const digits = (input.value || '').replace(/\D/g, '').slice(0, 10);
        if (digits.length === 0) {
            input.setCustomValidity('');
            return;
        }
        if (digits.length < 10) {
            input.setCustomValidity('Completa los 10 dígitos o borra el campo.');
            return;
        }
        input.setCustomValidity('');
    };

    input.addEventListener('input', () => {
        const digits = (input.value || '').replace(/\D/g, '').slice(0, 10);
        input.value = formatPhone(digits);
        validate();
    });

    input.addEventListener('blur', validate);
})();

function buildInitials(value) {
    const raw = (value || '').trim();
    if (!raw) {
        return 'U';
    }
    const parts = raw.split(/\s+/).filter(Boolean);
    const first = (parts[0] || '').charAt(0);
    const last = parts.length > 1 ? parts[parts.length - 1].charAt(0) : '';
    return (first + last).toUpperCase() || 'U';
}

(function bindAvatarPreview() {
    const aliasInput = document.getElementById('alias_perfil');
    const nameInput = document.getElementById('nombre');
    const initialsEl = document.getElementById('profile-avatar-initials');
    const nameEl = document.getElementById('profile-avatar-display-name');

    if (!nameInput || !initialsEl || !nameEl) {
        return;
    }

    const updateAvatar = () => {
        const alias = aliasInput && !aliasInput.disabled ? aliasInput.value.trim() : '';
        const legalName = nameInput.value.trim();
        const displayName = alias || legalName || 'Cliente';
        initialsEl.textContent = buildInitials(displayName);
        nameEl.textContent = displayName;
    };

    nameInput.addEventListener('input', updateAvatar);
    if (aliasInput && !aliasInput.disabled) {
        aliasInput.addEventListener('input', updateAvatar);
    }
    updateAvatar();
})();
</script>

<?php include __DIR__ . '/includes/footer.php'; ?>
