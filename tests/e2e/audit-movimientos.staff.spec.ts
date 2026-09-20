import type { Page } from '@playwright/test';
import { test, expect } from './fixtures';
import { cerrarOfertaVentaSiAparece, loginAsStaff, registerAndLogin, telefonoUnico } from './helpers';

// Auditoría completa (PR #201): views/activity_logs.php > pestaña "Movimientos" (logs_auditoria) responde
// "quién hizo qué, cuándo y desde dónde, con el valor de antes y de después". Este spec prueba la cadena
// COMPLETA de punta a punta -- hacer un cambio real en la UI de negocio y ver su rastro en Movimientos --,
// que es lo que los ~1800 tests unitarios (AuditUtilsTest, AuditEdgeCasesTest, ...) no pueden cubrir: que
// cada endpoint realmente llame a logAudit con el usuario correcto, que la vista lo pinte, y que los datos
// personales / secretos nunca salgan sin enmascarar.
//
// Cada test genera su PROPIO evento con un nombre único y lo busca por ese nombre (filtro "q" sobre el
// detalle): la BD acumula miles de movimientos de otras corridas y de tests en paralelo, así que jamás se
// afirma sobre "el primer renglón" ni sobre totales globales.

const ADMIN_NOMBRE = 'Playwright E2E Admin';

async function abrirMovimientos(page: Page, filtros: Record<string, string> = {}): Promise<void> {
  const params = new URLSearchParams({ vista: 'movimientos', ...filtros });
  await page.goto(`views/activity_logs.php?${params.toString()}`);
  await expect(page.locator('.log-tab.activa')).toContainText('Movimientos');
}

function fila(page: Page, accion: string) {
  return page.locator('.mov-item').filter({ has: page.locator('.mov-que strong', { hasText: accion }) });
}

async function crearProducto(page: Page, nombre: string, precioVenta: string): Promise<void> {
  await page.goto('views/products.php');
  await page.locator('#nombre').fill(nombre);
  await page.locator('#precio_costo').fill('10.00');
  await page.locator('#precio_venta').fill(precioVenta);
  await page.locator('#btn-submit').click();
  await expect(page.locator('#tabla-productos-body').getByText(nombre)).toBeVisible();
}

test.describe('Auditoría: Movimientos (activity_logs.php)', () => {
  test('editar el precio de un producto deja rastro con antes/después, severidad ALERTA y quién lo hizo', async ({ page }) => {
    await loginAsStaff(page, 'admin');
    const nombre = `Playwright Auditoria Producto ${Date.now()}`;
    await crearProducto(page, nombre, '19.99');

    const filaProducto = page.locator('#tabla-productos-body tr').filter({ hasText: nombre });
    await filaProducto.locator('button.blue').click();
    await expect(page.locator('#nombre')).toHaveValue(nombre);
    await page.locator('#precio_venta').fill('24.50');
    await page.getByRole('button', { name: 'Guardar Cambios' }).click();
    await expect(page.locator('.toast')).toBeVisible();

    await abrirMovimientos(page, { q: nombre });

    // Alta: informativa/aviso, con el precio en el detalle.
    const creado = fila(page, 'Producto creado');
    await expect(creado).toHaveCount(1);
    await expect(creado.locator('.mov-detalle')).toContainText('19.99');
    await expect(creado.locator('.mov-actor-nombre')).toHaveText(ADMIN_NOMBRE);

    // Edición de precio: severidad ALERTA (tocar precios es lo delicado) y antes → después.
    const editado = fila(page, 'Producto editado');
    await expect(editado).toHaveCount(1);
    await expect(editado.locator('.sev')).toHaveText('ALERTA');
    await expect(editado.locator('.mov-actor-nombre')).toHaveText(ADMIN_NOMBRE);
    await editado.locator('summary', { hasText: 'Ver antes' }).click();
    const filaPrecio = editado.locator('table.mov-cambios tbody tr').filter({ hasText: 'precio_venta' });
    await expect(filaPrecio.locator('td.antes')).toContainText('19.99');
    await expect(filaPrecio.locator('td.despues')).toContainText('24.5');
    // Solo se listan los campos que CAMBIARON (no toda la ficha).
    await expect(editado.locator('table.mov-cambios tbody tr')).toHaveCount(1);

    // Quién y desde dónde: IP, dispositivo y sesión quedan en el renglón.
    await expect(editado.locator('.mov-donde')).toContainText(/\d+\.\d+\.\d+\.\d+|::1/);
    await expect(editado.locator('.mov-donde')).toContainText('sesión');

    // Historial de UN registro: el enlace del renglón filtra por producto y trae alta + edición.
    await editado.locator('a.mov-registro').click();
    await expect(page.getByText('Historial completo de')).toBeVisible();
    await expect(fila(page, 'Producto creado')).toHaveCount(1);
    await expect(fila(page, 'Producto editado')).toHaveCount(1);
    await page.getByRole('link', { name: 'Quitar este filtro' }).click();
    await expect(page.getByText('Historial completo de')).toHaveCount(0);

    // Desactivar el producto también se registra, como ALERTA.
    await page.goto('views/products.php');
    page.once('dialog', (dialog) => dialog.accept());
    await page.locator('#tabla-productos-body tr').filter({ hasText: nombre }).locator('button.red').click();
    await expect(page.locator('#tabla-productos-body').getByText(nombre)).toHaveCount(0);
    await abrirMovimientos(page, { q: nombre });
    await expect(fila(page, 'Producto desactivado').locator('.sev')).toHaveText('ALERTA');
  });

  test('un cambio sin diferencias reales no deja movimiento (guardar igual no ensucia la auditoría)', async ({ page }) => {
    await loginAsStaff(page, 'admin');
    const nombre = `Playwright Auditoria SinCambio ${Date.now()}`;
    await crearProducto(page, nombre, '15.00');

    const filaProducto = page.locator('#tabla-productos-body tr').filter({ hasText: nombre });
    await filaProducto.locator('button.blue').click();
    await expect(page.locator('#nombre')).toHaveValue(nombre);
    await page.getByRole('button', { name: 'Guardar Cambios' }).click();
    await expect(page.locator('.toast')).toBeVisible();

    await abrirMovimientos(page, { q: nombre });
    await expect(fila(page, 'Producto creado')).toHaveCount(1);
    // logAuditCambios no escribe nada si no hubo cambio real.
    await expect(fila(page, 'Producto editado')).toHaveCount(0);

    await page.goto('views/products.php');
    page.once('dialog', (dialog) => dialog.accept());
    await page.locator('#tabla-productos-body tr').filter({ hasText: nombre }).locator('button.red').click();
    await expect(page.locator('#tabla-productos-body').getByText(nombre)).toHaveCount(0);
  });

  test('teléfono y correo de un cliente se guardan enmascarados: se ve QUE cambió, nunca el dato', async ({ page }) => {
    await loginAsStaff(page, 'admin');
    await page.goto('views/manage_customers.php');

    const nombre = `Playwright Cliente Auditoria ${Date.now()}`;
    // Unicos por corrida: los telefonos ya no se pueden repetir entre clientes (un fijo chocaria con otro cliente).
    const telefonoViejo = telefonoUnico();
    const telefonoNuevo = telefonoUnico();
    const correoViejo = `pii.viejo+${Date.now()}@example.com`;
    const correoNuevo = `pii.nuevo+${Date.now()}@example.com`;

    await page.getByRole('link', { name: 'Nuevo cliente' }).click();
    const modalCrear = page.locator('#modal-crear-cliente');
    await modalCrear.locator('input[name="nombre"]').fill(nombre);
    await modalCrear.locator('input[name="telefono"]').fill(telefonoViejo);
    await modalCrear.locator('input[name="email"]').fill(correoViejo);
    await modalCrear.getByRole('button', { name: /Crear|Guardar/ }).click();
    await expect(page.locator('.manage-customers-name', { hasText: nombre })).toBeVisible({ timeout: 20000 });
    await cerrarOfertaVentaSiAparece(page);

    const filaCliente = page.locator('.manage-customers-table-wrap tr').filter({ hasText: nombre });
    const modalEditar = page.locator('.modal.open').filter({ hasText: 'Editar cliente' });
    // Esta pantalla renderiza cientos de filas y sus modales; el clic puede llegar antes de que
    // M.Modal.init termine de enganchar los "modal-trigger". Se reintenta hasta que abra de verdad.
    await expect(async () => {
      await filaCliente.getByTitle('Editar cliente').click();
      await expect(modalEditar).toBeVisible({ timeout: 4000 });
    }).toPass({ timeout: 40000 });
    await modalEditar.locator('input[name="telefono"]').fill(telefonoNuevo);
    await modalEditar.locator('input[name="email"]').fill(correoNuevo);
    // El envio es por fetch y el servidor devuelve la pagina completa (cientos de clientes en esta BD): puede tardar
    // varios segundos antes de que aparezca el aviso, con el boton deshabilitado mientras tanto.
    await modalEditar.getByRole('button', { name: 'Guardar cambios' }).click();
    await expect(page.getByText('Cliente actualizado correctamente.')).toBeVisible({ timeout: 30000 });

    await abrirMovimientos(page, { q: nombre });
    const editado = fila(page, 'Cliente editado');
    await expect(editado).toHaveCount(1);
    await editado.locator('summary', { hasText: 'Ver antes' }).click();

    // Teléfono: ******<últimos 4>. Correo: <1a letra>***@dominio.
    const filaTel = editado.locator('table.mov-cambios tbody tr').filter({ hasText: 'telefono' });
    await expect(filaTel.locator('td.antes')).toHaveText(`******${telefonoViejo.slice(-4)}`);
    await expect(filaTel.locator('td.despues')).toHaveText(`******${telefonoNuevo.slice(-4)}`);
    const filaMail = editado.locator('table.mov-cambios tbody tr').filter({ hasText: 'email' });
    await expect(filaMail.locator('td.antes')).toHaveText('p***@example.com');
    await expect(filaMail.locator('td.despues')).toHaveText('p***@example.com');

    // Ningún dato personal en claro en TODA la página de Movimientos (ni del alta ni de la edición).
    const html = await page.content();
    for (const secreto of [telefonoViejo, telefonoNuevo, correoViejo, correoNuevo]) {
      expect(html, `"${secreto}" no debe aparecer en claro en la auditoría`).not.toContain(secreto);
    }
  });

  test('un acceso denegado (vendedor a Logs de Actividad) queda registrado como ALERTA con su autor', async ({ page }) => {
    await loginAsStaff(page, 'vendedor');
    await page.goto('views/activity_logs.php');
    await expect(page).toHaveURL(/views\/dashboard\.php/);

    await loginAsStaff(page, 'admin');
    await abrirMovimientos(page, { accion: 'ACCESO_DENEGADO', q: 'activity_logs.php' });
    const denegado = page.locator('.mov-item').filter({ hasText: 'Playwright E2E Vendedor' }).first();
    await expect(denegado).toBeVisible();
    await expect(denegado.locator('.mov-que strong')).toHaveText('Acceso denegado (sin permiso)');
    await expect(denegado.locator('.sev')).toHaveText('ALERTA');
  });

  test('los intentos fallidos de login escalan de AVISO a ALERTA, el bloqueo se registra, y la contraseña jamás aparece', async ({ page }) => {
    // Cliente desechable con nombre ÚNICO (el actor que la auditoría muestra es ese nombre), para
    // poder distinguir sus renglones de los de otras corridas. NUNCA una cuenta de staff: bloquearla 15
    // minutos rompería el resto de la suite.
    const nombre = `Playwright Auditoria Login ${Date.now()}`;
    const email = `playwright-e2e+aud-${Date.now()}@example.com`;
    const password = 'E2eTest!2026';
    await page.goto('views/register.php');
    await page.locator('#nombre').fill(nombre);
    await page.locator('#email').fill(email);
    await page.locator('#telefono').fill(telefonoUnico());
    await page.locator('#password').fill(password);
    await page.locator('#confirm_password').fill(password);
    await page.getByRole('button', { name: 'REGISTRARME' }).click();
    await expect(page.getByText('Cuenta creada con éxito.')).toBeVisible();

    const claveMarcada = `ClaveMarcaAuditoria-${Date.now()}!`;
    for (let intento = 1; intento <= 5; intento++) {
      await page.goto('views/login.php');
      await page.locator('#email').fill(email);
      await page.locator('#password').fill(claveMarcada + intento);
      await page.getByRole('button', { name: 'Iniciar Sesión' }).click();
      await expect(page.getByText('Credenciales incorrectas.')).toBeVisible();
    }
    // 6.º intento, ya con la contraseña CORRECTA: la cuenta sigue bloqueada.
    await page.goto('views/login.php');
    await page.locator('#email').fill(email);
    await page.locator('#password').fill(password);
    await page.getByRole('button', { name: 'Iniciar Sesión' }).click();
    await expect(page.getByText('Cuenta bloqueada temporalmente por seguridad', { exact: false })).toBeVisible();

    await loginAsStaff(page, 'admin');
    await abrirMovimientos(page, { accion: 'LOGIN_FALLIDO' });
    const delCliente = page.locator('.mov-item').filter({ hasText: nombre });
    const detalle = (n: number) => delCliente.filter({ hasText: `intento ${n} de 5` });

    // Intentos 1 y 2: AVISO. Del 3.º en adelante: ALERTA (severidad: $nuevosIntentos >= 3).
    await expect(detalle(1).locator('.sev')).toHaveText('AVISO');
    await expect(detalle(2).locator('.sev')).toHaveText('AVISO');
    await expect(detalle(3).locator('.sev')).toHaveText('ALERTA');
    await expect(detalle(5).locator('.sev')).toHaveText('ALERTA');
    // Y el 6.º (cuenta ya bloqueada) también queda, como ALERTA.
    await expect(delCliente.filter({ hasText: 'cuenta bloqueada' }).locator('.sev')).toHaveText('ALERTA');

    // El bloqueo en sí es un movimiento aparte.
    await abrirMovimientos(page, { accion: 'BLOQUEO_CUENTA' });
    const bloqueo = page.locator('.mov-item').filter({ hasText: nombre });
    await expect(bloqueo.locator('.mov-que strong')).toHaveText('Cuenta bloqueada por intentos fallidos');
    await expect(bloqueo.locator('.sev')).toHaveText('ALERTA');

    // Ninguna de las contraseñas tecleadas aparece en claro, en ninguna de las dos consultas.
    const html = await page.content();
    expect(html).not.toContain('ClaveMarcaAuditoria');
    expect(html).not.toContain(password);
  });

  test('los filtros de acción y severidad solo dejan pasar lo que dicen, y "Limpiar" los quita', async ({ page }) => {
    // Genera al menos un movimiento de cada extremo: un acceso denegado (ALERTA) ya está garantizado por
    // el test de arriba, pero este debe poder correr solo.
    await loginAsStaff(page, 'vendedor');
    await page.goto('views/activity_logs.php');
    await loginAsStaff(page, 'admin');

    await abrirMovimientos(page);
    await page.locator('select[name="accion"]').selectOption('ACCESO_DENEGADO');
    await page.getByRole('button', { name: 'Filtrar' }).click();
    await expect(page).toHaveURL(/accion=ACCESO_DENEGADO/);
    // toHaveURL se cumple en cuanto cambia la URL, ANTES de que cargue la página nueva: hay que esperar
    // a que pinte los renglones filtrados antes de contarlos.
    await expect(page.locator('.mov-item').first()).toBeVisible();
    const etiquetas = page.locator('.mov-item .mov-que strong');
    expect(await etiquetas.count()).toBeGreaterThan(0);
    for (const texto of await etiquetas.allTextContents()) {
      expect(texto).toBe('Acceso denegado (sin permiso)');
    }

    await abrirMovimientos(page, { severidad: 'alerta' });
    const severidades = page.locator('.mov-item .sev');
    expect(await severidades.count()).toBeGreaterThan(0);
    for (const texto of await severidades.allTextContents()) {
      expect(texto).toBe('ALERTA');
    }

    await abrirMovimientos(page, { severidad: 'info' });
    await expect(page.locator('.mov-item .sev', { hasText: 'ALERTA' })).toHaveCount(0);

    await abrirMovimientos(page, { severidad: 'alerta', q: 'texto-que-no-existe-' + Date.now() });
    await expect(page.getByText('No se encontraron movimientos para los filtros seleccionados.')).toBeVisible();
    await page.getByRole('link', { name: 'Limpiar' }).click();
    await expect(page).toHaveURL(/vista=movimientos$/);
    await expect(page.locator('select[name="severidad"]')).toHaveValue('');
    await expect(page.locator('input[name="q"]')).toHaveValue('');
  });

  test('un rango de fechas y valores de filtro basura no truenan la vista', async ({ page }) => {
    await loginAsStaff(page, 'admin');
    const pageErrors: string[] = [];
    page.on('pageerror', (err) => pageErrors.push(err.message));

    // Basura en todos los parámetros (incluido intento de inyección SQL/HTML): se sanean, no revientan.
    await page.goto(
      "views/activity_logs.php?vista=movimientos&usuario=abc&accion=%27%3B%20DROP%20TABLE%20x%3B--&modulo=%3Cscript%3E&severidad=zzz&q=%25_%5C&fecha_inicio=no-es-fecha&fecha_fin=2999-99-99&pagina=-5"
    );
    await expect(page.locator('h4', { hasText: 'Log de Actividad' })).toBeVisible();
    await expect(page.locator('.mov-resumen')).toBeVisible();
    await expect(page.locator('.card-panel.red')).toHaveCount(0);
    expect(pageErrors).toEqual([]);

    // Una página fuera de rango cae en la última, no en un error.
    await page.goto('views/activity_logs.php?vista=movimientos&pagina=99999');
    await expect(page.locator('.mov-resumen')).toBeVisible();
    await expect(page.locator('.card-panel.red')).toHaveCount(0);
  });

  test('la paginación de Movimientos avanza y retrocede sin perder los filtros', async ({ page }) => {
    await loginAsStaff(page, 'admin');
    await abrirMovimientos(page);
    const paginacion = page.locator('ul.pagination');
    test.skip((await paginacion.count()) === 0, 'Esta BD no tiene movimientos suficientes para más de una página.');

    await expect(page.getByText(/Página 1 de \d+/)).toBeVisible();
    await paginacion.locator('a', { hasText: '2' }).first().click();
    await expect(page).toHaveURL(/pagina=2/);
    await expect(page.getByText(/Página 2 de \d+/)).toBeVisible();
    await expect(page.locator('.log-tab.activa')).toContainText('Movimientos');
  });

  test('un usuario NO admin con el permiso ver_auditoria abre Movimientos; sin el permiso lo redirigen', async ({ page }) => {
    // e2e-auditor: encargado con override individual "conceder ver_auditoria" (semilla). e2e-encargado: sin él.
    await loginAsStaff(page, 'auditor');
    await page.goto('views/activity_logs.php');
    await expect(page).toHaveURL(/activity_logs\.php/);
    await expect(page.locator('.log-tab.activa')).toContainText('Movimientos');
    await expect(page.locator('.mov-resumen')).toBeVisible();

    await loginAsStaff(page, 'encargado');
    await page.goto('views/activity_logs.php');
    await expect(page).toHaveURL(/views\/dashboard\.php/);
  });
});
