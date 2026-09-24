import type { Page } from '@playwright/test';
import { test, expect } from './fixtures';
import { loginAsStaff, E2E_STAFF_EMAILS } from './helpers';
import { DESCARGAS, VISTAS, leerPermisosBD, tienePermiso } from './permisos-datos';

// MENUS Y DASHBOARD: el PR #205 puso "enlaces y tarjetas de encargado/vendedor detras de su permiso". La matriz ya
// prueba que la pagina bloquea; esto prueba que el menu no le ofrezca a la cuenta un enlace a algo donde va a
// rebotar (un boton que "no hace nada" es, para quien lo usa, un bug).
//   - Direccion estricta: SIN permiso => NINGUN enlace visible en el dashboard ni en su menu.
//   - Y a la inversa, el dashboard de cada rol debe ofrecerle al menos un acceso a algo que SI puede usar.

const permisosBD = leerPermisosBD();

const CUENTAS: Array<{ id: keyof typeof E2E_STAFF_EMAILS; etiqueta: string }> = [
  { id: 'admin', etiqueta: 'admin' },
  { id: 'encargado', etiqueta: 'encargado' },
  { id: 'vendedor', etiqueta: 'vendedor' },
  { id: 'repartidor', etiqueta: 'repartidor' },
  { id: 'auditor', etiqueta: 'auditor' },
];

// Enlaces del dashboard + menu que apuntan a una vista de personal conocida.
async function enlacesAVistas(page: Page): Promise<Map<string, string[]>> {
  const hrefs = await page.$$eval('a[href]', (anchors) => anchors.map((a) => (a as HTMLAnchorElement).href));
  const porRuta = new Map<string, string[]>();
  for (const href of hrefs) {
    let ruta: string;
    try {
      ruta = new URL(href).pathname;
    } catch {
      continue;
    }
    for (const vista of [...VISTAS, ...DESCARGAS]) {
      if (ruta.endsWith(`/${vista.ruta}`)) {
        porRuta.set(vista.ruta, [...(porRuta.get(vista.ruta) ?? []), href]);
      }
    }
  }
  return porRuta;
}

test.describe('Menús y dashboard: solo ofrecen lo que la cuenta puede usar', () => {
  test.describe.configure({ timeout: 120_000 });

  for (const cuenta of CUENTAS) {
    test(`${cuenta.etiqueta}: el dashboard y el menú no enlazan a páginas que le están bloqueadas`, async ({ page }) => {
      const efectivos = permisosBD.cuentas[E2E_STAFF_EMAILS[cuenta.id]].efectivos;
      await loginAsStaff(page, cuenta.id);
      await page.goto('views/dashboard.php', { waitUntil: 'domcontentloaded' });
      await expect(page).toHaveURL(/dashboard\.php/);

      const enlaces = await enlacesAVistas(page);
      const enlacesInutiles: string[] = [];
      for (const vista of [...VISTAS, ...DESCARGAS]) {
        if (enlaces.has(vista.ruta) && !tienePermiso(efectivos, vista.anyOf)) {
          enlacesInutiles.push(`${vista.ruta} (necesita ${vista.anyOf.join(' | ')})`);
        }
      }

      test.info().annotations.push({ type: 'enlaces', description: `${enlaces.size} enlaces a vistas de personal en su dashboard/menú` });
      expect(enlacesInutiles, `enlaces que llevan a una página bloqueada:\n${enlacesInutiles.join('\n')}`).toEqual([]);
    });
  }

  test('cada rol ve en su dashboard al menos un acceso a algo que sí puede usar (y el repartidor, sus entregas)', async ({ page }) => {
    const sinAcceso: string[] = [];
    for (const cuenta of CUENTAS) {
      const efectivos = permisosBD.cuentas[E2E_STAFF_EMAILS[cuenta.id]].efectivos;
      await loginAsStaff(page, cuenta.id);
      await page.goto('views/dashboard.php', { waitUntil: 'domcontentloaded' });
      const enlaces = await enlacesAVistas(page);
      const utiles = [...enlaces.keys()].filter((ruta) => {
        const vista = [...VISTAS, ...DESCARGAS].find((v) => v.ruta === ruta);
        return vista !== undefined && tienePermiso(efectivos, vista.anyOf);
      });
      if (utiles.length === 0) sinAcceso.push(`${cuenta.etiqueta}: su dashboard/menú no ofrece ninguna página que pueda usar`);
      if (cuenta.id === 'repartidor' && !enlaces.has('views/entregas.php')) {
        sinAcceso.push('repartidor: no ve el enlace a Entregas (su permiso ver_entregas)');
      }
    }
    expect(sinAcceso, sinAcceso.join('\n')).toEqual([]);
  });
});
