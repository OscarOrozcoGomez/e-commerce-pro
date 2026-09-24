import type { Page } from '@playwright/test';
import { test, expect } from './fixtures';
import {
  addSeededProductToCart,
  capturarRespuestaVenta,
  fijarUbicacionEntrega,
  getNumeroPedido,
  loginAsStaff,
  registerAndLogin,
  E2E_DIRECCION_ENTREGA,
  E2E_PRODUCT_NAME,
  E2E_SALES_CLIENTE_NOMBRE,
} from './helpers';
import { fijarPrecioOriginal, leerEntorno } from './db-utils';

// Commit 09d970e (views/entregas.php): cada producto de la tarjeta de entrega muestra su FOTO, su subtotal, "N × $unitario
// c/u" (si son varias piezas) y el precio original tachado si tuvo descuento; al tocar la foto se abre a pantalla completa
// (y el boton "atras" del celular la cierra); los placeholders NO se amplian; y cada tarjeta con coordenadas ofrece
// "Ver fachada" (Street View) SOLO si el entorno tiene API key de Google Maps.
// El repartidor es quien usa esta pantalla para confirmar que lleva el producto correcto y cuanto cuesta.

const PIXEL = 'data:image/gif;base64,R0lGODlhAQABAIAAAAAAAP///yH5BAEAAAAALAAAAAABAAEAAAIBRAA7';

async function crearPedidoWeb(page: Page, cantidad: number): Promise<void> {
  const cliente = await registerAndLogin(page);
  await addSeededProductToCart(page);
  await page.goto('views/cart.php');
  for (let i = 1; i < cantidad; i++) {
    await page.locator('.cart-qty-btn', { hasText: '+' }).click();
  }
  await expect(page.locator('.cart-qty-input')).toHaveValue(String(cantidad));
  await page.locator('#tipo_entrega').selectOption('Domicilio');
  await page.locator('#nombre').fill(cliente.nombre);
  await page.locator('#telefono').fill('3311234567');
  await page.locator('#direccion').fill(E2E_DIRECCION_ENTREGA);
  await fijarUbicacionEntrega(page, 'local');
  await page.getByRole('button', { name: 'Confirmar Pedido' }).click();
  await page.getByRole('button', { name: 'Continuar' }).click();
  await page.getByRole('button', { name: 'No, continuar' }).click();
  await page.waitForURL(/gracias\.php\?id=\d+/);
}

async function asignarAlRepartidor(page: Page, idPedido: number): Promise<void> {
  await loginAsStaff(page, 'encargado');
  await page.goto('views/asignar_entregas.php');
  const select = page.locator(`#repartidor-${idPedido}`);
  await expect(select).toBeVisible();
  await select.selectOption({ label: 'Playwright E2E Repartidor' });
  await page.locator(`#fecha-${idPedido}`).fill(new Date().toISOString().slice(0, 10));
  await page.locator('.assign-delivery-card').filter({ has: select }).getByRole('button', { name: 'Asignar' }).click();
  await expect(page.getByText('Pedido asignado correctamente.')).toBeVisible();
}

/** Crea un pedido web de `cantidad` piezas, lo asigna y devuelve la tarjeta tal como la ve el repartidor. */
async function tarjetaDelRepartidor(page: Page, cantidad: number, precioOriginal?: number) {
  await crearPedidoWeb(page, cantidad);
  const idPedido = Number(new URL(page.url()).searchParams.get('id'));
  if (precioOriginal !== undefined) {
    // Caso de oferta: el precio original (de lista) es MAYOR que el unitario que se cobro.
    fijarPrecioOriginal(await getNumeroPedido(page, idPedido), precioOriginal);
  }
  await asignarAlRepartidor(page, idPedido);
  await loginAsStaff(page, 'repartidor');
  await page.goto('views/entregas.php?fecha_entrega=');
  const tarjeta = page.locator(`[data-pedido-id="${idPedido}"]`);
  await expect(tarjeta).toBeVisible();
  return tarjeta;
}

test.describe('Entregas: foto, subtotal y precio de cada producto', () => {
  test.describe.configure({ timeout: 120_000 });

  test('varias piezas: muestra el subtotal en grande y "N × $unitario c/u", con su foto y sin precio tachado', async ({ page }) => {
    const tarjeta = await tarjetaDelRepartidor(page, 2);
    const producto = tarjeta.locator('.section-products li').first();

    await expect(producto).toContainText(`2x ${E2E_PRODUCT_NAME}`);
    await expect(producto.locator('img.entrega-item-img')).toHaveAttribute('alt', E2E_PRODUCT_NAME);
    await expect(producto.locator('.entrega-item-precio strong')).toHaveText('$199.98');
    await expect(producto.locator('.entrega-item-precio')).toContainText('(2 × $99.99 c/u)');
    // Sin descuento no hay precio original tachado.
    await expect(producto.locator('.entrega-item-precio s')).toHaveCount(0);
  });

  test('un producto vendido en oferta muestra su precio de lista TACHADO junto al precio cobrado', async ({ page }) => {
    // precio_original (lista) 129.99 > precio_unitario (cobrado) 99.99: es lo que dispara el tachado.
    const tarjeta = await tarjetaDelRepartidor(page, 1, 129.99);
    const precio = tarjeta.locator('.section-products li').first().locator('.entrega-item-precio');

    await expect(precio.locator('strong')).toHaveText('$99.99');
    await expect(precio.locator('s')).toHaveText('$129.99');
  });

  test('con varias piezas en oferta, el precio tachado es el de lista POR TODAS las piezas', async ({ page }) => {
    const tarjeta = await tarjetaDelRepartidor(page, 2, 129.99);
    const precio = tarjeta.locator('.section-products li').first().locator('.entrega-item-precio');

    await expect(precio.locator('strong')).toHaveText('$199.98');
    await expect(precio).toContainText('(2 × $99.99 c/u)');
    await expect(precio.locator('s')).toHaveText('$259.98');
  });

  // El POS (sales.php) guarda un descuento de linea en monto_descuento y deja precio_original = precio_unitario; el subtotal
  // ya viene rebajado. La tarjeta debe mostrarlo igual que una oferta: precio de lista TACHADO junto al subtotal cobrado
  // (entregaPreciosItem en core/entrega_item_utils.php). Antes solo se tachaba la oferta, y el repartidor veia un subtotal
  // rebajado sin explicacion (y con 2+ piezas "N × $unitario c/u" no cuadraba con el total).
  async function agendarConDescuentoYVerComoRepartidor(page: Page, cantidad: number, descuento: string) {
    await loginAsStaff(page, 'encargado');
    await page.goto('views/sales.php');
    const form = page.locator('.formulario-venta').first();
    await form.locator('.cliente_nombre').fill(E2E_SALES_CLIENTE_NOMBRE);
    await form.locator('.cliente_nombre').press('Tab');
    await expect(form.locator('.cliente_telefono')).not.toHaveValue('', { timeout: 10000 });
    await form.locator('.buscador-producto').fill(E2E_PRODUCT_NAME);
    await form.locator('.producto-dropdown .item').filter({ hasText: E2E_PRODUCT_NAME }).first().click();
    const item = form.locator('.producto-item').first();
    if (cantidad > 1) {
      await item.locator('.cantidad').fill(String(cantidad));
      await item.locator('.cantidad').press('Tab');
    }
    await item.locator('.descuento-linea').fill(descuento);
    await item.locator('.descuento-linea').press('Tab');

    const venta = await capturarRespuestaVenta(page);
    await form.getByRole('button', { name: 'Agendar Pedido' }).click();
    await expect.poll(() => venta.resultado).not.toBeNull();
    expect(venta.resultado!.success, JSON.stringify(venta.resultado)).toBe(true);
    const numero = String(venta.resultado!.numero_pedido);

    // sales.php recarga la pagina justo despues de agendar: un goto inmediato puede chocar con esa recarga (ERR_ABORTED).
    await expect(async () => {
      await page.goto('views/asignar_entregas.php');
    }).toPass({ timeout: 15000 });
    const tarjetaAsignar = page.locator('.assign-delivery-card').filter({ hasText: numero });
    await tarjetaAsignar.locator('select[id^="repartidor-"]').selectOption({ label: 'Playwright E2E Repartidor' });
    await tarjetaAsignar.locator('input[id^="fecha-"]').fill(new Date().toISOString().slice(0, 10));
    await tarjetaAsignar.getByRole('button', { name: 'Asignar' }).click();
    await expect(page.getByText('Pedido asignado correctamente.')).toBeVisible();

    await loginAsStaff(page, 'repartidor');
    await page.goto('views/entregas.php?fecha_entrega=');
    return page.locator('[data-pedido-id]').filter({ hasText: numero }).locator('.section-products li').first().locator('.entrega-item-precio');
  }

  test('descuento de línea capturado en el POS: la tarjeta tacha el precio de lista y muestra el subtotal ya rebajado', async ({ page }) => {
    const precio = await agendarConDescuentoYVerComoRepartidor(page, 1, '10');
    await expect(precio.locator('strong')).toHaveText('$89.99');
    await expect(precio.locator('s')).toHaveText('$99.99');
  });

  test('descuento de línea con 2 piezas: el subtotal, el "c/u" y el precio de lista tachado cuadran entre sí', async ({ page }) => {
    const precio = await agendarConDescuentoYVerComoRepartidor(page, 2, '20');
    await expect(precio.locator('strong')).toHaveText('$179.98');
    await expect(precio).toContainText('(2 × $99.99 c/u)');
    await expect(precio.locator('s')).toHaveText('$199.98');
  });

  test('una sola pieza: solo el subtotal, sin la aclaración "c/u"', async ({ page }) => {
    const tarjeta = await tarjetaDelRepartidor(page, 1);
    const producto = tarjeta.locator('.section-products li').first();

    await expect(producto).toContainText(`1x ${E2E_PRODUCT_NAME}`);
    await expect(producto.locator('.entrega-item-precio strong')).toHaveText('$99.99');
    await expect(producto.locator('.entrega-item-precio')).not.toContainText('c/u');
  });

  test('un producto sin foto (placeholder) no se puede ampliar', async ({ page }) => {
    const tarjeta = await tarjetaDelRepartidor(page, 1);
    const foto = tarjeta.locator('img.entrega-item-img').first();

    // El producto de prueba no tiene imagen: el servidor marca el placeholder con "sin-foto".
    await expect(foto).toHaveClass(/sin-foto/);
    await foto.click();
    await expect(page.locator('#entrega-lightbox')).toBeHidden();
  });

  test('una foto real se abre a pantalla completa y se cierra con la X, tocando, con Esc y con el botón "atrás"', async ({ page }) => {
    const tarjeta = await tarjetaDelRepartidor(page, 1);
    const foto = tarjeta.locator('img.entrega-item-img').first();
    // Se simula un producto CON foto (el sembrado no tiene): la miniatura deja de ser placeholder.
    await foto.evaluate((el, src) => {
      el.classList.remove('sin-foto');
      (el as HTMLImageElement).src = src;
    }, PIXEL);

    const visor = page.locator('#entrega-lightbox');
    const urlAntes = page.url();

    // Abre: muestra el nombre del producto y bloquea el scroll de la pagina de atras.
    await foto.click();
    await expect(visor).toBeVisible();
    await expect(page.locator('#entrega-lightbox-titulo')).toHaveText(E2E_PRODUCT_NAME);
    expect(await page.evaluate(() => document.body.style.overflow)).toBe('hidden');

    // 1) Boton X.
    await page.getByRole('button', { name: 'Cerrar foto' }).click();
    await expect(visor).toBeHidden();
    expect(await page.evaluate(() => document.body.style.overflow)).toBe('');

    // 2) Tocar la propia foto.
    await foto.click();
    await expect(visor).toBeVisible();
    await page.locator('#entrega-lightbox-img').click();
    await expect(visor).toBeHidden();

    // 3) Tecla Esc.
    await foto.click();
    await expect(visor).toBeVisible();
    await page.keyboard.press('Escape');
    await expect(visor).toBeHidden();

    // 4) Boton "atras" del celular: cierra la foto en vez de salirse de la pagina de entregas.
    await foto.click();
    await expect(visor).toBeVisible();
    await page.goBack();
    await expect(visor).toBeHidden();
    expect(page.url()).toBe(urlAntes);
  });

  test('con el teclado: Enter sobre la foto la abre', async ({ page }) => {
    const tarjeta = await tarjetaDelRepartidor(page, 1);
    const foto = tarjeta.locator('img.entrega-item-img').first();
    await foto.evaluate((el, src) => {
      el.classList.remove('sin-foto');
      (el as HTMLImageElement).src = src;
    }, PIXEL);

    await foto.focus();
    await page.keyboard.press('Enter');
    await expect(page.locator('#entrega-lightbox')).toBeVisible();
    await page.keyboard.press('Escape');
    await expect(page.locator('#entrega-lightbox')).toBeHidden();
  });
});

test.describe('Entregas: "Ver fachada" (Street View)', () => {
  test.describe.configure({ timeout: 120_000 });
  const conClave = leerEntorno().google_maps_key;

  test('con API key de Google Maps: la tarjeta con ubicación ofrece "Ver fachada" y la hoja inferior se abre con la dirección', async ({ page }) => {
    test.skip(!conClave, 'Este entorno no tiene GOOGLE_MAPS_API_KEY: ver la prueba de abajo.');
    const tarjeta = await tarjetaDelRepartidor(page, 1);

    const boton = tarjeta.getByRole('button', { name: /Ver fachada/ });
    await expect(boton).toBeVisible();
    // Las coordenadas viajan en la propia tarjeta: sirven al visor y a "Navegar".
    await expect(boton).toHaveAttribute('data-lat', /^-?\d+(\.\d+)?$/);
    await expect(boton).toHaveAttribute('data-lng', /^-?\d+(\.\d+)?$/);

    await boton.click();
    const hoja = page.locator('#modal-fachada');
    await expect(hoja).toBeVisible();
    await expect(page.locator('#fachada-direccion')).toContainText('Vallarta');
    await expect(page.locator('#fachada-navegar')).toHaveAttribute('href', /.+/);
  });

  test('sin API key de Google Maps: la tarjeta NO ofrece "Ver fachada" y no se carga el visor', async ({ page }) => {
    test.skip(conClave, 'Este entorno SÍ tiene GOOGLE_MAPS_API_KEY: ver la prueba de arriba.');
    const tarjeta = await tarjetaDelRepartidor(page, 1);

    await expect(tarjeta.getByRole('button', { name: /Ver fachada/ })).toHaveCount(0);
    await expect(page.locator('#modal-fachada')).toHaveCount(0);
  });
});
