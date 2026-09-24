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

async function crearProductoConLote(
  page: import('@playwright/test').Page,
  nombre: string,
  codigoLote: string,
  // 400 días por defecto: con LOTE_RUNWAY_VIGILAR=450 (core/lote_caducidad_utils.php), una
  // caducidad tan lejana cae en runway "vigilar" (peor caso posible, rank 1), por debajo de
  // "sin_historico" (rank 2) en loteSeveridadPeor() -- así que la severidad final la sigue
  // decidiendo la falta de histórico de ventas, no el runway. Con algo más cercano (p.ej. 20
  // días) el runway por sí solo ya cae en "crítico" (< LOTE_RUNWAY_CRITICO=45) y GANA sobre
  // "sin_historico" sin importar que el producto nunca se haya vendido -- ver el comentario
  // de loteSeveridadPeor().
  diasCaducidad = 400
): Promise<void> {
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
  await page.locator('#lp-fecha').fill(fechaFutura(diasCaducidad));
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
    // Nota: se acota SIEMPRE a "#tab-caducidades table" (no "table tbody tr" a secas) --
    // caducidades.php también trae, en el mismo DOM, la tabla de la pestaña "Inconsistencias
    // stock/lotes", que se carga vía JS sin importar cuál pestaña esté activa (ver
    // cargarInconsistencias() en el DOMContentLoaded del propio archivo) y usa su propio
    // filtro "q" independiente. Un producto de prueba recién creado sin fila en
    // inventario_almacen (como el que arma crearProductoConLote) reporta descuadre de stock
    // ("sobrante"), así que también aparece ahí -- sin acotar, el mismo nombre matchea 2 <tr>
    // y el locator revienta en "strict mode violation".
    await page.goto(`views/caducidades.php?q=${encodeURIComponent(nombre)}`);
    const fila = page.locator('#tab-caducidades table tbody tr').filter({ hasText: nombre });
    await expect(fila).toBeVisible();
    await expect(fila).toContainText(codigoLote);
    await expect(fila.getByText('Sin histórico')).toBeVisible();

    // El chip de severidad "sin_historico" no tiene chip propio en la fila de arriba (solo
    // caducado/critico/urgente/planificar/vigilar tienen chip dedicado), pero el filtro por
    // GET si funciona para cualquier clave de $SEV -- se confirma navegando directo.
    await page.goto(`views/caducidades.php?severidad=sin_historico&q=${encodeURIComponent(nombre)}`);
    await expect(page.locator('#tab-caducidades table tbody tr').filter({ hasText: nombre })).toBeVisible();

    // Retirar desde este tablero (confirm nativo) -- ya no aparece en ningun filtro.
    page.once('dialog', (dialog) => dialog.accept());
    await page.locator('#tab-caducidades table tbody tr').filter({ hasText: nombre }).locator('a[title="Retirar lote"]').click();
    await expect(page.getByText('Estado actualizado')).toBeVisible();

    await page.goto(`views/caducidades.php?q=${encodeURIComponent(nombre)}`);
    await expect(page.getByText('No hay lotes que coincidan con el filtro.')).toBeVisible();

    await eliminarProducto(page, nombre);
  });

  test('un filtro sin resultados muestra el mensaje vacío en vez de una tabla', async ({ page }) => {
    await loginAsStaff(page, 'admin');
    await page.goto('views/caducidades.php?q=' + encodeURIComponent('playwright_busqueda_sin_resultados_' + Date.now()));
    await expect(page.getByText('No hay lotes que coincidan con el filtro.')).toBeVisible();
    // Acotado a "#tab-caducidades": la pestaña "Inconsistencias" trae su propia tabla, cargada
    // vía JS sin importar el filtro "q" de esta -- si esta BD local ya trae algún descuadre
    // real (independiente de este test), esa tabla sí existe en el DOM.
    await expect(page.locator('#tab-caducidades table')).toHaveCount(0);
  });

  // "Poner en oferta" (1 clic, botón verde "sell"): agrega el producto a la categoría Ofertas y fija precio_oferta con la
  // ESCALERA por urgencia (core/oferta_pricing.php::ofertaPrecioEscalera, PR #213), que nunca baja del piso costo+$50 ni sube
  // sobre el precio normal. Aquí el costo es 10 y el precio 19.99: el piso (60) supera el precio normal, así que la oferta se
  // queda EN el precio normal (19.99). La escalera con precios reales se prueba en caducidades-oferta.staff.spec.ts.
  test('poner en oferta cuando costo+$50 supera el precio normal deja la oferta en el precio normal (nunca lo sube) y lo refleja en products.php', async ({ page }) => {
    await loginAsStaff(page, 'admin');

    const nombre = 'Playwright Caducidades Oferta ' + Date.now();
    const codigoLote = 'LOTE-OFERTA-' + Date.now();
    // precio_costo = 10.00, precio_venta = 19.99 -> el piso (60.00) queda por encima del precio: precio_oferta = 19.99.
    await crearProductoConLote(page, nombre, codigoLote);

    await page.goto(`views/caducidades.php?q=${encodeURIComponent(nombre)}`);
    // Ver el comentario del test anterior: acotado a "#tab-caducidades" para no chocar con la
    // fila que este mismo producto (sin inventario_almacen) genera en "Inconsistencias".
    const fila = page.locator('#tab-caducidades table tbody tr').filter({ hasText: nombre });
    await expect(fila).toBeVisible();

    page.once('dialog', (dialog) => dialog.accept());
    await fila.locator('a[title^="Poner en oferta"]').click();
    await expect(page.getByText(nombre + ' en Ofertas a $19.99')).toBeVisible();

    // Se refleja en la ficha del producto (products.php), no solo en la respuesta de la API.
    await page.goto('views/products.php');
    const filaProducto = page.locator('#tabla-productos-body tr').filter({ hasText: nombre });
    await filaProducto.locator('button.blue').click();
    await expect(page.locator('#precio_oferta')).toHaveValue('19.99');

    // Un segundo clic no debe pisar el precio ya fijado (aunque aquí siga siendo el mismo
    // valor sugerido) -- el mensaje cambia a "Ya estaba en Ofertas".
    await page.goto(`views/caducidades.php?q=${encodeURIComponent(nombre)}`);
    page.once('dialog', (dialog) => dialog.accept());
    await page.locator('#tab-caducidades table tbody tr').filter({ hasText: nombre }).locator('a[title^="Poner en oferta"]').click();
    await expect(page.getByText('Ya estaba en Ofertas. Precio de oferta: $19.99')).toBeVisible();

    await eliminarProducto(page, nombre);
  });
});
