import type { Page } from '@playwright/test';
import { test, expect } from './fixtures';
import { loginAsStaff, telefonoUnico, E2E_PRODUCT_NAME, E2E_UBICACION_ENTREGA } from './helpers';

// Commit fe34d54 (sales.php + api/create_customer.php): el modal "Nuevo cliente" del POS acepta un DOMICILIO opcional
// que se guarda como direccion predeterminada en la MISMA transaccion, y sales.php?id_cliente=NN abre la venta con
// ese cliente ya cargado. Toda ubicacion se manda como maps_link "query=lat,lng": el servidor la parsea SIN red
// (clienteResolverCoordsDireccion); sin ella geocodificaria el texto con Google (costo y resultado variable).

const DIRECCION = 'Av. Vallarta 1500, Guadalajara, Jal.';
const MAPS_LINK = `https://www.google.com/maps/search/?api=1&query=${E2E_UBICACION_ENTREGA.local.lat},${E2E_UBICACION_ENTREGA.local.lng}`;

async function tokenCsrf(page: Page): Promise<string> {
  await page.goto('views/sales.php');
  return page.locator('#form-nuevo-cliente input[name="csrf_token"]').inputValue();
}

// El formulario (pestana) donde quedo el cliente. NO se asume que sea la primera: si una visita previa a Ventas dejo un
// borrador guardado, ?id_cliente abre el cliente en una pestana nueva (ver la prueba de caracterizacion de abajo).
const formDelCliente = (page: Page, nombre: string) =>
  page.locator('.formulario-venta').filter({ has: page.locator('.selected-customer-chip', { hasText: nombre }) });

interface RespuestaCliente {
  success: boolean;
  message?: string;
  cliente?: {
    id_cliente: number;
    nombre: string;
    telefono: string;
    direccion?: string;
    direcciones?: Array<{ id_direccion: number; alias: string; direccion: string; es_default: boolean }>;
  };
}

async function crearClientePorApi(
  page: Page,
  csrf: string,
  campos: Record<string, string>
): Promise<RespuestaCliente> {
  const res = await page.request.post('api/create_customer.php', { multipart: { csrf_token: csrf, ...campos } });
  return (await res.json()) as RespuestaCliente;
}

test.describe('Ventas: alta de cliente con domicilio (sales.php + api/create_customer.php)', () => {
  test('el modal guarda el cliente con su domicilio, lo deja seleccionado y llena la dirección de entrega', async ({ page }) => {
    await loginAsStaff(page, 'encargado');
    await page.goto('views/sales.php');
    const form = page.locator('.formulario-venta').first();

    const nombre = `Playwright Sales Con Domicilio ${Date.now()}`;
    await form.getByRole('link', { name: '+ Crear cliente nuevo' }).click();
    const modal = page.locator('#modal-nuevo-cliente');
    await expect(modal).toBeVisible();
    await modal.locator('#nuevo-cliente-nombre').fill(nombre);
    await modal.locator('#nuevo-cliente-telefono').fill(telefonoUnico());
    await expect(modal.locator('#nuevo-cliente-maps-status')).toHaveText('Sin ubicacion en mapa seleccionada aun.');

    await modal.locator('#nuevo-cliente-dir-alias').fill('Casa E2E');
    await modal.locator('#nuevo-cliente-direccion').fill(DIRECCION);
    // El buscador de Google (Places) esta bloqueado en las pruebas: se fija el link que ese buscador arma.
    await modal.locator('#nuevo-cliente-maps-link').evaluate((el, link) => {
      (el as HTMLInputElement).value = link;
    }, MAPS_LINK);
    await page.locator('#btn-guardar-nuevo-cliente').click();

    await expect(page.getByText('Cliente creado con su domicilio y seleccionado.')).toBeVisible();
    await expect(form.locator('.selected-customer-chip')).toContainText(nombre);
    await expect(form.locator('.direccion_entrega')).toHaveValue(DIRECCION);
    // La dirección quedó guardada Y seleccionada: ya no aparece el aviso de "sin dirección".
    await expect(form.locator('.customer-address-select option:checked')).toContainText('Casa E2E');
    await expect(form.getByText('Este cliente no tiene una direccion valida.')).toHaveCount(0);
  });

  test('el modal de un cliente sin domicilio sigue funcionando: se crea, se selecciona y avisa que falta la dirección', async ({ page }) => {
    await loginAsStaff(page, 'encargado');
    await page.goto('views/sales.php');
    const form = page.locator('.formulario-venta').first();

    await form.getByRole('link', { name: '+ Crear cliente nuevo' }).click();
    const modal = page.locator('#modal-nuevo-cliente');
    await modal.locator('#nuevo-cliente-nombre').fill(`Playwright Sales Sin Domicilio ${Date.now()}`);
    await modal.locator('#nuevo-cliente-telefono').fill(telefonoUnico());
    await page.locator('#btn-guardar-nuevo-cliente').click();

    // Mensaje distinto al de "con domicilio": el POS sabe cuál de los dos casos ocurrió.
    await expect(page.getByText('Cliente creado y seleccionado.')).toBeVisible();
    await expect(page.getByText('Cliente creado con su domicilio y seleccionado.')).toHaveCount(0);
    await expect(form.getByText('Este cliente no tiene una direccion valida.')).toBeVisible();
  });

  test('API: guarda el domicilio como predeterminado (alias dado o "Direccion 1"), con teléfono normalizado', async ({ page }) => {
    await loginAsStaff(page, 'encargado');
    const csrf = await tokenCsrf(page);

    const conAlias = await crearClientePorApi(page, csrf, {
      nombre: `Playwright API Alias ${Date.now()}`,
      telefono: telefonoUnico(),
      direccion: DIRECCION,
      direccion_alias: 'Trabajo E2E',
      maps_link: MAPS_LINK,
    });
    expect(conAlias.success, conAlias.message).toBe(true);
    expect(conAlias.cliente!.direcciones).toHaveLength(1);
    expect(conAlias.cliente!.direcciones![0]).toMatchObject({ alias: 'Trabajo E2E', direccion: DIRECCION, es_default: true });
    expect(conAlias.cliente!.telefono).toMatch(/^\(\d{3}\) - \d{3} - \d{4}$/);

    const sinAlias = await crearClientePorApi(page, csrf, {
      nombre: `Playwright API Sin Alias ${Date.now()}`,
      telefono: telefonoUnico(),
      direccion: DIRECCION,
      maps_link: MAPS_LINK,
    });
    expect(sinAlias.success, sinAlias.message).toBe(true);
    expect(sinAlias.cliente!.direcciones![0].alias).toBe('Direccion 1');
  });

  test('API: sin domicilio no se crea ninguna dirección; con alias de más de 50 caracteres se rechaza TODO el alta', async ({ page }) => {
    await loginAsStaff(page, 'encargado');
    const csrf = await tokenCsrf(page);

    const sinDireccion = await crearClientePorApi(page, csrf, { nombre: `Playwright API Solo Cliente ${Date.now()}`, telefono: telefonoUnico() });
    expect(sinDireccion.success, sinDireccion.message).toBe(true);
    expect(sinDireccion.cliente!.direcciones ?? []).toHaveLength(0);

    const nombreRechazado = `Playwright API Alias Largo ${Date.now()}`;
    const largo = await crearClientePorApi(page, csrf, {
      nombre: nombreRechazado,
      telefono: telefonoUnico(),
      direccion: DIRECCION,
      direccion_alias: 'A'.repeat(51),
      maps_link: MAPS_LINK,
    });
    expect(largo.success).toBe(false);
    expect(largo.message).toBe('El alias de la direccion no puede exceder 50 caracteres.');
    // Todo o nada: el cliente tampoco quedó creado (un intento repetido no choca por "ya existe").
    const reintento = await crearClientePorApi(page, csrf, { nombre: nombreRechazado, telefono: telefonoUnico(), direccion: DIRECCION, maps_link: MAPS_LINK });
    expect(reintento.success, reintento.message).toBe(true);
  });

  test('API: sin token CSRF o con teléfono inválido se rechaza y no se crea el cliente', async ({ page }) => {
    await loginAsStaff(page, 'encargado');
    const csrf = await tokenCsrf(page);

    const sinToken = await crearClientePorApi(page, '', { nombre: `Playwright API Sin Token ${Date.now()}`, telefono: telefonoUnico() });
    expect(sinToken.success).toBe(false);

    const telefonoMalo = await crearClientePorApi(page, csrf, { nombre: `Playwright API Tel Malo ${Date.now()}`, telefono: '123' });
    expect(telefonoMalo.success).toBe(false);
    expect(telefonoMalo.message).toBe('El telefono es obligatorio y debe tener 10 digitos.');
  });

  test('sales.php?id_cliente=NN abre la venta con ese cliente, su teléfono y su domicilio predeterminado', async ({ page }) => {
    await loginAsStaff(page, 'encargado');
    const csrf = await tokenCsrf(page);
    const nombre = `Playwright Sales Preset ${Date.now()}`;
    const creado = await crearClientePorApi(page, csrf, {
      nombre,
      telefono: '3312345670',
      direccion: DIRECCION,
      direccion_alias: 'Casa Preset',
      maps_link: MAPS_LINK,
    });
    expect(creado.success, creado.message).toBe(true);

    await page.goto(`views/sales.php?id_cliente=${creado.cliente!.id_cliente}`);
    const form = formDelCliente(page, nombre);
    await expect(form).toHaveCount(1, { timeout: 20000 });
    await expect(form.locator('.cliente_telefono')).toHaveValue('(331) - 234 - 5670');
    await expect(form.locator('.direccion_entrega')).toHaveValue(DIRECCION);
    await expect(form.locator('.customer-address-select option:checked')).toContainText('Casa Preset');
  });

  // El mapa + Street View del domicilio (commit fe34d54) dependen de Google Maps, que las pruebas bloquean a proposito:
  // aqui NO se puede ver la imagen de la calle, pero si comprobar que su ausencia no rompe la venta.
  test('sin Google Maps la fila del mapa/Street View queda oculta y la venta con domicilio sigue funcionando sin errores de JS', async ({ page }) => {
    const errores: string[] = [];
    page.on('pageerror', (e) => errores.push(e.message));
    await loginAsStaff(page, 'encargado');
    const csrf = await tokenCsrf(page);
    const nombre = `Playwright Sales Sin Mapa ${Date.now()}`;
    const creado = await crearClientePorApi(page, csrf, { nombre, telefono: telefonoUnico(), direccion: DIRECCION, direccion_alias: 'Casa Sin Mapa', maps_link: MAPS_LINK });
    expect(creado.success, creado.message).toBe(true);

    await page.goto(`views/sales.php?id_cliente=${creado.cliente!.id_cliente}`);
    const form = formDelCliente(page, nombre);
    await expect(form).toHaveCount(1, { timeout: 20000 });
    await expect(form.locator('.direccion_entrega')).toHaveValue(DIRECCION);
    await expect(page.locator('.sales-map-row').first()).toBeHidden();
    await expect(page.locator('.sales-streetview-shield').first()).toBeHidden();
    expect(errores).toEqual([]);
  });

  // Un borrador VACIO no es una venta en curso: basta haber abierto Ventas antes para que quede guardado (a los 250 ms), y
  // antes ?id_cliente abria el cliente en una pestana NUEVA dejando la primera vacia. Ahora reutiliza la pestana vacia.
  test('una visita previa a Ventas deja un borrador vacío: ?id_cliente REUTILIZA esa pestaña en vez de abrir otra', async ({ page }) => {
    await loginAsStaff(page, 'encargado');
    const csrf = await tokenCsrf(page);
    const nombre = `Playwright Sales Pestana Vacia ${Date.now()}`;
    const creado = await crearClientePorApi(page, csrf, { nombre, telefono: telefonoUnico() });
    expect(creado.success, creado.message).toBe(true);

    await page.waitForTimeout(700); // el borrador (vacio) se guarda con debounce de 250 ms
    await page.goto(`views/sales.php?id_cliente=${creado.cliente!.id_cliente}`);

    await expect(page.locator('#venta-v1 .formulario-venta .selected-customer-chip')).toContainText(nombre, { timeout: 20000 });
    await expect(page.locator('.venta-context')).toHaveCount(1);
  });

  test('un id_cliente que no existe (o no es de tu sucursal) avisa y deja la venta vacía', async ({ page }) => {
    await loginAsStaff(page, 'encargado');
    await page.goto('views/sales.php?id_cliente=999999999');

    await expect(page.getByText('No se encontro al cliente para la venta')).toBeVisible();
    await expect(page.locator('.formulario-venta').first().locator('.selected-customer-chip')).toHaveCount(0);
  });

  test('con una venta en curso, ?id_cliente abre una pestaña NUEVA y no pisa lo que ya se estaba capturando', async ({ page }) => {
    await loginAsStaff(page, 'encargado');
    const csrf = await tokenCsrf(page);
    const nombre = `Playwright Sales Pestana Nueva ${Date.now()}`;
    const creado = await crearClientePorApi(page, csrf, { nombre, telefono: telefonoUnico() });
    expect(creado.success, creado.message).toBe(true);

    // Venta en curso: un producto en la primera pestaña (el borrador se guarda con debounce de 250 ms).
    await page.goto('views/sales.php');
    const primerForm = page.locator('#venta-v1 .formulario-venta');
    await primerForm.locator('.buscador-producto').fill(E2E_PRODUCT_NAME);
    await primerForm.locator('.producto-dropdown .item').filter({ hasText: E2E_PRODUCT_NAME }).first().click();
    await expect(primerForm.locator('.producto-item')).toHaveCount(1);
    await page.waitForTimeout(600);

    await page.goto(`views/sales.php?id_cliente=${creado.cliente!.id_cliente}`);
    await expect(page.locator('.venta-context')).toHaveCount(2);
    await expect(page.locator('#venta-v1 .formulario-venta .producto-item')).toHaveCount(1);
    await expect(page.locator('#venta-v2 .formulario-venta .selected-customer-chip')).toContainText(nombre, { timeout: 20000 });
  });

  test('la auditoría registra el alta desde el POS "con dirección" y el teléfono sale enmascarado', async ({ page }) => {
    await loginAsStaff(page, 'encargado');
    const csrf = await tokenCsrf(page);
    const nombre = `Playwright Auditoria POS ${Date.now()}`;
    const telefono = '3318889901';
    const creado = await crearClientePorApi(page, csrf, { nombre, telefono, direccion: DIRECCION, maps_link: MAPS_LINK });
    expect(creado.success, creado.message).toBe(true);

    await loginAsStaff(page, 'admin');
    await page.goto(`views/activity_logs.php?vista=movimientos&accion=CLIENTE_CREADO&q=${encodeURIComponent(nombre)}`);
    const fila = page.locator('.mov-item').first();
    await expect(fila).toContainText('creado desde el POS con dirección');
    await expect(fila.locator('.mov-actor-nombre')).toHaveText('Playwright E2E Encargado');
    const html = await page.content();
    expect(html).not.toContain(telefono);
    expect(html).not.toContain('331) - 888 - 9901');
  });
});
