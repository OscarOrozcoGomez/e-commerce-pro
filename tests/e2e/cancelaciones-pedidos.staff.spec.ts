import { test, expect } from './fixtures';
import { loginAsStaff } from './helpers';

test.describe('Cancelaciones de Pedidos (cancelaciones_pedidos.php)', () => {
  test('un vendedor no puede ver las cancelaciones de pedidos', async ({ page }) => {
    await loginAsStaff(page, 'vendedor');
    await page.goto('views/cancelaciones_pedidos.php');
    await expect(page).toHaveURL(/views\/dashboard\.php/);
  });

  test('un repartidor no puede ver las cancelaciones de pedidos', async ({ page }) => {
    await loginAsStaff(page, 'repartidor');
    await page.goto('views/cancelaciones_pedidos.php');
    await expect(page).toHaveURL(/views\/dashboard\.php/);
  });

  test('un encargado ve el resumen de cancelaciones', async ({ page }) => {
    await loginAsStaff(page, 'encargado');
    await page.goto('views/cancelaciones_pedidos.php');
    await expect(page.locator('h4', { hasText: 'Cancelaciones de Pedidos' })).toBeVisible();
    await expect(page.getByText('Total de Cancelaciones')).toBeVisible();
    // El total mostrado debe ser un numero valido (0 o mas), sin importar cuantas
    // cancelaciones reales existan ya en esta BD.
    const totalTexto = await page.locator('.card.red.darken-1 p').textContent();
    expect(Number((totalTexto ?? '').trim())).toBeGreaterThanOrEqual(0);
  });
});
