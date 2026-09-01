import { test, expect } from './fixtures';
import { E2E_PRODUCT_NAME } from './helpers';

test.describe('Detalle de producto', () => {
  test('muestra nombre, precio y permite agregar al carrito', async ({ page }) => {
    await page.goto(`views/catalogo.php?search=${encodeURIComponent(E2E_PRODUCT_NAME)}`);

    const card = page.locator('.product-card-container').filter({ hasText: E2E_PRODUCT_NAME }).first();
    await card.waitFor({ state: 'visible' });
    await card.locator('a.card-link').click();

    await page.waitForURL(/product_detail\.php\?id=\d+/);
    await expect(page.locator('#product-title')).toHaveText(E2E_PRODUCT_NAME);
    await expect(page.locator('#product-price')).toContainText('99.99');

    await page.locator('#btn-add-cart').click();
    await expect(page.locator('.toast', { hasText: 'agregado!' })).toBeVisible();
  });
});
