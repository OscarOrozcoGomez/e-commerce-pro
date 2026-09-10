import { test, expect } from './fixtures';
import { loginAsStaff } from './helpers';

// views/ventas_features_config.php prende/apaga iniciativas de ventas GLOBALES (una sola fila
// por feature en la BD, sin alcance por sucursal ni por test) -- y el formulario reescribe las
// 5 de un jalon en cada submit (cualquier checkbox no marcado = esa feature queda apagada). Para
// no dejar features reales prendidas/apagadas para el resto de la suite o para quien use la app
// despues, cada test que activa algo lo desactiva al final (o viceversa), enviando el formulario
// completo tal como esta en pantalla -- nunca solo el campo que le interesa a ese test.

test.describe('Nuevas Iniciativas de Ventas (ventas_features_config.php)', () => {
  test('un vendedor no puede acceder a la configuración de iniciativas de ventas', async ({ page }) => {
    await loginAsStaff(page, 'vendedor');
    await page.goto('views/ventas_features_config.php');
    await expect(page).toHaveURL(/views\/dashboard\.php/);
  });

  test('un encargado no puede acceder a la configuración de iniciativas de ventas', async ({ page }) => {
    await loginAsStaff(page, 'encargado');
    await page.goto('views/ventas_features_config.php');
    await expect(page).toHaveURL(/views\/dashboard\.php/);
  });

  test('un repartidor no puede acceder a la configuración de iniciativas de ventas', async ({ page }) => {
    await loginAsStaff(page, 'repartidor');
    await page.goto('views/ventas_features_config.php');
    await expect(page).toHaveURL(/views\/dashboard\.php/);
  });

  test('token CSRF inválido no guarda cambios', async ({ page }) => {
    await loginAsStaff(page, 'admin');
    await page.goto('views/ventas_features_config.php');

    const res = await page.request.post('views/ventas_features_config.php', {
      form: { csrf_token: 'token-invalido', accion: 'guardar_config' },
    });
    expect(await res.text()).toContain('Token CSRF inválido.');
  });

  test('activar/desactivar "Atribución de Ventas" persiste y se refleja tras recargar', async ({ page }) => {
    await loginAsStaff(page, 'admin');
    await page.goto('views/ventas_features_config.php');

    const casilla = page.locator('input[name="activo_atribucion_ventas"]');
    const estabaActivo = await casilla.isChecked();

    // El <span> del label solapa el checkbox nativo -- forzar evita depender de layout.
    await casilla.setChecked(!estabaActivo, { force: true });
    await page.getByRole('button', { name: 'Guardar' }).click();

    await expect(page.getByText('Configuración guardada.')).toBeVisible();
    await expect(page.locator('input[name="activo_atribucion_ventas"]')).toBeChecked({ checked: !estabaActivo });

    // Restaura el estado original para no dejar la iniciativa prendida/apagada de verdad.
    await page.locator('input[name="activo_atribucion_ventas"]').setChecked(estabaActivo, { force: true });
    await page.getByRole('button', { name: 'Guardar' }).click();
    await expect(page.getByText('Configuración guardada.')).toBeVisible();
    await expect(page.locator('input[name="activo_atribucion_ventas"]')).toBeChecked({ checked: estabaActivo });
  });

  test('activar "Catálogo Dinámico" revela la URL pública del feed', async ({ page }) => {
    await loginAsStaff(page, 'admin');
    await page.goto('views/ventas_features_config.php');

    const casilla = page.locator('input[name="activo_catalogo_feed"]');
    const estabaActivo = await casilla.isChecked();
    if (!estabaActivo) {
      await casilla.check({ force: true });
      await page.getByRole('button', { name: 'Guardar' }).click();
      await expect(page.getByText('Configuración guardada.')).toBeVisible();
    }

    await expect(page.getByText('api/product_feed.php')).toBeVisible();

    if (!estabaActivo) {
      await page.locator('input[name="activo_catalogo_feed"]').uncheck({ force: true });
      await page.getByRole('button', { name: 'Guardar' }).click();
      await expect(page.getByText('Configuración guardada.')).toBeVisible();
    }
  });

  test('guardar la config del Programa de Referidos persiste el descuento y el monto mínimo', async ({ page }) => {
    await loginAsStaff(page, 'admin');
    await page.goto('views/ventas_features_config.php');

    const descuentoOriginal = await page.locator('#referidos_descuento_porcentaje').inputValue();
    const montoOriginal = await page.locator('#referidos_monto_minimo').inputValue();

    await page.locator('#referidos_descuento_porcentaje').fill('15');
    await page.locator('#referidos_monto_minimo').fill('250');
    await page.getByRole('button', { name: 'Guardar' }).click();

    await expect(page.getByText('Configuración guardada.')).toBeVisible();
    await expect(page.locator('#referidos_descuento_porcentaje')).toHaveValue('15');
    await expect(page.locator('#referidos_monto_minimo')).toHaveValue('250');

    // Restaura los valores originales.
    await page.locator('#referidos_descuento_porcentaje').fill(descuentoOriginal);
    await page.locator('#referidos_monto_minimo').fill(montoOriginal);
    await page.getByRole('button', { name: 'Guardar' }).click();
    await expect(page.getByText('Configuración guardada.')).toBeVisible();
  });
});
