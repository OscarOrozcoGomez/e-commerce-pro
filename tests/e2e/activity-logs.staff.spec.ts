import { test, expect } from './fixtures';
import { loginAsStaff } from './helpers';

// views/activity_logs.php tiene DOS pestañas desde la auditoría completa:
//  - "Movimientos (auditoría)": logs_auditoria, quién hizo qué (vista por defecto). Su
//    cobertura a fondo vive en audit-movimientos.staff.spec.ts.
//  - "Navegación (visitas y clics)": logs_actividad, lo que ya existía (?vista=navegacion).
// Este spec cubre el acceso, el marco de la página y la pestaña de Navegación.
test.describe('Logs de Actividad (activity_logs.php)', () => {
  test('un encargado no puede ver los logs de actividad', { tag: '@smoke' }, async ({ page }) => {
    await loginAsStaff(page, 'encargado');
    await page.goto('views/activity_logs.php');
    await expect(page).toHaveURL(/views\/dashboard\.php/);
  });

  test('un admin ve el log de actividad y abre por defecto en Movimientos', async ({ page }) => {
    await loginAsStaff(page, 'admin');
    await page.goto('views/activity_logs.php');
    await expect(page.locator('h4', { hasText: 'Log de Actividad' })).toBeVisible();
    await expect(page.locator('.log-tab.activa')).toContainText('Movimientos');
    await expect(page.locator('.log-tab')).toHaveCount(2);
  });

  test('un rango de fechas sin registros muestra el mensaje vacio en Movimientos', async ({ page }) => {
    await loginAsStaff(page, 'admin');
    await page.goto('views/activity_logs.php?fecha_inicio=2000-01-01&fecha_fin=2000-01-31');
    await expect(page.getByText('No se encontraron movimientos para los filtros seleccionados.')).toBeVisible();
  });

  test('un rango de fechas sin registros muestra el mensaje vacio en Navegación', async ({ page }) => {
    await loginAsStaff(page, 'admin');
    await page.goto('views/activity_logs.php?vista=navegacion&fecha_inicio=2000-01-01&fecha_fin=2000-01-31');
    await expect(page.locator('.log-tab.activa')).toContainText('Navegación');
    await expect(page.getByText('No se encontraron registros para los filtros seleccionados.')).toBeVisible();
  });

  test('filtrar por tipo "Visitas" en Navegación solo muestra registros de visita', async ({ page }) => {
    await loginAsStaff(page, 'admin');
    await page.goto('views/activity_logs.php?vista=navegacion&tipo=visit');
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

  test('cambiar de pestaña conserva el acceso y muestra el texto de cada vista', async ({ page }) => {
    await loginAsStaff(page, 'admin');
    await page.goto('views/activity_logs.php');
    await expect(page.getByText('Quién hizo qué, cuándo y desde dónde')).toBeVisible();

    await page.locator('.log-tab', { hasText: 'Navegación' }).click();
    await expect(page).toHaveURL(/vista=navegacion/);
    await expect(page.getByText('Seguimiento detallado de clics y visitas')).toBeVisible();

    await page.locator('.log-tab', { hasText: 'Movimientos' }).click();
    await expect(page).toHaveURL(/vista=movimientos/);
  });
});
