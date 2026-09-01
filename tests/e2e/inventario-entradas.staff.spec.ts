import { test, expect } from './fixtures';
import { loginAsStaff, E2E_PRODUCT_NAME } from './helpers';

test.describe('Encargado: entrada individual de inventario', () => {
  test('registrar una entrada individual actualiza el stock', async ({ page }) => {
    const pageErrors: string[] = [];
    page.on('pageerror', (err) => pageErrors.push(err.message));

    await loginAsStaff(page, 'encargado');
    await page.goto('views/inventario_entradas.php');
    expect(pageErrors).toEqual([]);

    await page.locator('#buscador-inbound').fill(E2E_PRODUCT_NAME);
    await page.locator('#buscador-inbound').press('Tab');
    await expect(page.locator('#id_producto_inbound')).not.toHaveValue('');

    await page.locator('#cantidad_inbound').fill('5');

    let apiResult: { success?: boolean } | null = null;
    await page.route('**/api/inventory_handler.php', async (route) => {
      const response = await route.fetch();
      apiResult = await response.json();
      await route.fulfill({ response });
    });

    await page.getByRole('button', { name: 'REGISTRAR ENTRADA' }).click();
    await expect.poll(() => apiResult).not.toBeNull();
    expect(apiResult!.success).toBe(true);
  });
});
