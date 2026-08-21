import { test, expect } from './fixtures';
import { loginAsStaff } from './helpers';

test.describe('Admin: alta de producto', () => {
  test('agregar un producto nuevo lo muestra en la tabla', async ({ page }) => {
    await loginAsStaff(page, 'admin');
    await page.goto('views/products.php');

    const nombreProducto = `Playwright Admin Product ${Date.now()}`;
    await page.locator('#nombre').fill(nombreProducto);
    await page.locator('#precio_costo').fill('10.00');
    await page.locator('#precio_venta').fill('19.99');

    await page.locator('#btn-submit').click();

    // No fijamos el texto exacto del toast (es el message del backend); lo que
    // realmente prueba que se guardó es que la fila aparezca en la tabla.
    await expect(page.locator('.toast')).toBeVisible();
    await expect(page.locator('#tabla-productos-body').getByText(nombreProducto)).toBeVisible();
  });
});
