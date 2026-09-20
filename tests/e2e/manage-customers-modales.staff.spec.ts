import type { Page } from '@playwright/test';
import { test, expect } from './fixtures';
import { cerrarOfertaVentaSiAparece, loginAsStaff, telefonoUnico, E2E_UBICACION_ENTREGA } from './helpers';

// Commit cfcc8d3 (views/manage_customers.php): (1) los modales de editar / direcciones / nuevo cliente ya NO se cierran
// con clic afuera ni con Esc; (2) los formularios de los modales de direcciones y de editar cliente se envían con fetch
// y solo se refresca lo que cambió (el modal sigue abierto y no se pierde lo que se escribe); (3) los demás
// formularios recargan pero CONSERVAN la búsqueda y los filtros (sessionStorage); (4) las confirmaciones son un modal
// propio en vez de confirm(); (5) tras crear un cliente se ofrece agendarle/registrarle una venta.
//
// La página renderiza cientos de clientes en esta BD: cada envío por fetch devuelve el HTML completo y puede tardar
// varios segundos, de ahí los timeouts amplios.

const LENTO = { timeout: 30_000 };
const MAPS_LINK = `https://www.google.com/maps/search/?api=1&query=${E2E_UBICACION_ENTREGA.local.lat},${E2E_UBICACION_ENTREGA.local.lng}`;

async function crearCliente(page: Page, nombre: string, cerrarOferta = true): Promise<void> {
  await page.getByRole('link', { name: 'Nuevo cliente' }).click();
  const modal = page.locator('#modal-crear-cliente');
  await modal.locator('input[name="nombre"]').fill(nombre);
  await modal.locator('input[name="telefono"]').fill(telefonoUnico());
  await modal.getByRole('button', { name: 'Crear cliente' }).click();
  await expect(page.locator('.manage-customers-name', { hasText: nombre })).toBeVisible({ timeout: 30_000 });
  if (cerrarOferta) await cerrarOfertaVentaSiAparece(page);
}

const filaDe = (page: Page, nombre: string) => page.locator('.manage-customers-table-wrap tr').filter({ hasText: nombre });

async function abrirEditar(page: Page, nombre: string) {
  await filaDe(page, nombre).getByTitle('Editar cliente').click();
  const modal = page.locator('.modal.open').filter({ hasText: 'Editar cliente' });
  await expect(modal).toBeVisible({ timeout: 15_000 });
  return modal;
}

async function abrirDirecciones(page: Page, nombre: string) {
  await filaDe(page, nombre).getByTitle('Ver Direcciones').click();
  const modal = page.locator('.manage-customers-direcciones-modal.open');
  await expect(modal).toBeVisible({ timeout: 15_000 });
  return modal;
}

// Marca la pagina: si SE RECARGA la marca desaparece. Prueba que un envio no recargo.
async function marcarPagina(page: Page): Promise<void> {
  await page.evaluate(() => {
    (window as unknown as { __sinRecarga: boolean }).__sinRecarga = true;
  });
}
const paginaNoSeRecargo = (page: Page) => page.evaluate(() => (window as unknown as { __sinRecarga?: boolean }).__sinRecarga === true);

test.describe('Administrar Clientes: oferta de venta tras crear un cliente', () => {
  test('admin: se ofrece agendar una venta; "Ahora no" lo cierra y "Sí, agendar venta" abre la venta con el cliente cargado', async ({ page }) => {
    await loginAsStaff(page, 'admin');
    await page.goto('views/manage_customers.php');
    const nombre = `Playwright Cliente Oferta ${Date.now()}`;
    await crearCliente(page, nombre, false);
    await page.waitForLoadState('load');

    const oferta = page.locator('#modal-venta-tras-crear');
    await expect(oferta).toBeVisible();
    await expect(oferta).toContainText('ya quedo registrado');
    await expect(oferta).toContainText('¿Quieres agendarle una venta ahora?');
    await expect(oferta.locator('#btn-ir-a-venta')).toContainText('Si, agendar venta');
    await expect(oferta.locator('#btn-ir-a-venta')).toHaveAttribute('href', /views\/sales\.php\?id_cliente=\d+$/);

    await oferta.locator('#btn-ir-a-venta').click();
    await page.waitForURL(/views\/sales\.php\?id_cliente=\d+/);
    await expect(page.locator('.formulario-venta').first().locator('.selected-customer-chip')).toContainText(nombre, { timeout: 15_000 });
  });

  test('"Ahora no" cierra la oferta y deja la lista del cliente recién creado lista para usarse', async ({ page }) => {
    await loginAsStaff(page, 'admin');
    await page.goto('views/manage_customers.php');
    const nombre = `Playwright Cliente Ahora No ${Date.now()}`;
    await crearCliente(page, nombre, true);
    await expect(page.locator('#modal-venta-tras-crear')).toBeHidden();
    await expect(filaDe(page, nombre).getByTitle('Editar cliente')).toBeVisible();
  });

  test('sin el permiso realizar_ventas no se ofrece ninguna venta tras crear el cliente', async ({ page }) => {
    await loginAsStaff(page, 'encargadoSinVentas');
    await page.goto('views/manage_customers.php');
    await crearCliente(page, `Playwright Cliente Sin Oferta ${Date.now()}`, false);
    await page.waitForLoadState('load');

    await expect(page.locator('#modal-venta-tras-crear')).toHaveCount(0);
    await expect(page.locator('#btn-ir-a-venta')).toHaveCount(0);
  });

  test('sin asignar_entregas (puede vender pero no agendar a domicilio) la oferta dice "registrar", no "agendar"', async ({ page }) => {
    await loginAsStaff(page, 'encargadoSinAgendar');
    await page.goto('views/manage_customers.php');
    await crearCliente(page, `Playwright Cliente Registrar ${Date.now()}`, false);
    await page.waitForLoadState('load');

    const oferta = page.locator('#modal-venta-tras-crear');
    await expect(oferta).toBeVisible();
    await expect(oferta).toContainText('¿Quieres registrarle una venta ahora?');
    await expect(oferta.locator('#btn-ir-a-venta')).toContainText('Si, registrar venta');
  });
});

test.describe('Administrar Clientes: los modales no se cierran solos', () => {
  test.describe.configure({ timeout: 120_000 });

  test('"Nuevo cliente" no se cierra con Esc ni con clic fuera; solo con Cancelar', async ({ page }) => {
    await loginAsStaff(page, 'admin');
    await page.goto('views/manage_customers.php');
    await page.getByRole('link', { name: 'Nuevo cliente' }).click();
    const modal = page.locator('#modal-crear-cliente');
    await expect(modal).toBeVisible();
    await modal.locator('input[name="nombre"]').fill('Lo que ya escribí no se debe perder');

    await page.keyboard.press('Escape');
    await expect(modal).toBeVisible();
    await page.locator('.modal-overlay').click({ position: { x: 5, y: 5 }, force: true });
    await expect(modal).toBeVisible();
    await expect(modal.locator('input[name="nombre"]')).toHaveValue('Lo que ya escribí no se debe perder');

    await modal.getByRole('link', { name: 'Cancelar' }).click();
    await expect(modal).toBeHidden();
  });

  test('"Editar cliente" y "Direcciones" tampoco se cierran con Esc ni con clic fuera', async ({ page }) => {
    await loginAsStaff(page, 'admin');
    await page.goto('views/manage_customers.php');
    const nombre = `Playwright Cliente Modal Fijo ${Date.now()}`;
    await crearCliente(page, nombre);

    const editar = await abrirEditar(page, nombre);
    await page.keyboard.press('Escape');
    await expect(editar).toBeVisible();
    await page.locator('.modal-overlay').last().click({ position: { x: 5, y: 5 }, force: true });
    await expect(editar).toBeVisible();
    await editar.getByRole('link', { name: 'Cancelar' }).click();
    await expect(editar).toBeHidden();

    const direcciones = await abrirDirecciones(page, nombre);
    await page.keyboard.press('Escape');
    await expect(direcciones).toBeVisible();
    await page.locator('.modal-overlay').last().click({ position: { x: 5, y: 5 }, force: true });
    await expect(direcciones).toBeVisible();
    await direcciones.getByRole('link', { name: 'Cerrar' }).click();
    await expect(direcciones).toBeHidden();
  });
});

test.describe('Administrar Clientes: envíos sin recargar la página', () => {
  test.describe.configure({ timeout: 120_000 });

  test('editar un cliente: el modal se queda abierto, la fila se actualiza y la página NO se recarga', async ({ page }) => {
    await loginAsStaff(page, 'admin');
    await page.goto('views/manage_customers.php');
    const nombre = `Playwright Cliente Sin Recarga ${Date.now()}`;
    await crearCliente(page, nombre);

    const modal = await abrirEditar(page, nombre);
    await marcarPagina(page);
    const nombreNuevo = `${nombre} Editado`;
    await modal.locator('input[name="nombre"]').fill(nombreNuevo);
    await modal.getByRole('button', { name: 'Guardar cambios' }).click();

    await expect(page.locator('.toast', { hasText: 'Cliente actualizado correctamente.' })).toBeVisible(LENTO);
    await expect(modal).toBeVisible();
    await expect(page.locator('.manage-customers-name', { hasText: nombreNuevo })).toBeVisible();
    expect(await paginaNoSeRecargo(page)).toBe(true);
  });

  test('editar con un dato inválido avisa el error y deja el formulario como estaba para corregirlo', async ({ page }) => {
    await loginAsStaff(page, 'admin');
    await page.goto('views/manage_customers.php');
    const nombre = `Playwright Cliente Editar Malo ${Date.now()}`;
    await crearCliente(page, nombre);

    const modal = await abrirEditar(page, nombre);
    await marcarPagina(page);
    // Sin required nativo se llega al chequeo del servidor: el teléfono ya es obligatorio (PR #202).
    await modal.locator('input[name="telefono"]').evaluate((el) => {
      (el as HTMLInputElement).required = false;
    });
    await modal.locator('input[name="telefono"]').fill('');
    await modal.getByRole('button', { name: 'Guardar cambios' }).click();

    await expect(page.locator('.toast.red', { hasText: 'telefono es obligatorio' })).toBeVisible(LENTO);
    await expect(modal).toBeVisible();
    await expect(modal.locator('input[name="telefono"]')).toHaveValue('');
    expect(await paginaNoSeRecargo(page)).toBe(true);
    // El botón se vuelve a habilitar (no queda trabado en "guardando").
    await expect(modal.getByRole('button', { name: 'Guardar cambios' })).toBeEnabled();
  });

  test('agregar una dirección desde el modal: aparece en la lista sin recargar y el formulario se limpia', async ({ page }) => {
    await loginAsStaff(page, 'admin');
    await page.goto('views/manage_customers.php');
    const nombre = `Playwright Cliente Con Direccion ${Date.now()}`;
    await crearCliente(page, nombre);

    const modal = await abrirDirecciones(page, nombre);
    await expect(modal.locator('[data-refresco="direcciones"]')).toContainText('Sin direcciones registradas.');
    await marcarPagina(page);

    await modal.locator('input[name="alias"]').fill('Casa Modal');
    await modal.locator('textarea[name="direccion"]').fill('Av. Vallarta 1500, Guadalajara, Jal.');
    // El buscador de Google esta bloqueado en las pruebas: se fija el link con coordenadas que ese buscador arma.
    await modal.locator('input[name="maps_link"]').evaluate((el, link) => {
      (el as HTMLInputElement).value = link;
    }, MAPS_LINK);
    await modal.getByRole('button', { name: 'Guardar direccion' }).click();

    const lista = modal.locator('[data-refresco="direcciones"]');
    await expect(lista).toContainText('Casa Modal', LENTO);
    await expect(lista).toContainText('Av. Vallarta 1500');
    await expect(lista).not.toContainText('Sin direcciones registradas.');
    await expect(modal).toBeVisible();
    await expect(modal.locator('input[name="alias"]')).toHaveValue('');
    expect(await paginaNoSeRecargo(page)).toBe(true);
  });
});

test.describe('Administrar Clientes: confirmaciones propias y filtros que persisten', () => {
  test.describe.configure({ timeout: 120_000 });

  test('eliminar un cliente pide confirmación en un modal propio (no el confirm() del navegador); Cancelar no borra', async ({ page }) => {
    let dialogoNativo = false;
    page.on('dialog', (d) => {
      dialogoNativo = true;
      void d.dismiss();
    });
    await loginAsStaff(page, 'admin');
    await page.goto('views/manage_customers.php');
    const nombre = `Playwright Cliente Confirmar ${Date.now()}`;
    await crearCliente(page, nombre);

    await filaDe(page, nombre).getByTitle('Eliminar cliente').click();
    await expect(page.locator('#confirmar-accion-titulo')).toHaveText('¿Eliminar este cliente?');
    await expect(page.locator('#confirmar-accion-mensaje')).toContainText('Sus pedidos quedaran sin cliente asignado');
    await expect(page.locator('#confirmar-accion-aceptar')).toHaveText('Si, eliminar');

    // Cancelar: el cliente sigue ahí.
    await page.locator('#modal-confirmar-accion').getByRole('link', { name: /Cancelar/ }).click();
    await expect(page.locator('#modal-confirmar-accion')).toBeHidden();
    await expect(filaDe(page, nombre)).toHaveCount(1);

    // Aceptar: se elimina.
    await filaDe(page, nombre).getByTitle('Eliminar cliente').click();
    await page.locator('#confirmar-accion-aceptar').click();
    await expect(page.getByText(/Cliente eliminado correctamente/)).toBeVisible(LENTO);
    await expect(filaDe(page, nombre)).toHaveCount(0);
    expect(dialogoNativo).toBe(false);
  });

  test('eliminar una dirección también usa el modal propio, y al confirmar se quita de la lista sin recargar', async ({ page }) => {
    await loginAsStaff(page, 'admin');
    await page.goto('views/manage_customers.php');
    const nombre = `Playwright Cliente Borra Direccion ${Date.now()}`;
    await crearCliente(page, nombre);

    const modal = await abrirDirecciones(page, nombre);
    await modal.locator('input[name="alias"]').fill('Para Borrar');
    await modal.locator('textarea[name="direccion"]').fill('Calle Efímera 1, Guadalajara, Jal.');
    await modal.locator('input[name="maps_link"]').evaluate((el, link) => {
      (el as HTMLInputElement).value = link;
    }, MAPS_LINK);
    await modal.getByRole('button', { name: 'Guardar direccion' }).click();
    const lista = modal.locator('[data-refresco="direcciones"]');
    await expect(lista).toContainText('Para Borrar', LENTO);

    await marcarPagina(page);
    // El botón de borrar de la dirección lleva data-confirmar ("Se borrara del cliente..."): abre el modal propio.
    await lista.locator('button[data-confirmar^="Se borrara"]').first().click();
    await expect(page.locator('#modal-confirmar-accion')).toBeVisible();
    await expect(page.locator('#confirmar-accion-mensaje')).toContainText('Se borrara del cliente');
    await page.locator('#confirmar-accion-aceptar').click();

    await expect(lista).toContainText('Sin direcciones registradas.', LENTO);
    await expect(modal).toBeVisible();
    expect(await paginaNoSeRecargo(page)).toBe(true);
  });

  test('bloquear un cliente recarga la página pero CONSERVA la búsqueda y el filtro elegidos', async ({ page }) => {
    await loginAsStaff(page, 'admin');
    await page.goto('views/manage_customers.php');
    const nombre = `Playwright Cliente Filtro ${Date.now()}`;
    await crearCliente(page, nombre);

    await page.locator('#filtro-estado').selectOption('activo');
    await expect(async () => {
      await page.locator('#buscar-cliente').fill('');
      await page.locator('#buscar-cliente').fill(nombre);
      await expect(page.locator('#buscar-cliente')).toHaveValue(nombre);
    }).toPass({ timeout: 20_000 });

    await marcarPagina(page);
    await filaDe(page, nombre).getByTitle('Bloquear').click();
    // "Bloquear" NO es de los envíos sin recarga: la página se recarga (la marca se pierde) y trae el aviso.
    await expect(page.getByText('Cliente bloqueado.')).toBeVisible(LENTO);
    expect(await paginaNoSeRecargo(page)).toBe(false);

    // ...pero lo que la persona tenía escrito y elegido vuelve solo. El aviso se dibuja al INICIO de la carga y la
    // restauración corre en el script del FINAL de una página con cientos de filas: se espera a que termine de cargar.
    await page.waitForLoadState('load');
    await expect(page.locator('#buscar-cliente')).toHaveValue(nombre, LENTO);
    await expect(page.locator('#filtro-estado')).toHaveValue('activo', LENTO);
    // Y se limpia: una recarga posterior normal ya no restaura filtros viejos.
    const guardado = await page.evaluate(() => window.sessionStorage.getItem('manageCustomersFiltrosTrasEnvio'));
    expect(guardado).toBeNull();
  });
});

