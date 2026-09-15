import { test, expect } from './fixtures';
import { loginAsStaff } from './helpers';

test.describe('Admin: alta de sucursal', () => {
  test('crear una sucursal nueva la muestra en la tabla', async ({ page }) => {
    await loginAsStaff(page, 'admin');
    await page.goto('views/manage_branches.php');

    const nombre = `Playwright Sucursal ${Date.now()}`;
    await page.locator('#nombre').fill(nombre);
    await page.locator('#direccion').fill('Av. de Prueba 123, Guadalajara, Jal.');

    await page.getByRole('button', { name: 'Crear Sucursal' }).click();

    await expect(page.locator('table.striped').getByText(nombre)).toBeVisible();
  });

  test('editar una sucursal existente actualiza su nombre y direccion', async ({ page }) => {
    await loginAsStaff(page, 'admin');
    await page.goto('views/manage_branches.php');

    const nombreOriginal = `Playwright Sucursal Editar ${Date.now()}`;
    await page.locator('#nombre').fill(nombreOriginal);
    await page.locator('#direccion').fill('Direccion original 100');
    await page.getByRole('button', { name: 'Crear Sucursal' }).click();
    await expect(page.locator('table.striped').getByText(nombreOriginal)).toBeVisible();

    const fila = page.locator('table.striped tr').filter({ hasText: nombreOriginal });
    await fila.getByTitle('Editar sucursal').click();
    await expect(page).toHaveURL(/manage_branches\.php\?editar=\d+/);

    const nombreEditado = `${nombreOriginal} Editado`;
    await expect(page.locator('#nombre')).toHaveValue(nombreOriginal);
    await page.locator('#nombre').fill(nombreEditado);
    await page.locator('#direccion').fill('Direccion actualizada 200');
    await page.getByRole('button', { name: 'Guardar Cambios' }).click();

    await expect(page.getByText('Sucursal actualizada correctamente.')).toBeVisible();
    await expect(page.locator('table.striped').getByText(nombreEditado)).toBeVisible();
    await expect(page.locator('table.striped').getByText(nombreOriginal, { exact: true })).toHaveCount(0);
  });

  test('cambiar el estado de una sucursal la marca inactiva y de vuelta a activa', async ({ page }) => {
    await loginAsStaff(page, 'admin');
    await page.goto('views/manage_branches.php');

    const nombre = `Playwright Sucursal Estado ${Date.now()}`;
    await page.locator('#nombre').fill(nombre);
    await page.getByRole('button', { name: 'Crear Sucursal' }).click();
    await expect(page.locator('table.striped').getByText(nombre)).toBeVisible();

    let fila = page.locator('table.striped tr').filter({ hasText: nombre });
    await expect(fila.locator('.badge')).toHaveText('ACTIVO');

    await fila.getByTitle('Cambiar estado').click();
    await expect(page.getByText('Estado de sucursal actualizado.')).toBeVisible();
    fila = page.locator('table.striped tr').filter({ hasText: nombre });
    await expect(fila.locator('.badge')).toHaveText('INACTIVO');

    await fila.getByTitle('Cambiar estado').click();
    await expect(page.getByText('Estado de sucursal actualizado.')).toBeVisible();
    fila = page.locator('table.striped tr').filter({ hasText: nombre });
    await expect(fila.locator('.badge')).toHaveText('ACTIVO');
  });
});
