import { test, expect } from './fixtures';
import { telefonoUnico } from './helpers';

test.describe('Registro de cuenta', () => {
  test('crear cuenta con datos válidos redirige a login con mensaje de éxito', { tag: '@smoke' }, async ({ page }) => {
    await page.goto('views/register.php');

    await page.locator('#nombre').fill('Playwright Registro QA');
    await page.locator('#email').fill(`playwright-register+${Date.now()}@example.com`);
    await page.locator('#telefono').fill(telefonoUnico());
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

  // PR #202: ningun cliente se da de alta sin telefono (Alex y las entregas dependen de poder contactarlo).
  test('el teléfono es obligatorio: sin teléfono la cuenta no se crea', async ({ page }) => {
    await page.goto('views/register.php');
    await expect(page.locator('#telefono')).toHaveAttribute('required', '');
    await expect(page.locator('label[for="telefono"]')).not.toContainText('opcional');

    await page.locator('#nombre').fill('Playwright Registro Sin Telefono');
    await page.locator('#email').fill(`playwright-register+1789875096627@example.com`);
    await page.locator('#password').fill('E2eTest!2026');
    await page.locator('#confirm_password').fill('E2eTest!2026');
    await page.getByRole('button', { name: 'REGISTRARME' }).click();

    await expect(page.getByText('Cuenta creada con éxito.')).toHaveCount(0);
    await expect(page).toHaveURL(/register.php/);
  });

  test('un teléfono con formato inválido (menos de 10 dígitos) no crea la cuenta', async ({ page }) => {
    await page.goto('views/register.php');
    await page.locator('#nombre').fill('Playwright Registro Telefono Corto');
    await page.locator('#email').fill(`playwright-register+1789875096627@example.com`);
    await page.locator('#telefono').fill('331234');
    await page.locator('#password').fill('E2eTest!2026');
    await page.locator('#confirm_password').fill('E2eTest!2026');
    await page.getByRole('button', { name: 'REGISTRARME' }).click();

    await expect(page.getByText('Cuenta creada con éxito.')).toHaveCount(0);
  });
});
