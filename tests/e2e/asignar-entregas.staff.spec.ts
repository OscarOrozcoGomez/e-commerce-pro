import { test, expect } from './fixtures';
import { completeDomicilioCheckout, getNumeroPedido, loginAsStaff, E2E_PRODUCT_NAME } from './helpers';

// El happy path de asignar un pedido a un repartidor y completar la entrega ya vive en
// order-fulfillment-pipeline.staff.spec.ts. Este spec cubre lo que ese no toca: control de
// acceso, convertir un pedido a domicilio en "recoger en sucursal", y agregar/quitar productos
// de un pedido ya asignado desde la pestaña "Asignadas".

function hoyISO(): string {
  return new Date().toISOString().slice(0, 10);
}

// select.selectOption({label}) exige coincidencia exacta, pero la opcion incluye el precio
// ($XX.XX) al final -- se resuelve el value real buscando la opcion por texto parcial.
async function seleccionarProductoPorNombre(select: import('@playwright/test').Locator, nombreProducto: string): Promise<void> {
  const value = await select.locator('option', { hasText: nombreProducto }).getAttribute('value');
  await select.selectOption(value ?? '');
}

async function asignarRepartidor(page: import('@playwright/test').Page, idPedido: number): Promise<void> {
  const repartidorSelect = page.locator(`#repartidor-${idPedido}`);
  await expect(repartidorSelect).toBeVisible();
  await repartidorSelect.selectOption({ label: 'Playwright E2E Repartidor' });
  await page.locator(`#fecha-${idPedido}`).fill(hoyISO());

  const assignCard = page.locator('.assign-delivery-card').filter({ has: repartidorSelect });
  await assignCard.getByRole('button', { name: 'Asignar' }).click();
  await page.waitForURL(/asignar_entregas\.php/);
  await expect(page.getByText('Pedido asignado correctamente.')).toBeVisible();
}

test.describe('Asignar Entregas a Domicilio (asignar_entregas.php)', () => {
  test('un vendedor no puede acceder a asignar entregas', async ({ page }) => {
    await loginAsStaff(page, 'vendedor');
    await page.goto('views/asignar_entregas.php');
    await expect(page).toHaveURL(/views\/dashboard\.php/);
  });

  test('un repartidor no puede acceder a asignar entregas', async ({ page }) => {
    await loginAsStaff(page, 'repartidor');
    await page.goto('views/asignar_entregas.php');
    await expect(page).toHaveURL(/views\/dashboard\.php/);
  });

  test('convertir un pedido a domicilio en "recoger en sucursal" lo mueve a notificaciones pickup', async ({ page }) => {
    const idPedido = await completeDomicilioCheckout(page);
    const numeroPedido = await getNumeroPedido(page, idPedido);

    await loginAsStaff(page, 'encargado');
    await page.goto('views/asignar_entregas.php');

    const card = page.locator('.assign-delivery-card').filter({ hasText: numeroPedido });
    await expect(card).toBeVisible();

    page.once('dialog', (dialog) => dialog.accept());
    await card.getByRole('button', { name: /Cambiar a recoger en sucursal/ }).click();

    await page.waitForURL(/asignar_entregas\.php/);
    await expect(page.getByText('Pedido convertido a recoger en sucursal.')).toBeVisible();
    await expect(page.getByRole('link', { name: /Ir a Notificaciones Pickup/ })).toBeVisible();

    // Ya no debe aparecer como pedido de domicilio pendiente de asignar.
    await expect(page.locator('.assign-delivery-card').filter({ hasText: numeroPedido })).toHaveCount(0);

    // Y debe aparecer en la bandeja de pickup, con estado inicial "nueva".
    await page.goto('views/pickup_notifications.php');
    const filaPickup = page.locator('tr', { hasText: numeroPedido });
    await expect(filaPickup).toBeVisible();
    await expect(filaPickup.locator('.badge')).toHaveText('NUEVA');
  });

  test('agregar un producto a un pedido ya asignado lo suma al pedido y al total', async ({ page }) => {
    const idPedido = await completeDomicilioCheckout(page);
    const numeroPedido = await getNumeroPedido(page, idPedido);

    await loginAsStaff(page, 'encargado');
    await page.goto('views/asignar_entregas.php');
    await asignarRepartidor(page, idPedido);

    await page.goto('views/asignar_entregas.php?tab=asignadas');
    const card = page.locator('.assign-delivery-card').filter({ hasText: numeroPedido });
    await expect(card).toBeVisible();

    // El pedido ya trae 1 linea (el producto del checkout); se vuelve a agregar el mismo
    // producto (con stock de sobra) para no competir por stock con otros tests en paralelo.
    await expect(card.locator('.assign-products-list li')).toHaveCount(1);
    const totalAntesTexto = await card.locator('.assign-delivery-total').textContent();
    const totalAntes = Number((totalAntesTexto ?? '').replace(/[^0-9.]/g, ''));

    await seleccionarProductoPorNombre(card.locator('select[name="id_producto"]'), E2E_PRODUCT_NAME);
    await card.locator('input[name="cantidad"]').fill('1');
    await card.getByRole('button', { name: 'Agregar' }).click();

    await page.waitForURL(/asignar_entregas\.php\?tab=asignadas/);
    await expect(page.getByText('Producto agregado al pedido correctamente.')).toBeVisible();

    const cardDespues = page.locator('.assign-delivery-card').filter({ hasText: numeroPedido });
    await expect(cardDespues.locator('.assign-products-list li')).toHaveCount(2);
    const totalDespuesTexto = await cardDespues.locator('.assign-delivery-total').textContent();
    const totalDespues = Number((totalDespuesTexto ?? '').replace(/[^0-9.]/g, ''));
    expect(totalDespues).toBeGreaterThan(totalAntes);
  });

  test('quitar un producto de un pedido con varios lo marca como no entregado y devuelve el stock', async ({ page }) => {
    const idPedido = await completeDomicilioCheckout(page);
    const numeroPedido = await getNumeroPedido(page, idPedido);

    await loginAsStaff(page, 'encargado');
    await page.goto('views/asignar_entregas.php');
    await asignarRepartidor(page, idPedido);

    // Setup: se agrega el mismo producto una segunda vez para que el pedido tenga 2 lineas
    // -- si solo quedara 1, el boton de quitar aparece deshabilitado (ver siguiente test).
    await page.goto('views/asignar_entregas.php?tab=asignadas');
    let card = page.locator('.assign-delivery-card').filter({ hasText: numeroPedido });
    await seleccionarProductoPorNombre(card.locator('select[name="id_producto"]'), E2E_PRODUCT_NAME);
    await card.locator('input[name="cantidad"]').fill('1');
    await card.getByRole('button', { name: 'Agregar' }).click();
    await page.waitForURL(/asignar_entregas\.php\?tab=asignadas/);
    await expect(page.getByText('Producto agregado al pedido correctamente.')).toBeVisible();

    card = page.locator('.assign-delivery-card').filter({ hasText: numeroPedido });
    await expect(card.locator('.assign-products-list li')).toHaveCount(2);

    page.once('dialog', (dialog) => dialog.accept());
    await card.locator('.assign-products-list li').first().getByRole('button', { name: 'Quitar' }).click();

    await page.waitForURL(/asignar_entregas\.php\?tab=asignadas/);
    await expect(page.getByText('Producto marcado como no entregado. El stock fue devuelto al inventario.')).toBeVisible();

    card = page.locator('.assign-delivery-card').filter({ hasText: numeroPedido });
    await expect(card.getByText('Quitado')).toBeVisible();
  });

  test('no se puede quitar el ultimo producto de un pedido asignado', async ({ page }) => {
    const idPedido = await completeDomicilioCheckout(page);
    const numeroPedido = await getNumeroPedido(page, idPedido);

    await loginAsStaff(page, 'encargado');
    await page.goto('views/asignar_entregas.php');
    await asignarRepartidor(page, idPedido);

    await page.goto('views/asignar_entregas.php?tab=asignadas');
    const card = page.locator('.assign-delivery-card').filter({ hasText: numeroPedido });
    const botonQuitar = card.getByRole('button', { name: 'Quitar' });
    await expect(botonQuitar).toBeVisible();
    await expect(botonQuitar).toBeDisabled();
  });
});
