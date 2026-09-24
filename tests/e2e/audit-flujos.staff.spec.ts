import type { Page } from '@playwright/test';
import { test, expect } from './fixtures';
import {
  capturarRespuestaVenta,
  completeDomicilioCheckout,
  getNumeroPedido,
  loginAsStaff,
  E2E_PRODUCT_NAME,
} from './helpers';

// Complemento de audit-movimientos.staff.spec.ts: aquel prueba la VISTA de Movimientos (filtros, antes/después,
// enmascarado). Este prueba que los FLUJOS DE NEGOCIO más delicados realmente dejen su rastro, con el actor
// correcto y sin datos sensibles: cada test hace la operación real por la UI y la busca por un marcador único.
// (La auditoría se agregó a ~30 endpoints; si alguno deja de llamar a logAudit, aquí se nota.)

async function abrirMovimientos(page: Page, filtros: Record<string, string>): Promise<void> {
  const params = new URLSearchParams({ vista: 'movimientos', ...filtros });
  await page.goto(`views/activity_logs.php?${params.toString()}`);
  await expect(page.locator('.log-tab.activa')).toContainText('Movimientos');
}

test.describe('Auditoría: los flujos de negocio dejan rastro', () => {
  test('transferir stock entre almacenes queda registrado con origen, destino, producto y cantidad', async ({ page }) => {
    await loginAsStaff(page, 'admin');
    await page.goto('views/transfer_stock.php');

    const marcador = `Auditoria-${Date.now()}`;
    await page.locator('#id_origen').selectOption({ label: 'Almacén Central' });
    const destino = page.locator('#id_destino option', { hasText: /Papeler[ií]a Liz/i }).first();
    await page.locator('#id_destino').selectOption({ label: ((await destino.textContent()) ?? '').trim() });
    await page.locator('#p-search').fill(E2E_PRODUCT_NAME);
    await page.locator('#p-dropdown .item').filter({ hasText: E2E_PRODUCT_NAME }).first().click();
    await page.locator('#p-cantidad').fill('3');
    await page.getByRole('button', { name: 'Agregar' }).click();
    await page.locator('#observacion').fill(marcador);
    await page.getByRole('button', { name: /EJECUTAR TRANSFERENCIA/ }).click();
    await expect(page.getByText('¡Transferencia realizada!')).toBeVisible();

    await abrirMovimientos(page, { q: marcador });
    const fila = page.locator('.mov-item').filter({ hasText: marcador });
    await expect(fila).toHaveCount(1);
    await expect(fila.locator('.mov-que strong')).toHaveText('Transferencia de stock entre almacenes');
    await expect(fila.locator('.mov-actor-nombre')).toHaveText('Playwright E2E Admin');
    await expect(fila.locator('.mov-detalle')).toContainText('Almacén Central');
    await expect(fila.locator('.mov-detalle')).toContainText(E2E_PRODUCT_NAME);
    await expect(fila.locator('.mov-detalle')).toContainText('x3');
  });

  test('una entrada de inventario queda registrada con el producto, la cantidad y quién la hizo', async ({ page }) => {
    await loginAsStaff(page, 'encargado');
    await page.goto('views/inventario_entradas.php');

    // Cantidad poco común: sirve de marcador único en el detalle ("+NNN u.").
    const cantidad = 100 + (Date.now() % 800);
    await page.locator('#buscador-inbound').fill(E2E_PRODUCT_NAME);
    await page.locator('#buscador-inbound').press('Tab');
    await expect(page.locator('#id_producto_inbound')).not.toHaveValue('');
    await page.locator('#cantidad_inbound').fill(String(cantidad));
    await page.getByRole('button', { name: 'REGISTRAR ENTRADA' }).click();
    await expect(page.getByText('Stock actualizado correctamente')).toBeVisible();

    await loginAsStaff(page, 'admin');
    await abrirMovimientos(page, { accion: 'ENTRADA_INVENTARIO', q: `+${cantidad} u.` });
    const fila = page.locator('.mov-item').first();
    await expect(fila.locator('.mov-que strong')).toHaveText('Entrada de inventario');
    await expect(fila.locator('.mov-actor-nombre')).toHaveText('Playwright E2E Encargado');
    await expect(fila.locator('.mov-detalle')).toContainText(E2E_PRODUCT_NAME);
  });

  test('una venta de mostrador queda registrada con su folio y el vendedor, sin datos personales', async ({ page }) => {
    await loginAsStaff(page, 'vendedor');
    await page.goto('views/sales.php');
    const form = page.locator('.formulario-venta').first();
    await form.locator('.buscador-producto').fill(E2E_PRODUCT_NAME);
    await form.locator('.producto-dropdown .item').filter({ hasText: E2E_PRODUCT_NAME }).first().click();

    const venta = await capturarRespuestaVenta(page);
    await form.getByRole('button', { name: 'Registrar Venta' }).click();
    await expect.poll(() => venta.resultado?.numero_pedido ?? '').not.toBe('');
    const folio = venta.resultado!.numero_pedido as string;
    expect(folio).toMatch(/^MOS-/);

    await loginAsStaff(page, 'admin');
    await abrirMovimientos(page, { q: folio });
    const fila = page.locator('.mov-item').filter({ has: page.locator('.mov-que strong', { hasText: 'Venta en sucursal registrada' }) });
    await expect(fila).toHaveCount(1);
    await expect(fila.locator('.mov-actor-nombre')).toHaveText('Playwright E2E Vendedor');
    await expect(fila.locator('.mov-detalle')).toContainText(folio);
    await expect(fila.locator('.mov-detalle')).toContainText('99.99');
  });

  test('crear y eliminar un usuario interno: se registran (la baja como ALERTA) y la contraseña nunca aparece', async ({ page }) => {
    await loginAsStaff(page, 'admin');
    await page.goto('views/users.php');

    const marca = Date.now();
    const nombre = `Playwright Auditoria Usuario ${marca}`;
    const email = `e2e-aud-usuario-${marca}@playwright.test`;
    const password = 'E2eNewUser!2026';
    await page.locator('#nombre').fill(nombre);
    await page.locator('#email').fill(email);
    await page.locator('#password').fill(password);
    await page.locator('select[name="id_rol"]').selectOption({ label: 'vendedor' }, { force: true });
    await page.locator('select[name="id_almacen"]').selectOption({ label: 'Almacén Central' }, { force: true });
    await page.getByRole('button', { name: 'Crear Usuario' }).click();
    await expect(page.getByText(email).first()).toBeVisible();

    const fila = page.locator('.users-table-wrap tr').filter({ hasText: email });
    page.once('dialog', (dialog) => dialog.accept());
    await fila.getByTitle('Eliminar usuario').click();
    await expect(page.locator('.users-table-wrap tr').filter({ hasText: email })).toHaveCount(0);

    await abrirMovimientos(page, { q: nombre });
    const creado = page.locator('.mov-item').filter({ has: page.locator('.mov-que strong', { hasText: 'Usuario creado' }) });
    const eliminado = page.locator('.mov-item').filter({ has: page.locator('.mov-que strong', { hasText: 'Usuario eliminado' }) });
    await expect(creado).toHaveCount(1);
    await expect(creado.locator('.mov-actor-nombre')).toHaveText('Playwright E2E Admin');
    await expect(eliminado).toHaveCount(1);
    await expect(eliminado.locator('.sev')).toHaveText('ALERTA');
    expect(await page.content()).not.toContain(password);
  });

  test('un pedido web queda registrado con el teléfono de contacto enmascarado', async ({ page }) => {
    const idPedido = await completeDomicilioCheckout(page);
    const numero = await getNumeroPedido(page, idPedido);

    await loginAsStaff(page, 'admin');
    await abrirMovimientos(page, { q: numero });
    const fila = page.locator('.mov-item').filter({ has: page.locator('.mov-que strong', { hasText: 'Pedido creado desde el catálogo web' }) });
    await expect(fila).toHaveCount(1);
    // El teléfono del checkout de prueba es 3311234567 -> ******4567; el número completo NUNCA sale.
    await expect(fila.locator('.mov-detalle')).toContainText('******4567');
    expect(await page.content()).not.toContain('3311234567');
  });
});
