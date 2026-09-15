import { test, expect } from './fixtures';
import { loginAsStaff } from './helpers';

// views/salud_sistema.php es de SOLO LECTURA (sin ninguna accion POST): reune 7 señales de
// seguridad/mantenimiento (cuentas bloqueadas, bloqueos recientes, migraciones pendientes,
// logs locales, permisos sin efecto, accesos temporales por vencer, logins recientes) en
// tarjetas independientes -- cada saludQuery() atrapa su propio error y devuelve [], asi que
// la pagina nunca debería tronar sin importar el estado real de la BD/filesystem local. No se
// prueba el escenario de "cuenta realmente bloqueada" (5 intentos fallidos, 15 min): requeriria
// una cuenta de staff dedicada y desechable (el login fallido inserta ademas una alerta en
// mensajes_soporte) -- gap conocido, documentado aqui en vez de forzarlo con una cuenta
// compartida con el resto de la suite.

test.describe('Salud del Sistema (salud_sistema.php)', () => {
  test('un vendedor no puede acceder a salud del sistema', async ({ page }) => {
    await loginAsStaff(page, 'vendedor');
    await page.goto('views/salud_sistema.php');
    await expect(page).toHaveURL(/views\/dashboard\.php/);
  });

  test('un encargado no puede acceder a salud del sistema', async ({ page }) => {
    await loginAsStaff(page, 'encargado');
    await page.goto('views/salud_sistema.php');
    await expect(page).toHaveURL(/views\/dashboard\.php/);
  });

  test('un repartidor no puede acceder a salud del sistema', async ({ page }) => {
    await loginAsStaff(page, 'repartidor');
    await page.goto('views/salud_sistema.php');
    await expect(page).toHaveURL(/views\/dashboard\.php/);
  });

  test('un admin ve las 7 tarjetas de señales sin que la página truene', async ({ page }) => {
    await loginAsStaff(page, 'admin');
    await page.goto('views/salud_sistema.php');

    await expect(page.getByRole('heading', { name: 'Salud del sistema' })).toBeVisible();
    const titulos = [
      'Cuentas bloqueadas ahora',
      'Bloqueos recientes',
      'Migraciones pendientes',
      'Correo y logs locales',
      'Permisos sin efecto',
      'Accesos temporales por vencer',
      'Inicios de sesión recientes',
    ];
    for (const titulo of titulos) {
      await expect(page.locator('.salud-card h6', { hasText: titulo })).toBeVisible();
    }
  });

  test('el propio login del admin aparece en "Inicios de sesión recientes"', async ({ page }) => {
    await loginAsStaff(page, 'admin');
    await page.goto('views/salud_sistema.php');

    const tarjeta = page.locator('.salud-card').filter({ hasText: 'Inicios de sesión recientes' });
    // Se sembró/logueó como "Playwright E2E Admin" (scripts/seed_e2e_staff_accounts.php).
    await expect(tarjeta.getByText('Playwright E2E Admin').first()).toBeVisible();
  });

  test('"Migraciones pendientes" no rompe la página sin importar el estado real de la BD', async ({ page }) => {
    await loginAsStaff(page, 'admin');
    await page.goto('views/salud_sistema.php');

    const tarjeta = page.locator('.salud-card').filter({ hasText: 'Migraciones pendientes' });
    // O bien "al día" (0) o bien una lista de archivos pendientes -- nunca el mensaje de error
    // del catch (que solo aparece si glob()/la consulta a migration_history de verdad falla).
    await expect(tarjeta.getByText('El runner de migraciones devolvió un error')).toHaveCount(0);
  });
});
