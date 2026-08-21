import { test, expect } from './fixtures';
import { loginAsStaff } from './helpers';

test.describe('Admin: alta de cliente (walk-in)', () => {
  test('crear un cliente nuevo desde el modal lo muestra en la tabla', async ({ page }) => {
    await loginAsStaff(page, 'admin');
    await page.goto('views/manage_customers.php');

    await page.getByRole('link', { name: 'Nuevo cliente' }).click();

    const nombre = `Playwright Cliente Admin ${Date.now()}`;
    const modal = page.locator('#modal-crear-cliente');
    await modal.locator('input[name="nombre"]').fill(nombre);
    await modal.locator('input[name="telefono"]').fill('3312345678');

    await modal.getByRole('button', { name: /Crear|Guardar/ }).click();

    // manage_customers.php descifra el teléfono de cada cliente en la tabla para
    // renderizarla (500+ filas en esta BD local); bajo carga concurrente pesada
    // puede tardar más que el timeout default.
    await expect(page.locator('.manage-customers-name', { hasText: nombre })).toBeVisible({ timeout: 20000 });
  });
});
