import { test, expect } from './fixtures';

test.describe('Catálogo', () => {
  test('lista productos y cada uno enlaza a su detalle', async ({ page }) => {
    await page.goto('views/catalogo.php');

    const firstCard = page.locator('.product-card-container').first();
    await expect(firstCard).toBeVisible();

    const detailLink = firstCard.locator('a.card-link');
    await expect(detailLink).toHaveAttribute('href', /product_detail\.php\?id=\d+/);
  });

  test('agregar un producto al carrito desde el catálogo actualiza el carrito', async ({ page }) => {
    await page.goto('views/catalogo.php');

    const firstCard = page.locator('.product-card-container').first();
    await expect(firstCard).toBeVisible();

    await firstCard.locator('.card-action button').click();
    await expect(page.locator('.toast', { hasText: 'añadido al carrito' })).toBeVisible();

    await page.goto('views/cart.php');
    await expect(page.locator('#cart-table-body tr')).not.toHaveText('El carrito está vacío');
  });
});
