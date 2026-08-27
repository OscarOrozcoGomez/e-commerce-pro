import { test, expect } from './fixtures';
import { loginAsStaff } from './helpers';

test.describe('Admin: alta de cliente (walk-in)', () => {
  test('crear un cliente nuevo desde el modal lo muestra en la tabla', async ({ page }) => {
    await loginAsStaff(page, 'admin');
    await page.goto('views/manage_customers.php');

    await page.getByRole('link', { name: 'Nuevo cliente' }).click();

    const nombre = `Playwright Cliente Admin ${Date.now()}`;
    const modal = page.locator('#modal-crear-cliente');
    await modal.locator('input[name="nombre"]').fill(nombre);
    await modal.locator('input[name="telefono"]').fill('3312345678');

    await modal.getByRole('button', { name: /Crear|Guardar/ }).click();

    // manage_customers.php descifra el teléfono de cada cliente en la tabla para
    // renderizarla (500+ filas en esta BD local); bajo carga concurrente pesada
    // puede tardar más que el timeout default.
    await expect(page.locator('.manage-customers-name', { hasText: nombre })).toBeVisible({ timeout: 20000 });
  });

  test('editar un cliente existente actualiza su nombre y telefono', async ({ page }) => {
    await loginAsStaff(page, 'admin');
    await page.goto('views/manage_customers.php');

    const nombreOriginal = `Playwright Cliente Editar ${Date.now()}`;
    await page.getByRole('link', { name: 'Nuevo cliente' }).click();
    const modalCrear = page.locator('#modal-crear-cliente');
    await modalCrear.locator('input[name="nombre"]').fill(nombreOriginal);
    await modalCrear.locator('input[name="telefono"]').fill('3312345678');
    await modalCrear.getByRole('button', { name: /Crear|Guardar/ }).click();
    await expect(page.locator('.manage-customers-name', { hasText: nombreOriginal })).toBeVisible({ timeout: 20000 });

    const fila = page.locator('.manage-customers-table-wrap tr').filter({ hasText: nombreOriginal });
    await fila.getByTitle('Editar cliente').click();

    const nombreEditado = `${nombreOriginal} Editado`;
    const modalEditar = page.locator('.modal.open').filter({ hasText: 'Editar cliente' });
    await expect(modalEditar).toBeVisible();
    await modalEditar.locator('input[name="nombre"]').fill(nombreEditado);
    await modalEditar.locator('input[name="telefono"]').fill('3319876543');
    await modalEditar.getByRole('button', { name: 'Guardar cambios' }).click();

    await expect(page.getByText('Cliente actualizado correctamente.')).toBeVisible();
    await expect(page.locator('.manage-customers-table-wrap').getByText(nombreEditado)).toBeVisible();
  });

  test('bloquear y activar un cliente actualiza su estado', async ({ page }) => {
    await loginAsStaff(page, 'admin');
    await page.goto('views/manage_customers.php');

    const nombre = `Playwright Cliente Estado ${Date.now()}`;
    await page.getByRole('link', { name: 'Nuevo cliente' }).click();
    const modal = page.locator('#modal-crear-cliente');
    await modal.locator('input[name="nombre"]').fill(nombre);
    await modal.getByRole('button', { name: /Crear|Guardar/ }).click();
    await expect(page.locator('.manage-customers-name', { hasText: nombre })).toBeVisible({ timeout: 20000 });

    let fila = page.locator('.manage-customers-table-wrap tr').filter({ hasText: nombre });
    await expect(fila.locator('td.manage-customers-badge-cell').last().locator('.badge')).toHaveText('ACTIVO');

    await fila.getByTitle('Bloquear').click();
    await expect(page.getByText('Cliente bloqueado.')).toBeVisible();
    fila = page.locator('.manage-customers-table-wrap tr').filter({ hasText: nombre });
    await expect(fila.locator('td.manage-customers-badge-cell').last().locator('.badge')).toHaveText('INACTIVO');

    await fila.getByTitle('Activar').click();
    await expect(page.getByText('Cliente activado.')).toBeVisible();
    fila = page.locator('.manage-customers-table-wrap tr').filter({ hasText: nombre });
    await expect(fila.locator('td.manage-customers-badge-cell').last().locator('.badge')).toHaveText('ACTIVO');
  });

  test('eliminar un cliente lo quita de la tabla', async ({ page }) => {
    await loginAsStaff(page, 'admin');
    await page.goto('views/manage_customers.php');

    const nombre = `Playwright Cliente Eliminar ${Date.now()}`;
    await page.getByRole('link', { name: 'Nuevo cliente' }).click();
    const modal = page.locator('#modal-crear-cliente');
    await modal.locator('input[name="nombre"]').fill(nombre);
    await modal.getByRole('button', { name: /Crear|Guardar/ }).click();
    await expect(page.locator('.manage-customers-name', { hasText: nombre })).toBeVisible({ timeout: 20000 });

    const fila = page.locator('.manage-customers-table-wrap tr').filter({ hasText: nombre });
    page.once('dialog', (dialog) => dialog.accept());
    await fila.getByTitle('Eliminar cliente').click();

    await expect(page.getByText(/Cliente eliminado correctamente/)).toBeVisible();
    await expect(page.locator('.manage-customers-table-wrap').getByText(nombre)).toHaveCount(0);
  });

  test('buscar cliente por nombre filtra la tabla', async ({ page }) => {
    await loginAsStaff(page, 'admin');
    await page.goto('views/manage_customers.php');

    const nombre = `Playwright Cliente Buscar Unico ${Date.now()}`;
    await page.getByRole('link', { name: 'Nuevo cliente' }).click();
    const modal = page.locator('#modal-crear-cliente');
    await modal.locator('input[name="nombre"]').fill(nombre);
    await modal.getByRole('button', { name: /Crear|Guardar/ }).click();
    await expect(page.locator('.manage-customers-name', { hasText: nombre })).toBeVisible({ timeout: 20000 });

    // Esta BD local ya trae 700+ clientes (reales + acumulados de tests). Bajo carga
    // paralela pesada, el listener 'input' del buscador puede no estar enganchado
    // todavia cuando se llena el campo (la pagina sigue ocupada renderizando/desencriptando
    // esas 700+ filas), asi que el fill se repite dentro del reintento en vez de asumir
    // que un solo fill().  Se lee el estado real (style.display) en vez de ':visible' de
    // Playwright, que tambien es lento de reevaluar sobre esa cantidad de filas.
    await expect(async () => {
      await page.locator('#buscar-cliente').fill('');
      await page.locator('#buscar-cliente').fill(nombre);
      const estado = await page.evaluate((buscado) => {
        const filas = Array.from(document.querySelectorAll('table.manage-customers-table tbody tr[data-origen]'));
        const visibles = filas.filter((f) => (f as HTMLElement).style.display !== 'none');
        return {
          visibleCount: visibles.length,
          todasCoinciden: visibles.every((f) => (f.getAttribute('data-nombre') || '').includes(buscado)),
        };
      }, nombre.toLowerCase());
      expect(estado.visibleCount).toBe(1);
      expect(estado.todasCoinciden).toBe(true);
    }).toPass({ timeout: 30000 });
  });
});
