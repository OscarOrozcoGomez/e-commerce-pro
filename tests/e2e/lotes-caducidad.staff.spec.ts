import { test, expect } from './fixtures';
import { loginAsStaff, fechaFutura } from './helpers';

// El panel de lotes vive DENTRO de views/products.php (no en inventario_entradas.php): al
// editar un producto existente aparece "Lotes de este producto" (#lotes-producto-wrap),
// que habla con api/lotes_manager.php por fetch. La logica de negocio (proyeccion FEFO,
// severidad, etc.) ya tiene cobertura de unit tests (LoteCaducidadUtilsTest); esto prueba
// el cableado real de la UI: agregar, ajustar (prompt nativo), marcar atendida y retirar/
// eliminar (confirm nativo) un lote, sobre un producto desechable propio de este spec.

async function crearProductoYEditar(page: import('@playwright/test').Page, nombre: string): Promise<void> {
  await page.locator('#nombre').fill(nombre);
  await page.locator('#precio_costo').fill('10.00');
  await page.locator('#precio_venta').fill('19.99');
  await page.locator('#btn-submit').click();
  await expect(page.locator('#tabla-productos-body').getByText(nombre)).toBeVisible();

  const fila = page.locator('#tabla-productos-body tr').filter({ hasText: nombre });
  await fila.locator('button.blue').click();
  await expect(page.locator('#nombre')).toHaveValue(nombre);
  await expect(page.locator('#lotes-producto-wrap')).toBeVisible();
}

test.describe('Control de Caducidades: lotes por producto (products.php)', () => {
  test('un vendedor no puede llamar a la API de lotes', async ({ page }) => {
    await loginAsStaff(page, 'vendedor');
    await page.goto('views/dashboard.php');
    const res = await page.request.post('api/lotes_manager.php', {
      form: { accion: 'guardar', id_producto: '1', codigo_lote: 'X', fecha_caducidad: fechaFutura(30), cantidad: '1' },
    });
    const json = await res.json();
    expect(json.success).toBe(false);
    expect(json.message).toContain('No autorizado');
  });

  test('agregar un lote sin código no lo guarda', async ({ page }) => {
    await loginAsStaff(page, 'admin');
    await page.goto('views/products.php');

    const nombre = 'Playwright Lote Incompleto ' + Date.now();
    await crearProductoYEditar(page, nombre);

    await page.locator('#lp-fecha').fill(fechaFutura(30));
    await page.locator('#lp-cantidad').fill('5');
    await page.locator('#btn-agregar-lote').click();

    await expect(page.getByText('Completa código, caducidad y cantidad del lote')).toBeVisible();
    await expect(page.locator('#lotes-producto-tabla')).toContainText('Sin lotes registrados todavía.');

    // Limpieza: elimina el producto de prueba.
    const fila = page.locator('#tabla-productos-body tr').filter({ hasText: nombre });
    page.once('dialog', (dialog) => dialog.accept());
    await fila.locator('button.red').click();
    await expect(page.locator('#tabla-productos-body').getByText(nombre)).toHaveCount(0);
  });

  test('ciclo completo de un lote: agregar, ajustar cantidad, marcar atendida, y retirarlo', async ({ page }) => {
    await loginAsStaff(page, 'admin');
    await page.goto('views/products.php');

    const nombre = 'Playwright Lote Ciclo ' + Date.now();
    await crearProductoYEditar(page, nombre);

    // Agregar.
    await page.locator('#lp-codigo').fill('LOTE-PW-001');
    await page.locator('#lp-fecha').fill(fechaFutura(45));
    await page.locator('#lp-cantidad').fill('20');
    await page.locator('#btn-agregar-lote').click();

    await expect(page.getByText('Lote guardado')).toBeVisible();
    const filaLote = page.locator('#lotes-producto-tabla tr').filter({ hasText: 'LOTE-PW-001' });
    await expect(filaLote).toBeVisible();

    // Ajustar cantidad: prompt() nativo, pre-cargado con la cantidad actual (20).
    page.once('dialog', (dialog) => {
      expect(dialog.type()).toBe('prompt');
      expect(dialog.defaultValue()).toBe('20');
      dialog.accept('7');
    });
    await filaLote.locator('a[title="Ajustar cantidad"]').click();
    await expect(page.getByText('Cantidad ajustada')).toBeVisible();
    await expect(page.locator('#lotes-producto-tabla tr').filter({ hasText: 'LOTE-PW-001' })).toContainText('7');

    // Marcar atendida: confirm() nativo -- Cancelar = "solo marcar como revisado" (no en oferta).
    page.once('dialog', (dialog) => dialog.dismiss());
    await page.locator('#lotes-producto-tabla tr').filter({ hasText: 'LOTE-PW-001' }).locator('a[title="Marcar en oferta / atendida"]').click();
    await expect(page.getByText('Alerta marcada como atendida')).toBeVisible();

    // Retirar: sale de la proyeccion (estado deja de ser 'activo'/'caducado').
    page.once('dialog', (dialog) => dialog.accept());
    await page.locator('#lotes-producto-tabla tr').filter({ hasText: 'LOTE-PW-001' }).locator('a[title="Retirar"]').click();
    await expect(page.getByText('Estado actualizado')).toBeVisible();
    await expect(page.locator('#lotes-producto-tabla tr').filter({ hasText: 'LOTE-PW-001' })).toHaveCount(0);
    await expect(page.locator('#lotes-producto-tabla')).toContainText('Sin lotes registrados todavía.');

    // Limpieza: elimina el producto de prueba.
    const fila = page.locator('#tabla-productos-body tr').filter({ hasText: nombre });
    page.once('dialog', (dialog) => dialog.accept());
    await fila.locator('button.red').click();
    await expect(page.locator('#tabla-productos-body').getByText(nombre)).toHaveCount(0);
  });

  test('eliminar un lote directamente lo quita de la lista', async ({ page }) => {
    await loginAsStaff(page, 'admin');
    await page.goto('views/products.php');

    const nombre = 'Playwright Lote Eliminar ' + Date.now();
    await crearProductoYEditar(page, nombre);

    await page.locator('#lp-codigo').fill('LOTE-PW-DEL');
    await page.locator('#lp-fecha').fill(fechaFutura(10));
    await page.locator('#lp-cantidad').fill('3');
    await page.locator('#btn-agregar-lote').click();
    await expect(page.getByText('Lote guardado')).toBeVisible();

    const filaLote = page.locator('#lotes-producto-tabla tr').filter({ hasText: 'LOTE-PW-DEL' });
    await expect(filaLote).toBeVisible();

    page.once('dialog', (dialog) => dialog.accept());
    await filaLote.locator('a[title="Eliminar"]').click();
    await expect(page.getByText('Lote eliminado')).toBeVisible();
    await expect(page.locator('#lotes-producto-tabla tr').filter({ hasText: 'LOTE-PW-DEL' })).toHaveCount(0);

    // Limpieza: elimina el producto de prueba.
    const fila = page.locator('#tabla-productos-body tr').filter({ hasText: nombre });
    page.once('dialog', (dialog) => dialog.accept());
    await fila.locator('button.red').click();
    await expect(page.locator('#tabla-productos-body').getByText(nombre)).toHaveCount(0);
  });
});
