import { test, expect } from './fixtures';
import { loginAsStaff } from './helpers';

// views/blog_detail.php: ficha pública de un artículo de blog (views/blog.php ya se cubre en
// blog-and-static.spec.ts, pero solo de pasada -- nadie probaba el detalle en sí). Solo
// muestra posts con estado='publicado' (WHERE explícito en la consulta); cualquier otro caso
// (slug inexistente, o un post que se pasó a borrador) redirige de vuelta a blog.php.

test.describe('Detalle de artículo del blog (blog_detail.php)', () => {
  test('un slug inexistente redirige a blog.php', async ({ page }) => {
    await page.goto(`views/blog_detail.php?s=playwright-slug-que-no-existe-${Date.now()}`);
    await expect(page).toHaveURL(/views\/blog\.php$/);
  });

  test('un artículo publicado muestra título, fecha y contenido; deja de ser accesible al pasarlo a borrador', async ({ page }) => {
    await loginAsStaff(page, 'admin');
    await page.goto('views/manage_blogs.php');

    const titulo = `Playwright Blog Detalle ${Date.now()}`;
    await page.locator('#titulo').fill(titulo);
    await page.locator('#extracto').fill('Extracto de prueba generado por Playwright.');
    await page.getByRole('button', { name: 'GUARDAR POST' }).click();
    await expect(page.getByText('Operacion exitosa.')).toBeVisible();

    // Desde el listado público, no adivinando el slug (su algoritmo de generación no es
    // parte del contrato que prueba este spec).
    await page.goto('views/blog.php');
    const tarjeta = page.locator('.card').filter({ hasText: titulo });
    const link = tarjeta.getByRole('link', { name: 'LEER MÁS' });
    const href = await link.getAttribute('href');
    expect(href).toBeTruthy();

    await link.click();
    await page.waitForURL(/blog_detail\.php\?s=/);

    await expect(page.getByRole('heading', { name: titulo })).toBeVisible();
    await expect(page.getByText(/Publicado el \d{2}\/\d{2}\/\d{4}/)).toBeVisible();
    await expect(page.getByRole('link', { name: 'Volver al Blog' })).toBeVisible();

    // Lo pasa a borrador desde el panel de administración...
    await page.goto('views/manage_blogs.php');
    const filaAdmin = page.locator('table.striped tr').filter({ hasText: titulo });
    await filaAdmin.locator('button.blue').click();
    await page.locator('#estado').selectOption('borrador');
    await page.getByRole('button', { name: 'GUARDAR POST' }).click();
    await expect(page.getByText('Operacion exitosa.')).toBeVisible();

    // ...y la misma URL de detalle (mismo slug, ya capturado arriba) deja de ser accesible.
    await page.goto(href!);
    await expect(page).toHaveURL(/views\/blog\.php$/);
  });
});
