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
});
