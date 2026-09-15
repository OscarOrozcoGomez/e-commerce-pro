import { test, expect } from './fixtures';
import { registerAndLogin } from './helpers';

test.describe('Mis Direcciones', () => {
  test('agregar, editar y eliminar una dirección', async ({ page }) => {
    await registerAndLogin(page);
    await page.goto('views/mis_direcciones.php');

    await page.locator('#alias').fill('Casa Playwright');
    await page.locator('#direccion').fill('Calle Falsa 123, Colonia Centro');
    await page.locator('#btn-submit').click();

    await expect(page.getByText('Dirección guardada.')).toBeVisible();
    await expect(page.getByText('Casa Playwright', { exact: true })).toBeVisible();

    await page.getByRole('button', { name: 'Editar' }).click();
    await page.locator('#alias').fill('Casa Playwright Editada');
    await page.locator('#btn-submit').click();

    await expect(page.getByText('Dirección actualizada correctamente.')).toBeVisible();
    await expect(page.getByText('Casa Playwright Editada', { exact: true })).toBeVisible();

    page.on('dialog', (dialog) => dialog.accept());
    await page.getByRole('button', { name: 'Eliminar' }).click();

    await expect(page.getByText('Dirección eliminada.')).toBeVisible();
    await expect(page.locator('li.collection-item')).toHaveCount(0);
  });

  test('la segunda dirección guardada se puede marcar como predeterminada', async ({ page }) => {
    await registerAndLogin(page);
    await page.goto('views/mis_direcciones.php');

    await page.locator('#alias').fill('Casa');
    await page.locator('#direccion').fill('Calle Falsa 123, Colonia Centro');
    await page.locator('#btn-submit').click();
    await expect(page.getByText('Dirección guardada.')).toBeVisible();

    await page.locator('#alias').fill('Trabajo');
    await page.locator('#direccion').fill('Av. Vallarta 500, Zona Centro');
    await page.locator('#btn-submit').click();
    await expect(page.getByText('Dirección guardada.')).toBeVisible();

    const trabajoItem = page.locator('li.collection-item', { hasText: 'Trabajo' });
    await trabajoItem.getByRole('button', { name: 'Hacer Predeterminada' }).click();

    await expect(page.getByText('Dirección predeterminada actualizada.')).toBeVisible();
    await expect(trabajoItem.getByText('Predeterminada')).toBeVisible();
  });

  test('al llegar a 5 direcciones el formulario para agregar una nueva desaparece', async ({ page }) => {
    await registerAndLogin(page);
    await page.goto('views/mis_direcciones.php');

    for (let i = 1; i <= 5; i++) {
      await page.locator('#alias').fill(`Dirección Playwright ${i}`);
      await page.locator('#direccion').fill(`Calle Playwright ${i}, Colonia Centro`);
      await page.locator('#btn-submit').click();
      await expect(page.getByText('Dirección guardada.')).toBeVisible();
    }

    // "Direcciones Guardadas (5/5)": views/mis_direcciones.php esconde el formulario de alta
    // en cuanto count($direcciones) deja de ser < 5 -- límite duro de 5 por cliente.
    await expect(page.locator('.card-title', { hasText: 'Direcciones Guardadas' })).toHaveText('Direcciones Guardadas (5/5)');
    await expect(page.locator('#btn-submit')).toHaveCount(0);
    await expect(page.locator('li.collection-item')).toHaveCount(5);
  });
});
