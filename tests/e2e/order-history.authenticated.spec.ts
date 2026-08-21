import { test, expect } from './fixtures';
import { completeDomicilioCheckout } from './helpers';

test.describe('Historial de pedidos', () => {
  test('un pedido recién creado aparece en Mis Compras y su detalle', async ({ page }) => {
    const idPedido = await completeDomicilioCheckout(page);

    await page.goto('views/mis_compras.php');
    const detailLink = page.locator(`a[href*="detalle_compra.php?id=${idPedido}"]`);
    await expect(detailLink).toBeVisible();

    await detailLink.click();
    await page.waitForURL(new RegExp(`detalle_compra\\.php\\?id=${idPedido}`));
    await expect(page.locator('h4', { hasText: `Pedido:` })).toBeVisible();
  });
});
