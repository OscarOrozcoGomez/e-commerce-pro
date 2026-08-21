import { test, expect } from './fixtures';

// Punto de partida: valida el guardrail de checkout sin cubrir aún un pedido real
// end-to-end (eso requiere una cuenta cliente semillada con stock disponible).
test.describe('Carrito y checkout', () => {
  test('checkout sin sesión iniciada pide identificarse antes de confirmar', async ({ page }) => {
    await page.goto('views/catalogo.php');

    const firstCard = page.locator('.product-card-container').first();
    await expect(firstCard).toBeVisible();
    await firstCard.locator('.card-action button').click();
    await expect(page.locator('.toast', { hasText: 'añadido al carrito' })).toBeVisible();

    await page.goto('views/cart.php');

    // "Domicilio" evita la validación de stock por sucursal (que corre antes que la de
    // sesión) para que esta prueba solo dependa del guardrail de autenticación.
    await page.locator('#tipo_entrega').selectOption('Domicilio');
    await page.locator('#nombre').fill('Playwright QA');
    await page.locator('#telefono').fill('3311234567');
    await page.locator('#direccion').fill('Calle Falsa 123, Colonia Centro');

    await page.getByRole('button', { name: 'Confirmar Pedido' }).click();

    await expect(page.getByText('¡Identifícate primero!')).toBeVisible();
  });
});
