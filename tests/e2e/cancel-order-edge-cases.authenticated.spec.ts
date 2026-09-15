import { test, expect } from './fixtures';
import { completeDomicilioCheckout } from './helpers';

async function cancelCurrentOrder(page: import('@playwright/test').Page) {
  await page.getByRole('button', { name: 'CANCELAR PEDIDO' }).click();
  await page.locator('#swal-motivo-cancelacion').selectOption({ label: 'Cambie de opinion / ya no lo quiero' });
  await page.getByRole('button', { name: 'Sí, cancelar pedido' }).click();
  await expect(page.getByText('Pedido cancelado')).toBeVisible();
  await page.getByRole('button', { name: /^OK$|^Aceptar$/ }).click().catch(() => {});
  await page.waitForLoadState('networkidle');
}

test.describe('Cancelar pedido: edge cases', () => {
  test('un pedido ya cancelado no se puede volver a cancelar', async ({ page }) => {
    const idPedido = await completeDomicilioCheckout(page);

    await page.goto(`views/detalle_compra.php?id=${idPedido}`);
    // Se captura el token CSRF ANTES de cancelar: una vez cancelado, el bloque que
    // lo expone ya no se renderiza (el pedido deja de ser cancelable).
    const csrfToken = await page.evaluate<string>('CSRF_TOKEN_CANCELACION');
    expect(csrfToken).toBeTruthy();

    await cancelCurrentOrder(page);

    // Tras cancelar, el botón de cancelar debe haber desaparecido (ya no es cancelable).
    await expect(page.getByRole('button', { name: 'CANCELAR PEDIDO' })).toHaveCount(0);

    // Aunque alguien reintente el POST directo a la API con el mismo pedido (reusando
    // el token capturado antes), el backend debe rechazarlo por estado: no debe quedar
    // "cancelado dos veces" ni devolver stock de más.
    const response = await page.request.post('api/cancel_order.php', {
      data: { id_pedido: idPedido, id_motivo: 1, motivo_detalle: '', csrf_token: csrfToken },
    });
    const body = await response.json();
    expect(body.success).toBe(false);
  });

  test('el motivo "Otro motivo" exige el detalle antes de dejar confirmar la cancelación', async ({ page }) => {
    let apiCalled = false;
    await page.route('**/api/cancel_order.php', (route) => {
      apiCalled = true;
      route.continue();
    });

    const idPedido = await completeDomicilioCheckout(page);
    await page.goto(`views/detalle_compra.php?id=${idPedido}`);
    await page.getByRole('button', { name: 'CANCELAR PEDIDO' }).click();

    // "Otro motivo" es requiere_detalle=1 (ver migración 20260817_000002) -- confirmar sin
    // llenar el textarea debe bloquearse en el propio Swal (preConfirm), sin llamar la API.
    await page.locator('#swal-motivo-cancelacion').selectOption({ label: 'Otro motivo' });
    await page.getByRole('button', { name: 'Sí, cancelar pedido' }).click();
    await expect(page.locator('.swal2-validation-message')).toHaveText('Cuéntanos brevemente el motivo de tu cancelación.');
    expect(apiCalled).toBe(false);

    // Al completar el detalle, sí procede.
    await page.locator('#swal-detalle-cancelacion').fill('Detalle de prueba generado por Playwright.');
    await page.getByRole('button', { name: 'Sí, cancelar pedido' }).click();
    await expect(page.getByText('Pedido cancelado')).toBeVisible();
    expect(apiCalled).toBe(true);
  });
});
