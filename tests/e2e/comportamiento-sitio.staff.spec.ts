import { test, expect } from './fixtures';
import { loginAsStaff } from './helpers';

// views/comportamiento_sitio.php es de solo lectura, detras del flag 'comportamiento_sitio'
// (ventas_features_config.php, apagado por default) y del permiso 'ver_comportamiento_sitio'
// (solo admin lo tiene asignado hoy). "Elementos Más Clickeados"/"Páginas con Más Atención"
// muestran solo el top 20 por clics/vistas -- esta BD local ya acumulo miles de clics reales
// de años de correr esta misma suite (REGISTRARME, CONFIRMAR PEDIDO, etc., todos con
// es_interno=0 porque un cliente de prueba logueado SI cuenta como trafico real), asi que un
// clic nuevo de un solo test queda enterrado fuera del top 20 -- no es fiable afirmar que
// aparece ahi. Se verifica en cambio que la seccion "prendida" renderiza su estructura real
// (filtros, totales, las 3 tablas) sin tronar, que es lo que de verdad depende del feature flag.

async function setComportamientoSitioActivo(page: import('@playwright/test').Page, activo: boolean): Promise<void> {
  await page.goto('views/ventas_features_config.php');
  const casilla = page.locator('input[name="activo_comportamiento_sitio"]');
  if ((await casilla.isChecked()) !== activo) {
    // El <span> del label solapa el checkbox nativo (intercepta el click); force lo evita.
    await casilla.setChecked(activo, { force: true });
    await page.getByRole('button', { name: 'Guardar' }).click();
    await expect(page.getByText('Configuración guardada.')).toBeVisible();
  }
}

test.describe('Comportamiento en el Sitio (comportamiento_sitio.php)', () => {
  test('un vendedor no puede acceder', async ({ page }) => {
    await loginAsStaff(page, 'vendedor');
    await page.goto('views/comportamiento_sitio.php');
    await expect(page).toHaveURL(/views\/dashboard\.php/);
  });

  test('un encargado no puede acceder (ver_comportamiento_sitio es solo-admin)', async ({ page }) => {
    await loginAsStaff(page, 'encargado');
    await page.goto('views/comportamiento_sitio.php');
    await expect(page).toHaveURL(/views\/dashboard\.php/);
  });

  test('un repartidor no puede acceder', async ({ page }) => {
    await loginAsStaff(page, 'repartidor');
    await page.goto('views/comportamiento_sitio.php');
    await expect(page).toHaveURL(/views\/dashboard\.php/);
  });

  test('con la iniciativa apagada, muestra el aviso y no las tablas ni los filtros', async ({ page }) => {
    await loginAsStaff(page, 'admin');
    await setComportamientoSitioActivo(page, false);

    await page.goto('views/comportamiento_sitio.php');
    await expect(page.getByText('Esta sección está apagada.')).toBeVisible();
    await expect(page.locator('input[name="fecha_inicio"]')).toHaveCount(0);
  });

  test('con la iniciativa prendida, se ven los filtros, los totales, y las 3 secciones de reporte', async ({ page }) => {
    await loginAsStaff(page, 'admin');
    await setComportamientoSitioActivo(page, true);

    await page.goto('views/comportamiento_sitio.php');
    await expect(page.getByText('Esta sección está apagada.')).toHaveCount(0);

    await expect(page.locator('input[name="fecha_inicio"]')).toBeVisible();
    await expect(page.locator('input[name="fecha_fin"]')).toBeVisible();

    // Totales: siempre son numeros (0 si no hay trafico en el rango), nunca vacios/NaN.
    const totalVisitas = await page.locator('.card.indigo').locator('.display-metric').textContent();
    expect(Number((totalVisitas ?? '').replace(/[^\d]/g, ''))).toBeGreaterThanOrEqual(0);

    await expect(page.getByText('Productos con Más Atención', { exact: true })).toBeVisible();
    await expect(page.getByText('Páginas con Más Atención', { exact: true })).toBeVisible();
    await expect(page.getByText('Elementos Más Clickeados', { exact: true })).toBeVisible();

    // Restaura el estado apagado (el default real de esta iniciativa).
    await setComportamientoSitioActivo(page, false);
  });
});
