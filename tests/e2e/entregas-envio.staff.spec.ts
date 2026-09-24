import { test, expect } from './fixtures';
import { getNumeroPedido } from './helpers';
import { consultaPedido } from './db-utils';
import { ENVIO, PRODUCTO, asignarYAbrirComoRepartidor, dinero, pedidoConEnvio } from './entregas-utils';

// Entregas: cargo de envío (commits 36a38db, 1b3d51f, 59b160f). Un pedido a domicilio FORÁNEO trae un cargo de $40
// (coordenadas a ~22 km de la sucursal). Antes la tarjeta del repartidor no lo mostraba y el total "no cuadraba"; ahora:
//   - la tarjeta muestra "Envío: $40.00";
//   - la calculadora de cambio ofrece "No cobrar el envío" (cliente cerca del periférico) y recalcula total y cambio;
//   - esa casilla se mantiene sincronizada con la del formulario de entrega ("Este pedido trae un cargo de $40 por entrega
//     fuera del periférico"), y al entregar el servidor quita el envío del total del pedido.
// Producto sembrado $99.99 + envío $40 = $139.99.

test.describe('Entregas: cargo de envío en la tarjeta y en la calculadora de cambio', () => {
  test.describe.configure({ timeout: 180_000 });

  test('un pedido foráneo muestra su envío en la tarjeta y el total cuadra (producto + envío)', async ({ page }) => {
    const id = await pedidoConEnvio(page, 'foranea');
    const numero = await getNumeroPedido(page, id); // con la sesión del cliente, antes de entrar como repartidor
    const tarjeta = await asignarYAbrirComoRepartidor(page, id);

    await expect(tarjeta).toContainText('Envío: $40.00');
    expect(await dinero(tarjeta.locator('.cambio-box-total'))).toBeCloseTo(PRODUCTO + ENVIO, 2);
    expect(consultaPedido(numero)!.costo_envio).toBe(ENVIO);
  });

  test('"No cobrar el envío" en la calculadora quita el envío del total y del cambio; al desmarcarlo vuelve', async ({ page }) => {
    const id = await pedidoConEnvio(page, 'foranea');
    const tarjeta = await asignarYAbrirComoRepartidor(page, id);
    const total = tarjeta.locator('.cambio-box-total');
    const paga = tarjeta.locator('.cambio-paga');
    const resultado = tarjeta.locator('.cambio-result');
    const casilla = tarjeta.locator('.cambio-quitar-envio');

    await expect(tarjeta).toContainText('No cobrar el envío de $40.00');
    await paga.fill('150');
    await expect(resultado).toHaveText('Cambio a devolver: $10.01'); // 150 − 139.99

    await casilla.check({ force: true });
    expect(await dinero(total)).toBeCloseTo(PRODUCTO, 2);
    await expect(resultado).toHaveText('Cambio a devolver: $50.01'); // 150 − 99.99, se recalcula solo

    await casilla.uncheck({ force: true });
    expect(await dinero(total)).toBeCloseTo(PRODUCTO + ENVIO, 2);
    await expect(resultado).toHaveText('Cambio a devolver: $10.01');
  });

  test('un pedido dentro de la ZMG (sin envío) no muestra ni el cargo ni la casilla de "No cobrar el envío"', async ({ page }) => {
    const id = await pedidoConEnvio(page, 'local');
    const tarjeta = await asignarYAbrirComoRepartidor(page, id);

    await expect(tarjeta).not.toContainText('Envío: $');
    await expect(tarjeta.locator('.cambio-quitar-envio')).toHaveCount(0);
    expect(await dinero(tarjeta.locator('.cambio-box-total'))).toBeCloseTo(PRODUCTO, 2);
  });

  test('al entregar con la casilla del formulario marcada, la calculadora se sincroniza y el servidor quita el envío del total', async ({ page }) => {
    const id = await pedidoConEnvio(page, 'foranea');
    const numero = await getNumeroPedido(page, id);
    const tarjeta = await asignarYAbrirComoRepartidor(page, id);

    await tarjeta.getByRole('button', { name: 'SALIR A ENTREGAR' }).click();
    await page.locator('#mce-btn-confirmar').click();
    await page.waitForURL(/entregas\.php/);
    await expect(page.getByText('Pedido marcado como en camino.')).toBeVisible();

    await page.locator(`#ev-foto-input-${id}`).setInputFiles('assets/img/logo.png');
    await page.locator(`[data-pedido-id="${id}"] .ev-btn-continuar`).click();
    await expect(page.locator(`[data-pedido-id="${id}"] .ev-status`)).toHaveText(/Fotos subidas/, { timeout: 10_000 });

    const t = page.locator(`[data-pedido-id="${id}"]`);
    const casillaForm = t.locator('form .quitar-cargo-periferico');
    const casillaCalc = t.locator('.cambio-quitar-envio');
    await expect(casillaForm).toBeVisible({ timeout: 15_000 });

    // Marcar la del formulario de entrega marca la de la calculadora y baja el total mostrado.
    await casillaForm.check({ force: true });
    await expect(casillaCalc).toBeChecked();
    expect(await dinero(t.locator('.cambio-box-total'))).toBeCloseTo(PRODUCTO, 2);
    // ...y desmarcar la de la calculadora desmarca la del formulario.
    await casillaCalc.uncheck({ force: true });
    await expect(casillaForm).not.toBeChecked();
    await casillaForm.check({ force: true });

    await t.getByRole('button', { name: 'ENTREGADO Y COBRADO' }).click({ timeout: 15_000 });
    await page.locator('#mce-btn-confirmar').click();
    await page.waitForURL(/entregas\.php/);
    await expect(page.getByText('Pedido entregado y cobrado correctamente.')).toBeVisible();

    // El cambio es PERMANENTE: el total del pedido ya no incluye el envío.
    const pedido = consultaPedido(numero)!;
    expect(pedido.estado).toBe('entregado');
    expect(pedido.total).toBeCloseTo(PRODUCTO, 2);
  });

  test('entregar SIN marcar la casilla conserva el envío en el total del pedido', async ({ page }) => {
    const id = await pedidoConEnvio(page, 'foranea');
    const numero = await getNumeroPedido(page, id);
    const tarjeta = await asignarYAbrirComoRepartidor(page, id);

    await tarjeta.getByRole('button', { name: 'SALIR A ENTREGAR' }).click();
    await page.locator('#mce-btn-confirmar').click();
    await page.waitForURL(/entregas\.php/);
    await page.locator(`#ev-foto-input-${id}`).setInputFiles('assets/img/logo.png');
    await page.locator(`[data-pedido-id="${id}"] .ev-btn-continuar`).click();
    await expect(page.locator(`[data-pedido-id="${id}"] .ev-status`)).toHaveText(/Fotos subidas/, { timeout: 10_000 });

    const t = page.locator(`[data-pedido-id="${id}"]`);
    await t.getByRole('button', { name: 'ENTREGADO Y COBRADO' }).click({ timeout: 15_000 });
    await page.locator('#mce-btn-confirmar').click();
    await page.waitForURL(/entregas\.php/);
    await expect(page.getByText('Pedido entregado y cobrado correctamente.')).toBeVisible();

    const pedido = consultaPedido(numero)!;
    expect(pedido.estado).toBe('entregado');
    expect(pedido.total).toBeCloseTo(PRODUCTO + ENVIO, 2);
  });
});

