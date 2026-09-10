import { test, expect } from './fixtures';
import { loginAsStaff } from './helpers';

test.describe('Admin: dashboard', () => {
  test('carga con el saludo y los KPIs resueltos', { tag: '@smoke' }, async ({ page }) => {
    await loginAsStaff(page, 'admin');
    await page.goto('views/dashboard.php');

    await expect(page.getByText('Bienvenido', { exact: false })).toBeVisible();
    // Los KPIs arrancan en "Cargando..."/"0" y se resuelven vía fetch(api/dashboard_data.php).
    await expect(page.locator('#admin-branch-summary-body')).not.toContainText('Cargando', { timeout: 15000 });
  });
});
