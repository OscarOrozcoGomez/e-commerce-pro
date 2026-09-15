import { test, expect } from './fixtures';
import { E2E_PRODUCT_NAME } from './helpers';

// views/includes/floating_cart_widget.php: burbuja flotante con el conteo de piezas en el
// carrito, incluida SOLO en catalogo.php y product_detail.php (no en header.php, que es
// sitio-wide -- ver el comentario del propio archivo). Reutiliza el contenido que ya rellena
// renderCartMiniDropdown() (mismo carrito que el dropdown del header), así que aquí solo se
// prueba el widget en sí (bubble/badge/panel), no el carrito de nuevo.

test.describe('Burbuja flotante de carrito (catalogo.php y product_detail.php)', () => {
  test('no aparece con el carrito vacío y se abre/cierra al agregar un producto', async ({ page }) => {
    await page.goto(`views/catalogo.php?search=${encodeURIComponent(E2E_PRODUCT_NAME)}`);

    const bubble = page.locator('.floating-cart-bubble');
    const badge = page.locator('.floating-cart-bubble-badge');
    const panel = page.locator('.floating-cart-panel');

    await expect(bubble).toBeVisible();
    await expect(badge).toBeHidden();
    await expect(panel).not.toHaveClass(/active/);

    const card = page.locator('.product-card-container').filter({ hasText: E2E_PRODUCT_NAME }).first();
    await card.waitFor({ state: 'visible' });
    await card.locator('.card-action button').click();
    await expect(page.locator('.toast', { hasText: 'añadido al carrito' })).toBeVisible();

    await expect(badge).toBeVisible();
    await expect(badge).toHaveText('1');

    await bubble.click();
    await expect(panel).toHaveClass(/active/);
    await expect(panel.locator('.cart-mini-dropdown-items')).toContainText(E2E_PRODUCT_NAME);
    await expect(panel.locator('.cart-mini-total-amount')).toHaveText('99.99');

    // Cerrar con la X del panel.
    await panel.locator('.cart-mini-dropdown-header .material-icons').click();
    await expect(panel).not.toHaveClass(/active/);

    // Reabrir y cerrar haciendo clic fuera del widget.
    await bubble.click();
    await expect(panel).toHaveClass(/active/);
    await page.locator('body').click({ position: { x: 5, y: 5 } });
    await expect(panel).not.toHaveClass(/active/);
  });

  test('el badge suma cantidades y persiste al navegar del catálogo a la ficha del producto', async ({ page }) => {
    await page.goto(`views/catalogo.php?search=${encodeURIComponent(E2E_PRODUCT_NAME)}`);

    const card = page.locator('.product-card-container').filter({ hasText: E2E_PRODUCT_NAME }).first();
    await card.waitFor({ state: 'visible' });
    await card.locator('.card-action button').click();
    await card.locator('.card-action button').click();
    await expect(page.locator('.floating-cart-bubble-badge')).toHaveText('2');

    await card.locator('a.card-link').click();
    await page.waitForURL(/product_detail\.php\?id=\d+/);

    // La burbuja también vive en product_detail.php y arranca con el conteo ya guardado
    // en localStorage (getCart()), sin depender de que se agregue algo en esta página.
    await expect(page.locator('.floating-cart-bubble')).toBeVisible();
    await expect(page.locator('.floating-cart-bubble-badge')).toHaveText('2');

    await page.locator('#btn-add-cart').click();
    await expect(page.locator('.floating-cart-bubble-badge')).toHaveText('3');
  });

  test('"Ver carrito completo" navega a cart.php con el producto agregado', async ({ page }) => {
    await page.goto(`views/catalogo.php?search=${encodeURIComponent(E2E_PRODUCT_NAME)}`);
    const card = page.locator('.product-card-container').filter({ hasText: E2E_PRODUCT_NAME }).first();
    await card.waitFor({ state: 'visible' });
    await card.locator('.card-action button').click();

    await page.locator('.floating-cart-bubble').click();
    await page.locator('.floating-cart-panel').getByRole('link', { name: 'Ver carrito completo' }).click();

    await page.waitForURL(/views\/cart\.php/);
    await expect(page.getByText(E2E_PRODUCT_NAME)).toBeVisible();
  });
});
