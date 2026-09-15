import { test, expect } from './fixtures';
import { loginAsStaff, E2E_MAYOREO_PRODUCT_NAME } from './helpers';

// views/purchase_orders.php, pestaña "Cargar Pedido" (#tab-importar): "Importar pedido de
// mayoreo (B Life)" -- el usuario corre `node scripts/mayoreo_pedidos.mjs` a mano, pega el
// JSON resultante, y api/purchase_order_mayoreo_preview.php lo mapea contra el catálogo local
// (sin llamar a ningún servicio externo: es solo parseo + match de texto, ver
// core/purchase_order_utils.php::purchaseOrderBuildMayoreoPreview -- por eso es seguro
// automatizarlo, a diferencia del scraping real de mayoreo.blife.mx que hacen los .mjs). Cubre
// solo esta forma de import (JSON de mayoreo); la otra pestaña de la misma tarjeta ("Cargar
// pedido de proveedor", texto de correo / OCR local con Tesseract.js) no se toca aquí.

function pedidoMayoreo(overrides: { nombre?: string; cantidad?: number; precio_unitario?: number } = {}) {
  return JSON.stringify({
    numero: 'BLM-PW-' + Date.now(),
    fecha_compra: 'Septiembre 01, 2026',
    items: [
      {
        nombre: overrides.nombre ?? E2E_MAYOREO_PRODUCT_NAME,
        presentacion: '',
        cantidad: overrides.cantidad ?? 2,
        precio_unitario: overrides.precio_unitario ?? 15.5,
      },
    ],
  });
}

async function goToImportarMayoreo(page: import('@playwright/test').Page): Promise<void> {
  await page.goto('views/purchase_orders.php');
  await page.locator('#po-tabs a[href="#tab-importar"]').click();
}

test.describe('Importar pedido de mayoreo B Life (purchase_orders.php)', () => {
  test('un vendedor no puede acceder a la vista de compras (ni a este import)', async ({ page }) => {
    await loginAsStaff(page, 'vendedor');
    await page.goto('views/purchase_orders.php');
    await expect(page).toHaveURL(/views\/dashboard\.php/);
  });

  test('un JSON que no es un pedido válido muestra un error y no arma la tabla de vista previa', async ({ page }) => {
    await loginAsStaff(page, 'encargado');
    await goToImportarMayoreo(page);

    await page.locator('#may-json').fill('{"esto": "no es un pedido"}');
    await page.locator('button', { hasText: 'Analizar pedido de mayoreo' }).click();

    await expect(page.getByText('El JSON no trae renglones.')).toBeVisible();
    await expect(page.locator('#may-commit-wrapper')).toBeHidden();
  });

  test('analiza el JSON, empareja el producto sembrado y registra la orden "por llegar" sin tocar inventario', async ({ page }) => {
    await loginAsStaff(page, 'encargado');
    await goToImportarMayoreo(page);

    await page.locator('#may-json').fill(pedidoMayoreo({ cantidad: 2, precio_unitario: 15.5 }));
    await page.locator('button', { hasText: 'Analizar pedido de mayoreo' }).click();

    const fila = page.locator('#may-review tbody tr').first();
    await expect(fila).toBeVisible();
    await expect(fila).toContainText(E2E_MAYOREO_PRODUCT_NAME);
    // Cantidad detectada y precio de mayoreo se muestran tal cual vinieron en el JSON.
    await expect(fila.locator('.may-qty')).toHaveValue('2');
    await expect(fila).toContainText('$15.50');
    // El producto sembrado (nombre exacto) debe ganar como sugerido -> checkbox "Pedir" marcado solo.
    await expect(fila.locator('.may-incluir')).toBeChecked();
    await expect(fila.locator('.may-prod')).not.toHaveValue('0');

    await page.locator('#may-commit-wrapper button', { hasText: 'Registrar orden' }).click();
    await expect(page.locator('.swal2-confirm')).toBeVisible();
    await page.locator('.swal2-confirm').click();

    await expect(page.getByText(/Orden de compra generada|Orden registrada/)).toBeVisible();

    // El formulario se limpia tras registrar (esto corre síncrono, antes del setTimeout de
    // abajo, así que ya se puede comprobar aquí).
    await expect(page.locator('#may-json')).toHaveValue('');
    await expect(page.locator('#may-commit-wrapper')).toBeHidden();

    // registrarOrdenPorLlegar() hace location.reload() 1200ms después del toast (refresca las
    // 3 pestañas) -- hay que esperar esa navegación real antes de seguir, o el clic de abajo
    // se pierde a medio camino y la página vuelve a "Lista de Compra" (tab activo por defecto).
    await page.waitForEvent('load');

    // La orden aparece "abierta" en "Órdenes Abiertas" (purchase_orders_open.php solo lista
    // borrador/enviada/parcial -- si ya estuviera surtida no aparecería aquí en absoluto), con
    // la cantidad solicitada tal cual vino del JSON. "Por llegar" solo registra la orden: el
    // inventario real no se toca hasta que se surta aparte desde esta misma pestaña.
    await page.locator('#po-tabs a[href="#tab-ordenes"]').click();
    const card = page.locator('.po-orden-card').filter({ hasText: E2E_MAYOREO_PRODUCT_NAME });
    await expect(card).toBeVisible({ timeout: 15000 });
    await expect(card.locator('tbody tr').filter({ hasText: E2E_MAYOREO_PRODUCT_NAME })).toContainText('2');
  });

  test('un producto que no existe en el catálogo se reporta como advertencia "sin coincidencia"', async ({ page }) => {
    await loginAsStaff(page, 'encargado');
    await goToImportarMayoreo(page);

    // Deliberadamente SIN el prefijo "Playwright " ni la palabra "Producto": el match usa
    // solapamiento de palabras (purchaseOrderTextScore), y esta BD de desarrollo acumula
    // productos desechables "Playwright Producto ..." de otras specs que nunca se limpian
    // (ver el comentario de housekeeping en scripts/seed_e2e_test_data.php) -- con esas
    // palabras, el candidato más parecido de esa basura supera el umbral de 45% solo por el
    // prefijo compartido, aunque el resto del nombre no tenga nada que ver.
    const nombreInventado = 'Zzqx874 Vbnmqwer Asdfgh ' + Date.now();
    await page.locator('#may-json').fill(pedidoMayoreo({ nombre: nombreInventado, cantidad: 1, precio_unitario: 99 }));
    await page.locator('button', { hasText: 'Analizar pedido de mayoreo' }).click();

    await expect(page.getByText('Sin coincidencia en el catálogo:')).toBeVisible();
    await expect(page.locator('#may-review')).toContainText(nombreInventado);

    const fila = page.locator('#may-review tbody tr').filter({ hasText: nombreInventado });
    await expect(fila).toContainText('sin producto — créalo');
    // Sin producto sugerido, el renglón no se marca para pedir automáticamente.
    await expect(fila.locator('.may-incluir')).not.toBeChecked();
  });
});
