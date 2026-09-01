import { test, expect } from './fixtures';

test.describe('Detalle de producto: edge cases', () => {
  test('un id de producto inexistente muestra error en vez de tronar la página', async ({ page }) => {
    const pageErrors: string[] = [];
    page.on('pageerror', (err) => pageErrors.push(err.message));

    const response = await page.goto('product_detail.php?id=999999999');

    expect(response?.status()).toBe(404);
    await expect(page.getByRole('heading', { name: 'Producto no encontrado' })).toBeVisible();
    expect(pageErrors).toEqual([]);
  });

  test('sin parámetro id también muestra el mismo estado de "no encontrado", no se queda en blanco', async ({
    page,
  }) => {
    const response = await page.goto('product_detail.php');

    expect(response?.status()).toBe(404);
    await expect(page.getByRole('heading', { name: 'Producto no encontrado' })).toBeVisible();
  });

  test('un id negativo o no numérico no tumba la página (validación server-side)', async ({ page }) => {
    const pageErrors: string[] = [];
    page.on('pageerror', (err) => pageErrors.push(err.message));

    const response = await page.goto('product_detail.php?id=-1');

    expect(response?.status()).toBe(404);
    await expect(page.getByRole('heading', { name: 'Producto no encontrado' })).toBeVisible();
    expect(pageErrors).toEqual([]);
  });
});
