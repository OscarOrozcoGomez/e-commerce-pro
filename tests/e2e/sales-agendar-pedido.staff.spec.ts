import { test, expect } from './fixtures';
import { loginAsStaff, E2E_PRODUCT_NAME, E2E_SALES_CLIENTE_NOMBRE } from './helpers';

// views/sales.php es el "POS" interno (agendar pedido) que usan vendedor/encargado/admin.
// A diferencia del checkout público, solo permite elegir un cliente YA existente -por
// eso usamos el cliente fijo con domicilio guardado que siembra seed_e2e_test_data.php,
// en vez de registrar uno nuevo por test como en el resto de la suite.
test.describe('Vendedor: agendar pedido (sales.php)', () => {
  test('agendar un pedido para un cliente existente con producto en stock', { tag: '@smoke' }, async ({ page }) => {
    await loginAsStaff(page, 'vendedor');
    await page.goto('views/sales.php');

    const form = page.locator('.formulario-venta').first();

    // Escribir el nombre exacto y salir del campo resuelve el cliente
    // (resolveTypedCustomer, disparado por blur/Tab) sin depender de que el
    // dropdown de sugerencias de Materialize alcance a renderizar -- con
    // cientos de clientes cargados en la página, ese dropdown es más lento e
    // intermitente que este camino.
    await form.locator('.cliente_nombre').fill(E2E_SALES_CLIENTE_NOMBRE);
    await form.locator('.cliente_nombre').press('Tab');

    await expect(form.locator('.cliente_telefono')).not.toHaveValue('', { timeout: 10000 });

    await form.locator('.buscador-producto').fill(E2E_PRODUCT_NAME);
    const item = form.locator('.producto-dropdown .item').filter({ hasText: E2E_PRODUCT_NAME }).first();
    await item.waitFor({ state: 'visible' });
    await item.click();

    await expect(form.locator('.producto-item')).toHaveCount(1);

    // No confiamos en cachar el toast de éxito: cuando se agenda el único tab
    // abierto, sales.php hace location.reload() casi de inmediato después de
    // mostrarlo (incluso antes de que el body de la response siga disponible),
    // así que interceptamos la request para leer el JSON real de la API.
    let ventaResult: { success?: boolean } | null = null;
    await page.route('**/api/ventas.php', async (route) => {
      const response = await route.fetch();
      ventaResult = await response.json();
      await route.fulfill({ response });
    });

    await form.getByRole('button', { name: 'Agendar Pedido' }).click();
    await expect.poll(() => ventaResult).not.toBeNull();
    expect(ventaResult!.success).toBe(true);
  });

  test('no deja agendar sin seleccionar un cliente existente', async ({ page }) => {
    await loginAsStaff(page, 'vendedor');
    await page.goto('views/sales.php');

    const form = page.locator('.formulario-venta').first();

    await form.locator('.buscador-producto').fill(E2E_PRODUCT_NAME);
    const item = form.locator('.producto-dropdown .item').filter({ hasText: E2E_PRODUCT_NAME }).first();
    await item.waitFor({ state: 'visible' });
    await item.click();

    // El teléfono se llena a mano (sin resolver un cliente real) para satisfacer el
    // `required` nativo del campo y así llegar al guardrail de JS que sí valida
    // "hay un cliente seleccionado" ("Selecciona un cliente existente.").
    await form.locator('.cliente_telefono').fill('3311234567');
    await form.getByRole('button', { name: 'Agendar Pedido' }).click();

    await expect(page.getByText('Selecciona un cliente existente.')).toBeVisible();
  });
});
