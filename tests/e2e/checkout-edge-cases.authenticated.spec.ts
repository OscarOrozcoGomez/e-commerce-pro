import { test, expect } from './fixtures';
import {
  addProductToCartByName,
  addSeededProductToCart,
  confirmDomicilioZoneFeeIfPresent,
  registerAndLogin,
  submitDomicilioCheckoutForm,
  E2E_DIRECCION_ENTREGA,
  fijarUbicacionEntrega,
  E2E_LOW_STOCK_PRODUCT_NAME,
  E2E_PRODUCT_NAME,
} from './helpers';

test.describe('Checkout: edge cases (autenticado)', () => {
  test('pedir más unidades de las que hay en stock rechaza el pedido con el mensaje correcto', async ({ page }) => {
    await registerAndLogin(page);
    await addProductToCartByName(page, E2E_LOW_STOCK_PRODUCT_NAME);

    await page.goto('views/cart.php');
    // El producto sembrado tiene stock=1; subimos la cantidad a 2 para forzar el rechazo.
    await page.locator('.cart-qty-btn', { hasText: '+' }).click();
    await expect(page.locator('.cart-qty-input')).toHaveValue('2');

    await page.locator('#tipo_entrega').selectOption('Domicilio');
    await page.locator('#nombre').fill('Playwright QA');
    await page.locator('#telefono').fill('3311234567');
    await page.locator('#direccion').fill(E2E_DIRECCION_ENTREGA);
    await fijarUbicacionEntrega(page, 'local');
    await page.getByRole('button', { name: 'Confirmar Pedido' }).click();
    await confirmDomicilioZoneFeeIfPresent(page);

    await expect(page.getByText('Sin stock suficiente')).toBeVisible();
    await expect(
      page.locator('#swal2-html-container').getByText(E2E_LOW_STOCK_PRODUCT_NAME, { exact: false })
    ).toBeVisible();

    // El carrito no debió vaciarse ni redirigir a gracias.php: el pedido fue rechazado.
    expect(page.url()).toContain('cart.php');
  });

  test('declinar el cargo de envío foráneo ("Volver") no registra el pedido y deja el carrito intacto', async ({ page }) => {
    let orderApiCalled = false;
    await page.route('**/api/public_orders.php', (route) => {
      orderApiCalled = true;
      route.continue();
    });

    await registerAndLogin(page);
    await addSeededProductToCart(page);
    await page.goto('views/cart.php');
    await page.locator('#tipo_entrega').selectOption('Domicilio');
    await page.locator('#nombre').fill('Playwright QA');
    await page.locator('#telefono').fill('3311234567');
    // Zona foránea fijada por coordenadas (~22 km de la sucursal): determinista y sin llamar a
    // Google -- ver E2E_UBICACION_ENTREGA en helpers.ts.
    await page.locator('#direccion').fill(E2E_DIRECCION_ENTREGA);
    await fijarUbicacionEntrega(page, 'foranea');
    await page.getByRole('button', { name: 'Confirmar Pedido' }).click();

    const dialogTitle = page.getByText('Tu domicilio está fuera de la periferia');
    await expect(dialogTitle).toBeVisible();
    await page.getByRole('button', { name: 'Volver' }).click();
    await expect(dialogTitle).toBeHidden();

    // Ni se llamó a la API de registrar pedido ni se navegó a gracias.php: el carrito sigue
    // como estaba, listo para que el cliente lo intente de nuevo o cambie de dirección.
    expect(orderApiCalled).toBe(false);
    expect(page.url()).toContain('cart.php');
    await expect(page.getByText(E2E_PRODUCT_NAME)).toBeVisible();
  });

  test('una dirección foránea muestra el cargo de $40 con el total y, al aceptarlo, registra el pedido', async ({ page }) => {
    await registerAndLogin(page);
    await addSeededProductToCart(page);
    await page.goto('views/cart.php');
    await page.locator('#tipo_entrega').selectOption('Domicilio');
    await page.locator('#nombre').fill('Playwright QA');
    await page.locator('#telefono').fill('3311234567');
    await page.locator('#direccion').fill(E2E_DIRECCION_ENTREGA);
    await fijarUbicacionEntrega(page, 'foranea');
    await page.getByRole('button', { name: 'Confirmar Pedido' }).click();

    // El aviso trae el desglose real: producto sembrado ($99.99) + cargo foráneo ($40).
    await expect(page.getByText('Tu domicilio está fuera de la periferia')).toBeVisible();
    const detalle = page.locator('#swal2-html-container');
    await expect(detalle).toContainText('$40.00');
    await expect(detalle).toContainText('Productos: $99.99');
    await expect(detalle).toContainText('Total a pagar al recibir: $139.99');

    await page.getByRole('button', { name: 'De acuerdo, confirmar' }).click();
    await page.getByRole('button', { name: 'Continuar' }).click();
    await page.getByRole('button', { name: 'No, continuar' }).click();
    await page.waitForURL(/gracias\.php\?id=\d+/);
  });

  test('una dirección dentro de la ZMG no muestra aviso de envío foráneo y el pedido se registra directo', async ({ page }) => {
    await registerAndLogin(page);
    await addSeededProductToCart(page);
    await page.goto('views/cart.php');
    await page.locator('#tipo_entrega').selectOption('Domicilio');
    await page.locator('#nombre').fill('Playwright QA');
    await page.locator('#telefono').fill('3311234567');
    await page.locator('#direccion').fill(E2E_DIRECCION_ENTREGA);
    await fijarUbicacionEntrega(page, 'local');
    await page.getByRole('button', { name: 'Confirmar Pedido' }).click();

    // Sin cargo: directo a "¡Pedido Confirmado!" (nunca aparece el aviso de periferia).
    await expect(page.getByRole('button', { name: 'Continuar' })).toBeVisible();
    await expect(page.getByText('Tu domicilio está fuera de la periferia')).toHaveCount(0);
    await page.getByRole('button', { name: 'Continuar' }).click();
    await page.getByRole('button', { name: 'No, continuar' }).click();
    await page.waitForURL(/gracias\.php\?id=\d+/);
  });

  // CARACTERIZACION del comportamiento ACTUAL, no una afirmacion de que sea lo deseado: desde el
  // PR #188 una zona "indeterminado" (>40 km de la sucursal, el negocio no entrega ahi) ya no se cotiza
  // como foranea, y el checkout web NO le avisa al cliente ni la rechaza -- guarda el pedido con $0 de
  // envio (deliveryZoneShippingFee solo cobra 'foraneo'). El fix del PR #188 cubrio a Alex
  // (aiToolAgendarVenta pausa y alerta), no este flujo. Si se decide bloquear/avisar aqui, este test
  // debe cambiar junto con esa decision.
  test('zona indeterminada (fuera de cobertura): hoy el checkout web registra el pedido sin cargo ni aviso', async ({ page }) => {
    await registerAndLogin(page);
    await addSeededProductToCart(page);
    await page.goto('views/cart.php');
    await page.locator('#tipo_entrega').selectOption('Domicilio');
    await page.locator('#nombre').fill('Playwright QA');
    await page.locator('#telefono').fill('3311234567');
    await page.locator('#direccion').fill(E2E_DIRECCION_ENTREGA);
    await fijarUbicacionEntrega(page, 'indeterminada');
    await page.getByRole('button', { name: 'Confirmar Pedido' }).click();

    await expect(page.getByRole('button', { name: 'Continuar' })).toBeVisible();
    await expect(page.getByText('Tu domicilio está fuera de la periferia')).toHaveCount(0);
  });

  test('el nombre del cliente se guarda escapado, no como HTML/script ejecutable', async ({ page }) => {
    let dialogFired = false;
    page.on('dialog', (dialog) => {
      dialogFired = true;
      void dialog.dismiss();
    });

    const xssPayload = `<script>alert('xss')</script>`;
    await registerAndLogin(page);
    await addProductToCartByName(page, 'Playwright E2E Test Product');
    await submitDomicilioCheckoutForm(page, { nombre: xssPayload });

    await page.getByRole('button', { name: 'Continuar' }).click();
    await page.getByRole('button', { name: 'No, continuar' }).click();
    await page.waitForURL(/gracias\.php\?id=\d+/);

    const url = new URL(page.url());
    const idPedido = Number(url.searchParams.get('id'));

    await page.goto(`views/detalle_compra.php?id=${idPedido}`);

    expect(dialogFired).toBe(false);
    // Si esc() falla, el navegador interpretaría el <script> real y este texto no
    // aparecería como texto plano visible en la página.
    await expect(page.getByText(xssPayload, { exact: false })).toBeVisible();
  });
});
