import { test, expect } from './fixtures';
import { loginAsStaff } from './helpers';

test.describe('Logs de Actividad (activity_logs.php)', () => {
  test('un encargado no puede ver los logs de actividad', { tag: '@smoke' }, async ({ page }) => {
    await loginAsStaff(page, 'encargado');
    await page.goto('views/activity_logs.php');
    await expect(page).toHaveURL(/views\/dashboard\.php/);
  });

  test('un admin ve el log de actividad', async ({ page }) => {
    await loginAsStaff(page, 'admin');
    await page.goto('views/activity_logs.php');
    await expect(page.locator('h4', { hasText: 'Log de Actividad de Usuarios' })).toBeVisible();
  });

  test('un rango de fechas sin registros muestra el mensaje vacio', async ({ page }) => {
    await loginAsStaff(page, 'admin');
    await page.goto('views/activity_logs.php?fecha_inicio=2000-01-01&fecha_fin=2000-01-31');
    await expect(page.getByText('No se encontraron registros para los filtros seleccionados.')).toBeVisible();
  });

  test('filtrar por tipo "Visitas" solo muestra registros de visita', async ({ page }) => {
    await loginAsStaff(page, 'admin');
    await page.goto('views/activity_logs.php?tipo=visit');
    const badges = page.locator('.collapsible-body .badge');
    const count = await badges.count();
    // Sin registros de visita es un resultado valido (mensaje vacio); si hay filas, todas
    // deben decir VISIT (ninguna CLICK debe colarse).
    if (count > 0) {
      await expect(page.locator('.collapsible-body .badge', { hasText: 'CLICK' })).toHaveCount(0);
      await expect(badges.first()).toHaveText('VISIT');
    } else {
      await expect(page.getByText('No se encontraron registros para los filtros seleccionados.')).toBeVisible();
    }
  });
});
