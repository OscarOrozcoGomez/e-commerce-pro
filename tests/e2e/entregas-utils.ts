import type { Locator, Page } from '@playwright/test';
import { expect } from './fixtures';
import {
  E2E_DIRECCION_ENTREGA,
  addSeededProductToCart,
  fechaFutura,
  fijarUbicacionEntrega,
  loginAsStaff,
  registerAndLogin,
} from './helpers';

// Helpers compartidos por entregas-envio y entregas-ruta-wa: un pedido a domicilio (foraneo = $40 de envio, o local sin envio), su
// asignacion al repartidor y la tarjeta tal como la ve el. Producto sembrado $99.99.

export const PRODUCTO = 99.99;
export const ENVIO = 40;

export async function pedidoConEnvio(page: Page, zona: 'foranea' | 'local'): Promise<number> {
  const cliente = await registerAndLogin(page);
  await addSeededProductToCart(page);
  await page.goto('views/cart.php');
  await page.locator('#tipo_entrega').selectOption('Domicilio');
  await page.locator('#nombre').fill(cliente.nombre);
  await page.locator('#telefono').fill('3311234567');
  await page.locator('#direccion').fill(E2E_DIRECCION_ENTREGA);
  await fijarUbicacionEntrega(page, zona);
  await page.getByRole('button', { name: 'Confirmar Pedido' }).click();
  if (zona === 'foranea') {
    await expect(page.getByText('Tu domicilio está fuera de la periferia')).toBeVisible();
    await page.getByRole('button', { name: 'De acuerdo, confirmar' }).click();
  }
  await page.getByRole('button', { name: 'Continuar' }).click();
  await page.getByRole('button', { name: 'No, continuar' }).click();
  await page.waitForURL(/gracias\.php\?id=\d+/);
  return Number(new URL(page.url()).searchParams.get('id'));
}

export async function asignarYAbrirComoRepartidor(page: Page, idPedido: number) {
  await loginAsStaff(page, 'encargado');
  await page.goto('views/asignar_entregas.php');
  const select = page.locator(`#repartidor-${idPedido}`);
  await expect(select).toBeVisible();
  await select.selectOption({ label: 'Playwright E2E Repartidor' });
  await page.locator(`#fecha-${idPedido}`).fill(fechaFutura(0));
  await page.locator('.assign-delivery-card').filter({ has: select }).getByRole('button', { name: 'Asignar' }).click();
  await expect(page.getByText('Pedido asignado correctamente.')).toBeVisible();

  await loginAsStaff(page, 'repartidor');
  await page.goto('views/entregas.php?fecha_entrega=');
  const tarjeta = page.locator(`[data-pedido-id="${idPedido}"]`);
  await expect(tarjeta).toBeVisible();
  return tarjeta;
}

export const dinero = async (loc: Locator) => Number(((await loc.textContent()) ?? '').replace(/[^0-9.-]/g, ''));

