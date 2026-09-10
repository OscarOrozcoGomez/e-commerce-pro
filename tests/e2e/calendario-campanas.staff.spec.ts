import { test, expect } from './fixtures';
import { loginAsStaff, E2E_PRODUCT_NAME, fechaFutura } from './helpers';

// views/calendario_campanas.php es un formulario clasico que se auto-postea (sin AJAX).
// Solo lista campañas con fecha_fin >= CURDATE(), asi que los tests usan fechas futuras y
// eliminan al final la campaña que crean, para no dejar filas de prueba acumulandose en una
// tabla que cualquier otra persona/test podria ver como "vigente".

test.describe('Stock vs. Calendario de Campañas (calendario_campanas.php)', () => {
  test('un vendedor no puede acceder', async ({ page }) => {
    await loginAsStaff(page, 'vendedor');
    await page.goto('views/calendario_campanas.php');
    await expect(page).toHaveURL(/views\/dashboard\.php/);
  });

  test('un encargado no puede acceder (gestionar_campanas es solo-admin)', async ({ page }) => {
    await loginAsStaff(page, 'encargado');
    await page.goto('views/calendario_campanas.php');
    await expect(page).toHaveURL(/views\/dashboard\.php/);
  });

  test('un repartidor no puede acceder', async ({ page }) => {
    await loginAsStaff(page, 'repartidor');
    await page.goto('views/calendario_campanas.php');
    await expect(page).toHaveURL(/views\/dashboard\.php/);
  });

  test('la fecha de fin anterior a la de inicio falla con un mensaje claro', async ({ page }) => {
    await loginAsStaff(page, 'admin');
    await page.goto('views/calendario_campanas.php');

    const nombre = 'playwright_campana_invalida_' + Date.now();
    await page.locator('#nombre').fill(nombre);
    await page.locator('#fecha_inicio').fill(fechaFutura(10));
    await page.locator('#fecha_fin').fill(fechaFutura(5));
    await page.getByRole('button', { name: 'Guardar Campaña' }).click();

    await expect(page.getByText('La fecha de fin no puede ser anterior a la fecha de inicio.')).toBeVisible();
    await expect(page.locator('table tbody tr').filter({ hasText: nombre })).toHaveCount(0);
  });

  test('campos obligatorios vacíos por POST directo fallan con un mensaje claro', async ({ page }) => {
    await loginAsStaff(page, 'admin');
    await page.goto('views/calendario_campanas.php');
    const csrfToken = await page.locator('input[name="csrf_token"]').inputValue();

    const res = await page.request.post('views/calendario_campanas.php', {
      form: { csrf_token: csrfToken, accion: 'crear_campana', nombre: '', fecha_inicio: '', fecha_fin: '' },
    });
    expect(await res.text()).toContain('Nombre, fecha de inicio y fecha de fin son obligatorios.');
  });

  test('ciclo completo: crear una campaña con un producto destacado, verla listada, y eliminarla', async ({ page }) => {
    await loginAsStaff(page, 'admin');
    await page.goto('views/calendario_campanas.php');

    const nombre = 'playwright_campana_' + Date.now();
    await page.locator('#nombre').fill(nombre);
    await page.locator('select[name="canal"]').selectOption('facebook_ads', { force: true });
    await page.locator('#fecha_inicio').fill(fechaFutura(5));
    await page.locator('#fecha_fin').fill(fechaFutura(15));
    // Materialize convierte el <select multiple> en su propio dropdown -- se selecciona el
    // valor directamente en el <select> nativo con force, igual que en otros specs de la suite.
    await page.locator('#select-productos-campana').selectOption({ label: E2E_PRODUCT_NAME }, { force: true });
    await page.locator('#notas').fill('Creada por Playwright, se elimina en este mismo test.');
    await page.getByRole('button', { name: 'Guardar Campaña' }).click();

    await expect(page.getByText('Campaña registrada.')).toBeVisible();
    const fila = page.locator('table tbody tr').filter({ hasText: nombre });
    await expect(fila).toBeVisible();
    await expect(fila.getByText('Facebook Ads')).toBeVisible();
    await expect(fila.getByText(E2E_PRODUCT_NAME)).toBeVisible();

    page.once('dialog', (dialog) => dialog.accept());
    await fila.locator('button').click();

    await expect(page.getByText('Campaña eliminada.')).toBeVisible();
    await expect(page.locator('table tbody tr').filter({ hasText: nombre })).toHaveCount(0);
  });
});
