import { test, expect } from './fixtures';
import { loginAsStaff, E2E_PURCHASE_ORDER_PRODUCT_NAME } from './helpers';

// views/purchase_orders.php ("Lista de Compra Sugerida") calcula sugerencias de resurtido
// sobre TODA la BD (esta instancia local ya trae ~95 productos reales con stock bajo, no solo
// los sembrados por Playwright). "Confirmar Recepción" y "Actualizar Mínimos/Máximos" envían
// TODAS las filas de la tabla sin importar cuáles edites -- no hay forma de acotar el submit a
// un solo producto. Para no mutar inventario/reglas de esos ~95 productos reales cada vez que
// corre la suite, esos dos flujos se prueban interceptando la API con datos sinteticos
// controlados. El unico test que sí pega contra la API real (posponer un producto) esta acotado
// a un solo renglon propio y se revierte al final con un ingreso real minimo (mismo mecanismo
// que ya usa la app para reactivar pospuestos), para no dejar el producto pospuesto entre
// corridas de la suite.

const MOCK_ITEM = {
  id_producto: 555001,
  nombre: 'Playwright PO Mock Producto',
  sku: 'PW-PO-MOCK',
  precio_costo: 10,
  precio_venta: 20,
  cantidad_actual: 1,
  stock_minimo: 2,
  stock_maximo: 5,
  sucursal: 'Almacén Mock',
  id_almacen: 1,
};

async function mockListaCompra(page: import('@playwright/test').Page, listaCompra: unknown[]): Promise<void> {
  await page.route('**/api/purchase_orders_data.php', (route) =>
    route.fulfill({ json: { success: true, listaCompra, chartData: [] } })
  );
}

test.describe('Lista de Compra Sugerida (purchase_orders.php)', () => {
  test('un vendedor no puede acceder a la lista de compra sugerida', async ({ page }) => {
    await loginAsStaff(page, 'vendedor');
    await page.goto('views/purchase_orders.php');
    await expect(page).toHaveURL(/views\/dashboard\.php/);
  });

  test('un repartidor no puede acceder a la lista de compra sugerida', async ({ page }) => {
    await loginAsStaff(page, 'repartidor');
    await page.goto('views/purchase_orders.php');
    await expect(page).toHaveURL(/views\/dashboard\.php/);
  });

  test('sin productos por resurtir muestra el mensaje de inventario saludable', async ({ page }) => {
    await loginAsStaff(page, 'encargado');
    await mockListaCompra(page, []);
    await page.goto('views/purchase_orders.php');
    await expect(page.getByText('¡Inventario saludable!')).toBeVisible();
  });

  test('cambiar la cantidad a recibir recalcula el subtotal y el total en vivo', async ({ page }) => {
    await loginAsStaff(page, 'encargado');
    await mockListaCompra(page, [MOCK_ITEM]);
    await page.goto('views/purchase_orders.php');

    const fila = page.locator('#table-po-body tr').first();
    // aComprar por defecto = stock_maximo - cantidad_actual = 5 - 1 = 4; subtotal = 4 * 10.
    await expect(fila.locator('.po-subtotal')).toHaveText('$40.00');
    await expect(page.locator('#total-inversion-val')).toHaveText('40.00');

    await fila.locator('input[name$="[cantidad]"]').fill('2');
    await expect(fila.locator('.po-subtotal')).toHaveText('$20.00');
    await expect(page.locator('#total-inversion-val')).toHaveText('20.00');
  });

  test('sin cantidades a recibir, avisa y no llama a la API de entrada', async ({ page }) => {
    await loginAsStaff(page, 'encargado');
    await mockListaCompra(page, [MOCK_ITEM]);
    let batchInboundCalled = false;
    await page.route('**/api/batch_inbound.php', async (route) => {
      batchInboundCalled = true;
      await route.fulfill({ json: { success: true, message: 'Inventario actualizado correctamente' } });
    });

    await page.goto('views/purchase_orders.php');
    await page.locator('#table-po-body tr').first().locator('input[name$="[cantidad]"]').fill('0');

    await page.getByRole('button', { name: /CONFIRMAR RECEPCIÓN DE MERCANCÍA/ }).click();
    await expect(page.getByText('No hay cantidades para ingresar')).toBeVisible();
    expect(batchInboundCalled).toBe(false);
  });

  test('confirmar la recepcion envia la cantidad correcta al servidor', async ({ page }) => {
    await loginAsStaff(page, 'encargado');
    await mockListaCompra(page, [MOCK_ITEM]);
    let batchInboundBody: any = null;
    await page.route('**/api/batch_inbound.php', async (route) => {
      batchInboundBody = route.request().postDataJSON();
      await route.fulfill({ json: { success: true, message: 'Inventario actualizado correctamente' } });
    });

    await page.goto('views/purchase_orders.php');
    await page.getByRole('button', { name: /CONFIRMAR RECEPCIÓN DE MERCANCÍA/ }).click();
    await expect(page.locator('.swal2-confirm')).toBeVisible();
    await page.locator('.swal2-confirm').click();

    await expect(page.getByText('Inventario actualizado correctamente')).toBeVisible();
    expect(batchInboundBody).not.toBeNull();
    expect(batchInboundBody.items).toHaveLength(1);
    expect(Number(batchInboundBody.items[0].id_producto)).toBe(MOCK_ITEM.id_producto);
    expect(Number(batchInboundBody.items[0].cantidad)).toBe(4);
  });

  test('actualizar minimos/maximos envia los valores editados al servidor', async ({ page }) => {
    await loginAsStaff(page, 'encargado');
    await mockListaCompra(page, [MOCK_ITEM]);
    let updateBody: any = null;
    await page.route('**/api/update_thresholds.php', async (route) => {
      updateBody = route.request().postDataJSON();
      await route.fulfill({ json: { success: true, message: 'Reglas de stock actualizadas correctamente' } });
    });

    await page.goto('views/purchase_orders.php');
    const fila = page.locator('#table-po-body tr').first();
    await fila.locator('input[title="Mínimo"]').fill('3');
    await fila.locator('input[title="Máximo"]').fill('10');

    await page.getByRole('button', { name: /ACTUALIZAR MÍNIMOS\/MÁXIMOS/ }).click();
    await expect(page.locator('.swal2-confirm')).toBeVisible();
    await page.locator('.swal2-confirm').click();

    await expect(page.getByText('Reglas de stock actualizadas correctamente')).toBeVisible();
    expect(updateBody).not.toBeNull();
    expect(Number(updateBody.items[0].stock_minimo)).toBe(3);
    expect(Number(updateBody.items[0].stock_maximo)).toBe(10);
  });

  test('un encargado ve un producto real de bajo stock en la lista y puede posponerlo', async ({ page }) => {
    await loginAsStaff(page, 'encargado');
    await page.goto('views/purchase_orders.php');

    // No se afirma un valor exacto de stock: el producto es de uso exclusivo de este test y
    // el ciclo posponer->reactivar (ver limpieza al final) le suma una unidad en cada corrida;
    // por eso se siembra con stock_minimo generoso (scripts/seed_e2e_test_data.php) y aqui solo
    // importa que sigue calificando para la lista, no el valor exacto.
    const fila = page.locator('#table-po-body tr').filter({ hasText: E2E_PURCHASE_ORDER_PRODUCT_NAME });
    await expect(fila).toBeVisible({ timeout: 15000 });

    // Se capturan antes de posponer: la fila (y sus inputs ocultos) desaparece del DOM al posponer.
    const idProducto = await fila.locator('input[name$="[id_producto]"]').inputValue();
    const idAlmacen = await fila.locator('input[name$="[id_almacen]"]').inputValue();
    const csrfToken = await page.locator('input[name="csrf_token"]').inputValue();

    await fila.locator('button').click();
    await expect(page.locator('.swal2-confirm')).toBeVisible();
    await page.locator('.swal2-confirm').click();

    await expect(page.getByText('Producto pospuesto para el siguiente pedido')).toBeVisible();
    await expect(page.locator('#table-po-body tr').filter({ hasText: E2E_PURCHASE_ORDER_PRODUCT_NAME })).toHaveCount(0);

    // Revierte el posponer con un ingreso real minimo (mismo mecanismo que usa la app para
    // reactivar pospuestos al confirmar una recepcion), para que el producto siga disponible
    // en la siguiente corrida de la suite en vez de quedar pospuesto indefinidamente.
    const cleanupResponse = await page.request.post('api/batch_inbound.php', {
      data: { csrf_token: csrfToken, items: [{ id_producto: idProducto, id_almacen: idAlmacen, cantidad: 1 }] },
    });
    expect((await cleanupResponse.json()).success).toBe(true);
  });
});
