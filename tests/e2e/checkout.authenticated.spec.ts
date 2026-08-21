import { test, expect } from './fixtures';
import { completeDomicilioCheckout } from './helpers';

test.describe('Checkout autenticado', () => {
  test('completar un pedido real por Domicilio confirma la compra', async ({ page }) => {
    const idPedido = await completeDomicilioCheckout(page);

    expect(idPedido).toBeGreaterThan(0);
    await expect(page.locator('.badge.ok')).toHaveText('Pedido confirmado');
  });
});
