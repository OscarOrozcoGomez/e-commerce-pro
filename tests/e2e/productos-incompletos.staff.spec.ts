import { test, expect } from './fixtures';
import { loginAsStaff, E2E_PRODUCTO_INCOMPLETO_NOMBRE } from './helpers';

// views/productos_incompletos.php es de solo lectura (sin AJAX, sin formularios de escritura):
// lista productos vendibles con algun dato clave faltante (precio venta/costo, SKU, codigo de
// barras, inventario base), con chips de filtro por GET y un boton "Completar" que manda a
// products.php. Esta instancia local ya trae ~95 productos reales con algo faltante -- los
// asserts de conteo exacto se evitan a proposito; se usa E2E_PRODUCTO_INCOMPLETO_NOMBRE
// (sembrado sin NINGUNO de los 5 datos) para verificar el renderizado con certeza.

test.describe('Productos sin configuración (productos_incompletos.php)', () => {
  test('un vendedor no puede acceder', async ({ page }) => {
    await loginAsStaff(page, 'vendedor');
    await page.goto('views/productos_incompletos.php');
    await expect(page).toHaveURL(/views\/dashboard\.php/);
  });

  test('un repartidor no puede acceder', async ({ page }) => {
    await loginAsStaff(page, 'repartidor');
    await page.goto('views/productos_incompletos.php');
    await expect(page).toHaveURL(/views\/dashboard\.php/);
  });

  test('un encargado sí puede acceder (tiene gestionar_productos)', async ({ page }) => {
    await loginAsStaff(page, 'encargado');
    await page.goto('views/productos_incompletos.php');
    await expect(page.getByText('Resumen de pendientes')).toBeVisible();
  });

  test('el chip "Ver todos" está activo por defecto, sin filtro', async ({ page }) => {
    await loginAsStaff(page, 'admin');
    await page.goto('views/productos_incompletos.php');

    await expect(page.locator('a.pi-chip.pi-chip-active')).toHaveText(/Ver todos/);
    await expect(page.getByText('Quitar filtro')).toHaveCount(0);
  });

  test('un producto sin ningún dato clave aparece con sus 5 banderas y el botón Completar', async ({ page }) => {
    await loginAsStaff(page, 'admin');
    await page.goto('views/productos_incompletos.php');

    const fila = page.locator('.pi-table tbody tr').filter({ hasText: E2E_PRODUCTO_INCOMPLETO_NOMBRE });
    await expect(fila).toBeVisible();

    await expect(fila.locator('.pi-badge')).toHaveCount(5);
    await expect(fila.getByText('Precio de venta')).toBeVisible();
    await expect(fila.getByText('Precio de costo')).toBeVisible();
    await expect(fila.getByText('SKU', { exact: true })).toBeVisible();
    await expect(fila.getByText('Codigo de barras')).toBeVisible();
    await expect(fila.getByText('Inventario base')).toBeVisible();

    const idProducto = await fila.locator('td').first().locator('small').textContent();
    const match = idProducto?.match(/ID (\d+)/);
    expect(match).toBeTruthy();

    const boton = fila.getByRole('link', { name: /Completar/ });
    await expect(boton).toHaveAttribute('href', new RegExp(`products\\.php\\?id_producto=${match![1]}$`));
  });

  test('filtrar por "Sin SKU" solo muestra productos con esa bandera, y se puede quitar el filtro', async ({ page }) => {
    await loginAsStaff(page, 'admin');
    await page.goto('views/productos_incompletos.php?falta=sku');

    await expect(page.locator('a.pi-chip.pi-chip-active')).toHaveText(/Sin SKU/);
    await expect(page.getByText('Mostrando solo los que les falta:')).toBeVisible();

    // El producto dedicado (le falta SKU tambien) sigue apareciendo bajo este filtro.
    const fila = page.locator('.pi-table tbody tr').filter({ hasText: E2E_PRODUCTO_INCOMPLETO_NOMBRE });
    await expect(fila).toBeVisible();

    // Ninguna fila visible bajo este filtro deja de tener la bandera "SKU".
    const filasVisibles = page.locator('.pi-table tbody tr');
    const total = await filasVisibles.count();
    for (let i = 0; i < total; i++) {
      await expect(filasVisibles.nth(i).getByText('SKU', { exact: true })).toBeVisible();
    }

    await page.getByText('Quitar filtro').click();
    await expect(page).toHaveURL(/productos_incompletos\.php\?$|productos_incompletos\.php$/);
    await expect(page.locator('a.pi-chip.pi-chip-active')).toHaveText(/Ver todos/);
  });
});
