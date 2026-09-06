import { test, expect } from './fixtures';
import {
  loginAsStaff,
  E2E_PURCHASE_ORDER_PRODUCT_NAME,
  E2E_PO_SURTIR_PRODUCT_NAME,
  E2E_PO_CANCEL_PRODUCT_NAME,
} from './helpers';

// views/purchase_orders.php ("Compras y Resurtido") calcula sugerencias de resurtido sobre
// TODA la BD (esta instancia local ya trae ~95 productos reales con stock bajo, no solo los
// sembrados por Playwright), agrupadas por sucursal en la pestaña "Lista de Compra". "Generar
// Orden de Compra" y "Actualizar Mínimos/Máximos" envían TODAS las filas de la tabla sin
// importar cuáles edites -- no hay forma de acotar el submit a un solo producto. Para no mutar
// inventario/reglas/generar órdenes reales sobre esos ~95 productos cada vez que corre la
// suite, esos flujos se prueban interceptando la API con datos sintéticos controlados.
//
// Los tests que sí pegan contra la API real usan productos de uso exclusivo (ver
// scripts/seed_e2e_test_data.php): "posponer" (E2E_PURCHASE_ORDER_PRODUCT_NAME, reactiva con
// el botón "Devolver" de Pospuestos, no toca inventario) y el ciclo completo de una orden real
// -generar -> Órdenes Abiertas -> surtir/cancelar- (E2E_PO_SURTIR_PRODUCT_NAME /
// E2E_PO_CANCEL_PRODUCT_NAME, sembrados en 0 y reseteados en cada corrida para que sean
// repetibles sin resembrar).

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

async function goToTab(page: import('@playwright/test').Page, tab: 'lista' | 'pospuestos' | 'ordenes'): Promise<void> {
  await page.locator(`#po-tabs a[href="#tab-${tab}"]`).click();
}

/**
 * "Generar Orden de Compra" envia TODAS las filas visibles en #po-groups (igual que
 * "Confirmar Recepción"/"Actualizar Mínimos/Máximos", ver comentario arriba) -- sin acotar
 * la Lista de Compra real a un solo producto, generaría una orden real que arrastra los
 * ~95 productos reales con stock bajo que ya trae esta BD. Para probar el ciclo real de una
 * orden (crear/surtir/cancelar) sin ese riesgo, se pide la lista real una vez (para no
 * inventar id_producto/id_almacen/precio_costo, que deben ser validos de verdad por las
 * llaves foraneas de detalle_orden_compra), se filtra al producto dedicado, y se vuelve a
 * servir esa unica fila -- purchase_order_create.php/receive.php/cancel.php corren sin mock.
 */
async function mockSingleRealItem(page: import('@playwright/test').Page, productName: string): Promise<void> {
  const real = await page.request.get('api/purchase_orders_data.php');
  const { listaCompra } = await real.json();
  const item = (listaCompra as Array<{ nombre: string }>).find((i) => i.nombre === productName);
  if (!item) {
    throw new Error(`No se encontró "${productName}" en la Lista de Compra real -- ¿se corrió scripts/seed_e2e_test_data.php?`);
  }
  await page.route('**/api/purchase_orders_data.php', (route) =>
    route.fulfill({ json: { success: true, listaCompra: [item], chartData: [] } })
  );
}

test.describe('Compras y Resurtido (purchase_orders.php)', () => {
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
    await expect(page.locator('#po-form-wrapper')).toBeHidden();
  });

  test('cambiar la cantidad a recibir recalcula el subtotal y el total en vivo', async ({ page }) => {
    await loginAsStaff(page, 'encargado');
    await mockListaCompra(page, [MOCK_ITEM]);
    await page.goto('views/purchase_orders.php');

    const fila = page.locator('#po-groups .po-item-row').first();
    // aComprar por defecto = stock_maximo - cantidad_actual = 5 - 1 = 4; subtotal = 4 * 10.
    await expect(fila.locator('.po-subtotal')).toHaveText('$40.00');
    await expect(page.locator('#total-inversion-val')).toHaveText('40.00');

    await fila.locator('input[name$="[cantidad]"]').fill('2');
    await expect(fila.locator('.po-subtotal')).toHaveText('$20.00');
    await expect(page.locator('#total-inversion-val')).toHaveText('20.00');
  });

  test('sin cantidades a pedir, avisa y no llama a la API de generar orden', async ({ page }) => {
    await loginAsStaff(page, 'encargado');
    await mockListaCompra(page, [MOCK_ITEM]);
    let createCalled = false;
    await page.route('**/api/purchase_order_create.php', async (route) => {
      createCalled = true;
      await route.fulfill({ json: { success: true, message: 'Orden de compra generada' } });
    });

    await page.goto('views/purchase_orders.php');
    await page.locator('#po-groups .po-item-row').first().locator('input[name$="[cantidad]"]').fill('0');

    await page.getByRole('button', { name: /GENERAR ORDEN DE COMPRA/ }).click();
    await expect(page.getByText('No hay cantidades para ordenar')).toBeVisible();
    expect(createCalled).toBe(false);
  });

  test('generar orden de compra envia la cantidad correcta al servidor', async ({ page }) => {
    await loginAsStaff(page, 'encargado');
    await mockListaCompra(page, [MOCK_ITEM]);
    let createBody: any = null;
    await page.route('**/api/purchase_order_create.php', async (route) => {
      createBody = route.request().postDataJSON();
      await route.fulfill({ json: { success: true, message: 'Orden de compra generada' } });
    });

    await page.goto('views/purchase_orders.php');
    await page.getByRole('button', { name: /GENERAR ORDEN DE COMPRA/ }).click();
    await expect(page.locator('.swal2-confirm')).toBeVisible();
    await page.locator('.swal2-confirm').click();

    await expect(page.getByText('Orden de compra generada')).toBeVisible();
    expect(createBody).not.toBeNull();
    expect(createBody.items).toHaveLength(1);
    expect(Number(createBody.items[0].id_producto)).toBe(MOCK_ITEM.id_producto);
    expect(Number(createBody.items[0].cantidad)).toBe(4);
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
    const fila = page.locator('#po-groups .po-item-row').first();
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

  test('un encargado puede posponer un producto real y devolverlo desde la pestaña Pospuestos', async ({ page }) => {
    await loginAsStaff(page, 'encargado');
    await page.goto('views/purchase_orders.php');

    // No se afirma un valor exacto de stock: el producto es de uso exclusivo de este test
    // (se siembra con stock_minimo generoso) y aqui solo importa que sigue calificando para
    // la lista, no el valor exacto.
    const fila = page.locator('#po-groups .po-item-row').filter({ hasText: E2E_PURCHASE_ORDER_PRODUCT_NAME });
    await expect(fila).toBeVisible({ timeout: 15000 });

    await fila.locator('button[aria-label="Posponer producto"]').click();
    await expect(page.locator('.swal2-confirm')).toBeVisible();
    await page.locator('.swal2-confirm').click();

    await expect(page.getByText('Producto pospuesto para el siguiente pedido')).toBeVisible();
    await expect(page.locator('#po-groups .po-item-row').filter({ hasText: E2E_PURCHASE_ORDER_PRODUCT_NAME })).toHaveCount(0);

    // Aparece en la pestaña Pospuestos con el boton "Devolver".
    await goToTab(page, 'pospuestos');
    const filaPospuesta = page.locator('#pospuestos-container tr').filter({ hasText: E2E_PURCHASE_ORDER_PRODUCT_NAME });
    await expect(filaPospuesta).toBeVisible();

    await filaPospuesta.getByRole('button', { name: /Devolver/ }).click();
    await expect(page.locator('.swal2-confirm')).toBeVisible();
    await page.locator('.swal2-confirm').click();

    await expect(page.getByText('Producto regresado a la lista de compra')).toBeVisible();
    await expect(page.locator('#pospuestos-container tr').filter({ hasText: E2E_PURCHASE_ORDER_PRODUCT_NAME })).toHaveCount(0);

    // Y vuelve a calificar para la Lista de Compra sin recargar la pagina.
    await goToTab(page, 'lista');
    await expect(page.locator('#po-groups .po-item-row').filter({ hasText: E2E_PURCHASE_ORDER_PRODUCT_NAME })).toBeVisible();
  });

  test('generar una orden real y surtirla sube el inventario y la cierra', async ({ page }) => {
    await loginAsStaff(page, 'encargado');
    await mockSingleRealItem(page, E2E_PO_SURTIR_PRODUCT_NAME);
    await page.goto('views/purchase_orders.php');

    const fila = page.locator('#po-groups .po-item-row').filter({ hasText: E2E_PO_SURTIR_PRODUCT_NAME });
    await expect(fila).toBeVisible({ timeout: 15000 });

    await page.getByRole('button', { name: /GENERAR ORDEN DE COMPRA/ }).click();
    await expect(page.locator('.swal2-confirm')).toBeVisible();
    await page.locator('.swal2-confirm').click();
    await expect(page.getByText('Orden de compra generada')).toBeVisible();

    // Se quita el mock antes de recargar: de aqui en adelante todo corre contra la API real.
    await page.unroute('**/api/purchase_orders_data.php');
    // generarOrdenCompra() recarga la pagina entera tras el toast (refresca las 3 pestañas).
    await page.reload();

    await goToTab(page, 'ordenes');
    const card = page.locator('.po-orden-card').filter({ hasText: E2E_PO_SURTIR_PRODUCT_NAME });
    await expect(card).toBeVisible({ timeout: 15000 });

    await card.getByRole('button', { name: /Surtir todo/ }).click();
    await card.getByRole('button', { name: /^(?!.*todo).*Surtir orden/ }).click();
    await expect(page.locator('.swal2-confirm')).toBeVisible();
    await page.locator('.swal2-confirm').click();

    await expect(page.getByText('Orden surtida: inventario actualizado')).toBeVisible();
    await expect(card).toHaveCount(0);

    // El producto ya no califica para la Lista de Compra (el stock subio por encima del minimo).
    await goToTab(page, 'lista');
    await expect(page.locator('#po-groups .po-item-row').filter({ hasText: E2E_PO_SURTIR_PRODUCT_NAME })).toHaveCount(0);
  });

  test('generar una orden real y cancelarla no toca el inventario y regresa el producto a la lista', async ({ page }) => {
    await loginAsStaff(page, 'encargado');
    await mockSingleRealItem(page, E2E_PO_CANCEL_PRODUCT_NAME);
    await page.goto('views/purchase_orders.php');

    const fila = page.locator('#po-groups .po-item-row').filter({ hasText: E2E_PO_CANCEL_PRODUCT_NAME });
    await expect(fila).toBeVisible({ timeout: 15000 });

    await page.getByRole('button', { name: /GENERAR ORDEN DE COMPRA/ }).click();
    await expect(page.locator('.swal2-confirm')).toBeVisible();
    await page.locator('.swal2-confirm').click();
    await expect(page.getByText('Orden de compra generada')).toBeVisible();

    // Se quita el mock antes de recargar: de aqui en adelante todo corre contra la API real.
    await page.unroute('**/api/purchase_orders_data.php');
    await page.reload();

    await goToTab(page, 'ordenes');
    const card = page.locator('.po-orden-card').filter({ hasText: E2E_PO_CANCEL_PRODUCT_NAME });
    await expect(card).toBeVisible({ timeout: 15000 });

    await card.getByRole('button', { name: /Cancelar orden/ }).click();
    await expect(page.locator('.swal2-confirm')).toBeVisible();
    await page.locator('.swal2-confirm').click();

    await expect(page.getByText('Orden de compra cancelada')).toBeVisible();
    await expect(card).toHaveCount(0);

    // El inventario no se tocó: el producto sigue calificando para la Lista de Compra.
    await goToTab(page, 'lista');
    await expect(page.locator('#po-groups .po-item-row').filter({ hasText: E2E_PO_CANCEL_PRODUCT_NAME })).toBeVisible();
  });
});
