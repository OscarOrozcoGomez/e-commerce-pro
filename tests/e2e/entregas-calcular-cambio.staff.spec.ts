import { test, expect } from './fixtures';
import { completeDomicilioCheckout, getNumeroPedido, loginAsStaff } from './helpers';

// views/entregas.php, tarjeta "Calcular cambio" (visible al repartidor en cualquier pedido no
// entregado, sin importar el método de pago): calculadora puramente en JS, sin llamar a
// ningún endpoint -- "mismo criterio que deliveryCalcularCambio() en PHP
// (core/entrega_cambio_utils.php)" segun el propio comentario del script. Nadie la probaba.
// Cubre los 5 casos de deliveryCalcularCambio()/su espejo en JS: pago exacto, de más, de
// menos, negativo, y entrada vacía/no numérica (sin mensaje).

async function asignarRepartidor(page: import('@playwright/test').Page, idPedido: number): Promise<void> {
  await page.goto('views/asignar_entregas.php');
  const repartidorSelect = page.locator(`#repartidor-${idPedido}`);
  await expect(repartidorSelect).toBeVisible();
  await repartidorSelect.selectOption({ label: 'Playwright E2E Repartidor' });
  await page.locator(`#fecha-${idPedido}`).fill(new Date().toISOString().slice(0, 10));

  const assignCard = page.locator('.assign-delivery-card').filter({ has: repartidorSelect });
  await assignCard.getByRole('button', { name: 'Asignar' }).click();
  await page.waitForURL(/asignar_entregas\.php/);
  await expect(page.getByText('Pedido asignado correctamente.')).toBeVisible();
}

test.describe('Calcular cambio en la entrega (entregas.php)', () => {
  test('cubre pago exacto, de más, de menos, negativo, y entrada vacía/inválida', async ({ page }) => {
    const idPedido = await completeDomicilioCheckout(page);
    const numeroPedido = await getNumeroPedido(page, idPedido);

    await loginAsStaff(page, 'encargado');
    await asignarRepartidor(page, idPedido);

    await loginAsStaff(page, 'repartidor');
    await page.goto('views/entregas.php');

    const card = page.locator(`[data-pedido-id="${idPedido}"]`);
    await expect(card).toBeVisible();
    await expect(card.getByText(numeroPedido)).toBeVisible();

    const totalTexto = await card.locator('.cambio-box-total').textContent();
    const total = Number((totalTexto ?? '').replace(/[^0-9.]/g, ''));
    expect(total).toBeGreaterThan(0);

    const input = card.locator('.cambio-paga');
    const resultado = card.locator('.cambio-result');

    // Vacío: sin mensaje (ni error ni resultado).
    await expect(resultado).toHaveText('');

    // Pago exacto.
    await input.fill(total.toFixed(2));
    await expect(resultado).toHaveText('Pago exacto, no hay que dar cambio.');
    await expect(resultado).toHaveClass(/is-ok/);

    // Pago de más: cambio a devolver.
    await input.fill((total + 50).toFixed(2));
    await expect(resultado).toHaveText('Cambio a devolver: $50.00');
    await expect(resultado).toHaveClass(/is-ok/);

    // Pago de menos: falta.
    await input.fill(Math.max(0, total - 20).toFixed(2));
    await expect(resultado).toHaveText('Falta: $20.00');
    await expect(resultado).toHaveClass(/is-error/);

    // Monto negativo: el propio <input type="number" min="0"> no deja escribir "-" en la
    // mayoría de navegadores, pero el JS igual lo valida por si el valor llega por otra vía
    // (pegar, autocompletado) -- se fuerza vía fill + dispatchEvent('input') en vez de
    // pressSequentially(), que respetaría la restricción del control nativo.
    await input.evaluate((el: HTMLInputElement) => {
      el.value = '-10';
      el.dispatchEvent(new Event('input', { bubbles: true }));
    });
    await expect(resultado).toHaveText('El monto no puede ser negativo.');
    await expect(resultado).toHaveClass(/is-error/);

    // Vuelve a vaciarse: sin mensaje otra vez (no se queda pegado el último resultado).
    await input.fill('');
    await expect(resultado).toHaveText('');
  });
});
