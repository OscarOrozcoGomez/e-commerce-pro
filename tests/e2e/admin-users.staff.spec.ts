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

  test('cambiar el estado de un usuario lo desactiva y lo vuelve a activar', async ({ page }) => {
    await loginAsStaff(page, 'admin');
    await page.goto('views/users.php');

    const email = `e2e-users-estado-${Date.now()}@playwright.test`;
    await page.locator('#nombre').fill('Playwright Usuario Estado');
    await page.locator('#email').fill(email);
    await page.locator('#password').fill('E2eNewUser!2026');
    await page.locator('select[name="id_rol"]').selectOption({ label: 'encargado' }, { force: true });
    await page.locator('select[name="id_almacen"]').selectOption({ label: 'Almacén Central' }, { force: true });
    await page.getByRole('button', { name: 'Crear Usuario' }).click();
    await expect(page.getByText(email).first()).toBeVisible();

    const tabla = page.locator('.users-table-wrap');
    let fila = tabla.locator('tr').filter({ hasText: email });
    await fila.getByTitle('Desactivar').click();
    await expect(page.getByText('Estado de usuario actualizado.')).toBeVisible();

    fila = tabla.locator('tr').filter({ hasText: email });
    await expect(fila.getByTitle('Activar')).toBeVisible();

    await fila.getByTitle('Activar').click();
    await expect(page.getByText('Estado de usuario actualizado.')).toBeVisible();
    fila = tabla.locator('tr').filter({ hasText: email });
    await expect(fila.getByTitle('Desactivar')).toBeVisible();
  });

  test('resetear la contraseña de un usuario genera y muestra una temporal', async ({ page }) => {
    await loginAsStaff(page, 'admin');
    await page.goto('views/users.php');

    const email = `e2e-users-reset-${Date.now()}@playwright.test`;
    await page.locator('#nombre').fill('Playwright Usuario Reset');
    await page.locator('#email').fill(email);
    await page.locator('#password').fill('E2eNewUser!2026');
    await page.locator('select[name="id_rol"]').selectOption({ label: 'vendedor' }, { force: true });
    await page.locator('select[name="id_almacen"]').selectOption({ label: 'Almacén Central' }, { force: true });
    await page.getByRole('button', { name: 'Crear Usuario' }).click();
    await expect(page.getByText(email).first()).toBeVisible();

    const fila = page.locator('.users-table-wrap tr').filter({ hasText: email });
    page.once('dialog', (dialog) => dialog.accept());
    await fila.getByTitle('Resetear contraseña').click();

    await expect(page.getByText(new RegExp(`Contraseña temporal generada para ${email}: \\S{10,}`))).toBeVisible();
  });

  test('eliminar un usuario lo quita de la tabla', async ({ page }) => {
    await loginAsStaff(page, 'admin');
    await page.goto('views/users.php');

    const email = `e2e-users-eliminar-${Date.now()}@playwright.test`;
    await page.locator('#nombre').fill('Playwright Usuario Eliminar');
    await page.locator('#email').fill(email);
    await page.locator('#password').fill('E2eNewUser!2026');
    await page.locator('select[name="id_rol"]').selectOption({ label: 'vendedor' }, { force: true });
    await page.locator('select[name="id_almacen"]').selectOption({ label: 'Almacén Central' }, { force: true });
    await page.getByRole('button', { name: 'Crear Usuario' }).click();
    await expect(page.getByText(email).first()).toBeVisible();

    const fila = page.locator('.users-table-wrap tr').filter({ hasText: email });
    page.once('dialog', (dialog) => dialog.accept());
    await fila.getByTitle('Eliminar usuario').click();

    await expect(page.getByText('Usuario eliminado correctamente.')).toBeVisible();
    await expect(page.locator('.users-table-wrap').getByText(email)).toHaveCount(0);
  });

  test('crear un vendedor sin sucursal asignada falla con un mensaje claro', async ({ page }) => {
    await loginAsStaff(page, 'admin');
    await page.goto('views/users.php');

    const email = `e2e-users-sin-sucursal-${Date.now()}@playwright.test`;
    await page.locator('#nombre').fill('Playwright Vendedor Sin Sucursal');
    await page.locator('#email').fill(email);
    await page.locator('#password').fill('E2eNewUser!2026');
    await page.locator('select[name="id_rol"]').selectOption({ label: 'vendedor' }, { force: true });
    // Deja "Almacén" en "-- Sin almacén asignado --" a proposito.
    await page.getByRole('button', { name: 'Crear Usuario' }).click();

    await expect(
      page.getByText('Los vendedores y encargados deben tener una sucursal asignada obligatoriamente.')
    ).toBeVisible();
    await expect(page.getByText(email)).toHaveCount(0);
  });
});
