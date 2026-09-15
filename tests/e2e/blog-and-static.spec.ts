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

  test('index.php redirige al catálogo', async ({ page }) => {
    await page.goto('index.php');
    await expect(page).toHaveURL(/views\/catalogo\.php/);
  });

  test('la pantalla de error genérica sanea el folio técnico ("rid") en vez de reflejarlo tal cual', async ({ page }) => {
    const pageErrors: string[] = [];
    page.on('pageerror', (err) => pageErrors.push(err.message));

    const response = await page.goto('views/error.php');
    expect(response?.status()).toBe(200);
    await expect(page.getByText('Lo sentimos, ha ocurrido un error inesperado')).toBeVisible();
    await expect(page.getByRole('link', { name: 'Volver al Inicio' })).toBeVisible();
    expect(pageErrors).toEqual([]);

    // El "rid" pasa por un regex que solo deja [a-f0-9] antes de htmlspecialchars() -- un
    // intento de inyección se reduce a los caracteres hex sueltos que traiga (aquí:
    // "<script>alert(1)</script>deadbeef" -> "cae1cdeadbeef"), nunca se ejecuta ni se refleja
    // el marcado original.
    await page.goto('views/error.php?rid=' + encodeURIComponent('<script>alert(1)</script>deadbeef'));
    const folio = page.getByText('Folio técnico:');
    await expect(folio).toBeVisible();
    await expect(folio).toContainText('cae1cdeadbeef');
    await expect(page.locator('body')).not.toContainText('<script>');
  });
});
