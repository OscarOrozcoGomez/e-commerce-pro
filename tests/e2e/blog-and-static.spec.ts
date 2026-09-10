import { test, expect } from './fixtures';

// Páginas públicas de bajo riesgo (sin formularios, sin datos de negocio que
// dependan de nuestros fixtures): solo verificamos que carguen sin errores.
test.describe('Contenido público estático', () => {
  test('el blog carga sin errores de JS', async ({ page }) => {
    const pageErrors: string[] = [];
    page.on('pageerror', (err) => pageErrors.push(err.message));

    const response = await page.goto('views/blog.php');
    expect(response?.status()).toBe(200);
    expect(pageErrors).toEqual([]);
  });

  test('términos y condiciones carga sin errores de JS', async ({ page }) => {
    const pageErrors: string[] = [];
    page.on('pageerror', (err) => pageErrors.push(err.message));

    const response = await page.goto('views/terminos.php');
    expect(response?.status()).toBe(200);
    expect(pageErrors).toEqual([]);
  });
});
