import { test, expect } from './fixtures';
import { registerAndLogin, addProductToCartByName, E2E_PRODUCT_NAME, E2E_LOW_STOCK_PRODUCT_NAME } from './helpers';

/** Llena el formulario de Sucursal SIN enviarlo, para poder inspeccionar el banner de stock antes. */
async function fillSucursalCheckoutForm(page: import('@playwright/test').Page) {
  await page.goto('views/cart.php');
  await page.locator('#tipo_entrega').selectOption('Sucursal');
  await page.locator('#nombre').fill('Playwright QA');
  await page.locator('#telefono').fill('3311234567');
}

// "Recoger en Sucursal" es un camino de checkout completamente distinto a
// "Domicilio" (el único que probábamos antes): valida stock específicamente en
// el almacén de pickup público y, si falta, intenta cubrirlo transfiriendo desde
// otros almacenes. scripts/seed_e2e_test_data.php siembra los 3 escenarios
// posibles (ok / transferible / sin_stock) usando los mismos productos de prueba.
test.describe('Checkout: Recoger en Sucursal', () => {
  test('con stock completo en sucursal, el pedido se confirma sin fricción', async ({ page }) => {
    await registerAndLogin(page);
    await addProductToCartByName(page, E2E_PRODUCT_NAME);
    await fillSucursalCheckoutForm(page);

    await expect(page.getByText('Listo para recoger en sucursal.')).toBeVisible();
    await expect(page.getByRole('button', { name: 'Confirmar Pedido' })).toBeEnabled();

    await page.getByRole('button', { name: 'Confirmar Pedido' }).click();
    // A diferencia de Domicilio, Sucursal no pregunta por guardar dirección: va
    // directo a "¡Pedido Confirmado!" -> Continuar -> gracias.php.
    await page.getByRole('button', { name: 'Continuar' }).click();
    await page.waitForURL(/gracias\.php\?id=\d+/);
    await expect(page.locator('.badge.ok')).toHaveText('Pedido confirmado');
  });

  test('con stock faltante pero transferible desde otro almacén, avisa el retraso sin bloquear', async ({
    page,
  }) => {
    await registerAndLogin(page);
    await addProductToCartByName(page, E2E_LOW_STOCK_PRODUCT_NAME);
    await fillSucursalCheckoutForm(page);

    await expect(page.getByText('En este momento no esta completo en stock de sucursal.', { exact: false })).toBeVisible();
    await expect(page.getByText('2 a 3 horas', { exact: false })).toBeVisible();
    // No completamos el pedido aquí a propósito: el producto de stock bajo solo
    // tiene 1 unidad compartida entre corridas, y una transferencia real la
    // consumiría, dejando "sin_stock" para la siguiente corrida sin volver a
    // sembrar. Lo que importa probar es que el aviso NO bloquea el botón (a
    // diferencia de 'sin_stock' en el siguiente test).
    await expect(page.getByRole('button', { name: 'Confirmar Pedido' })).toBeEnabled();
  });

  test('sin stock suficiente ni transferible, bloquea la confirmación', async ({ page }) => {
    await registerAndLogin(page);
    await addProductToCartByName(page, E2E_LOW_STOCK_PRODUCT_NAME);

    await page.goto('views/cart.php');
    // El producto de stock bajo solo tiene 1 unidad en TODO el sistema; pedir 2
    // hace que ni la sucursal ni el almacén de apoyo puedan cubrirlo.
    await page.locator('.cart-qty-btn', { hasText: '+' }).click();
    await expect(page.locator('.cart-qty-input')).toHaveValue('2');

    await page.locator('#tipo_entrega').selectOption('Sucursal');
    await page.locator('#nombre').fill('Playwright QA');
    await page.locator('#telefono').fill('3311234567');

    await expect(page.getByText('Sin inventario suficiente para recoger en sucursal.', { exact: false })).toBeVisible();
    await expect(page.getByRole('button', { name: /Sin stock disponible/ })).toBeDisabled();
  });
});
