import { test, expect } from './fixtures';
import { loginAsStaff } from './helpers';

test.describe('Admin: alta de usuario interno', () => {
  test('crear un usuario encargado nuevo lo muestra en la tabla', async ({ page }) => {
    await loginAsStaff(page, 'admin');
    await page.goto('views/users.php');

    const email = `e2e-users-crud-${Date.now()}@playwright.test`;
    await page.locator('#nombre').fill('Playwright Nuevo Encargado');
    await page.locator('#email').fill(email);
    await page.locator('#password').fill('E2eNewUser!2026');
    // Estos <select> son mejorados por Materialize (M.FormSelect), que oculta el
    // <select> nativo detrás de un dropdown propio; el valor se puede fijar en el
    // elemento oculto igual, solo hay que saltar el chequeo de visibilidad.
    await page.locator('select[name="id_rol"]').selectOption({ label: 'encargado' }, { force: true });
    await page.locator('select[name="id_almacen"]').selectOption({ label: 'Almacén Central' }, { force: true });

    await page.getByRole('button', { name: 'Crear Usuario' }).click();

    await expect(page.getByText(email).first()).toBeVisible();
  });
});
