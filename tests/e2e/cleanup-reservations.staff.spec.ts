import { test, expect } from './fixtures';
import {
  addProductToCartByName,
  loginAsStaff,
  registerAndLogin,
  submitDomicilioCheckoutForm,
  E2E_CLEANUP_PRODUCT_NAME,
  E2E_PRODUCT_NAME,
} from './helpers';

// views/cleanup_reservations.php ("Liberar Stock Apartado"): lista pedidos 'pendiente_pago'/
// 'apartado' (el checkout web real los deja así -- ver dbCreatePublicOrder en core/auth.php)
// y permite liberar manualmente el inventario reservado (normalmente lo hace un cron por
// antigüedad, ver RESERVATION_EXPIRY_HOURS). Un pedido recién creado por Playwright no es
// "viejo", así que todos los tests navegan con "?apply_threshold=0" (el mismo "Sin filtro"
// de la UI) para verlo sin esperar el umbral de horas.

async function crearPedidoDeDosProductos(page: import('@playwright/test').Page): Promise<number> {
  await registerAndLogin(page);
  await addProductToCartByName(page, E2E_PRODUCT_NAME);
  await addProductToCartByName(page, E2E_CLEANUP_PRODUCT_NAME);
  await submitDomicilioCheckoutForm(page);

  await page.getByRole('button', { name: 'Continuar' }).click();
  await page.getByRole('button', { name: 'No, continuar' }).click();
  await page.waitForURL(/gracias\.php\?id=\d+/);

  const url = new URL(page.url());
  return Number(url.searchParams.get('id'));
}

test.describe('Liberar Stock Apartado (cleanup_reservations.php)', () => {
  test('un vendedor no puede acceder', async ({ page }) => {
    await loginAsStaff(page, 'vendedor');
    await page.goto('views/cleanup_reservations.php');
    await expect(page).toHaveURL(/views\/dashboard\.php/);
  });

  test('un repartidor no puede acceder', async ({ page }) => {
    await loginAsStaff(page, 'repartidor');
    await page.goto('views/cleanup_reservations.php');
    await expect(page).toHaveURL(/views\/dashboard\.php/);
  });

  test('liberar un solo producto deja el pedido vivo con el otro renglón; liberar el resto lo cancela', async ({ page }) => {
    const idPedido = await crearPedidoDeDosProductos(page);

    await loginAsStaff(page, 'encargado');
    await page.goto('views/cleanup_reservations.php?apply_threshold=0');

    const fila = page.locator('table tbody tr').filter({ hasText: new RegExp(`#${idPedido}(?!\d)`) });
    await expect(fila).toBeVisible();
    await expect(fila).toContainText(E2E_PRODUCT_NAME);
    await expect(fila).toContainText(E2E_CLEANUP_PRODUCT_NAME);

    // "Liberar producto": prompt (cantidad) seguido de confirm -- se maneja cada uno segun
    // su tipo, ya que aparecen en secuencia (ver liberarProducto() en la vista). Se acota al
    // <li> de E2E_PRODUCT_NAME especificamente: el orden de los 2 renglones en el DOM no esta
    // garantizado por la consulta (solo ordena por pedido, no por detalle).
    page.on('dialog', (dialog) => {
      void (dialog.type() === 'prompt' ? dialog.accept('1') : dialog.accept());
    });
    await fila.locator('li').filter({ hasText: E2E_PRODUCT_NAME }).getByRole('button', { name: 'Liberar producto' }).click();
    await expect(page.getByText(/Se libero 1 unidad\(es\) del producto seleccionado\./)).toBeVisible();

    // El pedido sigue vivo (solo se liberó 1 de 2 renglones) con el producto restante.
    const filaTrasParcial = page.locator('table tbody tr').filter({ hasText: new RegExp(`#${idPedido}(?!\d)`) });
    await expect(filaTrasParcial).toBeVisible();
    await expect(filaTrasParcial).not.toContainText(E2E_PRODUCT_NAME);
    await expect(filaTrasParcial).toContainText(E2E_CLEANUP_PRODUCT_NAME);

    // "Liberar seleccionados" sobre el renglón restante cancela el pedido por completo.
    await filaTrasParcial.locator('.release-check').check({ force: true });
    await page.getByRole('button', { name: 'Liberar seleccionados' }).click();
    await expect(page.getByText(/Se liberaron 1 pedidos pendientes\/apartados\./)).toBeVisible();

    await expect(page.locator('table tbody tr').filter({ hasText: new RegExp(`#${idPedido}(?!\d)`) })).toHaveCount(0);
  });

  test('con el umbral por defecto (horas), un pedido recién creado no aparece; con "Sin filtro" sí', async ({ page }) => {
    const idPedido = await crearPedidoDeDosProductos(page);

    await loginAsStaff(page, 'admin');
    await page.goto('views/cleanup_reservations.php');
    await expect(page.locator('table tbody tr').filter({ hasText: new RegExp(`#${idPedido}(?!\d)`) })).toHaveCount(0);

    await page.getByRole('link', { name: 'Sin filtro' }).click();
    await expect(page).toHaveURL(/apply_threshold=0/);
    await expect(page.locator('table tbody tr').filter({ hasText: new RegExp(`#${idPedido}(?!\d)`) })).toBeVisible();
  });
});
