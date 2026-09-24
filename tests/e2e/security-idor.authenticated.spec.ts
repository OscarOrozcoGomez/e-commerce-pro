import { test, expect } from './fixtures';
import { completeDomicilioCheckout, registerAndLogin } from './helpers';

test.describe('Seguridad: acceso entre cuentas (IDOR)', () => {
  test('un cliente no puede ver el detalle del pedido de otro cliente cambiando el id en la URL', async ({
    page,
    browser,
  }) => {
    const idPedidoDeA = await completeDomicilioCheckout(page); // Cliente A

    const contextB = await browser.newContext();
    const pageB = await contextB.newPage();
    await pageB.route(/maps\.googleapis\.com|maps\.gstatic\.com/, (route) => route.abort());
    await registerAndLogin(pageB); // Cliente B, cuenta distinta

    await pageB.goto(`views/detalle_compra.php?id=${idPedidoDeA}`);

    // No debe mostrar el pedido ajeno: debe rebotar a su propio historial en vez
    // de filtrar datos de la cuenta de otro cliente.
    await pageB.waitForURL(/mis_compras\.php/);

    await contextB.close();
  });
});
