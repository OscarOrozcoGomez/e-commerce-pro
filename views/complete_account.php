<?php
declare(strict_types=1);

require_once __DIR__ . '/../core/config.php';
require_once __DIR__ . '/../core/auth.php';
require_once __DIR__ . '/../core/phone_utils.php';

if (isAuthenticated()) {
    header('Location: ' . BASE_URL . 'index.php');
    exit;
}

$error = '';

$completionData = $_SESSION['account_completion'] ?? [];
if (!is_array($completionData)) {
    $completionData = [];
}

$phoneDigits = normalizePhoneDigitsMx((string)($completionData['telefono'] ?? $_GET['telefono'] ?? ''));
$prefillName = trim((string)($completionData['nombre'] ?? ''));
$prefillEmail = trim((string)($completionData['email'] ?? ''));

if ($phoneDigits === null || $phoneDigits === '') {
    $error = 'No encontramos un teléfono pendiente de completar. Vuelve al registro e inténtalo otra vez.';
}

$clientePendiente = null;
if ($error === '') {
    try {
        $pdo = getPDO();
        $clientePendiente = findClienteByPhone($pdo, $phoneDigits ?? '');
        if (!$clientePendiente) {
            $error = 'No encontramos un cliente asociado a ese teléfono.';
        } elseif (!empty($clientePendiente['id_usuario'])) {
            $_SESSION['session_notice'] = 'Ese teléfono ya tiene una cuenta activa. Inicia sesión para continuar.';
            unset($_SESSION['account_completion']);
            if (session_status() === PHP_SESSION_ACTIVE) {
                session_write_close();
            }
            header('Location: ' . BASE_URL . 'views/login.php');
            exit;
        } else {
            $nombreActual = trim((string)($clientePendiente['nombre'] ?? ''));
            if ($prefillName === '' && $nombreActual !== '') {
                $prefillName = function_exists('piiIsEncryptedValue') && function_exists('piiDecryptValue') && piiIsEncryptedValue($nombreActual)
                    ? (string)piiDecryptValue($nombreActual)
                    : $nombreActual;
            }
            if ($phoneDigits !== null && $phoneDigits !== '') {
                $prefillPhone = formatPhoneMxDigits($phoneDigits);
            } else {
                $prefillPhone = '';
            }
        }
    } catch (Throwable $e) {
        $error = 'No se pudo cargar el flujo de completado de cuenta.';
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $error === '') {
    if (!validateCsrfToken($_POST['csrf_token'] ?? '')) {
        $error = 'Token CSRF inválido.';
    } else {
        $nombre = trim((string)($_POST['nombre'] ?? ''));
        $email = strtolower(trim((string)($_POST['email'] ?? '')));
        $password = (string)($_POST['password'] ?? '');
        $confirmPassword = (string)($_POST['confirm_password'] ?? '');

        if ($nombre === '' || $email === '' || $password === '') {
            $error = 'Nombre, correo y contraseña son obligatorios.';
        } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $error = 'El formato del correo electrónico no es válido.';
        } elseif (!isPasswordSecure($password)) {
            $error = 'La contraseña debe tener al menos 10 caracteres e incluir mayúsculas, minúsculas, números y un símbolo.';
        } elseif ($password !== $confirmPassword) {
            $error = 'Las contraseñas no coinciden.';
        } else {
            try {
                $pdo = getPDO();
                $stmt = $pdo->prepare('SELECT id_usuario FROM usuarios WHERE email = ? LIMIT 1');
                $stmt->execute([$email]);
                if ($stmt->fetch(PDO::FETCH_ASSOC)) {
                    $error = 'Ese correo electrónico ya está registrado.';
                } else {
                    $clienteId = (int)($clientePendiente['id_cliente'] ?? 0);
                    if ($clienteId <= 0) {
                        throw new RuntimeException('No se pudo resolver el cliente pendiente.');
                    }

                    $pdo->beginTransaction();

                    $hash = password_hash($password, PASSWORD_BCRYPT);
                    $insertUser = $pdo->prepare("INSERT INTO usuarios (nombre, email, contrasena, id_rol, id_almacen, estado) VALUES (?, ?, ?, 4, NULL, 'activo')");
                    $insertUser->execute([$nombre, $email, $hash]);
                    $newUserId = (int)$pdo->lastInsertId();

                    $nombreStore = function_exists('piiEncryptValue') ? piiEncryptValue($nombre) : $nombre;
                    $telefonoStore = function_exists('piiEncryptValue') ? piiEncryptValue($phoneDigits) : $phoneDigits;

                    $updateCliente = $pdo->prepare('UPDATE clientes SET nombre = ?, email = ?, telefono = ?, id_usuario = ? WHERE id_cliente = ?');
                    $updateCliente->execute([$nombreStore, $email, $telefonoStore, $newUserId, $clienteId]);

                    $pdo->commit();

                    unset($_SESSION['account_completion']);
                    $_SESSION['session_notice'] = 'Cuenta completada con éxito. Ya puedes iniciar sesión.';
                    if (session_status() === PHP_SESSION_ACTIVE) {
                        session_write_close();
                    }
                    header('Location: ' . BASE_URL . 'views/login.php');
                    exit;
                }
            } catch (Throwable $e) {
                if (isset($pdo) && $pdo instanceof PDO && $pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                $error = 'No se pudo completar la cuenta.';
            }
        }
    }
}

$pageTitle = 'Completar Cuenta';
include __DIR__ . '/includes/header.php';
?>

<div class="container">
    <div class="row" style="margin-top: 50px;">
        <div class="col s12 m6 offset-m3">
            <div class="card">
                <div class="card-content">
                    <span class="card-title center-align">Completar tu Cuenta</span>
                    <p class="center-align grey-text">Detectamos tu teléfono en la plataforma. Completa estos datos para activar tu acceso.</p>

                    <?php if ($error): ?>
                        <div class="card-panel red lighten-4 red-text text-darken-4">
                            <?php echo esc($error); ?>
                        </div>
                    <?php endif; ?>

                    <?php if ($error === ''): ?>
                    <form method="post" id="complete-account-form">
                        <?php echo csrfInput(); ?>

                        <div class="input-field">
                            <i class="material-icons prefix">person</i>
                            <input id="nombre" name="nombre" type="text" required value="<?php echo esc($prefillName); ?>">
                            <label for="nombre" class="active">Nombre Completo</label>
                        </div>

                        <div class="input-field">
                            <i class="material-icons prefix">phone</i>
                            <input id="telefono" name="telefono" type="text" value="<?php echo esc(formatPhoneMxDigits((string)$phoneDigits)); ?>" disabled>
                            <label for="telefono" class="active">Teléfono detectado</label>
                        </div>

                        <div class="input-field">
                            <i class="material-icons prefix">email</i>
                            <input id="email" name="email" type="email" required value="<?php echo esc($prefillEmail); ?>">
                            <label for="email" class="active">Correo Electrónico</label>
                        </div>

                        <div class="input-field">
                            <i class="material-icons prefix">lock</i>
                            <input id="password" name="password" type="password" required minlength="10">
                            <label for="password">Contraseña</label>
                            <i class="material-icons" style="position: absolute; right: 10px; top: 15px; cursor: pointer; color: #9e9e9e;" onclick="togglePass('password', this)">visibility</i>
                        </div>

                        <div class="input-field">
                            <i class="material-icons prefix">lock_outline</i>
                            <input id="confirm_password" name="confirm_password" type="password" required minlength="10">
                            <label for="confirm_password">Confirmar Contraseña</label>
                            <i class="material-icons" style="position: absolute; right: 10px; top: 15px; cursor: pointer; color: #9e9e9e;" onclick="togglePass('confirm_password', this)">visibility</i>
                        </div>

                        <ul id="password-rules-complete" class="password-criteria-list" aria-live="polite">
                            <li id="complete-rule-length" class="red-text text-darken-2"><i class="material-icons criteria-icon" aria-hidden="true">cancel</i><span>Al menos 10 caracteres</span></li>
                            <li id="complete-rule-upper" class="red-text text-darken-2"><i class="material-icons criteria-icon" aria-hidden="true">cancel</i><span>Al menos una mayúscula</span></li>
                            <li id="complete-rule-lower" class="red-text text-darken-2"><i class="material-icons criteria-icon" aria-hidden="true">cancel</i><span>Al menos una minúscula</span></li>
                            <li id="complete-rule-number" class="red-text text-darken-2"><i class="material-icons criteria-icon" aria-hidden="true">cancel</i><span>Al menos un número</span></li>
                            <li id="complete-rule-symbol" class="red-text text-darken-2"><i class="material-icons criteria-icon" aria-hidden="true">cancel</i><span>Al menos un símbolo (!@#$...)</span></li>
                            <li id="complete-rule-match" class="red-text text-darken-2"><i class="material-icons criteria-icon" aria-hidden="true">cancel</i><span>Las contraseñas coinciden</span></li>
                        </ul>

                        <div style="margin-top: 30px;">
                            <button type="submit" id="complete-submit-btn" class="btn-large blue darken-4 waves-effect waves-light w-100" disabled>
                                COMPLETAR CUENTA
                            </button>
                        </div>
                    </form>
                    <?php endif; ?>
                </div>

                <div class="card-action center-align">
                    <p>¿Ya recordaste tus datos? <a href="<?php echo BASE_URL; ?>views/login.php" class="blue-text text-darken-4">Inicia Sesión</a></p>
                </div>
            </div>
        </div>
    </div>
</div>

<div id="page-transition-overlay" class="page-transition-overlay" aria-live="polite" aria-busy="true">
    <div class="page-transition-card">
        <div class="preloader-wrapper active" style="width:52px; height:52px;">
            <div class="spinner-layer spinner-blue-only">
                <div class="circle-clipper left"><div class="circle"></div></div>
                <div class="gap-patch"><div class="circle"></div></div>
                <div class="circle-clipper right"><div class="circle"></div></div>
            </div>
        </div>
        <p id="page-transition-text" class="page-transition-text">Estamos completando tu cuenta...</p>
    </div>
</div>

<style>
    .w-100 { width: 100%; }
    .password-criteria-list { margin-top: -6px; margin-bottom: 20px; padding-left: 0; }
    .password-criteria-list li { list-style: none; display: flex; align-items: center; gap: 8px; margin-bottom: 4px; }
    .password-criteria-list .criteria-icon { font-size: 16px; line-height: 1; }
    .page-transition-overlay {
        position: fixed;
        inset: 0;
        background: rgba(15, 23, 42, 0.65);
        z-index: 9999;
        display: none;
        align-items: center;
        justify-content: center;
        padding: 20px;
    }
    .page-transition-card {
        background: #ffffff;
        border-radius: 12px;
        padding: 24px 22px;
        width: min(420px, 92vw);
        text-align: center;
        box-shadow: 0 18px 45px rgba(15, 23, 42, 0.25);
    }
    .page-transition-text {
        margin: 14px 0 0 0;
        color: #1f2937;
        font-weight: 500;
    }
</style>

<script>
    function togglePass(inputId, iconElement) {
        const input = document.getElementById(inputId);
        if (input.type === 'password') {
            input.type = 'text';
            iconElement.innerText = 'visibility_off';
        } else {
            input.type = 'password';
            iconElement.innerText = 'visibility';
        }
    }

    function bindPasswordRealtimeValidation(passwordId, confirmId, prefix, submitButtonId) {
        const passwordInput = document.getElementById(passwordId);
        const confirmInput = document.getElementById(confirmId);
        const submitButton = document.getElementById(submitButtonId);
        if (!passwordInput || !confirmInput) {
            return;
        }

        const hasSymbol = (value) => /[!@#$%^&*(),.?":{}|<>]/.test(value);
        const rules = [
            { id: `${prefix}-rule-length`, test: (value) => value.length >= 10 },
            { id: `${prefix}-rule-upper`, test: (value) => /[A-Z]/.test(value) },
            { id: `${prefix}-rule-lower`, test: (value) => /[a-z]/.test(value) },
            { id: `${prefix}-rule-number`, test: (value) => /[0-9]/.test(value) },
            { id: `${prefix}-rule-symbol`, test: (value) => hasSymbol(value) }
        ];

        function paintRule(ruleId, ok) {
            const el = document.getElementById(ruleId);
            if (!el) {
                return;
            }
            const icon = el.querySelector('.criteria-icon');
            el.classList.remove('red-text', 'green-text', 'text-darken-2');
            el.classList.add(ok ? 'green-text' : 'red-text', 'text-darken-2');
            if (icon) {
                icon.textContent = ok ? 'check_circle' : 'cancel';
            }
        }

        function updateState() {
            const pass = passwordInput.value || '';
            const confirm = confirmInput.value || '';

            let allRulesOk = true;
            rules.forEach((rule) => {
                const ok = rule.test(pass);
                paintRule(rule.id, ok);
                if (!ok) {
                    allRulesOk = false;
                }
            });

            const matchOk = confirm.length > 0 && pass === confirm;
            paintRule(`${prefix}-rule-match`, matchOk);

            if (submitButton) {
                const canSubmit = allRulesOk && matchOk;
                submitButton.disabled = !canSubmit;
                submitButton.classList.toggle('disabled', !canSubmit);
            }
        }

        passwordInput.addEventListener('input', updateState);
        confirmInput.addEventListener('input', updateState);
        updateState();
    }

    function showTransitionOverlay(message) {
        const overlay = document.getElementById('page-transition-overlay');
        const text = document.getElementById('page-transition-text');
        if (text && message) {
            text.textContent = message;
        }
        if (overlay) {
            overlay.style.display = 'flex';
        }
    }

    const completeAccountForm = document.getElementById('complete-account-form');
    if (completeAccountForm) {
        completeAccountForm.addEventListener('submit', function () {
            const submitBtn = document.getElementById('complete-submit-btn');
            if (submitBtn) {
                submitBtn.disabled = true;
                submitBtn.textContent = 'PROCESANDO...';
            }
            showTransitionOverlay('Estamos validando tus datos y activando tu cuenta...');
        });
    }

    bindPasswordRealtimeValidation('password', 'confirm_password', 'complete', 'complete-submit-btn');
</script>

<?php include __DIR__ . '/includes/footer.php'; ?>