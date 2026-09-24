import { test, expect } from './fixtures';
import { completeDomicilioCheckout, getNumeroPedido, loginAsStaff } from './helpers';

// Integración cruzada de roles: valida el ciclo de vida completo de un pedido a
// domicilio -- cliente -> encargado (asigna repartidor) -> repartidor (sale a
// entregar -> entrega y cobra). Es el flujo de negocio más valioso de todo el
// sitio y el más fácil de romper en silencio con un cambio en cualquiera de los
// 3 roles, así que vale la pena probarlo de punta a punta aunque sea más largo.
test.describe('Pipeline de cumplimiento de pedidos (cliente -> encargado -> repartidor)', () => {
  test('un pedido a domicilio se puede asignar y entregar de punta a punta', { tag: '@smoke' }, async ({ page }) => {
    const idPedido = await completeDomicilioCheckout(page);
    const numeroPedido = await getNumeroPedido(page, idPedido);

    // --- Encargado: asigna el pedido al repartidor sembrado ---
    await loginAsStaff(page, 'encargado');
    await page.goto('views/asignar_entregas.php');

    const repartidorSelect = page.locator(`#repartidor-${idPedido}`);
    await expect(repartidorSelect).toBeVisible();
    await repartidorSelect.selectOption({ label: 'Playwright E2E Repartidor' });
    await page.locator(`#fecha-${idPedido}`).fill('2026-08-26');

    const assignCard = page.locator('.assign-delivery-card').filter({ has: repartidorSelect });
    await assignCard.getByRole('button', { name: 'Asignar' }).click();

    await page.waitForURL(/asignar_entregas\.php/);
    await expect(page.getByText('Pedido asignado correctamente.')).toBeVisible();

    // --- Repartidor: sale a entregar y luego marca entregado/cobrado ---
    await loginAsStaff(page, 'repartidor');
    await page.goto('views/entregas.php');

    const deliveryCard = page.locator(`[data-pedido-id="${idPedido}"]`);
    await expect(deliveryCard).toBeVisible();
    await expect(deliveryCard.getByText(numeroPedido)).toBeVisible();

    await deliveryCard.getByRole('button', { name: 'SALIR A ENTREGAR' }).click();
    await page.locator('#mce-btn-confirmar').click();
    await page.waitForURL(/entregas\.php/);
    await expect(page.getByText('Pedido marcado como en camino.')).toBeVisible();

    // Con el pedido ya "en camino", la tarjeta pide subir evidencia (una o varias fotos)
    // antes de ofrecer "ENTREGADO Y COBRADO" (api/entrega_publicacion.php). Elegir un archivo
    // solo lo agrega a una selección local con miniatura (.ev-thumbs) -- hay que pulsar
    // ".ev-btn-continuar" ("SUBIR N FOTO(S) Y CONTINUAR") para que se suba de verdad. El
    // input de archivo está oculto, pero setInputFiles() no necesita que sea visible. Hay DOS
    // inputs con la clase .ev-foto-input (cámara y galería/multi-foto, ver views/entregas.php)
    // -- se apunta al de cámara por id exacto para no ambigüar. Tras subir con éxito, el
    // propio JS hace location.reload() ~500ms después.
    const deliveryCardEnCamino = page.locator(`[data-pedido-id="${idPedido}"]`);
    await page.locator(`#ev-foto-input-${idPedido}`).setInputFiles('assets/img/logo.png');
    await deliveryCardEnCamino.locator('.ev-btn-continuar').click();
    await expect(deliveryCardEnCamino.locator('.ev-status')).toHaveText(/Fotos subidas/, { timeout: 10000 });

    const deliveryCardAfter = page.locator(`[data-pedido-id="${idPedido}"]`);
    await deliveryCardAfter.getByRole('button', { name: 'ENTREGADO Y COBRADO' }).click({ timeout: 15000 });
    await page.locator('#mce-btn-confirmar').click();
    await page.waitForURL(/entregas\.php/);
    await expect(page.getByText('Pedido entregado y cobrado correctamente.')).toBeVisible();
  });
});
