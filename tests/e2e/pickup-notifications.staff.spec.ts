import { test, expect } from './fixtures';
import { completeSucursalCheckout, getNumeroPedido, loginAsStaff } from './helpers';

test.describe('Encargado: notificaciones pickup', () => {
  test('un pedido de Sucursal avanza nueva -> vista -> apartada -> atendida', async ({ page }) => {
    const idPedido = await completeSucursalCheckout(page);
    const numeroPedido = await getNumeroPedido(page, idPedido);

    await loginAsStaff(page, 'encargadoPickup');
    await page.goto('views/pickup_notifications.php');

    const row = () => page.locator('tr').filter({ hasText: numeroPedido });

    await expect(row().locator('.badge')).toHaveText('NUEVA');

    await row().getByRole('button', { name: 'Marcar vista' }).click();
    await expect(page.getByText('Seguimiento de pickup actualizado.')).toBeVisible();
    await expect(row().locator('.badge')).toHaveText('VISTA');

    await row().getByRole('button', { name: 'Marcar apartada' }).click();
    await expect(page.getByText('Seguimiento de pickup actualizado.')).toBeVisible();
    await expect(row().locator('.badge')).toHaveText('APARTADA');

    await row().getByRole('button', { name: 'Marcar atendida y pagada' }).click();
    await expect(page.getByText('Seguimiento de pickup actualizado.')).toBeVisible();
    await expect(row().locator('.badge')).toHaveText('ATENDIDA');
    await expect(row().getByText('Flujo completado.')).toBeVisible();
  });
});
