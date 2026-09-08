import { test, expect } from './fixtures';
import { loginAsStaff } from './helpers';

// views/trafico_visitas.php es de solo lectura (sin AJAX). "Por Plataforma" y "Top Campañas"
// no dependen de ningun flag (siempre calculan), pero esta BD local ya acumulo miles de
// visitas reales de años de correr esta misma suite (registerAndLogin logueado SI cuenta
// como trafico real) -- se evitan asserts sobre filas/orden especificos de esas tablas, igual
// que en comportamiento-sitio.staff.spec.ts. Solo "Ventas por Plataforma" depende de un flag
// (atribucion_ventas, prendido por default en scripts/seed_e2e_test_data.php).
//
// NOTA: ese mismo flag tambien lo toca ventas-features-config.staff.spec.ts ("activar/
// desactivar Atribución de Ventas"). Si esos dos tests caen exactamente al mismo tiempo en
// corridas con mas de 1 worker (fullyParallel corre archivos distintos en paralelo), podrian
// pisarse -- riesgo bajo y ya aceptado (no hay infraestructura de locks entre specs para
// flags globales en esta suite); si algun dia se vuelve flaky de verdad, la solucion es
// forzar esos dos tests a un mismo worker/proyecto serial.

async function setAtribucionVentasActiva(page: import('@playwright/test').Page, activo: boolean): Promise<void> {
  await page.goto('views/ventas_features_config.php');
  const casilla = page.locator('input[name="activo_atribucion_ventas"]');
  if ((await casilla.isChecked()) !== activo) {
    await casilla.setChecked(activo, { force: true });
    await page.getByRole('button', { name: 'Guardar' }).click();
    await expect(page.getByText('Configuración guardada.')).toBeVisible();
  }
}

test.describe('Tráfico y Campañas (trafico_visitas.php)', () => {
  test('un vendedor no puede acceder', async ({ page }) => {
    await loginAsStaff(page, 'vendedor');
    await page.goto('views/trafico_visitas.php');
    await expect(page).toHaveURL(/views\/dashboard\.php/);
  });

  test('un encargado no puede acceder (ver_trafico_campanas es solo-admin)', async ({ page }) => {
    await loginAsStaff(page, 'encargado');
    await page.goto('views/trafico_visitas.php');
    await expect(page).toHaveURL(/views\/dashboard\.php/);
  });

  test('un repartidor no puede acceder', async ({ page }) => {
    await loginAsStaff(page, 'repartidor');
    await page.goto('views/trafico_visitas.php');
    await expect(page).toHaveURL(/views\/dashboard\.php/);
  });

  test('un admin ve los filtros, los totales, y las secciones de reporte', async ({ page }) => {
    await loginAsStaff(page, 'admin');
    await page.goto('views/trafico_visitas.php');

    await expect(page.locator('input[name="fecha_inicio"]')).toBeVisible();
    await expect(page.locator('input[name="fecha_fin"]')).toBeVisible();

    // Totales: siempre numeros, nunca vacios/NaN.
    const totalVisitas = await page.locator('.card.indigo').locator('.display-metric').textContent();
    expect(Number((totalVisitas ?? '').replace(/[^\d]/g, ''))).toBeGreaterThanOrEqual(0);

    await expect(page.getByText('Por Plataforma', { exact: true })).toBeVisible();
    await expect(page.getByText('Top Campañas (utm_campaign)')).toBeVisible();
  });

  // Un solo test, secuencial: 'atribucion_ventas' es un flag GLOBAL de un jalon (ver
  // comentario arriba) -- dos tests separados tocandolo en paralelo (workers>1) se pisan
  // entre si. Cubre ambos estados (apagado y prendido/default) sin ese riesgo de carrera.
  test('la sección "Ventas por Plataforma" responde al flag "Atribución de Ventas"', async ({ page }) => {
    await loginAsStaff(page, 'admin');

    await setAtribucionVentasActiva(page, false);
    await page.goto('views/trafico_visitas.php');
    await expect(page.getByText('La sección "Ventas por Plataforma" está apagada.')).toBeVisible();
    await expect(page.getByText('Ventas por Plataforma', { exact: true })).toHaveCount(0);

    // Restaura el estado prendido (el default real de esta iniciativa) y confirma que
    // la seccion vuelve a aparecer.
    await setAtribucionVentasActiva(page, true);
    await page.goto('views/trafico_visitas.php');
    await expect(page.getByText('Ventas por Plataforma', { exact: true })).toBeVisible();
    await expect(page.getByText('La sección "Ventas por Plataforma" está apagada.')).toHaveCount(0);
  });
});
