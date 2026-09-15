import { test, expect } from './fixtures';
import { loginAsStaff } from './helpers';

// views/dashboard.php tiene una sección completamente distinta por rol (admin/encargado/
// repartidor/vendedor), todas alimentadas por el mismo fetch(api/dashboard_data.php) --
// admin-dashboard.staff.spec.ts solo cubre la del admin. Un error de JS o un dato faltante
// que rompa la sección de encargado/repartidor/vendedor pasaría desapercibido: muchos otros
// specs navegan por dashboard.php de pasada (como escala hacia otra vista) sin verificar su
// contenido. Aquí solo se confirma que cada rol carga sin errores de consola y sin quedarse
// pegado en el toast de error -- no se afirman valores exactos de KPIs (podrían ser
// legítimamente "0" en una BD real, indistinguible de "sigue cargando" solo por el texto).

test.describe('Dashboard por rol (dashboard.php)', () => {
  test('encargado: carga sin errores de JS ni el toast de error de estadísticas', async ({ page }) => {
    const pageErrors: string[] = [];
    page.on('pageerror', (err) => pageErrors.push(err.message));

    await loginAsStaff(page, 'encargado');
    await page.goto('views/dashboard.php');

    await expect(page.getByText('Ventas Hoy')).toBeVisible();
    await expect(page.getByText('Stock Bajo')).toBeVisible();
    await expect(page.getByText('Por Entregar')).toBeVisible();
    await expect(page.getByText('Error cargando estadísticas')).toHaveCount(0);
    expect(pageErrors).toEqual([]);
  });

  test('repartidor: carga "Mis Entregas Hoy" sin errores de JS', async ({ page }) => {
    const pageErrors: string[] = [];
    page.on('pageerror', (err) => pageErrors.push(err.message));

    await loginAsStaff(page, 'repartidor');
    await page.goto('views/dashboard.php');

    await expect(page.getByRole('heading', { name: 'Mis Entregas Hoy' })).toBeVisible();
    await expect(page.getByRole('link', { name: 'VER MIS ENTREGAS' })).toBeVisible();
    await expect(page.getByText('Error cargando estadísticas')).toHaveCount(0);
    expect(pageErrors).toEqual([]);
  });

  test('vendedor: carga sus KPIs de ventas/clientes/ingresos sin errores de JS', async ({ page }) => {
    const pageErrors: string[] = [];
    page.on('pageerror', (err) => pageErrors.push(err.message));

    await loginAsStaff(page, 'vendedor');
    await page.goto('views/dashboard.php');

    await expect(page.getByText('Ventas Hoy')).toBeVisible();
    await expect(page.getByText('Ventas Mes')).toBeVisible();
    await expect(page.getByText('Clientes Este Mes')).toBeVisible();
    await expect(page.getByText('Ingresos Mes')).toBeVisible();
    await expect(page.getByText('Error cargando estadísticas')).toHaveCount(0);
    expect(pageErrors).toEqual([]);
  });

  test('si api/dashboard_data.php falla, se avisa con un toast en vez de dejar los KPIs pegados en "Cargando"', async ({ page }) => {
    await page.route('**/api/dashboard_data.php*', (route) =>
      route.fulfill({ json: { success: false, message: 'Error simulado por Playwright' } })
    );

    await loginAsStaff(page, 'admin');
    await page.goto('views/dashboard.php');

    await expect(page.getByText('Error cargando estadísticas')).toBeVisible();
  });
});
