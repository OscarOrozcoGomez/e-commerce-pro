import { test, expect } from './fixtures';
import { completeDomicilioCheckout } from './helpers';

test.describe('Cancelar pedido', () => {
  test('un pedido recién creado se puede cancelar desde su detalle', async ({ page }) => {
    const idPedido = await completeDomicilioCheckout(page);

    await page.goto(`views/detalle_compra.php?id=${idPedido}`);
    await page.getByRole('button', { name: 'CANCELAR PEDIDO' }).click();

    await page.locator('#swal-motivo-cancelacion').selectOption({ label: 'Cambie de opinion / ya no lo quiero' });
    await page.getByRole('button', { name: 'Sí, cancelar pedido' }).click();

    await expect(page.getByText('Pedido cancelado')).toBeVisible();
    await page.getByRole('button', { name: /^OK$|^Aceptar$/ }).click().catch(() => {});

    await page.waitForLoadState('networkidle');
    await expect(page.getByRole('button', { name: 'CANCELAR PEDIDO' })).toHaveCount(0);
  });
});
