import { test, expect } from './fixtures';
import { registerAndLogin } from './helpers';

test.describe('Login', () => {
  test('shows the login form', { tag: '@smoke' }, async ({ page }) => {
    await page.goto('views/login.php');

    await expect(page.locator('span.card-title')).toHaveText('Iniciar Sesión');
    await expect(page.locator('#email')).toBeVisible();
    await expect(page.locator('#password')).toBeVisible();
  });

  test('rejects invalid credentials', { tag: '@smoke' }, async ({ page }) => {
    await page.goto('views/login.php');

    await page.locator('#email').fill('no-existe@example.com');
    await page.locator('#password').fill('contraseña-incorrecta');
    await page.getByRole('button', { name: 'Iniciar Sesión' }).click();

    await expect(page.getByText('Credenciales incorrectas.')).toBeVisible();
  });

  // core/auth.php::authenticate() bloquea la cuenta 15 minutos tras 5 intentos fallidos
  // (intentos_fallidos/bloqueado_hasta en usuarios) -- nunca se había probado. Se usa una
  // cuenta cliente desechable (no una de staff/E2E_STAFF_EMAILS): esas se reutilizan en
  // decenas de specs en paralelo, así que bloquearlas 15 minutos rompería el resto de la
  // suite.
  test('bloquea la cuenta 15 minutos tras 5 intentos fallidos, incluso si el 6to trae la contraseña correcta', async ({ page }) => {
    const cliente = await registerAndLogin(page);
    await page.goto('logout.php');

    for (let intento = 1; intento <= 5; intento++) {
      await page.goto('views/login.php');
      await page.locator('#email').fill(cliente.email);
      await page.locator('#password').fill('contraseña-incorrecta-' + intento);
      await page.getByRole('button', { name: 'Iniciar Sesión' }).click();
      await expect(page.getByText('Credenciales incorrectas.')).toBeVisible();
    }

    await page.goto('views/login.php');
    await page.locator('#email').fill(cliente.email);
    await page.locator('#password').fill(cliente.password);
    await page.getByRole('button', { name: 'Iniciar Sesión' }).click();

    await expect(page.getByText('Cuenta bloqueada temporalmente por seguridad', { exact: false })).toBeVisible();
    await expect(page).toHaveURL(/views\/login\.php/);
  });
});
