import { test, expect } from './fixtures';
import { loginAsStaff } from './helpers';

// Rango de fechas fijo y muy en el pasado: garantiza "sin ventas" de forma determinista, sin
// depender de que la BD local no tenga ventas reales en el mes actual.
const RANGO_SIN_VENTAS = 'fecha_inicio=2000-01-01&fecha_fin=2000-01-31';

test.describe('Reportes del Sistema (reportes.php)', () => {
  test('un vendedor no puede ver los reportes', async ({ page }) => {
    await loginAsStaff(page, 'vendedor');
    await page.goto('views/reportes.php');
    await expect(page).toHaveURL(/views\/dashboard\.php/);
  });

  test('un vendedor no puede exportar el reporte en CSV ni PDF directamente', async ({ page }) => {
    await loginAsStaff(page, 'vendedor');

    const csvRes = await page.request.get('views/export_reports.php', { maxRedirects: 0 });
    expect(csvRes.status()).toBe(302);
    expect(csvRes.headers()['location']).toContain('dashboard.php');

    const pdfRes = await page.request.get('views/export_reports_pdf.php', { maxRedirects: 0 });
    expect(pdfRes.status()).toBe(302);
    expect(pdfRes.headers()['location']).toContain('dashboard.php');
  });

  test('un encargado ve el reporte de ventas con el resumen', async ({ page }) => {
    await loginAsStaff(page, 'encargado');
    await page.goto('views/reportes.php');
    await expect(page.locator('h4', { hasText: 'Reportes del Sistema' })).toBeVisible();
    await expect(page.getByText('Total Ventas')).toBeVisible();
    await expect(page.getByText('Monto Total')).toBeVisible();
    await expect(page.getByText('Promedio Venta')).toBeVisible();
  });

  test('un rango de fechas sin ventas muestra el mensaje vacio y totales en cero', async ({ page }) => {
    await loginAsStaff(page, 'encargado');
    await page.goto(`views/reportes.php?${RANGO_SIN_VENTAS}`);
    await expect(page.getByText('No hay ventas en el período especificado.')).toBeVisible();
    await expect(page.locator('.display-metric').first()).toHaveText('0');
    await expect(page.getByText('$0.00').first()).toBeVisible();
  });

  test('exportar en CSV devuelve un archivo descargable con encabezados correctos', async ({ page }) => {
    await loginAsStaff(page, 'encargado');
    const res = await page.request.get(`views/export_reports.php?${RANGO_SIN_VENTAS}`);
    expect(res.status()).toBe(200);
    expect(res.headers()['content-type']).toContain('text/csv');
    expect(res.headers()['content-disposition']).toContain('attachment');
    const body = await res.text();
    expect(body).toContain('Número Pedido');
  });

  test('exportar en PDF devuelve un documento PDF valido', async ({ page }) => {
    await loginAsStaff(page, 'encargado');
    const res = await page.request.get(`views/export_reports_pdf.php?${RANGO_SIN_VENTAS}`);
    expect(res.status()).toBe(200);
    expect(res.headers()['content-type']).toContain('application/pdf');
    const body = await res.body();
    expect(body.subarray(0, 4).toString('latin1')).toBe('%PDF');
  });
});
