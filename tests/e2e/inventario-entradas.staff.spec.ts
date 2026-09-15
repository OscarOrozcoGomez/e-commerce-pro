import { test, expect } from './fixtures';
import { loginAsStaff, E2E_PRODUCT_NAME } from './helpers';

test.describe('Encargado: entrada individual de inventario', () => {
  test('un vendedor no puede acceder a entradas de inventario', async ({ page }) => {
    await loginAsStaff(page, 'vendedor');
    await page.goto('views/inventario_entradas.php');
    await expect(page).toHaveURL(/views\/dashboard\.php/);
  });

  test('un repartidor no puede acceder a entradas de inventario', async ({ page }) => {
    await loginAsStaff(page, 'repartidor');
    await page.goto('views/inventario_entradas.php');
    await expect(page).toHaveURL(/views\/dashboard\.php/);
  });

  test('registrar una entrada individual actualiza el stock', async ({ page }) => {
    const pageErrors: string[] = [];
    page.on('pageerror', (err) => pageErrors.push(err.message));

    await loginAsStaff(page, 'encargado');
    await page.goto('views/inventario_entradas.php');
    expect(pageErrors).toEqual([]);

    await page.locator('#buscador-inbound').fill(E2E_PRODUCT_NAME);
    await page.locator('#buscador-inbound').press('Tab');
    await expect(page.locator('#id_producto_inbound')).not.toHaveValue('');

    await page.locator('#cantidad_inbound').fill('5');

    let apiResult: { success?: boolean } | null = null;
    await page.route('**/api/inventory_handler.php', async (route) => {
      const response = await route.fetch();
      apiResult = await response.json();
      await route.fulfill({ response });
    });

    await page.getByRole('button', { name: 'REGISTRAR ENTRADA' }).click();
    await expect.poll(() => apiResult).not.toBeNull();
    expect(apiResult!.success).toBe(true);
  });

  test('el formulario individual rechaza el envío sin un producto válido seleccionado de la lista', async ({ page }) => {
    let apiCalled = false;
    await page.route('**/api/inventory_handler.php', (route) => {
      apiCalled = true;
      route.continue();
    });

    await loginAsStaff(page, 'encargado');
    await page.goto('views/inventario_entradas.php');

    // Escribe texto que no matchea ningún producto real (no se resuelve #id_producto_inbound)
    // y llena la cantidad -- el guardrail de JS debe bloquear el envío antes de llamar la API.
    await page.locator('#buscador-inbound').fill('Playwright texto que no matchea ningun producto ' + Date.now());
    await page.locator('#cantidad_inbound').fill('3');
    await page.getByRole('button', { name: 'REGISTRAR ENTRADA' }).click();

    await expect(page.getByText('Selecciona un producto válido de la lista')).toBeVisible();
    expect(apiCalled).toBe(false);
  });

  test('"Carga Rápida de Inventario" (lista por fila): cantidad inválida no llama a la API; una cantidad válida suma al badge de stock', async ({ page }) => {
    let apiCalled = false;
    await page.route('**/api/inventory_handler.php', async (route) => {
      apiCalled = true;
      await route.continue();
    });

    await loginAsStaff(page, 'encargado');
    await page.goto('views/inventario_entradas.php');

    await page.locator('#filtro-lista-rapida').fill(E2E_PRODUCT_NAME);
    const fila = page.locator('table.striped.condensed tbody tr').filter({ hasText: E2E_PRODUCT_NAME });
    await expect(fila).toBeVisible();

    // Sin cantidad (input vacío): el guardrail de JS bloquea antes de llamar la API.
    await fila.locator('button.green').click();
    await expect(page.getByText('Ingresa una cantidad válida')).toBeVisible();
    expect(apiCalled).toBe(false);

    const badge = fila.locator('.badge');
    const stockAntes = Number((await badge.textContent())?.trim() ?? '0');

    await fila.locator('.qty-input').fill('7');
    await fila.locator('button.green').click();

    // api/inventory_handler.php sí manda su propio "message" ("Stock actualizado
    // correctamente"), así que gana sobre el fallback ("Entrada registrada con éxito") que
    // solo se usaría si el backend no mandara mensaje.
    await expect(page.getByText('Stock actualizado correctamente')).toBeVisible();
    expect(apiCalled).toBe(true);
    await expect(badge).toHaveText(String(stockAntes + 7));
    // El input de cantidad se limpia tras una entrada exitosa, listo para la siguiente.
    await expect(fila.locator('.qty-input')).toHaveValue('');
  });
});
