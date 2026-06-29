<?php
declare(strict_types=1);

require_once __DIR__ . '/../core/config.php';
require_once __DIR__ . '/../core/auth.php';

if (isAuthenticated()) {
    header('Location: ' . BASE_URL . 'index.php');
    exit;
}

$error = '';
$success = '';

/**
 * Valida si el correo tiene formato correcto y un dominio con registros DNS utiles.
 */
function isLikelyDeliverableEmail(string $email): bool
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

/**
 * Formatea y valida telefono opcional a formato (XXX) - XXX - XXXX.
 */
function normalizeUsStylePhone(string $phone): ?string
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

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!validateCsrfToken($_POST['csrf_token'] ?? '')) {
        $error = 'Token CSRF inválido.';
    } else {
        $nombre = trim($_POST['nombre'] ?? '');
        $email = trim($_POST['email'] ?? '');
        $telefono = trim($_POST['telefono'] ?? '');
        $telefonoNormalizado = normalizeUsStylePhone($telefono);
        $password = $_POST['password'] ?? '';
        $confirm_password = $_POST['confirm_password'] ?? '';

        if (empty($nombre) || empty($email) || empty($password)) {
            $error = 'Todos los campos son obligatorios.';
        } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $error = 'El formato del correo electrónico no es válido.';
        } elseif (!isLikelyDeliverableEmail($email)) {
            $error = 'No pudimos validar el dominio del correo. Usa un correo real y verificable.';
        } elseif ($telefonoNormalizado === null) {
            $error = 'Si capturas teléfono, debe tener 10 dígitos con formato (331) - 863 - 5185.';
        } elseif ($password !== $confirm_password) {
            $error = 'Las contraseñas no coinciden.';
        } elseif (!isPasswordSecure($password)) {
            $error = 'Seguridad insuficiente: la contraseña debe tener al menos 10 caracteres, incluir mayúsculas, minúsculas, números y un símbolo.';
        } else {
            try {
                $pdo = getPDO();
                // Verificar si el email ya existe
                $stmt = $pdo->prepare("SELECT id_usuario FROM usuarios WHERE email = ?");
                $stmt->execute([$email]);
                if ($stmt->fetch()) {
                    $error = 'El correo electrónico ya está registrado.';
                } else {
                    $hash = password_hash($password, PASSWORD_BCRYPT);
                    // id_rol = 4 es 'cliente' según vimos anteriormente
                    $sql = "INSERT INTO usuarios (nombre, email, contrasena, id_rol, id_almacen, estado) VALUES (?, ?, ?, 4, NULL, 'activo')";
                    $stmt = $pdo->prepare($sql);
                    $stmt->execute([$nombre, $email, $hash]);
                    
                    $newUserId = $pdo->lastInsertId();
                    
                    // Crear entrada en tabla clientes para este usuario
                    $stmtCli = $pdo->prepare("INSERT INTO clientes (nombre, email, id_usuario) VALUES (?, ?, ?)");
                    $stmtCli = $pdo->prepare("INSERT INTO clientes (nombre, email, telefono, id_usuario) VALUES (?, ?, ?, ?)");
                    $stmtCli->execute([$nombre, $email, ($telefonoNormalizado === '' ? null : $telefonoNormalizado), $newUserId]);
                    
                    $success = 'Cuenta creada con éxito. Ya puedes iniciar sesión.';
                }
            } catch (PDOException $e) {
                $error = 'Error al registrar usuario: ' . $e->getMessage();
            }
        }
    }
}

$pageTitle = 'Registro de Cliente';
include __DIR__ . '/includes/header.php';
?>

<div class="container">
    <div class="row" style="margin-top: 50px;">
        <div class="col s12 m6 offset-m3">
            <div class="card">
                <div class="card-content">
                    <span class="card-title center-align">Crear Cuenta Nueva</span>
                    <p class="center-align grey-text">Únete a Belleza y Bienestar para gestionar tus compras.</p>
                    
                    <?php if ($error): ?>
                        <div class="card-panel red lighten-4 red-text text-darken-4">
                            <?php echo esc($error); ?>
                        </div>
                    <?php endif; ?>

                    <?php if ($success): ?>
                        <div class="card-panel green lighten-4 green-text text-darken-4">
                            <?php echo esc($success); ?>
                            <div style="margin-top: 10px;">
                                <a href="<?php echo BASE_URL; ?>views/login.php" class="btn green waves-effect waves-light">Ir a Iniciar Sesión</a>
                            </div>
                        </div>
                    <?php else: ?>
                        <form method="POST">
                            <?php echo csrfInput(); ?>
                            <div class="input-field">
                                <i class="material-icons prefix">person</i>
                                <input id="nombre" name="nombre" type="text" required value="<?php echo esc($nombre ?? ''); ?>">
                                <label for="nombre">Nombre Completo</label>
                            </div>
                            <div class="input-field">
                                <i class="material-icons prefix">email</i>
                                <input id="email" name="email" type="email" required value="<?php echo esc($email ?? ''); ?>">
                                <label for="email">Correo Electrónico</label>
                                <span id="email-feedback" class="helper-inline-feedback"></span>
                            </div>
                            <div class="input-field">
                                <i class="material-icons prefix">phone</i>
                                <input id="telefono" name="telefono" type="tel" value="<?php echo esc($telefono ?? ''); ?>" placeholder="Ej: (331) - 863 - 5185" maxlength="19" inputmode="numeric" autocomplete="tel-national">
                                <label for="telefono">Teléfono de contacto (opcional)</label>
                                <span id="telefono-feedback" class="helper-inline-feedback"></span>
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
                            <ul id="password-rules-register" class="password-criteria-list" aria-live="polite">
                                <li id="register-rule-length" class="red-text text-darken-2"><i class="material-icons criteria-icon" aria-hidden="true">cancel</i><span>Al menos 10 caracteres</span></li>
                                <li id="register-rule-upper" class="red-text text-darken-2"><i class="material-icons criteria-icon" aria-hidden="true">cancel</i><span>Al menos una mayúscula</span></li>
                                <li id="register-rule-lower" class="red-text text-darken-2"><i class="material-icons criteria-icon" aria-hidden="true">cancel</i><span>Al menos una minúscula</span></li>
                                <li id="register-rule-number" class="red-text text-darken-2"><i class="material-icons criteria-icon" aria-hidden="true">cancel</i><span>Al menos un número</span></li>
                                <li id="register-rule-symbol" class="red-text text-darken-2"><i class="material-icons criteria-icon" aria-hidden="true">cancel</i><span>Al menos un símbolo (!@#$...)</span></li>
                                <li id="register-rule-match" class="red-text text-darken-2"><i class="material-icons criteria-icon" aria-hidden="true">cancel</i><span>Las contraseñas coinciden</span></li>
                            </ul>
                            
                            <div style="margin-top: 30px;">
                                <button type="submit" id="register-submit-btn" class="btn-large blue darken-4 waves-effect waves-light w-100" disabled>
                                    REGISTRARME
                                </button>
                            </div>
                        </form>
                    <?php endif; ?>
                </div>
                <div class="card-action center-align">
                    <p>¿Ya tienes cuenta? <a href="<?php echo BASE_URL; ?>views/login.php" class="blue-text text-darken-4">Inicia Sesión</a></p>
                </div>
            </div>
        </div>
    </div>
</div>

<style>
    .w-100 { width: 100%; }
    .helper-inline-feedback { display: block; min-height: 18px; font-size: 12px; margin-top: 4px; }
    .helper-inline-feedback.error { color: #c62828; }
    .helper-inline-feedback.success { color: #2e7d32; }
    .password-criteria-list { margin-top: -6px; margin-bottom: 20px; padding-left: 0; }
    .password-criteria-list li { list-style: none; display: flex; align-items: center; gap: 8px; margin-bottom: 4px; }
    .password-criteria-list .criteria-icon { font-size: 16px; line-height: 1; }
</style>

<script>
    const blockedEmailDomains = new Set([
        'mailinator.com',
        'tempmail.com',
        '10minutemail.com',
        'guerrillamail.com',
        'yopmail.com',
        'fakeinbox.com'
    ]);

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

    function formatPhone(digits) {
        if (!digits) {
            return '';
        }

        if (digits.length <= 3) {
            return `(${digits}`;
        }
        if (digits.length <= 6) {
            return `(${digits.slice(0, 3)}) - ${digits.slice(3)}`;
        }
        return `(${digits.slice(0, 3)}) - ${digits.slice(3, 6)} - ${digits.slice(6, 10)}`;
    }

    function bindPhoneMaskValidation(inputId) {
        const phoneInput = document.getElementById(inputId);
        const feedback = document.getElementById('telefono-feedback');
        if (!phoneInput) {
            return;
        }

        function paintField(status, message) {
            phoneInput.classList.remove('valid', 'invalid');
            if (status === 'error') {
                phoneInput.classList.add('invalid');
            } else if (status === 'success') {
                phoneInput.classList.add('valid');
            }

            if (feedback) {
                feedback.classList.remove('error', 'success');
                feedback.textContent = message || '';
                if (status === 'error') {
                    feedback.classList.add('error');
                } else if (status === 'success') {
                    feedback.classList.add('success');
                }
            }
        }

        const validatePhone = () => {
            const digits = (phoneInput.value || '').replace(/\D/g, '').slice(0, 10);
            if (digits.length === 0) {
                phoneInput.setCustomValidity('');
                paintField('', '');
                return;
            }
            if (digits.length < 10) {
                phoneInput.setCustomValidity('Completa los 10 dígitos o deja el campo vacío.');
                paintField('error', 'Completa los 10 dígitos o borra el campo si no lo usarás por ahora.');
                return;
            }
            phoneInput.setCustomValidity('');
            paintField('success', 'Teléfono válido (10 dígitos).');
        };

        phoneInput.addEventListener('input', () => {
            const digits = (phoneInput.value || '').replace(/\D/g, '').slice(0, 10);
            phoneInput.value = formatPhone(digits);
            validatePhone();
        });

        phoneInput.addEventListener('blur', () => {
            const digits = (phoneInput.value || '').replace(/\D/g, '').slice(0, 10);
            phoneInput.value = digits.length === 10 ? formatPhone(digits) : (digits.length === 0 ? '' : formatPhone(digits));
            validatePhone();
        });
    }

    function bindEmailValidation(inputId) {
        const emailInput = document.getElementById(inputId);
        const feedback = document.getElementById('email-feedback');
        if (!emailInput) {
            return;
        }

        const emailRegex = /^[^\s@]+@[^\s@]+\.[^\s@]{2,}$/;

        function paintField(status, message) {
            emailInput.classList.remove('valid', 'invalid');
            if (status === 'error') {
                emailInput.classList.add('invalid');
            } else if (status === 'success') {
                emailInput.classList.add('valid');
            }

            if (feedback) {
                feedback.classList.remove('error', 'success');
                feedback.textContent = message || '';
                if (status === 'error') {
                    feedback.classList.add('error');
                } else if (status === 'success') {
                    feedback.classList.add('success');
                }
            }
        }

        function validateEmail() {
            const value = (emailInput.value || '').trim().toLowerCase();
            if (value === '') {
                emailInput.setCustomValidity('');
                paintField('', '');
                return;
            }
            if (!emailRegex.test(value)) {
                emailInput.setCustomValidity('Ingresa un correo electrónico válido.');
                paintField('error', 'Correo inválido. Ejemplo correcto: nombre@dominio.com');
                return;
            }

            const domain = (value.split('@')[1] || '').trim();
            if (domain === '' || blockedEmailDomains.has(domain)) {
                emailInput.setCustomValidity('Usa un correo real y verificable.');
                paintField('error', 'Usa un correo real y verificable.');
                return;
            }

            emailInput.setCustomValidity('');
            paintField('success', 'Formato de correo válido.');
        }

        emailInput.addEventListener('input', validateEmail);
        emailInput.addEventListener('blur', validateEmail);
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

    bindPhoneMaskValidation('telefono');
    bindEmailValidation('email');
    bindPasswordRealtimeValidation('password', 'confirm_password', 'register', 'register-submit-btn');
</script>

<?php include __DIR__ . '/includes/footer.php'; ?>
