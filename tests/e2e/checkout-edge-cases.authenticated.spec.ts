import { test, expect } from './fixtures';
import {
  addProductToCartByName,
  addSeededProductToCart,
  confirmDomicilioZoneFeeIfPresent,
  registerAndLogin,
  submitDomicilioCheckoutForm,
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
    await page.locator('#direccion').fill('Calle Falsa 123, Colonia Centro');
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
    // Misma dirección fija que usan el resto de los specs -- cae fuera de la periferia en
    // este ambiente (ver confirmDomicilioZoneFeeIfPresent en helpers.ts).
    await page.locator('#direccion').fill('Calle Falsa 123, Colonia Centro');
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
