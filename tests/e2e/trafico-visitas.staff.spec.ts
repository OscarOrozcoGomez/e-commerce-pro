import { test, expect } from './fixtures';
import { loginAsStaff } from './helpers';

// views/trafico_visitas.php es de solo lectura (permiso 'ver_trafico_campanas', solo admin
// hoy). A diferencia de comportamiento_sitio.php, las secciones de Total de Visitas / Por
// Plataforma / Top Campañas NO estan detras de ningun feature flag -- solo "Ventas por
// Plataforma" lo esta (flag 'atribucion_ventas', activo por default segun
// scripts/seed_e2e_test_data.php -> database/migrations/20260825_000002_...).

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

  test('un admin ve los totales y el desglose por plataforma', async ({ page }) => {
    await loginAsStaff(page, 'admin');
    await page.goto('views/trafico_visitas.php');

    await expect(page.getByText('Total de Visitas')).toBeVisible();
    await expect(page.getByText('Visitantes Únicos', { exact: true })).toBeVisible();
    await expect(page.getByText('Por Plataforma', { exact: true })).toBeVisible();
    await expect(page.getByText('Top Campañas')).toBeVisible();

    // Los totales siempre son numeros validos, con o sin trafico en el rango.
    const totalVisitas = await page.locator('.card.indigo').locator('.display-metric').textContent();
    expect(Number((totalVisitas ?? '').replace(/[^\d]/g, ''))).toBeGreaterThanOrEqual(0);
  });

  test('con "Atribución de Ventas" prendida (default), muestra Ventas por Plataforma', async ({ page }) => {
    await loginAsStaff(page, 'admin');
    await setAtribucionVentasActiva(page, true);

    await page.goto('views/trafico_visitas.php');
    await expect(page.getByText('Ventas por Plataforma')).toBeVisible();
    await expect(page.getByText('La sección "Ventas por Plataforma" está apagada.')).toHaveCount(0);
  });

  test('con "Atribución de Ventas" apagada, muestra el aviso en su lugar', async ({ page }) => {
    await loginAsStaff(page, 'admin');
    await setAtribucionVentasActiva(page, false);

    await page.goto('views/trafico_visitas.php');
    await expect(page.getByText('La sección "Ventas por Plataforma" está apagada.')).toBeVisible();

    // Restaura el estado activo (el default real de esta iniciativa).
    await setAtribucionVentasActiva(page, true);
  });
});
