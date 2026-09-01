import { test, expect } from './fixtures';
import { completeDomicilioCheckout, getNumeroPedido, loginAsStaff, registerAndLogin, E2E_PRODUCT_NAME } from './helpers';

// El happy path (salir a entregar -> entregado y cobrado) ya vive en
// order-fulfillment-pipeline.staff.spec.ts. Este spec cubre las 2 ramas negativas de
// views/entregas.php que ese no toca: el repartidor cancela una entrega que no pudo completar
// (devuelve stock, cancela el pedido) y rechaza un solo producto de un pedido con varios
// (el resto se sigue entregando y cobrando). Se deja fuera a proposito el panel de
// "Optimizacion de Ruta" (admin): depende de Google Maps/geolocalizacion real.

async function asignarRepartidor(page: import('@playwright/test').Page, idPedido: number): Promise<void> {
  await page.goto('views/asignar_entregas.php');
  const repartidorSelect = page.locator(`#repartidor-${idPedido}`);
  await expect(repartidorSelect).toBeVisible();
  await repartidorSelect.selectOption({ label: 'Playwright E2E Repartidor' });
  await page.locator(`#fecha-${idPedido}`).fill(new Date().toISOString().slice(0, 10));

  const assignCard = page.locator('.assign-delivery-card').filter({ has: repartidorSelect });
  await assignCard.getByRole('button', { name: 'Asignar' }).click();
  await page.waitForURL(/asignar_entregas\.php/);
  await expect(page.getByText('Pedido asignado correctamente.')).toBeVisible();
}

async function agregarSegundoProducto(page: import('@playwright/test').Page, numeroPedido: string): Promise<void> {
  await page.goto('views/asignar_entregas.php?tab=asignadas');
  const card = page.locator('.assign-delivery-card').filter({ hasText: numeroPedido });
  const select = card.locator('select[name="id_producto"]');
  const value = await select.locator('option', { hasText: E2E_PRODUCT_NAME }).getAttribute('value');
  await select.selectOption(value ?? '');
  await card.locator('input[name="cantidad"]').fill('1');
  await card.getByRole('button', { name: 'Agregar' }).click();
  await page.waitForURL(/asignar_entregas\.php\?tab=asignadas/);
  await expect(page.getByText('Producto agregado al pedido correctamente.')).toBeVisible();
}

test.describe('Entregas Asignadas (entregas.php): casos negativos', () => {
  test('el repartidor cancela una entrega que no pudo completar y el stock se devuelve', async ({ page }) => {
    const idPedido = await completeDomicilioCheckout(page);
    const numeroPedido = await getNumeroPedido(page, idPedido);

    await loginAsStaff(page, 'encargado');
    await asignarRepartidor(page, idPedido);

    await loginAsStaff(page, 'repartidor');
    await page.goto('views/entregas.php?fecha_entrega=');
    const deliveryCard = page.locator(`[data-pedido-id="${idPedido}"]`);
    await expect(deliveryCard).toBeVisible();

    await deliveryCard.getByRole('button', { name: 'No pude entregar' }).click();
    const cancelForm = deliveryCard.locator('form.cancel-entrega-form');
    await expect(cancelForm).toBeVisible();

    // "Otro" exige texto: primero se confirma que el navegador bloquea el envio vacio.
    await cancelForm.locator('select[name="motivo_cancelacion"]').selectOption('otro');
    const otroInput = cancelForm.locator('input[name="motivo_cancelacion_otro"]');
    await expect(otroInput).toBeVisible();
    await expect(otroInput).toHaveJSProperty('required', true);

    await otroInput.fill('El cliente no contesto el telefono en la direccion');
    // A diferencia del rechazo de un solo producto (mas abajo, que si usa confirm() nativo),
    // este formulario pasa por mceConfirmarFormulario() -- abre el modal Materialize
    // #modal-confirmar-entrega en vez de un dialog nativo del navegador; el submit real
    // ocurre hasta que se hace click en su boton de confirmar.
    await cancelForm.getByRole('button', { name: 'CONFIRMAR CANCELACION' }).click();
    await page.locator('#mce-btn-confirmar').click();

    await page.waitForURL(/entregas\.php/);
    await expect(page.getByText('Entrega cancelada. El stock fue devuelto al inventario de la sucursal.')).toBeVisible();
    await expect(page.locator(`[data-pedido-id="${idPedido}"]`)).toHaveCount(0);

    // El pedido ya no debe aparecer como entrega pendiente en ningun lado.
    await loginAsStaff(page, 'encargado');
    await page.goto('views/asignar_entregas.php?tab=asignadas');
    const cardEncargado = page.locator('.assign-delivery-card').filter({ hasText: numeroPedido });
    await expect(cardEncargado.getByText('CANCELADO', { exact: false })).toBeVisible();
  });

  test('el repartidor rechaza un solo producto de un pedido con varios; el resto sigue en la entrega', async ({ page }) => {
    const idPedido = await completeDomicilioCheckout(page);
    const numeroPedido = await getNumeroPedido(page, idPedido);

    await loginAsStaff(page, 'encargado');
    await asignarRepartidor(page, idPedido);
    await agregarSegundoProducto(page, numeroPedido);

    await loginAsStaff(page, 'repartidor');
    await page.goto('views/entregas.php?fecha_entrega=');
    const deliveryCard = page.locator(`[data-pedido-id="${idPedido}"]`);
    await expect(deliveryCard).toBeVisible();
    await expect(deliveryCard.locator('.section-products li')).toHaveCount(2);

    const primerItem = deliveryCard.locator('.section-products li').first();
    await primerItem.getByRole('button', { name: 'No lo quiso' }).click();
    const rejectForm = primerItem.locator('form.reject-item-form');
    await expect(rejectForm).toBeVisible();

    await rejectForm.locator('select[name="motivo_producto"]').selectOption('cliente_no_lo_quiere');
    page.once('dialog', (dialog) => dialog.accept());
    await rejectForm.getByRole('button', { name: 'Confirmar' }).click();

    await page.waitForURL(/entregas\.php/);
    await expect(page.getByText('Producto marcado como no entregado. El stock fue devuelto al inventario.')).toBeVisible();

    // El pedido sigue en la lista (todavia tiene 1 producto por entregar), y ese producto
    // ahora aparece tachado con la etiqueta "No entregado".
    const deliveryCardDespues = page.locator(`[data-pedido-id="${idPedido}"]`);
    await expect(deliveryCardDespues).toBeVisible();
    await expect(deliveryCardDespues.getByText('No entregado')).toBeVisible();
  });

  test('un cliente autenticado no puede acceder a entregas.php', async ({ page }) => {
    await registerAndLogin(page);
    await page.goto('views/entregas.php');
    // requirePermission('ver_entregas', .../dashboard.php) redirige ahi, y dashboard.php a su
    // vez redirige a un cliente fuera del panel de staff -- lo que importa es que nunca se
    // queda en entregas.php.
    await expect(page).not.toHaveURL(/views\/entregas\.php/);
  });
});
