import { test, expect } from './fixtures';
import { E2E_UBICACION_ENTREGA, E2E_PRODUCT_NAME } from './helpers';
import { ENVIO, PRODUCTO, asignarYAbrirComoRepartidor, pedidoConEnvio } from './entregas-utils';

// Entregas > "Optimizar ruta" > "Avisar hora estimada por WhatsApp" (PR #218, commit e56e10d). El mensaje de WhatsApp de cada
// parada usa los productos y el total ACTUALES del pedido (no los de cuando se generó la ruta) y respeta "No cobrar el envío":
// al marcar la casilla, el enlace de esa parada se rehace al instante SIN recargar. La construcción del mensaje ya la cubren
// 21 casos en node (tests/js/entregas_wa_ruta.test.mjs vía EntregasWaRutaJsTest); aquí se prueba el recorrido real: la API
// arma la ruta, la pantalla pinta el enlace, y la casilla lo actualiza. No se manda nada por WhatsApp: solo se lee el enlace.

test.describe('Entregas: aviso de WhatsApp de la ruta con datos vivos', () => {
  test.describe.configure({ timeout: 180_000 });

  test('el enlace de la parada lista el producto y el total con envío; "No cobrar el envío" lo rehace sin recargar', async ({ page }) => {
    const id = await pedidoConEnvio(page, 'foranea');
    const tarjeta = await asignarYAbrirComoRepartidor(page, id);

    // Origen fijo (sucursal) para no depender de la geolocalización del navegador ni de Google.
    await page.locator('#route-origin-lat').fill(String(E2E_UBICACION_ENTREGA.local.lat));
    await page.locator('#route-origin-lng').fill(String(E2E_UBICACION_ENTREGA.local.lng));
    await page.locator('#route-start-time').fill('09:30');
    await tarjeta.locator(`.route-check[value="${id}"]`).check({ force: true });
    await page.locator('#btn-generate-route').click();

    const enlace = page.locator(`#route-result-content a.whatsapp-business-link[data-route-pedido="${id}"]`);
    await expect(enlace).toBeVisible({ timeout: 60_000 });
    const texto = () => enlace.getAttribute('data-wa-text');

    expect(await texto()).toContain(E2E_PRODUCT_NAME);
    expect(await texto()).toContain(`Total: $${(PRODUCTO + ENVIO).toFixed(2)}`);
    await expect(enlace).toHaveAttribute('data-wa-phone', /^\d{10,13}$/);

    // Sin recargar: la marca de la página debe sobrevivir.
    await page.evaluate(() => {
      (window as unknown as { __sinRecarga: boolean }).__sinRecarga = true;
    });
    const casilla = page.locator(`[data-pedido-id="${id}"] .cambio-quitar-envio`);
    await casilla.check({ force: true });
    await expect.poll(texto).toContain(`Total: $${PRODUCTO.toFixed(2)}`);
    expect(await enlace.getAttribute('href')).toContain(encodeURIComponent(`Total: $${PRODUCTO.toFixed(2)}`));

    await casilla.uncheck({ force: true });
    await expect.poll(texto).toContain(`Total: $${(PRODUCTO + ENVIO).toFixed(2)}`);
    expect(await page.evaluate(() => (window as unknown as { __sinRecarga?: boolean }).__sinRecarga === true)).toBe(true);
  });

  test('un pedido sin envío genera el enlace con el total del producto y no ofrece la casilla', async ({ page }) => {
    const id = await pedidoConEnvio(page, 'local');
    const tarjeta = await asignarYAbrirComoRepartidor(page, id);

    await page.locator('#route-origin-lat').fill(String(E2E_UBICACION_ENTREGA.local.lat));
    await page.locator('#route-origin-lng').fill(String(E2E_UBICACION_ENTREGA.local.lng));
    await page.locator('#route-start-time').fill('09:30');
    await tarjeta.locator(`.route-check[value="${id}"]`).check({ force: true });
    await page.locator('#btn-generate-route').click();

    const enlace = page.locator(`#route-result-content a.whatsapp-business-link[data-route-pedido="${id}"]`);
    await expect(enlace).toBeVisible({ timeout: 60_000 });
    expect(await enlace.getAttribute('data-wa-text')).toContain(`Total: $${PRODUCTO.toFixed(2)}`);
    await expect(tarjeta.locator('.cambio-quitar-envio')).toHaveCount(0);
  });
});
