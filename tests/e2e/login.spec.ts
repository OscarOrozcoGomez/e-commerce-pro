import { test, expect } from './fixtures';

test.describe('Login', () => {
  test('shows the login form', async ({ page }) => {
    await page.goto('views/login.php');

    await expect(page.locator('span.card-title')).toHaveText('Iniciar Sesión');
    await expect(page.locator('#email')).toBeVisible();
    await expect(page.locator('#password')).toBeVisible();
  });

  test('rejects invalid credentials', async ({ page }) => {
    await page.goto('views/login.php');

    await page.locator('#email').fill('no-existe@example.com');
    await page.locator('#password').fill('contraseña-incorrecta');
    await page.getByRole('button', { name: 'Iniciar Sesión' }).click();

    await expect(page.getByText('Credenciales incorrectas.')).toBeVisible();
  });
});
