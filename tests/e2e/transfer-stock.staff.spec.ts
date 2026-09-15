import type { Page } from '@playwright/test';
import { test, expect } from './fixtures';
import { loginAsStaff, E2E_PRODUCT_NAME, E2E_LOW_STOCK_PRODUCT_NAME } from './helpers';

// transfer_stock.php reescrito (multi-producto, PR #147): ya no usa M.Autocomplete -- el
// buscador (#p-search) tiene su propio dropdown (#p-dropdown, .item) que reacciona al evento
// 'input' normal, y cada producto se agrega como una LINEA a una lista (#lineas-body) antes
// de "EJECUTAR TRANSFERENCIA" -- no hay un solo campo de cantidad para toda la operacion.
async function agregarLineaTransferencia(page: Page, nombreProducto: string, cantidad: number): Promise<void> {
  await page.locator('#p-search').fill(nombreProducto);
  const item = page.locator('#p-dropdown .item').filter({ hasText: nombreProducto }).first();
  await item.waitFor({ state: 'visible' });
  await item.click();
  await page.locator('#p-cantidad').fill(String(cantidad));
  await page.getByRole('button', { name: 'Agregar' }).click();
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

    await page.locator('#id_origen').selectOption({ label: 'Almacén Central' });
    await page.locator('#id_destino').selectOption({ label: 'Papelería Liz' });
    await agregarLineaTransferencia(page, E2E_PRODUCT_NAME, 5);
    await expect(page.locator('#lineas-body tr.linea')).toHaveCount(1);
    await expect(page.getByRole('button', { name: /EJECUTAR TRANSFERENCIA/ })).toBeEnabled();

    await page.locator('#observacion').fill('Transferencia de prueba Playwright');
    await page.getByRole('button', { name: /EJECUTAR TRANSFERENCIA/ }).click();
    await expect(page.getByText('¡Transferencia realizada!')).toBeVisible();
  });

  test('el mismo origen y destino se bloquea antes de enviar', async ({ page }) => {
    await loginAsStaff(page, 'admin');
    await page.goto('views/transfer_stock.php');

    await page.locator('#id_origen').selectOption({ label: 'Almacén Central' });
    await page.locator('#id_destino').selectOption({ label: 'Almacén Central' });
    await agregarLineaTransferencia(page, E2E_PRODUCT_NAME, 5);

    await page.getByRole('button', { name: /EJECUTAR TRANSFERENCIA/ }).click();
    await expect(page.getByText('El origen y el destino no pueden ser iguales.')).toBeVisible();
  });

  test('transferir mas stock del disponible en origen falla con el mensaje correcto', async ({ page }) => {
    await loginAsStaff(page, 'admin');
    await page.goto('views/transfer_stock.php');

    // Stock=1 en Almacen Central (ver scripts/seed_e2e_test_data.php); se piden 5.
    await page.locator('#id_origen').selectOption({ label: 'Almacén Central' });
    await page.locator('#id_destino').selectOption({ label: 'Papelería Liz' });
    await agregarLineaTransferencia(page, E2E_LOW_STOCK_PRODUCT_NAME, 5);

    await page.getByRole('button', { name: /EJECUTAR TRANSFERENCIA/ }).click();
    await expect(page.getByText(/Stock disponible insuficiente en origen/)).toBeVisible();
  });
});
