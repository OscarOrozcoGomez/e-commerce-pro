import { test, expect } from './fixtures';
import { registerAndLogin, E2E_PRODUCT_NAME } from './helpers';

test.describe('Página de Favoritos', () => {
  test('muestra un producto marcado como favorito y permite quitarlo', async ({ page }) => {
    await registerAndLogin(page);

    await page.goto(`views/catalogo.php?search=${encodeURIComponent(E2E_PRODUCT_NAME)}`);
    const card = page.locator('.product-card-container').filter({ hasText: E2E_PRODUCT_NAME }).first();
    await card.waitFor({ state: 'visible' });
    await card.locator('a.card-link').click();
    await page.waitForURL(/product_detail\.php\?id=\d+/);

    await page.locator('#btn-favorite').click();
    await expect(page.locator('#btn-favorite i')).toHaveText('favorite');

    await page.goto('favoritos.php');
    await expect(page.getByText(E2E_PRODUCT_NAME)).toBeVisible();

    await page.getByRole('button', { name: 'Quitar' }).click();
    await expect(page.getByText('Producto eliminado de favoritos', { exact: false })).toBeVisible();
    await expect(page.locator('#no-favorites')).toBeVisible();
  });
});
