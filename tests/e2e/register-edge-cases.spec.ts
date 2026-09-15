import { test, expect } from './fixtures';

test.describe('Registro: edge cases', () => {
  test('no permite registrar dos cuentas con el mismo correo', async ({ page }) => {
    const email = `playwright-dup+${Date.now()}@example.com`;
    const password = 'E2eTest!2026';

    async function fillAndSubmitRegister() {
      await page.goto('views/register.php');
      await page.locator('#nombre').fill('Playwright QA');
      await page.locator('#email').fill(email);
      await page.locator('#password').fill(password);
      await page.locator('#confirm_password').fill(password);
      await page.getByRole('button', { name: 'REGISTRARME' }).click();
    }

    await fillAndSubmitRegister();
    await expect(page.getByText('Cuenta creada con éxito.')).toBeVisible();

    await fillAndSubmitRegister();
    await expect(page.getByText('El correo electrónico ya está registrado.')).toBeVisible();
  });

  test('rechaza dominios de correo desechables conocidos', async ({ page }) => {
    await page.goto('views/register.php');
    await page.locator('#nombre').fill('Playwright QA');
    await page.locator('#email').fill('playwright-test@mailinator.com');
    await page.locator('#password').fill('E2eTest!2026');
    await page.locator('#confirm_password').fill('E2eTest!2026');
    await page.getByRole('button', { name: 'REGISTRARME' }).click();

    // Ya sea que lo bloquee el navegador (validez nativa del input) o el servidor,
    // el registro con un dominio desechable conocido nunca debe completarse.
    await expect(page).not.toHaveURL(/login\.php/);
    await expect(page.getByText('Cuenta creada con éxito.')).toHaveCount(0);
  });
});
