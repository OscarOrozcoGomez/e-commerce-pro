import { test, expect } from './fixtures';
import { registerAndLogin, getLatestPasswordResetCode } from './helpers';

test.describe('Recuperar contraseña', () => {
  test('recuperar con el código de mail_log.txt permite iniciar sesión con la nueva contraseña', async ({
    page,
  }) => {
    const cliente = await registerAndLogin(page);
    await page.goto('logout.php');

    await page.goto('views/forgot_password.php');
    await page.locator('#email').fill(cliente.email);
    await page.getByRole('button', { name: 'Obtener código' }).click();

    await expect(page.getByText('Se ha generado un código de seguridad', { exact: false })).toBeVisible();

    const code = getLatestPasswordResetCode(cliente.email);
    const newPassword = 'E2eReset!2026';

    await page.locator('#code').fill(code);
    await page.locator('#new_password').fill(newPassword);
    await page.locator('#confirm_new_password').fill(newPassword);
    await page.locator('#forgot-submit-btn').click();

    await expect(page.getByText('Tu contraseña ha sido actualizada con éxito.')).toBeVisible();

    await page.goto('views/login.php');
    await page.locator('#email').fill(cliente.email);
    await page.locator('#password').fill(newPassword);
    await page.getByRole('button', { name: 'Iniciar Sesión' }).click();

    await page.waitForURL((url) => !url.toString().includes('views/login.php'));
  });

  test('un correo que no existe muestra el mismo mensaje genérico (no revela si la cuenta existe)', async ({ page }) => {
    await page.goto('views/forgot_password.php');
    await page.locator('#email').fill(`playwright-no-existe-${Date.now()}@example.com`);
    await page.getByRole('button', { name: 'Obtener código' }).click();

    // generatePasswordResetToken() se llama incondicionalmente (views/forgot_password.php) --
    // mismo mensaje que con un correo real, para no filtrar por enumeración qué correos
    // están registrados.
    await expect(page.getByText('Se ha generado un código de seguridad', { exact: false })).toBeVisible();
    await expect(page.locator('#code')).toBeVisible();
  });

  test('un código incorrecto muestra el error y deja reintentar sin perder el paso 2', async ({ page }) => {
    const cliente = await registerAndLogin(page);
    await page.goto('logout.php');

    await page.goto('views/forgot_password.php');
    await page.locator('#email').fill(cliente.email);
    await page.getByRole('button', { name: 'Obtener código' }).click();
    await expect(page.getByText('Se ha generado un código de seguridad', { exact: false })).toBeVisible();

    await page.locator('#code').fill('000000');
    await page.locator('#new_password').fill('OtraClaveValida!9');
    await page.locator('#confirm_new_password').fill('OtraClaveValida!9');
    await page.locator('#forgot-submit-btn').click();

    await expect(page.getByText('El código es inválido o ha expirado.')).toBeVisible();
    // Sigue en el paso 2 (no volvió al paso 1 ni avanzó al de éxito): puede reintentar con
    // el código correcto sin tener que pedir uno nuevo.
    await expect(page.locator('#code')).toBeVisible();
  });

  test('el botón de restablecer permanece deshabilitado hasta que la contraseña cumple todas las reglas y coincide', async ({ page }) => {
    const cliente = await registerAndLogin(page);
    await page.goto('logout.php');

    await page.goto('views/forgot_password.php');
    await page.locator('#email').fill(cliente.email);
    await page.getByRole('button', { name: 'Obtener código' }).click();
    await expect(page.getByText('Se ha generado un código de seguridad', { exact: false })).toBeVisible();

    const submitBtn = page.locator('#forgot-submit-btn');
    await page.locator('#code').fill(getLatestPasswordResetCode(cliente.email));

    // Corta (no llega a 10 caracteres): sigue deshabilitado.
    await page.locator('#new_password').fill('Abc1!');
    await page.locator('#confirm_new_password').fill('Abc1!');
    await expect(submitBtn).toBeDisabled();

    // Larga y con todas las reglas, pero la confirmación no coincide: sigue deshabilitado.
    await page.locator('#new_password').fill('ClaveValida!9');
    await page.locator('#confirm_new_password').fill('OtraClaveDistinta!9');
    await expect(submitBtn).toBeDisabled();

    // Cumple todo y coincide: se habilita.
    await page.locator('#confirm_new_password').fill('ClaveValida!9');
    await expect(submitBtn).toBeEnabled();
  });
});
