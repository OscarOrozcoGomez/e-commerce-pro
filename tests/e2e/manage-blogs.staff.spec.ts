import { test, expect } from './fixtures';
import { loginAsStaff } from './helpers';

// manage_blogs.php requiere el permiso 'gestionar_blogs', que en la BD solo tienen
// admin y encargado (verificado via rol_permisos) -- vendedor y repartidor deben
// quedar bloqueados.
test.describe('Gestionar Blogs (manage_blogs.php)', () => {
  test('un vendedor no puede gestionar blogs', async ({ page }) => {
    await loginAsStaff(page, 'vendedor');
    await page.goto('views/manage_blogs.php');
    await expect(page).toHaveURL(/views\/dashboard\.php/);
  });

  test('un repartidor no puede gestionar blogs', async ({ page }) => {
    await loginAsStaff(page, 'repartidor');
    await page.goto('views/manage_blogs.php');
    await expect(page).toHaveURL(/views\/dashboard\.php/);
  });

  test('un encargado tambien puede administrar el blog', async ({ page }) => {
    await loginAsStaff(page, 'encargado');
    await page.goto('views/manage_blogs.php');
    await expect(page.locator('span.card-title', { hasText: 'Escribir Artículo' })).toBeVisible();
  });

  test('crear un articulo nuevo lo muestra publicado en la tabla', async ({ page }) => {
    await loginAsStaff(page, 'admin');
    await page.goto('views/manage_blogs.php');

    const titulo = `Playwright Blog ${Date.now()}`;
    await page.locator('#titulo').fill(titulo);
    await page.locator('#extracto').fill('Extracto de prueba generado por Playwright.');
    await page.getByRole('button', { name: 'GUARDAR POST' }).click();

    await expect(page.getByText('Operacion exitosa.')).toBeVisible();
    const fila = page.locator('table.striped tr').filter({ hasText: titulo });
    await expect(fila).toBeVisible();
    await expect(fila.locator('.badge')).toHaveText('publicado');
  });

  test('crear dos articulos con el mismo titulo (mismo slug) falla con un mensaje claro', async ({ page }) => {
    await loginAsStaff(page, 'admin');
    await page.goto('views/manage_blogs.php');

    const titulo = `Playwright Blog Duplicado ${Date.now()}`;
    await page.locator('#titulo').fill(titulo);
    await page.getByRole('button', { name: 'GUARDAR POST' }).click();
    await expect(page.getByText('Operacion exitosa.')).toBeVisible();

    // El slug se autogenera a partir del titulo (mismo titulo -> mismo slug ->
    // choca con el UNIQUE KEY uq_blogs_slug).
    await page.locator('#titulo').fill(titulo);
    await page.getByRole('button', { name: 'GUARDAR POST' }).click();
    await expect(page.getByText('Ya existe un articulo con ese slug. Usa otro.')).toBeVisible();
  });

  test('editar un articulo existente actualiza su titulo sin duplicar la fila', async ({ page }) => {
    await loginAsStaff(page, 'admin');
    await page.goto('views/manage_blogs.php');

    const tituloOriginal = `Playwright Blog Editar ${Date.now()}`;
    await page.locator('#titulo').fill(tituloOriginal);
    await page.getByRole('button', { name: 'GUARDAR POST' }).click();
    await expect(page.getByText('Operacion exitosa.')).toBeVisible();

    const filaOriginal = page.locator('table.striped tr').filter({ hasText: tituloOriginal });
    await filaOriginal.locator('button.blue').click();

    await expect(page.locator('#form-title')).toHaveText('Editando Artículo');
    await expect(page.locator('#titulo')).toHaveValue(tituloOriginal);

    const tituloEditado = `${tituloOriginal} (editado)`;
    await page.locator('#titulo').fill(tituloEditado);
    await page.locator('#estado').selectOption('borrador');
    await page.getByRole('button', { name: 'GUARDAR POST' }).click();

    await expect(page.getByText('Operacion exitosa.')).toBeVisible();
    await expect(page.locator('table.striped').getByText(tituloOriginal, { exact: true })).toHaveCount(0);
    const filaEditada = page.locator('table.striped tr').filter({ hasText: tituloEditado });
    await expect(filaEditada).toBeVisible();
    await expect(filaEditada.locator('.badge')).toHaveText('borrador');
  });

  test('eliminar un articulo lo quita de la tabla', async ({ page }) => {
    await loginAsStaff(page, 'admin');
    await page.goto('views/manage_blogs.php');

    const titulo = `Playwright Blog Eliminar ${Date.now()}`;
    await page.locator('#titulo').fill(titulo);
    await page.getByRole('button', { name: 'GUARDAR POST' }).click();
    await expect(page.getByText('Operacion exitosa.')).toBeVisible();

    const fila = page.locator('table.striped tr').filter({ hasText: titulo });
    page.once('dialog', (dialog) => dialog.accept());
    await fila.locator('button.red').click();

    await expect(page.getByText('Artículo eliminado.')).toBeVisible();
    await expect(page.locator('table.striped').getByText(titulo)).toHaveCount(0);
  });
});
