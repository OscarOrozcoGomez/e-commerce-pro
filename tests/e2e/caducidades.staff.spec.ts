import { test, expect } from './fixtures';
import { loginAsStaff, fechaFutura } from './helpers';

// views/caducidades.php es el tablero cruzado (todos los productos) de Control de
// Caducidades -- filtros (severidad, almacen, categoria, busqueda, solo con excedente) sobre
// la misma proyeccion que ya cubre LoteCaducidadUtilsTest (unit), y las mismas acciones
// (marcar atendida / retirar) que ya prueba tests/e2e/lotes-caducidad.staff.spec.ts sobre el
// panel de products.php -- aqui se prueba el tablero en si, no la API otra vez.
//
// Un producto recien creado (sin ninguna venta historica) cae en la severidad "sin_historico"
// de forma determinista (loteVelocidadVentas() solo incluye productos con al menos una venta),
// asi que no hace falta fabricar historial de ventas para tener un caso reproducible.

async function crearProductoConLote(page: import('@playwright/test').Page, nombre: string, codigoLote: string): Promise<void> {
  await page.goto('views/products.php');
  await page.locator('#nombre').fill(nombre);
  await page.locator('#precio_costo').fill('10.00');
  await page.locator('#precio_venta').fill('19.99');
  await page.locator('#btn-submit').click();
  await expect(page.locator('#tabla-productos-body').getByText(nombre)).toBeVisible();

  const fila = page.locator('#tabla-productos-body tr').filter({ hasText: nombre });
  await fila.locator('button.blue').click();
  await expect(page.locator('#lotes-producto-wrap')).toBeVisible();

  await page.locator('#lp-codigo').fill(codigoLote);
  await page.locator('#lp-fecha').fill(fechaFutura(20));
  await page.locator('#lp-cantidad').fill('5');
  await page.locator('#btn-agregar-lote').click();
  await expect(page.getByText('Lote guardado')).toBeVisible();
}

async function eliminarProducto(page: import('@playwright/test').Page, nombre: string): Promise<void> {
  await page.goto('views/products.php');
  const fila = page.locator('#tabla-productos-body tr').filter({ hasText: nombre });
  page.once('dialog', (dialog) => dialog.accept());
  await fila.locator('button.red').click();
  await expect(page.locator('#tabla-productos-body').getByText(nombre)).toHaveCount(0);
}

test.describe('Control de Caducidades: tablero cruzado (caducidades.php)', () => {
  test('un vendedor no puede acceder', async ({ page }) => {
    await loginAsStaff(page, 'vendedor');
    await page.goto('views/caducidades.php');
    await expect(page).toHaveURL(/views\/dashboard\.php/);
  });

  test('un repartidor no puede acceder', async ({ page }) => {
    await loginAsStaff(page, 'repartidor');
    await page.goto('views/caducidades.php');
    await expect(page).toHaveURL(/views\/dashboard\.php/);
  });

  test('un encargado sí puede acceder y ve el resumen por severidad', async ({ page }) => {
    await loginAsStaff(page, 'encargado');
    await page.goto('views/caducidades.php');
    await expect(page.getByText('Ver todos:')).toBeVisible();
    // Materialize esconde el <select> nativo detras de su propio dropdown -- basta con
    // que este adjunto al DOM, no que sea visualmente "visible".
    await expect(page.locator('select[name="severidad"]')).toBeAttached();
  });

  test('un lote real de un producto sin historial aparece como "Sin histórico" y se puede buscar y retirar', async ({ page }) => {
    await loginAsStaff(page, 'admin');

    const nombre = 'Playwright Caducidades ' + Date.now();
    const codigoLote = 'LOTE-CAD-' + Date.now();
    await crearProductoConLote(page, nombre, codigoLote);

    // Buscar por el nombre del producto (el filtro "q" matchea producto/lote/sku).
    await page.goto(`views/caducidades.php?q=${encodeURIComponent(nombre)}`);
    const fila = page.locator('table tbody tr').filter({ hasText: nombre });
    await expect(fila).toBeVisible();
    await expect(fila).toContainText(codigoLote);
    await expect(fila.getByText('Sin histórico')).toBeVisible();

    // El chip de severidad "sin_historico" no tiene chip propio en la fila de arriba (solo
    // caducado/critico/urgente/planificar/vigilar tienen chip dedicado), pero el filtro por
    // GET si funciona para cualquier clave de $SEV -- se confirma navegando directo.
    await page.goto(`views/caducidades.php?severidad=sin_historico&q=${encodeURIComponent(nombre)}`);
    await expect(page.locator('table tbody tr').filter({ hasText: nombre })).toBeVisible();

    // Retirar desde este tablero (confirm nativo) -- ya no aparece en ningun filtro.
    page.once('dialog', (dialog) => dialog.accept());
    await page.locator('table tbody tr').filter({ hasText: nombre }).locator('a[title="Retirar lote"]').click();
    await expect(page.getByText('Estado actualizado')).toBeVisible();

    await page.goto(`views/caducidades.php?q=${encodeURIComponent(nombre)}`);
    await expect(page.getByText('No hay lotes que coincidan con el filtro.')).toBeVisible();

    await eliminarProducto(page, nombre);
  });

  test('un filtro sin resultados muestra el mensaje vacío en vez de una tabla', async ({ page }) => {
    await loginAsStaff(page, 'admin');
    await page.goto('views/caducidades.php?q=' + encodeURIComponent('playwright_busqueda_sin_resultados_' + Date.now()));
    await expect(page.getByText('No hay lotes que coincidan con el filtro.')).toBeVisible();
    await expect(page.locator('table')).toHaveCount(0);
  });
});
