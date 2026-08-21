import { test, expect } from './fixtures';
import { loginAsStaff } from './helpers';

test.describe('Admin: alta de sucursal', () => {
  test('crear una sucursal nueva la muestra en la tabla', async ({ page }) => {
    await loginAsStaff(page, 'admin');
    await page.goto('views/manage_branches.php');

    const nombre = `Playwright Sucursal ${Date.now()}`;
    await page.locator('#nombre').fill(nombre);
    await page.locator('#direccion').fill('Av. de Prueba 123, Guadalajara, Jal.');

    await page.getByRole('button', { name: 'Crear Sucursal' }).click();

    await expect(page.locator('table.striped').getByText(nombre)).toBeVisible();
  });
});
