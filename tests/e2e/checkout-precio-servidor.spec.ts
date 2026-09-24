import { test, expect } from './fixtures';
import {
  E2E_PRODUCT_NAME,
  E2E_UBICACION_ENTREGA,
  registerAndLogin,
  telefonoUnico,
} from './helpers';
import { consultaPedido, consultaProducto } from './db-utils';

// Checkout público (api/public_orders.php): "el precio de cada producto lo fija el servidor, nunca el navegador" (commit
// b1b5d5c). Antes el carrito mandaba `precio` y el pedido se guardaba a ese precio, así que un POST directo con
// precio 1.00 creaba un pedido por casi nada. Ahora ofertaPreciosSeguros() lo pisa SIEMPRE (invitado o con sesión).
// Se prueba por API (es un ataque, no un flujo de pantalla) y se compara contra lo que quedó GUARDADO en la BD.

const MAPS_LINK = `https://www.google.com/maps/search/?api=1&query=${E2E_UBICACION_ENTREGA.local.lat},${E2E_UBICACION_ENTREGA.local.lng}`;

function cuerpoPedido(idProducto: number, item: Record<string, unknown>, extra: Record<string, unknown> = {}) {
  return {
    tipo_entrega: 'Domicilio',
    cliente: { nombre: 'Playwright Precio Servidor', telefono: telefonoUnico(), direccion: 'Av. Vallarta 1500, Guadalajara, Jal.' },
    maps_link: MAPS_LINK,
    items: [{ id_producto: idProducto, quantity: 1, nombre: E2E_PRODUCT_NAME, ...item }],
    ...extra,
  };
}

test.describe('Checkout público: el precio lo fija el servidor', () => {
  // En beforeAll (no al cargar el archivo): si aun no se sembro, el error dice que falta el seed y no rompe la carga de la spec.
  let producto: ReturnType<typeof consultaProducto>;
  test.beforeAll(() => {
    producto = consultaProducto(E2E_PRODUCT_NAME);
    if (!producto) throw new Error('Falta el producto sembrado "' + E2E_PRODUCT_NAME + '": corre scripts/seed_e2e_test_data.php');
  });

  test('un invitado que manda precio 1.00 por POST directo paga el precio real, no $1', async ({ request }) => {
    expect(producto, 'falta el producto sembrado (seed_e2e_test_data.php)').not.toBeNull();
    const res = await request.post('api/public_orders.php', { data: cuerpoPedido(producto!.id_producto, { precio: 1.0 }) });
    const cuerpo = await res.json();
    expect(cuerpo.success, JSON.stringify(cuerpo)).toBe(true);

    const pedido = consultaPedido(cuerpo.pedido);
    expect(pedido).not.toBeNull();
    expect(pedido!.renglones).toHaveLength(1);
    expect(pedido!.renglones[0].precio_unitario).toBe(producto!.precio_venta);
    expect(pedido!.renglones[0].subtotal).toBe(producto!.precio_venta);
    expect(pedido!.subtotal).toBe(producto!.precio_venta);
  });

  test('un precio negativo, cero o ausente tampoco cuenta: siempre el real', async ({ request }) => {
    for (const item of [{ precio: -50 }, { precio: 0 }, {}]) {
      const res = await request.post('api/public_orders.php', { data: cuerpoPedido(producto!.id_producto, item) });
      const cuerpo = await res.json();
      expect(cuerpo.success, JSON.stringify(cuerpo)).toBe(true);
      expect(consultaPedido(cuerpo.pedido)!.renglones[0].precio_unitario, `con ${JSON.stringify(item)}`).toBe(producto!.precio_venta);
    }
  });

  test('con varias piezas el subtotal es precio real × cantidad aunque el navegador mande otro precio', async ({ request }) => {
    const res = await request.post('api/public_orders.php', { data: cuerpoPedido(producto!.id_producto, { quantity: 3, precio: 0.01 }) });
    const cuerpo = await res.json();
    expect(cuerpo.success, JSON.stringify(cuerpo)).toBe(true);
    const pedido = consultaPedido(cuerpo.pedido)!;
    expect(pedido.renglones[0].cantidad).toBe(3);
    expect(pedido.renglones[0].subtotal).toBeCloseTo(producto!.precio_venta * 3, 2);
  });

  test('un cliente con sesión (con su token CSRF) tampoco puede rebajar el precio', async ({ page }) => {
    await registerAndLogin(page);
    await page.goto('views/cart.php');
    const csrf = (await page.content()).match(/csrf_token:\s*"([^"]+)"/)?.[1];
    expect(csrf, 'no se encontró el token CSRF en el carrito').toBeTruthy();

    const res = await page.request.post('api/public_orders.php', {
      data: cuerpoPedido(producto!.id_producto, { precio: 1.0 }, { csrf_token: csrf }),
    });
    const cuerpo = await res.json();
    expect(cuerpo.success, JSON.stringify(cuerpo)).toBe(true);
    expect(consultaPedido(cuerpo.pedido)!.renglones[0].precio_unitario).toBe(producto!.precio_venta);
  });

  test('con sesión pero SIN token CSRF el pedido se rechaza (y no se crea)', async ({ page }) => {
    await registerAndLogin(page);
    const res = await page.request.post('api/public_orders.php', {
      data: cuerpoPedido(producto!.id_producto, { precio: 1.0 }),
      failOnStatusCode: false,
    });
    const texto = await res.text();
    expect(texto).not.toContain('"pedido"');
    expect(res.status() === 403 || /"success":\s*false/.test(texto), texto.slice(0, 200)).toBe(true);
  });

  test('un producto que no existe no crea pedido', async ({ request }) => {
    const res = await request.post('api/public_orders.php', { data: cuerpoPedido(999999999, { precio: 1.0 }) });
    const cuerpo = await res.json();
    expect(cuerpo.success).toBe(false);
    expect(cuerpo.pedido).toBeUndefined();
  });
});
