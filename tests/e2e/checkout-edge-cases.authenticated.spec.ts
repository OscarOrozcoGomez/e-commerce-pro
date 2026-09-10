import { test, expect } from './fixtures';
import {
  addProductToCartByName,
  registerAndLogin,
  submitDomicilioCheckoutForm,
  E2E_LOW_STOCK_PRODUCT_NAME,
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

    await expect(page.getByText('Sin stock suficiente')).toBeVisible();
    await expect(
      page.locator('#swal2-html-container').getByText(E2E_LOW_STOCK_PRODUCT_NAME, { exact: false })
    ).toBeVisible();

    // El carrito no debió vaciarse ni redirigir a gracias.php: el pedido fue rechazado.
    expect(page.url()).toContain('cart.php');
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
