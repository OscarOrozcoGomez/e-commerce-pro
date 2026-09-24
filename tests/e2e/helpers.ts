import type { Page } from '@playwright/test';
import { expect } from './fixtures';
import * as fs from 'fs';
import * as path from 'path';

// Debe coincidir con scripts/seed_e2e_test_data.php
export const E2E_PRODUCT_NAME = 'Playwright E2E Test Product';
// Sembrado con stock=1 a propósito, para los tests negativos de stock insuficiente.
export const E2E_LOW_STOCK_PRODUCT_NAME = 'Playwright E2E Low Stock Product';
// Sembrado con stock=0 en la sucursal por defecto, para el test negativo de
// "producto agotado" en views/sales.php. Debe quedarse SIEMPRE en 0 -- para pruebas que
// necesiten posponer/reactivar (mutan el stock), usar E2E_PURCHASE_ORDER_PRODUCT_NAME en vez
// de este, para no romper este test (ver scripts/seed_e2e_test_data.php).
export const E2E_OUT_OF_STOCK_PRODUCT_NAME = 'Playwright E2E Out Of Stock Product';
// Uso exclusivo del test de "posponer" en purchase_orders.php: se reactiva con el boton
// "Devolver" de la pestana Pospuestos (no toca el inventario), asi que se siembra con un
// stock_minimo generoso solo para que siga calificando para la lista de resurtido.
export const E2E_PURCHASE_ORDER_PRODUCT_NAME = 'Playwright E2E Purchase Order Product';
// Dos productos de uso exclusivo del ciclo REAL de Ordenes de Compra (generar orden ->
// pestana "Ordenes Abiertas" -> surtir/cancelar). Debe coincidir con scripts/seed_e2e_test_data.php.
export const E2E_PO_SURTIR_PRODUCT_NAME = 'Playwright E2E PO Surtir Product';
export const E2E_PO_CANCEL_PRODUCT_NAME = 'Playwright E2E PO Cancel Product';
// Sin precio_venta/precio_costo/sku/codigo_barras ni fila en inventario_almacen a
// proposito, para views/productos_incompletos.php.
export const E2E_PRODUCTO_INCOMPLETO_NOMBRE = 'Playwright E2E Producto Incompleto';
// Direccion de texto que se escribe en el checkout a domicilio. La ZONA de envio NO se decide por este
// texto en las pruebas: se fija con coordenadas via fijarUbicacionEntrega() (ver abajo).
export const E2E_DIRECCION_ENTREGA = "Av. Vallarta 1500, Guadalajara, Jal.";

// Coordenadas de prueba respecto a la sucursal (core/delivery_zone_utils.php: 20.605,-103.240; radio local
// 18 km, foraneo hasta 40 km, mas lejos = indeterminado). Se mandan como maps_link "query=lat,lng", que
// el servidor parsea SIN llamada HTTP (obtenerCoordenadasDesdeUrl). Sin esto, api/delivery_zone_quote.php
// y dbCreatePublicOrder geocodifican el TEXTO con Google en el servidor (Geocoding API real, con costo, y
// con un resultado que puede cambiar) -- y las coordenadas mandan sobre el texto.
export const E2E_UBICACION_ENTREGA = {
  local: { lat: 20.605, lng: -103.24 },
  foranea: { lat: 20.405, lng: -103.24 },
  indeterminada: { lat: 20.105, lng: -103.24 },
} as const;
// Uso exclusivo del import de "Pedido de mayoreo (B Life)" en views/purchase_orders.php
// (pestaña "Cargar Pedido"). precio_costo=10.00 fijo -- ver scripts/seed_e2e_test_data.php.
export const E2E_MAYOREO_PRODUCT_NAME = 'Playwright E2E Mayoreo Product';
// Segundo producto (junto con E2E_PRODUCT_NAME) de uso exclusivo de
// cleanup-reservations.staff.spec.ts -- necesita un pedido real de 2 renglones distintos.
export const E2E_CLEANUP_PRODUCT_NAME = 'Playwright E2E Cleanup Reservations Product';
// Cliente fijo (no autoregistrado) con domicilio guardado, para views/sales.php.
export const E2E_SALES_CLIENTE_NOMBRE = 'Playwright E2E Sales Cliente';
// Clientes "legados" SIN telefono (ya no se pueden dar de alta asi desde el PR #202). A solo se lee; B lo modifica
// el test de "agregar telefono desde la alerta". Deben coincidir con scripts/seed_e2e_test_data.php.
export const E2E_CLIENTE_SIN_TELEFONO_A = 'Playwright E2E Cliente Sin Telefono A';
export const E2E_CLIENTE_SIN_TELEFONO_B = 'Playwright E2E Cliente Sin Telefono B';

/**
 * Telefono de 10 digitos unico por llamada. Desde el PR #202 register.php lo exige, y si coincide con el de un
 * cliente existente la cuenta se enlaza a ESE cliente (findClienteByPhone), asi que no puede repetirse.
 */
export function telefonoUnico(): string {
  return `33${Math.floor(Math.random() * 1e8).toString().padStart(8, "0")}`;
}

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
  await page.locator('#telefono').fill(telefonoUnico());
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

/**
 * Tras crear un cliente en Administrar Clientes, la pagina recarga y (con permiso de ventas) abre la oferta
 * "Cliente creado ... ¿agendarle una venta ahora?" (#modal-venta-tras-crear, commit cfcc8d3). Su overlay tapa
 * TODA la pagina: hay que cerrarla ("Ahora no") antes de tocar la fila del cliente. Espera al load porque el modal
 * esta al final del documento y aun no existe cuando la tabla ya se ve.
 */
export async function cerrarOfertaVentaSiAparece(page: Page): Promise<void> {
  await page.waitForLoadState('load');
  const oferta = page.locator('#modal-venta-tras-crear');
  if ((await oferta.count()) === 0) return;
  await expect(oferta).toBeVisible();
  await oferta.getByRole('link', { name: 'Ahora no' }).click();
  await expect(oferta).toBeHidden();
}

// Debe coincidir con scripts/seed_e2e_staff_accounts.php
export const E2E_STAFF_PASSWORD = 'E2eStaff!2026';
export const E2E_STAFF_EMAILS = {
  admin: 'e2e-admin@playwright.test',
  encargado: 'e2e-encargado@playwright.test',
  vendedor: 'e2e-vendedor@playwright.test',
  repartidor: 'e2e-repartidor@playwright.test',
  // Encargado asignado a la sucursal de pickup (resolvePickupWarehouseId), no a
  // la sucursal "default" -- necesario para views/pickup_notifications.php.
  encargadoPickup: 'e2e-encargado-pickup@playwright.test',
  // isSuperAdmin() = isAdmin() + usuarios.es_superadmin -- distinto del "admin" de arriba
  // (que es admin normal, sin ese flag). Necesario para el catalogo de permisos en
  // views/roles_permisos.php.
  superadmin: 'e2e-superadmin@playwright.test',
  // Encargado con override individual 'conceder ver_auditoria' (scripts/seed_e2e_staff_accounts.php): abre
  // Logs de Actividad > Movimientos sin ser admin.
  auditor: 'e2e-auditor@playwright.test',
  // Vendedor exclusivo de permisos-en-vivo.staff.spec.ts (se le cambian permisos a media sesion).
  permisosVivo: 'e2e-permisos-vivo@playwright.test',
  // Encargados con un permiso menos por override individual (ver scripts/seed_e2e_staff_accounts.php).
  encargadoSinVentas: 'e2e-encargado-sin-ventas@playwright.test',
  encargadoSinAgendar: 'e2e-encargado-sin-agendar@playwright.test',
  // Encargados con override de WhatsApp: lector (solo ver_conversaciones_whatsapp) y feedback (+ dar_feedback_asistente_ia).
  whatsappLector: 'e2e-whatsapp-lector@playwright.test',
  whatsappFeedback: 'e2e-whatsapp-feedback@playwright.test',
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
  // No basta ".card-action button": a cualquier sesión iniciada (cliente o staff),
  // catalogRenderProductCard() (core/catalogo_utils.php) también le pinta un botón verde
  // "Compartir por WhatsApp" ahí mismo -- hay que apuntar al de agregar al carrito por su
  // onclick, no por posición.
  await card.locator('.card-action button[onclick^="handleAddToCart"]').click();
}

export async function addSeededProductToCart(page: Page): Promise<void> {
  await addProductToCartByName(page, E2E_PRODUCT_NAME);
}

/**
 * Si la dirección de Domicilio queda fuera de la periferia de Guadalajara, cart.php
 * muestra un Swal "Tu domicilio está fuera de la periferia" (cotización en vivo vía
 * api/delivery_zone_quote.php) que hay que confirmar ("De acuerdo, confirmar") antes de que
 * el pedido se registre -- ver el bloque `if (tipoEntregaSeleccionada === 'Domicilio')` en
 * views/cart.php. Es condicional (solo aparece si costo_envio > 0), así que no falla si no
 * aparece. Con la zona por defecto de los helpers ('local') NO aparece; solo con zona 'foranea'
 * (ver fijarUbicacionEntrega). Se conserva la tolerancia por si un spec pide zona foránea.
 */
export async function confirmDomicilioZoneFeeIfPresent(page: Page): Promise<void> {
  const confirmar = page.getByRole('button', { name: 'De acuerdo, confirmar' });
  try {
    await confirmar.waitFor({ state: 'visible', timeout: 3000 });
    await confirmar.click();
  } catch {
    // No aplicó cargo de envío foráneo (dirección dentro de la periferia) -- seguir.
  }
}

/**
 * Captura el JSON de la venta REAL que manda sales.php a api/ventas.php.
 *
 * procesarVenta() (views/sales.php) hace DOS POST a ese endpoint por cada cobro: primero una consulta
 * previa `modo=plan_lotes` (verificacion FEFO de lotes: si hay algo que verificar abre el modal
 * "Verifica el lote antes de cobrar") y luego la venta de verdad. Interceptar sin distinguirlas
 * captura la respuesta equivocada (la del plan) y, ademas, compite con el location.reload() que la
 * pagina hace al terminar. Aqui el plan pasa tal cual y solo se lee/reenvia la venta real.
 */
export async function capturarRespuestaVenta(page: Page): Promise<{ resultado: { success?: boolean; message?: string; numero_pedido?: string } | null }> {
  const captura: { resultado: { success?: boolean; message?: string; numero_pedido?: string } | null } = { resultado: null };
  await page.route('**/api/ventas.php', async (route) => {
    const cuerpo = route.request().postData() ?? '';
    if (cuerpo.includes('plan_lotes')) {
      await route.continue();
      return;
    }
    const response = await route.fetch();
    captura.resultado = await response.json();
    await route.fulfill({ response });
  });
  return captura;
}

/**
 * Fija la ubicacion de entrega del checkout por coordenadas (campo oculto #maps_link) para que la zona y
 * el cargo de envio sean deterministas. Debe llamarse DESPUES de escribir la direccion: cart.php limpia
 * ese campo cuando el cliente edita la direccion a mano.
 */
export async function fijarUbicacionEntrega(page: Page, zona: keyof typeof E2E_UBICACION_ENTREGA): Promise<void> {
  const { lat, lng } = E2E_UBICACION_ENTREGA[zona];
  await page.locator('#maps_link').evaluate((el, link) => {
    (el as HTMLInputElement).value = link;
  }, `https://www.google.com/maps/search/?api=1&query=${lat},${lng}`);
}

/** Llena y envía el formulario de checkout por Domicilio con los datos dados. */
export async function submitDomicilioCheckoutForm(
  page: Page,
  overrides: { nombre?: string; telefono?: string; direccion?: string; zona?: keyof typeof E2E_UBICACION_ENTREGA } = {}
): Promise<void> {
  await page.goto('views/cart.php');
  await page.locator('#tipo_entrega').selectOption('Domicilio');
  await page.locator('#nombre').fill(overrides.nombre ?? 'Playwright QA');
  await page.locator('#telefono').fill(overrides.telefono ?? '3311234567');
  await page.locator('#direccion').fill(overrides.direccion ?? E2E_DIRECCION_ENTREGA);
  await fijarUbicacionEntrega(page, overrides.zona ?? 'local');
  await page.getByRole('button', { name: 'Confirmar Pedido' }).click();
  await confirmDomicilioZoneFeeIfPresent(page);
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
 * sembrado al carrito y completa el checkout por "Recoger en Sucursal" (con
 * el producto principal, que sí tiene stock ahí -- ver
 * scripts/seed_e2e_test_data.php). Devuelve el id_pedido creado.
 */
export async function completeSucursalCheckout(page: Page): Promise<number> {
  const cliente = await registerAndLogin(page);
  await addSeededProductToCart(page);
  await submitSucursalCheckoutForm(page, { nombre: cliente.nombre });

  // A diferencia de Domicilio, Sucursal no pregunta por guardar dirección: va
  // directo a "¡Pedido Confirmado!" -> Continuar -> gracias.php.
  await page.getByRole('button', { name: 'Continuar' }).click();
  await page.waitForURL(/gracias\.php\?id=\d+/);

  const url = new URL(page.url());
  return Number(url.searchParams.get('id'));
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

/**
 * Fecha futura en formato YYYY-MM-DD (para inputs type="date"), en componentes de
 * fecha LOCALES -- toISOString() convierte a UTC, que puede adelantar o atrasar un
 * día segun la hora local en que corre el test (ej. America/Mexico_City es UTC-6:
 * pasadas las 6pm locales, "hoy" en UTC ya es "mañana").
 */
export function fechaFutura(diasDesdeHoy: number): string {
  const d = new Date();
  d.setDate(d.getDate() + diasDesdeHoy);
  const year = d.getFullYear();
  const month = String(d.getMonth() + 1).padStart(2, '0');
  const day = String(d.getDate()).padStart(2, '0');
  return `${year}-${month}-${day}`;
}
