import { test, expect } from './fixtures';

test.describe('Registro de cuenta', () => {
  test('crear cuenta con datos válidos redirige a login con mensaje de éxito', async ({ page }) => {
    await page.goto('views/register.php');

    await page.locator('#nombre').fill('Playwright Registro QA');
    await page.locator('#email').fill(`playwright-register+${Date.now()}@example.com`);
    await page.locator('#password').fill('E2eTest!2026');
    await page.locator('#confirm_password').fill('E2eTest!2026');

    await page.getByRole('button', { name: 'REGISTRARME' }).click();

    await expect(page.getByText('Cuenta creada con éxito.')).toBeVisible();
  });

  test('contraseña débil mantiene deshabilitado el botón de registro', async ({ page }) => {
    await page.goto('views/register.php');

    await page.locator('#nombre').fill('Playwright Registro QA');
    await page.locator('#email').fill(`playwright-register+${Date.now()}@example.com`);
    await page.locator('#password').fill('abc');
    await page.locator('#confirm_password').fill('abc');

    await expect(page.locator('#register-submit-btn')).toBeDisabled();
  });
});
