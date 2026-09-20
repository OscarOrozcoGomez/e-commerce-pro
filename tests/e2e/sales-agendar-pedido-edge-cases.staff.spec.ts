import type { Locator, Page } from '@playwright/test';
import { test, expect } from './fixtures';
import {
  loginAsStaff,
  telefonoUnico,
  E2E_PRODUCT_NAME,
  E2E_LOW_STOCK_PRODUCT_NAME,
  E2E_OUT_OF_STOCK_PRODUCT_NAME,
  E2E_CLIENTE_SIN_TELEFONO_A,
  E2E_CLIENTE_SIN_TELEFONO_B,
} from './helpers';

// Cubre lo que sales-agendar-pedido.staff.spec.ts no toca: alta de cliente nuevo desde el
// propio formulario, las alertas de telefono/direccion faltante, los guardrails de stock y
// descuento del carrito, el manejo de pestañas multiples y el borrador en localStorage. El
// happy-path basico y la validacion de "sin cliente seleccionado" ya viven en el otro spec.
//
// PR #202: un cliente NUNCA se da de alta sin telefono. Por eso el alta desde el formulario lo exige, y las
// alertas de "cliente sin telefono" solo se pueden ver con clientes ANTIGUOS (sembrados sin telefono a proposito).

async function crearClienteNuevo(page: Page, form: Locator, nombre: string, telefono: string = telefonoUnico()): Promise<void> {
  await form.getByRole('link', { name: '+ Crear cliente nuevo' }).click();
  const modal = page.locator('#modal-nuevo-cliente');
  await expect(modal).toBeVisible();
  await modal.locator('#nuevo-cliente-nombre').fill(nombre);
  await modal.locator('#nuevo-cliente-telefono').fill(telefono);
  await page.locator('#btn-guardar-nuevo-cliente').click();
  await expect(page.getByText('Cliente creado y seleccionado.')).toBeVisible();
}

// Igual que sales-agendar-pedido.staff.spec.ts: escribir el nombre exacto y salir del campo resuelve el cliente.
async function seleccionarClienteExistente(form: Locator, nombre: string): Promise<void> {
  await form.locator('.cliente_nombre').fill(nombre);
  await form.locator('.cliente_nombre').press('Tab');
  await expect(form.locator('.selected-customer-chip')).toContainText(nombre, { timeout: 10000 });
}

async function agregarProductoPorNombre(form: Locator, nombreProducto: string): Promise<void> {
  await form.locator('.buscador-producto').fill(nombreProducto);
  const item = form.locator('.producto-dropdown .item').filter({ hasText: nombreProducto }).first();
  await item.waitFor({ state: 'visible' });
  await item.click();
}

test.describe('Agendar pedido (sales.php): edge cases', () => {
  test('un encargado crea un cliente nuevo (con telefono) y ve la alerta de que aun no tiene direccion', async ({ page }) => {
    await loginAsStaff(page, 'encargado');
    await page.goto('views/sales.php');
    const form = page.locator('.formulario-venta').first();

    const nombreCliente = `Playwright Sales Cliente Nuevo ${Date.now()}`;
    await crearClienteNuevo(page, form, nombreCliente, '3319876543');

    await expect(form.locator('.selected-customer-chip')).toContainText(nombreCliente);
    // El telefono capturado en el alta ya viene en el formulario: ya no hay alerta de "sin telefono".
    await expect(form.locator('.cliente_telefono')).toHaveValue('(331) - 987 - 6543');
    await expect(form.locator('.cliente-sin-telefono-alert')).toBeHidden();
    await expect(form.locator('.customer-address-select')).toHaveValue('');
    await expect(form.getByText('Este cliente no tiene una direccion valida.')).toBeVisible();
  });

  test('no se puede crear un cliente nuevo sin telefono: el modal avisa y no se selecciona nada', async ({ page }) => {
    await loginAsStaff(page, 'encargado');
    await page.goto('views/sales.php');
    const form = page.locator('.formulario-venta').first();

    await form.getByRole('link', { name: '+ Crear cliente nuevo' }).click();
    const modal = page.locator('#modal-nuevo-cliente');
    await expect(modal).toBeVisible();
    const nombreCliente = `Playwright Sales Sin Telefono ${Date.now()}`;
    await modal.locator('#nuevo-cliente-nombre').fill(nombreCliente);
    await page.locator('#btn-guardar-nuevo-cliente').click();

    await expect(modal.locator('#nuevo-cliente-error')).toContainText('El telefono es obligatorio y debe tener 10 digitos.');
    await expect(modal).toBeVisible();
    await expect(page.getByText('Cliente creado y seleccionado.')).toHaveCount(0);
    await expect(form.locator('.selected-customer-chip')).toHaveCount(0);
  });

  test('un cliente antiguo sin telefono muestra la alerta de datos faltantes', async ({ page }) => {
    await loginAsStaff(page, 'encargado');
    await page.goto('views/sales.php');
    const form = page.locator('.formulario-venta').first();

    await seleccionarClienteExistente(form, E2E_CLIENTE_SIN_TELEFONO_A);
    await expect(form.locator('.cliente-sin-telefono-alert')).toBeVisible();
    await expect(form.locator('.cliente_telefono')).toHaveValue('');
  });

  test('agregar telefono desde la alerta lo guarda y quita la alerta', async ({ page }) => {
    await loginAsStaff(page, 'encargado');
    await page.goto('views/sales.php');
    const form = page.locator('.formulario-venta').first();

    await seleccionarClienteExistente(form, E2E_CLIENTE_SIN_TELEFONO_B);
    await expect(form.locator('.cliente-sin-telefono-alert')).toBeVisible();

    await form.locator('.cliente-editar-telefono-link').click();
    const modal = page.locator('#modal-agregar-telefono');
    await expect(modal).toBeVisible();
    await modal.locator('#agregar-telefono-input').fill('3319876543');
    await page.locator('#btn-guardar-agregar-telefono').click();

    await expect(page.getByText('Telefono guardado.')).toBeVisible();
    await expect(form.locator('.cliente_telefono')).toHaveValue('(331) - 987 - 6543');
    await expect(form.locator('.cliente-sin-telefono-alert')).toBeHidden();
  });

  test('no deja agendar sin telefono del cliente (guardrail de JS, el campo ya no es "required" nativo)', async ({ page }) => {
    await loginAsStaff(page, 'encargado');
    await page.goto('views/sales.php');
    const form = page.locator('.formulario-venta').first();

    await seleccionarClienteExistente(form, E2E_CLIENTE_SIN_TELEFONO_A);
    await agregarProductoPorNombre(form, E2E_PRODUCT_NAME);
    await expect(form.locator('.producto-item')).toHaveCount(1);

    // .cliente_telefono ya no tiene el atributo "required": la validacion vive entera en
    // procesarVenta() (mismo guardrail de JS que el caso de "solo espacios" de abajo).
    await form.getByRole('button', { name: 'Agendar Pedido' }).click();
    await expect(page.getByText('Captura el telefono del cliente para continuar.')).toBeVisible();
    await expect(form.locator('.producto-item')).toHaveCount(1);
  });

  test('no deja agendar con un telefono de solo espacios (JS detecta lo que el navegador no)', async ({ page }) => {
    await loginAsStaff(page, 'encargado');
    await page.goto('views/sales.php');
    const form = page.locator('.formulario-venta').first();

    await crearClienteNuevo(page, form, `Playwright Sales Tel Espacios ${Date.now()}`);
    // El navegador considera "required" satisfecho con solo espacios (no esta vacio segun
    // el DOM), pero procesarVenta() hace trim() antes de validar -- este es el caso real
    // que ese guardrail de JS cubre (uno que el HTML5 "required" no puede cubrir solo).
    await form.locator('.cliente_telefono').fill('   ');
    await agregarProductoPorNombre(form, E2E_PRODUCT_NAME);
    await expect(form.locator('.producto-item')).toHaveCount(1);

    await form.getByRole('button', { name: 'Agendar Pedido' }).click();
    await expect(page.getByText('Captura el telefono del cliente para continuar.')).toBeVisible();
  });

  test('no deja agendar sin una direccion de entrega', async ({ page }) => {
    await loginAsStaff(page, 'encargado');
    await page.goto('views/sales.php');
    const form = page.locator('.formulario-venta').first();

    await crearClienteNuevo(page, form, `Playwright Sales Sin Dir ${Date.now()}`);
    // Cliente sin domicilios guardados: se llena el telefono a mano para pasar ese guardrail
    // y llegar al de direccion, que es el que este test quiere probar.
    await form.locator('.cliente_telefono').fill('3311234567');
    await agregarProductoPorNombre(form, E2E_PRODUCT_NAME);

    await form.getByRole('button', { name: 'Agendar Pedido' }).click();
    await expect(page.getByText(/Selecciona una direccion guardada/)).toBeVisible();
  });

  test('un vendedor no ve la opcion de crear cliente nuevo ni de completar datos faltantes', async ({ page }) => {
    await loginAsStaff(page, 'vendedor');
    await page.goto('views/sales.php');
    const form = page.locator('.formulario-venta').first();

    await expect(form.getByRole('link', { name: '+ Crear cliente nuevo' })).toHaveCount(0);
    await expect(form.getByText('Si no aparece, contacta al administrador.')).toBeVisible();
  });

  test('un producto agotado no se puede agregar al carrito', async ({ page }) => {
    await loginAsStaff(page, 'vendedor');
    await page.goto('views/sales.php');
    const form = page.locator('.formulario-venta').first();

    await form.locator('.buscador-producto').fill(E2E_OUT_OF_STOCK_PRODUCT_NAME);
    const item = form.locator('.producto-dropdown .item').filter({ hasText: E2E_OUT_OF_STOCK_PRODUCT_NAME }).first();
    await item.waitFor({ state: 'visible' });
    await expect(item).toHaveClass(/sin-stock/);
    await item.click();

    await expect(page.getByText(/está agotado en esta sucursal/)).toBeVisible();
    await expect(form.locator('.producto-item')).toHaveCount(0);
  });

  test('el boton + respeta el stock maximo disponible', async ({ page }) => {
    await loginAsStaff(page, 'vendedor');
    await page.goto('views/sales.php');
    const form = page.locator('.formulario-venta').first();

    // Stock=1 (ver scripts/seed_e2e_test_data.php): la primera unidad se agrega sola.
    await agregarProductoPorNombre(form, E2E_LOW_STOCK_PRODUCT_NAME);
    const item = form.locator('.producto-item').first();
    await expect(item.locator('.cantidad')).toHaveValue('1');

    await item.locator('button', { hasText: '+' }).click();
    await expect(page.getByText('No hay más stock disponible para este producto.')).toBeVisible();
    await expect(item.locator('.cantidad')).toHaveValue('1');
  });

  test('un descuento mayor al subtotal de la linea se ajusta automaticamente', async ({ page }) => {
    await loginAsStaff(page, 'vendedor');
    await page.goto('views/sales.php');
    const form = page.locator('.formulario-venta').first();

    await agregarProductoPorNombre(form, E2E_PRODUCT_NAME);
    const item = form.locator('.producto-item').first();
    const precioUnitario = await item.locator('.precio-unitario').inputValue();

    await item.locator('.descuento-linea').fill('999999');
    await expect(page.getByText('El descuento no puede superar el subtotal del producto.')).toBeVisible();
    await expect(item.locator('.descuento-linea')).toHaveValue(Number(precioUnitario).toFixed(2));
    await expect(item.locator('.line-subtotal')).toHaveText('0.00');
  });

  test('cerrar una pestaña con datos pide confirmacion antes de perderlos', async ({ page }) => {
    await loginAsStaff(page, 'vendedor');
    await page.goto('views/sales.php');
    const form = page.locator('.formulario-venta').first();

    // Escribir un nombre (sin llegar a resolver un cliente real) ya cuenta como "datos sin
    // guardar" para hasVentaData(), que es lo que dispara el modal de confirmacion.
    await form.locator('.cliente_nombre').fill('Cliente a medio capturar');

    await page.locator('.close-tab').click();
    const modal = page.locator('#modal-cerrar-venta');
    await expect(modal).toBeVisible();

    await modal.getByRole('link', { name: 'Cancelar' }).click();
    await expect(modal).toBeHidden();
    await expect(page.locator('.venta-context')).toHaveCount(1);

    await page.locator('.close-tab').click();
    await expect(modal).toBeVisible();
    await page.locator('#btn-confirmar-cerrar-venta').click();

    // Al quedarse sin pestañas, sales.php abre una nueva vacia automaticamente.
    await expect(page.locator('.venta-context')).toHaveCount(1);
    await expect(page.locator('.formulario-venta').first().locator('.cliente_nombre')).toHaveValue('');
  });

  test('el boton + abre una pestaña nueva independiente de la actual', async ({ page }) => {
    await loginAsStaff(page, 'vendedor');
    await page.goto('views/sales.php');
    const primerForm = page.locator('#venta-v1 .formulario-venta');
    await agregarProductoPorNombre(primerForm, E2E_PRODUCT_NAME);
    await expect(primerForm.locator('.producto-item')).toHaveCount(1);

    await page.getByRole('button', { name: 'add' }).click();
    await expect(page.locator('.venta-context')).toHaveCount(2);

    const segundoForm = page.locator('#venta-v2 .formulario-venta');
    await expect(segundoForm.locator('.producto-item')).toHaveCount(0);
    await expect(segundoForm.locator('.cliente_nombre')).toHaveValue('');
    // La primera pestaña no debe haberse tocado al abrir la segunda.
    await expect(primerForm.locator('.producto-item')).toHaveCount(1);
  });

  test('el borrador del pedido persiste tras recargar la pagina', async ({ page }) => {
    await loginAsStaff(page, 'vendedor');
    await page.goto('views/sales.php');
    const form = page.locator('.formulario-venta').first();

    const nombreEscrito = 'Cliente borrador sin resolver';
    await form.locator('.cliente_nombre').fill(nombreEscrito);
    await agregarProductoPorNombre(form, E2E_PRODUCT_NAME);
    await expect(form.locator('.producto-item')).toHaveCount(1);

    // scheduleSalesDraftSave() hace debounce de 250ms antes de escribir a localStorage.
    await page.waitForTimeout(500);
    await page.reload();

    const formRecargado = page.locator('.formulario-venta').first();
    await expect(formRecargado.locator('.cliente_nombre')).toHaveValue(nombreEscrito);
    await expect(formRecargado.locator('.producto-item')).toHaveCount(1);
  });

  test('un repartidor no puede acceder a agendar pedido', async ({ page }) => {
    await loginAsStaff(page, 'repartidor');
    await page.goto('views/sales.php');
    await expect(page).toHaveURL(/views\/dashboard\.php/);
  });
});
