import type { Page } from '@playwright/test';
import { test, expect } from './fixtures';
import {
  E2E_DIRECCION_ENTREGA,
  E2E_PRODUCT_NAME,
  addProductToCartByName,
  completeDomicilioCheckout,
  fijarUbicacionEntrega,
  getNumeroPedido,
  loginAsStaff,
  registerAndLogin,
  telefonoUnico,
} from './helpers';
import { consultaLote, consultaPedido, consultaProducto, prepararOferta } from './db-utils';

// Estrategia de venta de productos por caducar (PR #213). "Poner en oferta" en Caducidades agrega el producto a la categoría
// Ofertas y fija precio_oferta con una ESCALERA por urgencia (core/oferta_pricing.php): planificar/sin rotación -15 %,
// urgente -30 %, crítico = piso (costo + $50); nunca sube un precio ya bajado. Lo que se guarda lo leen la ficha, el POS,
// el catálogo, el checkout público y Entregas. Aquí se prueba el recorrido completo con un producto propio (costo 100,
// precio 199, lote a 20 días) y se compara SIEMPRE contra lo que quedó guardado en la BD, no contra un número fijo: la
// urgencia depende de la fecha y del historial de ventas.
//   Sin Alex ni modelo (solo pantallas y API). La lógica de la escalera ya la cubre OfertaCaducidadPricingTest (PHPUnit).

const NOMBRE = 'Playwright E2E Oferta Product';
const PISO = 150; // costo 100 + $50
const PRECIO = 199;

test.describe.configure({ mode: 'serial', timeout: 120_000 });

test.beforeAll(() => {
  prepararOferta();
});

let precioOferta = 0;

const filaDelTablero = (page: Page) => page.locator('#tab-caducidades table tbody tr').filter({ hasText: NOMBRE });

test.describe('Poner en oferta: del tablero de Caducidades a todos los canales', () => {
  test('sin permiso no se puede: un vendedor recibe "No autorizado" y el producto no cambia', async ({ page }) => {
    const lote = consultaLote('E2E-OFERTA-LOTE')!;
    await loginAsStaff(page, 'vendedor');
    const res = await page.request.post('api/lotes_manager.php', {
      data: { action: 'poner_producto_en_oferta', id_producto: lote.id_producto, id_lote: lote.id_lote, csrf_token: '' },
      failOnStatusCode: false,
    });
    expect(await res.text()).toMatch(/no autorizado/i);
    const producto = consultaProducto(NOMBRE)!;
    expect(producto.en_ofertas).toBe(false);
    expect(producto.precio_oferta).toBeNull();
  });

  test('el botón pone el producto en Ofertas con el precio de la escalera (nunca bajo el piso) y queda gestionado', async ({ page }) => {
    await loginAsStaff(page, 'admin');
    await page.goto(`views/caducidades.php?q=${encodeURIComponent(NOMBRE)}`);
    await expect(filaDelTablero(page)).toBeVisible();

    page.once('dialog', (dialog) => dialog.accept());
    await filaDelTablero(page).locator('a[title^="Poner en oferta"]').click();
    const aviso = page.getByText(/en Ofertas a \$\d+\.\d{2}/);
    await expect(aviso).toBeVisible();
    precioOferta = Number((await aviso.first().innerText()).match(/\$(\d+\.\d{2})/)![1]);

    const producto = consultaProducto(NOMBRE)!;
    expect(producto.en_ofertas, 'debe estar en la categoría Ofertas').toBe(true);
    expect(producto.gestionado, 'debe quedar gestionado (el cron lo baja y lo retira solo)').toBe(true);
    expect(producto.precio_oferta).toBe(precioOferta);
    // Descuento real y nunca por debajo del piso: costo + $50.
    expect(precioOferta).toBeGreaterThanOrEqual(PISO);
    expect(precioOferta).toBeLessThan(PRECIO);
    // Es uno de los escalones posibles: piso (urgente/crítico) o -15 % (planificar/sin rotación).
    expect([PISO, 169.15]).toContain(precioOferta);
  });

  test('un segundo clic dice "Ya estaba en Ofertas" y NUNCA sube el precio', async ({ page }) => {
    await loginAsStaff(page, 'admin');
    await page.goto(`views/caducidades.php?q=${encodeURIComponent(NOMBRE)}`);
    page.once('dialog', (dialog) => dialog.accept());
    await filaDelTablero(page).locator('a[title^="Poner en oferta"]').click();
    await expect(page.getByText(/Ya estaba en Ofertas\. Precio de oferta: \$\d+\.\d{2}/)).toBeVisible();

    const producto = consultaProducto(NOMBRE)!;
    expect(producto.precio_oferta).not.toBeNull();
    expect(producto.precio_oferta!).toBeLessThanOrEqual(precioOferta);
  });

  test('la ficha del producto (products.php) muestra ese precio de oferta', async ({ page }) => {
    await loginAsStaff(page, 'admin');
    await page.goto('views/products.php');
    await page.locator('#buscar_producto').fill(NOMBRE);
    const fila = page.locator('#tabla-productos-body tr').filter({ hasText: NOMBRE });
    await fila.locator('button.blue').click();
    await expect(page.locator('#precio_oferta')).toHaveValue(precioOferta.toFixed(2));
  });

  test('queda auditado como ALERTA, con la urgencia del lote y avisando que queda gestionado', async ({ page }) => {
    await loginAsStaff(page, 'admin');
    await page.goto(`views/activity_logs.php?vista=movimientos&accion=PRODUCTO_EN_OFERTA&q=${encodeURIComponent(NOMBRE)}`);
    const fila = page.locator('.mov-item').first();
    await expect(fila.locator('.sev')).toHaveText('ALERTA');
    await expect(fila.locator('.mov-detalle')).toContainText('urgencia del lote');
    await expect(fila.locator('.mov-detalle')).toContainText('queda gestionado');
    await expect(fila.locator('.mov-actor-nombre')).toHaveText('Playwright E2E Admin');
  });

  test('POS: el producto se precarga al precio de oferta con la etiqueta OFERTA (y el precio normal en su ayuda)', async ({ page }) => {
    await loginAsStaff(page, 'vendedor');
    await page.goto('views/sales.php');
    const form = page.locator('.formulario-venta').first();
    await form.locator('.buscador-producto').fill(NOMBRE);
    await form.locator('.producto-dropdown .item').filter({ hasText: NOMBRE }).first().click();

    const item = form.locator('.producto-item').first();
    // Campo numérico: muestra 150 (no 150.00), se compara como número.
    await expect.poll(async () => Number(await item.locator('.precio-unitario').inputValue())).toBe(precioOferta);
    const etiqueta = item.locator('span', { hasText: /^OFERTA$/ });
    await expect(etiqueta).toBeVisible();
    await expect(etiqueta).toHaveAttribute('title', `Precio normal: $${PRECIO.toFixed(2)}`);
  });

  test('catálogo público: la tarjeta muestra el precio de lista y, junto a él, el precio de oferta', async ({ page }) => {
    await page.goto(`views/catalogo.php?search=${encodeURIComponent(NOMBRE)}`);
    const tarjeta = page.locator('.product-card-container').filter({ hasText: NOMBRE }).first();
    await expect(tarjeta).toBeVisible();
    await expect(tarjeta).toContainText(`Precio de lista: $${PRECIO.toFixed(2)}`);
    await expect(tarjeta).toContainText(`$${precioOferta.toFixed(2)}`);
  });

  test('checkout público: el pedido guarda el precio de oferta (no el normal) y el total cuadra', async ({ page }) => {
    const cliente = await registerAndLogin(page);
    await addProductToCartByName(page, NOMBRE);
    await page.goto('views/cart.php');
    await page.locator('#tipo_entrega').selectOption('Domicilio');
    await page.locator('#nombre').fill(cliente.nombre);
    await page.locator('#telefono').fill(telefonoUnico());
    await page.locator('#direccion').fill(E2E_DIRECCION_ENTREGA);
    await fijarUbicacionEntrega(page, 'local');
    await page.getByRole('button', { name: 'Confirmar Pedido' }).click();
    await page.getByRole('button', { name: 'Continuar' }).click();
    await page.getByRole('button', { name: 'No, continuar' }).click();
    await page.waitForURL(/gracias\.php\?id=\d+/);

    const numero = await getNumeroPedido(page, Number(new URL(page.url()).searchParams.get('id')));
    const pedido = consultaPedido(numero)!;
    expect(pedido.renglones).toHaveLength(1);
    expect(pedido.renglones[0].precio_unitario).toBe(precioOferta);
    expect(pedido.renglones[0].subtotal).toBe(precioOferta);
    expect(pedido.total).toBeCloseTo(precioOferta + pedido.costo_envio - pedido.descuento_total, 2);
  });

  test('un POST directo al checkout con precio 1.00 tampoco rebaja la oferta: se cobra el precio de oferta real', async ({ request }) => {
    const producto = consultaProducto(NOMBRE)!;
    const res = await request.post('api/public_orders.php', {
      data: {
        tipo_entrega: 'Domicilio',
        cliente: { nombre: 'Playwright Oferta Servidor', telefono: telefonoUnico(), direccion: E2E_DIRECCION_ENTREGA },
        maps_link: 'https://www.google.com/maps/search/?api=1&query=20.605,-103.24',
        items: [{ id_producto: producto.id_producto, quantity: 1, nombre: NOMBRE, precio: 1.0 }],
      },
    });
    const cuerpo = await res.json();
    expect(cuerpo.success, JSON.stringify(cuerpo)).toBe(true);
    expect(consultaPedido(cuerpo.pedido)!.renglones[0].precio_unitario).toBe(precioOferta);
  });

  test('Entregas: agregar el producto en oferta a un pedido asignado sube el total exactamente por el precio de oferta', async ({ page }) => {
    const idPedido = await completeDomicilioCheckout(page);
    const numero = await getNumeroPedido(page, idPedido);

    await loginAsStaff(page, 'encargado');
    await page.goto('views/asignar_entregas.php');
    const select = page.locator(`#repartidor-${idPedido}`);
    await select.selectOption({ label: 'Playwright E2E Repartidor' });
    await page.locator(`#fecha-${idPedido}`).fill(new Date().toISOString().slice(0, 10));
    await page.locator('.assign-delivery-card').filter({ has: select }).getByRole('button', { name: 'Asignar' }).click();
    await expect(page.getByText('Pedido asignado correctamente.')).toBeVisible();

    await page.goto('views/asignar_entregas.php?tab=asignadas');
    const card = page.locator('.assign-delivery-card').filter({ hasText: numero });
    const total = async () => Number(((await card.locator('.assign-delivery-total').textContent()) ?? '').replace(/[^0-9.]/g, ''));
    const antes = await total();

    await card.locator('.assign-prod-combo-search').fill(NOMBRE);
    await card.locator('.assign-prod-combo-list li[data-id]').filter({ hasText: NOMBRE }).first().click();
    await card.locator('input[name="cantidad"]').fill('1');
    await card.getByRole('button', { name: 'Agregar' }).click();
    await page.waitForURL(/asignar_entregas\.php\?tab=asignadas/);
    await expect(page.getByText('Producto agregado al pedido correctamente.')).toBeVisible();

    const despues = Number(
      ((await page.locator('.assign-delivery-card').filter({ hasText: numero }).locator('.assign-delivery-total').textContent()) ?? '').replace(/[^0-9.]/g, '')
    );
    expect(despues - antes).toBeCloseTo(precioOferta, 2);
    // Y en la BD el renglón nuevo quedó al precio de oferta.
    const renglon = consultaPedido(numero)!.renglones.find((r) => r.nombre === NOMBRE);
    expect(renglon?.precio_unitario).toBe(precioOferta);
  });

  test('el tablero muestra el panel "Alex y las ofertas" con sus métricas (solo lectura, sin invocar a Alex)', async ({ page }) => {
    await loginAsStaff(page, 'admin');
    await page.goto('views/caducidades.php');
    const panel = page.locator('.card-panel').filter({ hasText: 'Alex y las ofertas' });
    await expect(panel).toBeVisible();
    await expect(panel).toContainText(/últimos \d+ días/);
    for (const metrica of ['Conversaciones con oferta mostrada:', 'Pedidos con productos en oferta:', 'Piezas vendidas:', 'Ingreso:']) {
      await expect(panel).toContainText(metrica);
    }
  });

  test('producto normal (sin oferta) sigue cobrándose a su precio de lista en el checkout', async ({ page }) => {
    await completeDomicilioCheckout(page);
    const id = Number(new URL(page.url()).searchParams.get('id'));
    const pedido = consultaPedido(await getNumeroPedido(page, id))!;
    const normal = consultaProducto(E2E_PRODUCT_NAME)!;
    expect(pedido.renglones[0].precio_unitario).toBe(normal.precio_venta);
    expect(pedido.renglones[0].precio_original).toBe(normal.precio_venta);
  });
});
