import { test, expect } from './fixtures';
import { completeSucursalCheckout, getNumeroPedido, loginAsStaff } from './helpers';

test.describe('Encargado: notificaciones pickup', () => {
  test('un repartidor no puede acceder a notificaciones pickup', async ({ page }) => {
    await loginAsStaff(page, 'repartidor');
    await page.goto('views/pickup_notifications.php');
    await expect(page).toHaveURL(/views\/dashboard\.php/);
  });

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

  test('"Cancelar y resurtir stock" con motivo "Otro" cancela el pedido y devuelve el inventario', async ({ page }) => {
    const idPedido = await completeSucursalCheckout(page);
    const numeroPedido = await getNumeroPedido(page, idPedido);

    await loginAsStaff(page, 'encargadoPickup');
    await page.goto('views/pickup_notifications.php');

    const row = () => page.locator('tr').filter({ hasText: numeroPedido });
    await expect(row().locator('.badge')).toHaveText('NUEVA');

    await row().locator('select[name="motivo_cancelacion"]').selectOption('otro');
    const otroInput = row().locator('input[name="motivo_cancelacion_otro"]');
    await expect(otroInput).toBeVisible();
    await otroInput.fill('Motivo de prueba Playwright');
    await row().getByRole('button', { name: 'Cancelar y resurtir stock' }).click();

    await expect(page.getByText('Pedido pickup cancelado y stock devuelto al inventario de sucursal.')).toBeVisible();
    await expect(row().locator('.badge')).toHaveText('CANCELADA');
    await expect(row().getByText('Pedido cancelado y stock devuelto.')).toBeVisible();

    // Ya no tiene ninguna de las acciones de seguimiento normales (marcar vista/apartada/
    // atendida) ni el propio botón de cancelar de nuevo -- una notificación cancelada es un
    // estado terminal en la UI.
    await expect(row().getByRole('button', { name: 'Marcar vista' })).toHaveCount(0);
    await expect(row().getByRole('button', { name: 'Cancelar y resurtir stock' })).toHaveCount(0);

    // El filtro "estado=cancelada" la sigue mostrando ahí.
    await page.goto('views/pickup_notifications.php?estado=cancelada');
    await expect(page.locator('tr').filter({ hasText: numeroPedido }).locator('.badge')).toHaveText('CANCELADA');
  });

  test('"Cambiar a domicilio" convierte el pedido y queda disponible en Asignar Entregas', async ({ page }) => {
    const idPedido = await completeSucursalCheckout(page);
    const numeroPedido = await getNumeroPedido(page, idPedido);

    await loginAsStaff(page, 'encargadoPickup');
    await page.goto('views/pickup_notifications.php');

    const row = () => page.locator('tr').filter({ hasText: numeroPedido });
    await row().getByRole('link', { name: 'Cambiar a domicilio' }).click();

    const modal = page.locator('.modal.open');
    await expect(modal).toBeVisible();
    await modal.locator('textarea[name="direccion_entrega"]').fill('Av. Playwright 456, Colonia Prueba');
    await modal.locator('input[name="telefono_entrega"]').fill('3319876543');
    await modal.getByRole('button', { name: 'Confirmar cambio a domicilio' }).click();

    await expect(page.getByText('Pedido convertido a entrega a domicilio. Ya esta disponible para asignar repartidor.')).toBeVisible();
    await expect(page.getByRole('link', { name: 'Ir a Asignar Entregas' })).toBeVisible();

    // Ya no aparece en pickup (se elimina la notificación al convertir)...
    await expect(page.locator('tr').filter({ hasText: numeroPedido })).toHaveCount(0);

    // ...y sí aparece en Asignar Entregas, listo para asignarle un repartidor.
    await page.goto('views/asignar_entregas.php');
    await expect(page.locator('.assign-delivery-card').filter({ hasText: numeroPedido })).toBeVisible();
  });
});
