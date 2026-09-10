import { test, expect } from './fixtures';
import { E2E_PRODUCT_NAME, registerAndLogin } from './helpers';

test.describe('Favoritos', () => {
  test('marcar y desmarcar un producto como favorito desde su detalle', async ({ page }) => {
    await registerAndLogin(page);
    await page.goto(`views/catalogo.php?search=${encodeURIComponent(E2E_PRODUCT_NAME)}`);
    const card = page.locator('.product-card-container').filter({ hasText: E2E_PRODUCT_NAME }).first();
    await card.waitFor({ state: 'visible' });
    await card.locator('a.card-link').click();
    await page.waitForURL(/product_detail\.php\?id=\d+/);

    const favoriteIcon = page.locator('#btn-favorite i');
    await expect(favoriteIcon).toHaveText('favorite_border');

    await page.locator('#btn-favorite').click();
    await expect(favoriteIcon).toHaveText('favorite');

    await page.locator('#btn-favorite').click();
    await expect(favoriteIcon).toHaveText('favorite_border');
  });
});
