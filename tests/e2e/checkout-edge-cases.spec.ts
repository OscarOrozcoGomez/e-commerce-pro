import { test, expect } from './fixtures';

test.describe('Checkout: edge cases (invitado)', () => {
  test('confirmar pedido con el carrito vacío muestra aviso y no llama a la API', async ({ page }) => {
    let orderApiCalled = false;
    await page.route('**/api/public_orders.php', (route) => {
      orderApiCalled = true;
      route.continue();
    });

    await page.goto('views/cart.php');
    // Campos requeridos llenos para que el navegador deje pasar el submit al JS;
    // lo que se prueba es el guardrail de "carrito vacío" del propio JS, no HTML5.
    await page.locator('#tipo_entrega').selectOption('Domicilio');
    await page.locator('#nombre').fill('Playwright QA');
    await page.locator('#telefono').fill('3311234567');
    await page.locator('#direccion').fill('Calle Falsa 123, Colonia Centro');

    await page.getByRole('button', { name: 'Confirmar Pedido' }).click();

    await expect(page.getByText('Tu carrito está vacío')).toBeVisible();
    expect(orderApiCalled).toBe(false);
  });
});
