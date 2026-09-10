import { test, expect } from './fixtures';
import { loginAsStaff, E2E_PRODUCT_NAME, E2E_SALES_CLIENTE_NOMBRE } from './helpers';

// reservations.php ("Mis Apartados") lista los pedidos agendados por el usuario staff QUE
// TIENE LA SESION ABIERTA (p.id_usuario = usuario actual) que siguen pendientes de pago --
// solo tiene sentido probarlo agendando un pedido real primero con esa misma cuenta.
test.describe('Mis Apartados (reservations.php)', () => {
  test('un vendedor no puede ver sus apartados', async ({ page }) => {
    await loginAsStaff(page, 'vendedor');
    await page.goto('views/reservations.php');
    await expect(page).toHaveURL(/views\/dashboard\.php/);
  });

  test('un repartidor no puede ver apartados', async ({ page }) => {
    await loginAsStaff(page, 'repartidor');
    await page.goto('views/reservations.php');
    await expect(page).toHaveURL(/views\/dashboard\.php/);
  });

  test('un pedido agendado por el encargado aparece en sus apartados', async ({ page }) => {
    await loginAsStaff(page, 'encargado');
    await page.goto('views/sales.php');

    const form = page.locator('.formulario-venta').first();
    await form.locator('.cliente_nombre').fill(E2E_SALES_CLIENTE_NOMBRE);
    await form.locator('.cliente_nombre').press('Tab');
    await expect(form.locator('.cliente_telefono')).not.toHaveValue('', { timeout: 10000 });

    await form.locator('.buscador-producto').fill(E2E_PRODUCT_NAME);
    const item = form.locator('.producto-dropdown .item').filter({ hasText: E2E_PRODUCT_NAME }).first();
    await item.waitFor({ state: 'visible' });
    await item.click();
    await expect(form.locator('.producto-item')).toHaveCount(1);

    let numeroPedido = '';
    await page.route('**/api/ventas.php', async (route) => {
      const response = await route.fetch();
      const body = await response.json();
      numeroPedido = body?.numero_pedido ?? '';
      await route.fulfill({ response });
    });
    await form.getByRole('button', { name: 'Agendar Pedido' }).click();
    await expect.poll(() => numeroPedido).not.toBe('');

    await page.goto('views/reservations.php');
    const fila = page.locator('table.striped tr').filter({ hasText: numeroPedido });
    await expect(fila).toBeVisible();
    await expect(fila.locator('td').nth(4)).toHaveText('pendiente_pago');

    await fila.getByRole('link', { name: 'Ver' }).click();
    const modal = page.locator('.modal.open');
    await expect(modal).toBeVisible();
    await expect(modal.getByText(numeroPedido)).toBeVisible();
  });
});
