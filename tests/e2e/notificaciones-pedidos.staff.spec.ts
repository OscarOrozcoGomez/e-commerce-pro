import { test, expect } from './fixtures';
import { loginAsStaff } from './helpers';

test.describe('Notificaciones de Pedidos (notificaciones_pedidos.php)', () => {
  test('un encargado no puede administrar los correos de notificacion', async ({ page }) => {
    await loginAsStaff(page, 'encargado');
    await page.goto('views/notificaciones_pedidos.php');
    await expect(page).toHaveURL(/views\/dashboard\.php/);
  });

  test('agregar un correo lo muestra activo en la tabla', async ({ page }) => {
    await loginAsStaff(page, 'admin');
    await page.goto('views/notificaciones_pedidos.php');

    const correo = `playwright-notif-${Date.now()}@example.com`;
    await page.locator('#correo').fill(correo);
    await page.getByRole('button', { name: 'Agregar a la Lista' }).click();

    await expect(page.getByText('Correo agregado correctamente.')).toBeVisible();
    const fila = page.locator('table.striped tr').filter({ hasText: correo });
    await expect(fila).toBeVisible();
    await expect(fila.locator('.badge')).toHaveText('ACTIVO');
  });

  test('agregar el mismo correo dos veces falla con un mensaje claro', async ({ page }) => {
    await loginAsStaff(page, 'admin');
    await page.goto('views/notificaciones_pedidos.php');

    const correo = `playwright-notif-dup-${Date.now()}@example.com`;
    await page.locator('#correo').fill(correo);
    await page.getByRole('button', { name: 'Agregar a la Lista' }).click();
    await expect(page.getByText('Correo agregado correctamente.')).toBeVisible();

    await page.locator('#correo').fill(correo);
    await page.getByRole('button', { name: 'Agregar a la Lista' }).click();
    await expect(page.getByText('Ese correo ya está en la lista.')).toBeVisible();
  });

  test('cambiar el estado de un correo lo desactiva y lo vuelve a activar', async ({ page }) => {
    await loginAsStaff(page, 'admin');
    await page.goto('views/notificaciones_pedidos.php');

    const correo = `playwright-notif-estado-${Date.now()}@example.com`;
    await page.locator('#correo').fill(correo);
    await page.getByRole('button', { name: 'Agregar a la Lista' }).click();
    await expect(page.getByText('Correo agregado correctamente.')).toBeVisible();

    let fila = page.locator('table.striped tr').filter({ hasText: correo });
    await fila.getByTitle('Cambiar estado').click();
    await expect(page.getByText('Estado actualizado correctamente.')).toBeVisible();
    fila = page.locator('table.striped tr').filter({ hasText: correo });
    await expect(fila.locator('.badge')).toHaveText('INACTIVO');

    await fila.getByTitle('Cambiar estado').click();
    await expect(page.getByText('Estado actualizado correctamente.')).toBeVisible();
    fila = page.locator('table.striped tr').filter({ hasText: correo });
    await expect(fila.locator('.badge')).toHaveText('ACTIVO');
  });

  test('eliminar un correo lo quita de la tabla', async ({ page }) => {
    await loginAsStaff(page, 'admin');
    await page.goto('views/notificaciones_pedidos.php');

    const correo = `playwright-notif-eliminar-${Date.now()}@example.com`;
    await page.locator('#correo').fill(correo);
    await page.getByRole('button', { name: 'Agregar a la Lista' }).click();
    await expect(page.getByText('Correo agregado correctamente.')).toBeVisible();

    const fila = page.locator('table.striped tr').filter({ hasText: correo });
    page.once('dialog', (dialog) => dialog.accept());
    await fila.getByTitle('Eliminar correo').click();

    await expect(page.getByText('Correo eliminado de la lista.')).toBeVisible();
    await expect(page.locator('table.striped').getByText(correo)).toHaveCount(0);
  });
});
