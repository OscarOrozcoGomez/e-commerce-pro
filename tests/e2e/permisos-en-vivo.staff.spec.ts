import { test, expect } from './fixtures';
import { loginAsStaff, E2E_STAFF_EMAILS } from './helpers';
import { fijarOverride } from './permisos-datos';

// Conceder / quitar un permiso SURTE EFECTO sin volver a iniciar sesion.
// La sesion re-lee sus permisos como mucho cada 30 s (refreshSessionPermissions en core/auth.php), asi que la
// prueba espera un poco mas que eso. Usa una cuenta EXCLUSIVA (permisosVivo, un vendedor) porque los
// otros specs corren en paralelo y se contaminarian si a la suya se le cambiaran permisos.
//
// Es la prueba del caso real: el admin abre Roles y Permisos, le quita o da algo a alguien, y esa persona
// (con la sesion ya abierta) debe perder/ganar el acceso de verdad.

const EMAIL = E2E_STAFF_EMAILS.permisosVivo;
const ESPERA_REFRESCO_MS = 31_000;

async function puedeEntrar(page: import('@playwright/test').Page, ruta: string): Promise<boolean> {
  await page.goto(ruta, { waitUntil: 'domcontentloaded' });
  return new URL(page.url()).pathname.endsWith(`/${ruta}`);
}

test.describe('Permisos en vivo: un cambio aplica sin re-login', () => {
  test.describe.configure({ mode: 'serial', timeout: 240_000 });

  test.afterEach(() => {
    // Pase o falle, la cuenta vuelve a su estado base (sin overrides): no contamina corridas futuras.
    fijarOverride(EMAIL, 'ver_auditoria', 'quitar');
    fijarOverride(EMAIL, 'realizar_ventas', 'quitar');
  });

  test('conceder ver_auditoria abre Movimientos; denegar realizar_ventas cierra Ventas; y al quitarlos vuelve todo a su lugar', async ({ page }) => {
    await loginAsStaff(page, 'permisosVivo');

    // Punto de partida: un vendedor normal vende y NO audita.
    expect(await puedeEntrar(page, 'views/sales.php'), 'el vendedor debería poder vender al inicio').toBe(true);
    expect(await puedeEntrar(page, 'views/activity_logs.php'), 'el vendedor NO debería ver auditoría al inicio').toBe(false);

    // El "admin" cambia sus permisos (override individual) con la sesión del vendedor ya abierta.
    fijarOverride(EMAIL, 'ver_auditoria', 'conceder');
    fijarOverride(EMAIL, 'realizar_ventas', 'denegar');
    await page.waitForTimeout(ESPERA_REFRESCO_MS);

    expect(await puedeEntrar(page, 'views/activity_logs.php'), 'concedido: ahora SÍ debería ver auditoría').toBe(true);
    expect(await puedeEntrar(page, 'views/sales.php'), 'denegado: ya NO debería poder vender (deny gana al rol)').toBe(false);
    // El endpoint de ventas también se cierra, no solo la pantalla.
    const api = await page.request.get('api/ventas.php', { failOnStatusCode: false, maxRedirects: 0 });
    expect(api.status() === 403 || /no autorizado|no tienes permiso/i.test(await api.text()), 'api/ventas.php debería negar').toBe(true);

    // Se revierte: vuelve a su estado original.
    fijarOverride(EMAIL, 'ver_auditoria', 'quitar');
    fijarOverride(EMAIL, 'realizar_ventas', 'quitar');
    await page.waitForTimeout(ESPERA_REFRESCO_MS);

    expect(await puedeEntrar(page, 'views/activity_logs.php'), 'quitado: ya NO debería ver auditoría').toBe(false);
    expect(await puedeEntrar(page, 'views/sales.php'), 'quitado el deny: debería volver a vender').toBe(true);
  });
});
