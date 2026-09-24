import { test, expect } from './fixtures';

// Catálogo público: la búsqueda también coincide con productos.nombre_corto, la etiqueta del pomo (commit 7e6b82a; igual que en
// products.php). Producto sembrado "Playwright E2E Producto Con Etiqueta" con nombre_corto "Zorbamag Pomo": la palabra
// "Zorbamag" NO aparece en su nombre largo, así que solo se puede encontrar por la etiqueta. Cada card expone esa etiqueta en
// data-short para que el filtro instantáneo del navegador no la oculte antes de que responda el servidor.

const NOMBRE = 'Playwright E2E Producto Con Etiqueta';
const tarjeta = (page: import('@playwright/test').Page) => page.locator('.product-card-container').filter({ hasText: NOMBRE });

test.describe('Catálogo: búsqueda por nombre corto', () => {
  test('buscar por la etiqueta del pomo (que no está en el nombre) encuentra el producto', async ({ page }) => {
    await page.goto('views/catalogo.php?search=Zorbamag');
    await expect(tarjeta(page)).toHaveCount(1);
  });

  test('la búsqueda ignora mayúsculas y encuentra con una parte de la etiqueta', async ({ page }) => {
    await page.goto('views/catalogo.php?search=zORBA');
    await expect(tarjeta(page)).toHaveCount(1);
  });

  test('una etiqueta que no existe no devuelve el producto', async ({ page }) => {
    await page.goto('views/catalogo.php?search=Zorbamagxxx');
    await expect(tarjeta(page)).toHaveCount(0);
  });

  test('la card expone la etiqueta en data-short (minúsculas) para el filtro instantáneo', async ({ page }) => {
    await page.goto(`views/catalogo.php?search=${encodeURIComponent(NOMBRE)}`);
    await expect(tarjeta(page).first()).toHaveAttribute('data-short', /zorbamag pomo/);
  });

  test('escribir la etiqueta en el buscador del catálogo muestra el producto', async ({ page }) => {
    await page.goto('views/catalogo.php');
    const buscador = page.locator('input[type="search"], #search-input, input[name="search"]').first();
    await buscador.fill('Zorbamag');
    await expect(tarjeta(page).first()).toBeVisible({ timeout: 15_000 });
  });
});
