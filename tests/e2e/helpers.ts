import type { Page } from '@playwright/test';
import { expect } from './fixtures';
import * as fs from 'fs';
import * as path from 'path';

// Debe coincidir con scripts/seed_e2e_test_data.php
export const E2E_PRODUCT_NAME = 'Playwright E2E Test Product';
// Sembrado con stock=1 a propósito, para los tests negativos de stock insuficiente.
export const E2E_LOW_STOCK_PRODUCT_NAME = 'Playwright E2E Low Stock Product';
// Cliente fijo (no autoregistrado) con domicilio guardado, para views/sales.php.
export const E2E_SALES_CLIENTE_NOMBRE = 'Playwright E2E Sales Cliente';

/**
 * Registra e inicia sesión con una cuenta cliente nueva y desechable.
 *
 * Cada spec autenticado llama esto por su cuenta (en vez de compartir una sola
 * sesión vía storageState) a propósito: PHP bloquea el archivo de sesión por
 * request, así que varios tests compartiendo la misma sesión se serializan y
 * empiezan a expirar por timeout en cuanto corren en paralelo. Con una cuenta
 * por test, cada uno tiene su propia sesión y sí pueden correr en paralelo.
 */
export async function registerAndLogin(page: Page): Promise<{ nombre: string; email: string; password: string }> {
  const cliente = {
    nombre: 'Playwright QA',
    // Dominio con DNS real (requerido por la validación de registro) pero
    // correo único por test para no chocar entre corridas ni entre specs.
    email: `playwright-e2e+${Date.now()}-${Math.random().toString(36).slice(2, 8)}@example.com`,
    password: 'E2eTest!2026',
  };

  await page.goto('views/register.php');
  await page.locator('#nombre').fill(cliente.nombre);
  await page.locator('#email').fill(cliente.email);
  await page.locator('#password').fill(cliente.password);
  await page.locator('#confirm_password').fill(cliente.password);
  await page.getByRole('button', { name: 'REGISTRARME' }).click();

  await expect(page.getByText('Cuenta creada con éxito.')).toBeVisible();

  await page.locator('#email').fill(cliente.email);
  await page.locator('#password').fill(cliente.password);
  await page.getByRole('button', { name: 'Iniciar Sesión' }).click();

  // login.php redirige a index.php, que a su vez redirige al catálogo; no fijamos
  // una URL exacta, solo confirmamos que ya salimos de login.php.
  await page.waitForURL((url) => !url.toString().includes('views/login.php'));

  return cliente;
}

// Debe coincidir con scripts/seed_e2e_staff_accounts.php
export const E2E_STAFF_PASSWORD = 'E2eStaff!2026';
export const E2E_STAFF_EMAILS = {
  admin: 'e2e-admin@playwright.test',
  encargado: 'e2e-encargado@playwright.test',
  vendedor: 'e2e-vendedor@playwright.test',
  repartidor: 'e2e-repartidor@playwright.test',
} as const;

/**
 * Inicia sesión con una de las cuentas de staff fijas sembradas por
 * scripts/seed_e2e_staff_accounts.php (a diferencia del cliente, el staff no se
 * puede autoregistrar desde la UI pública, así que son cuentas fijas, no desechables).
 */
export async function loginAsStaff(page: Page, role: keyof typeof E2E_STAFF_EMAILS): Promise<void> {
  // login.php redirige de inmediato si ya hay una sesión activa (p.ej. la del
  // cliente que acaba de hacer checkout en el mismo `page`), así que primero
  // hay que cerrarla para que el formulario de login vuelva a aparecer.
  await page.goto('logout.php');
  await page.goto('views/login.php');
  await page.locator('#email').fill(E2E_STAFF_EMAILS[role]);
  await page.locator('#password').fill(E2E_STAFF_PASSWORD);
  await page.getByRole('button', { name: 'Iniciar Sesión' }).click();
  await page.waitForURL((url) => !url.toString().includes('views/login.php'));
}

/**
 * Lee el código de recuperación de contraseña más reciente para un correo desde
 * mail_log.txt: en localhost/CI (host contiene "localhost"/"127.0.0.1"),
 * appSendPlainTextEmail() (core/auth.php) escribe ahí en vez de enviar un correo
 * real, así que podemos completar el flujo de "Olvidé mi contraseña" de punta a
 * punta sin depender de un buzón real.
 */
export function getLatestPasswordResetCode(email: string): string {
  const logPath = path.resolve(__dirname, '../../mail_log.txt');
  const content = fs.readFileSync(logPath, 'utf-8');
  const blocks = content.split('========================================').filter((b) => b.includes(`PARA: ${email}`));
  const lastBlock = blocks[blocks.length - 1];
  if (!lastBlock) {
    throw new Error(`No se encontró ningún correo registrado para ${email} en mail_log.txt`);
  }
  const match = lastBlock.match(/c[oó]digo de seguridad es:\s*(\d{4,8})/i);
  if (!match) {
    throw new Error(`No se pudo extraer el código de seguridad del bloque más reciente para ${email}`);
  }
  return match[1];
}

/** Lee el "numero_pedido" (ej. WEB-XXXX) visible en el detalle de un pedido. */
export async function getNumeroPedido(page: Page, idPedido: number): Promise<string> {
  await page.goto(`views/detalle_compra.php?id=${idPedido}`);
  const heading = await page.locator('h4', { hasText: 'Pedido:' }).textContent();
  const match = heading?.match(/Pedido:\s*(\S+)/);
  if (!match) {
    throw new Error(`No se pudo leer el numero_pedido del pedido ${idPedido}: "${heading}"`);
  }
  return match[1];
}

export async function addProductToCartByName(page: Page, productName: string): Promise<void> {
  await page.goto(`views/catalogo.php?search=${encodeURIComponent(productName)}`);
  const card = page.locator('.product-card-container').filter({ hasText: productName }).first();
  await card.waitFor({ state: 'visible' });
  await card.locator('.card-action button').click();
}

export async function addSeededProductToCart(page: Page): Promise<void> {
  await addProductToCartByName(page, E2E_PRODUCT_NAME);
}

/** Llena y envía el formulario de checkout por Domicilio con los datos dados. */
export async function submitDomicilioCheckoutForm(
  page: Page,
  overrides: { nombre?: string; telefono?: string; direccion?: string } = {}
): Promise<void> {
  await page.goto('views/cart.php');
  await page.locator('#tipo_entrega').selectOption('Domicilio');
  await page.locator('#nombre').fill(overrides.nombre ?? 'Playwright QA');
  await page.locator('#telefono').fill(overrides.telefono ?? '3311234567');
  await page.locator('#direccion').fill(overrides.direccion ?? 'Calle Falsa 123, Colonia Centro');
  await page.getByRole('button', { name: 'Confirmar Pedido' }).click();
}

/** Llena y envía el formulario de checkout por "Recoger en Sucursal" (sin dirección). */
export async function submitSucursalCheckoutForm(
  page: Page,
  overrides: { nombre?: string; telefono?: string } = {}
): Promise<void> {
  await page.goto('views/cart.php');
  await page.locator('#tipo_entrega').selectOption('Sucursal');
  await page.locator('#nombre').fill(overrides.nombre ?? 'Playwright QA');
  await page.locator('#telefono').fill(overrides.telefono ?? '3311234567');
  await page.getByRole('button', { name: 'Confirmar Pedido' }).click();
}

/**
 * Registra + inicia sesión con una cuenta cliente nueva, agrega el producto
 * sembrado al carrito y completa el checkout por "Domicilio" (evita la
 * validación de stock por sucursal, que no aplica a este flujo).
 * Devuelve el id_pedido creado.
 */
export async function completeDomicilioCheckout(page: Page): Promise<number> {
  const cliente = await registerAndLogin(page);
  await addSeededProductToCart(page);
  await submitDomicilioCheckoutForm(page, { nombre: cliente.nombre });

  // "¡Pedido Confirmado!" -> Continuar
  await page.getByRole('button', { name: 'Continuar' }).click();
  // Como es una dirección manual nueva, sigue "¿Deseas guardar esta dirección...?" -> No, continuar
  await page.getByRole('button', { name: 'No, continuar' }).click();

  await page.waitForURL(/gracias\.php\?id=\d+/);

  const url = new URL(page.url());
  return Number(url.searchParams.get('id'));
}
