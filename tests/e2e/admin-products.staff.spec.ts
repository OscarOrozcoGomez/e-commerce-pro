import { test, expect } from './fixtures';
import { loginAsStaff } from './helpers';

test.describe('Admin: alta de producto', () => {
  test('agregar un producto nuevo lo muestra en la tabla', { tag: '@smoke' }, async ({ page }) => {
    await loginAsStaff(page, 'admin');
    await page.goto('views/products.php');

    const nombreProducto = `Playwright Admin Product ${Date.now()}`;
    await page.locator('#nombre').fill(nombreProducto);
    await page.locator('#precio_costo').fill('10.00');
    await page.locator('#precio_venta').fill('19.99');

    await page.locator('#btn-submit').click();

    // No fijamos el texto exacto del toast (es el message del backend); lo que
    // realmente prueba que se guardó es que la fila aparezca en la tabla.
    await expect(page.locator('.toast')).toBeVisible();
    await expect(page.locator('#tabla-productos-body').getByText(nombreProducto)).toBeVisible();
  });

  test('editar un producto existente actualiza su nombre y precio', async ({ page }) => {
    await loginAsStaff(page, 'admin');
    await page.goto('views/products.php');

    const nombreOriginal = `Playwright Producto Editar ${Date.now()}`;
    await page.locator('#nombre').fill(nombreOriginal);
    await page.locator('#precio_costo').fill('10.00');
    await page.locator('#precio_venta').fill('19.99');
    await page.locator('#btn-submit').click();
    await expect(page.locator('#tabla-productos-body').getByText(nombreOriginal)).toBeVisible();

    const fila = page.locator('#tabla-productos-body tr').filter({ hasText: nombreOriginal });
    await fila.locator('button.blue').click();

    await expect(page.locator('#nombre')).toHaveValue(nombreOriginal);
    const nombreEditado = `${nombreOriginal} Editado`;
    await page.locator('#nombre').fill(nombreEditado);
    await page.locator('#precio_venta').fill('29.99');
    await page.getByRole('button', { name: 'Guardar Cambios' }).click();

    await expect(page.locator('.toast')).toBeVisible();
    await expect(page.locator('#tabla-productos-body').getByText(nombreEditado)).toBeVisible();
    await expect(page.locator('#tabla-productos-body').getByText(nombreOriginal, { exact: true })).toHaveCount(0);
  });

  test('eliminar un producto lo quita del listado', async ({ page }) => {
    await loginAsStaff(page, 'admin');
    await page.goto('views/products.php');

    const nombre = `Playwright Producto Eliminar ${Date.now()}`;
    await page.locator('#nombre').fill(nombre);
    await page.locator('#precio_costo').fill('5.00');
    await page.locator('#precio_venta').fill('9.99');
    await page.locator('#btn-submit').click();
    await expect(page.locator('#tabla-productos-body').getByText(nombre)).toBeVisible();

    const fila = page.locator('#tabla-productos-body tr').filter({ hasText: nombre });
    page.once('dialog', (dialog) => dialog.accept());
    await fila.locator('button.red').click();

    await expect(page.getByText('Producto eliminado')).toBeVisible();
    await expect(page.locator('#tabla-productos-body').getByText(nombre)).toHaveCount(0);
  });

  test('buscar producto por nombre filtra la tabla', async ({ page }) => {
    await loginAsStaff(page, 'admin');
    await page.goto('views/products.php');

    const nombre = `Playwright Producto Buscar Unico ${Date.now()}`;
    await page.locator('#nombre').fill(nombre);
    await page.locator('#precio_costo').fill('5.00');
    await page.locator('#precio_venta').fill('9.99');
    await page.locator('#btn-submit').click();
    await expect(page.locator('#tabla-productos-body').getByText(nombre)).toBeVisible();

    // #buscar_producto solo filtra en 'keyup' (ver views/products.php), que .fill() no
    // dispara por si solo. Esta BD local tambien trae cientos de productos reales, asi
    // que se lee el estado real (style.display) en vez de confiar en ':visible'.
    const buscador = page.locator('#buscar_producto');
    await expect(async () => {
      await buscador.fill('');
      await buscador.fill(nombre);
      await buscador.dispatchEvent('keyup');
      const estado = await page.evaluate((buscado) => {
        const filas = Array.from(document.querySelectorAll('#tabla-productos-body tr'));
        const visibles = filas.filter((f) => (f as HTMLElement).style.display !== 'none');
        return {
          visibleCount: visibles.length,
          todasCoinciden: visibles.every((f) => (f.textContent || '').toLowerCase().includes(buscado)),
        };
      }, nombre.toLowerCase());
      expect(estado.visibleCount).toBe(1);
      expect(estado.todasCoinciden).toBe(true);
    }).toPass({ timeout: 15000 });
  });
});
