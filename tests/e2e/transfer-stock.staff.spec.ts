import type { Page } from '@playwright/test';
import { test, expect } from './fixtures';
import { loginAsStaff, E2E_PRODUCT_NAME, E2E_LOW_STOCK_PRODUCT_NAME } from './helpers';

// El buscador de producto usa M.Autocomplete (sin resolucion alterna por blur, a diferencia
// de otros buscadores del proyecto), asi que hay que interactuar con su dropdown real.
async function seleccionarProductoTransferencia(page: Page, nombreProducto: string): Promise<void> {
  // M.Autocomplete (a diferencia de otros buscadores del proyecto) no tiene una resolucion
  // alterna por blur, y su dropdown no reacciona a .fill() (no dispara eventos de tecleo
  // real) -- hay que escribir caracter por caracter para que se abra.
  const buscador = page.locator('#p-search');
  await buscador.pressSequentially(nombreProducto, { delay: 20 });
  const item = page.locator('.autocomplete-content li').filter({ hasText: nombreProducto }).first();
  await item.waitFor({ state: 'visible' });
  await item.click();
}

test.describe('Transferencia entre Almacenes (transfer_stock.php)', () => {
  test('un encargado no puede acceder a transferencias entre almacenes', async ({ page }) => {
    await loginAsStaff(page, 'encargado');
    await page.goto('views/transfer_stock.php');
    await expect(page).toHaveURL(/views\/dashboard\.php/);
  });

  test('un vendedor no puede acceder a transferencias entre almacenes', async ({ page }) => {
    await loginAsStaff(page, 'vendedor');
    await page.goto('views/transfer_stock.php');
    await expect(page).toHaveURL(/views\/dashboard\.php/);
  });

  test('transferir stock entre dos sucursales distintas se completa con exito', async ({ page }) => {
    await loginAsStaff(page, 'admin');
    await page.goto('views/transfer_stock.php');

    await seleccionarProductoTransferencia(page, E2E_PRODUCT_NAME);
    await page.locator('#id_origen').selectOption({ label: 'Almacén Central' });
    await page.locator('#id_destino').selectOption({ label: 'Papelería Liz' });
    await page.locator('#cantidad').fill('5');
    await page.locator('#observacion').fill('Transferencia de prueba Playwright');

    await page.getByRole('button', { name: 'EJECUTAR TRANSFERENCIA' }).click();
    await expect(page.getByText('Mercancía transferida correctamente')).toBeVisible();
  });

  test('el mismo origen y destino se bloquea antes de enviar', async ({ page }) => {
    await loginAsStaff(page, 'admin');
    await page.goto('views/transfer_stock.php');

    await seleccionarProductoTransferencia(page, E2E_PRODUCT_NAME);
    await page.locator('#id_origen').selectOption({ label: 'Almacén Central' });
    await page.locator('#id_destino').selectOption({ label: 'Almacén Central' });
    await page.locator('#cantidad').fill('5');

    await page.getByRole('button', { name: 'EJECUTAR TRANSFERENCIA' }).click();
    await expect(page.getByText('El origen y destino no pueden ser iguales')).toBeVisible();
  });

  test('transferir mas stock del disponible en origen falla con el mensaje correcto', async ({ page }) => {
    await loginAsStaff(page, 'admin');
    await page.goto('views/transfer_stock.php');

    // Stock=1 en Almacen Central (ver scripts/seed_e2e_test_data.php); se piden 5.
    await seleccionarProductoTransferencia(page, E2E_LOW_STOCK_PRODUCT_NAME);
    await page.locator('#id_origen').selectOption({ label: 'Almacén Central' });
    await page.locator('#id_destino').selectOption({ label: 'Papelería Liz' });
    await page.locator('#cantidad').fill('5');

    await page.getByRole('button', { name: 'EJECUTAR TRANSFERENCIA' }).click();
    await expect(page.getByText(/Stock insuficiente en origen/)).toBeVisible();
  });
});
