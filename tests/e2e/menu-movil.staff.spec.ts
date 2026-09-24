import { test, expect } from './fixtures';
import { loginAsStaff } from './helpers';

// Menú lateral móvil (#mobile-nav, commit 9813bd3): Materialize fija cada enlace a 48 px, así que un texto largo como
// "Notificaciones de caducidades" se partía en dos líneas y la segunda se encimaba sobre el siguiente enlace ("Salud del sistema").
// Ahora el enlace crece con su contenido (los de una línea siguen midiendo 48 px). Solo afecta al menú móvil.

test.use({ viewport: { width: 375, height: 667 } });

async function abrirMenu(page: import('@playwright/test').Page): Promise<void> {
  await page.goto('views/dashboard.php');
  await page.locator('a.sidenav-trigger[data-target="mobile-nav"]').first().click();
  await expect(page.locator('#mobile-nav')).toBeVisible();
  await expect(page.locator('#mobile-nav li > a').first()).toBeVisible();
}

test.describe('Menú lateral móvil', () => {
  test('ningún enlace se encima sobre el siguiente (con un admin, que tiene los textos más largos)', async ({ page }) => {
    await loginAsStaff(page, 'admin');
    await abrirMenu(page);

    const cajas = await page.locator('#mobile-nav li > a:not(.subheader)').evaluateAll((els) =>
      els
        .filter((e) => (e as HTMLElement).offsetParent !== null)
        .map((e) => {
          const r = e.getBoundingClientRect();
          return { texto: (e.textContent || '').replace(/\s+/g, ' ').trim().slice(0, 40), top: r.top, bottom: r.bottom, alto: r.height };
        })
    );
    expect(cajas.length).toBeGreaterThan(8);

    const encimados: string[] = [];
    for (let i = 1; i < cajas.length; i++) {
      // Tolerancia de 1 px por redondeo; si el anterior invade al siguiente, se encima.
      if (cajas[i].top < cajas[i - 1].bottom - 1) encimados.push(`"${cajas[i - 1].texto}" se encima con "${cajas[i].texto}"`);
    }
    expect(encimados, encimados.join('\n')).toEqual([]);
    // Ninguno queda más bajo que el mínimo de Materialize (48 px).
    for (const c of cajas) expect(c.alto, `"${c.texto}" mide ${c.alto}px`).toBeGreaterThanOrEqual(47.5);
  });

  test('un enlace largo crece a más de una línea en vez de invadir al de abajo', async ({ page }) => {
    await loginAsStaff(page, 'admin');
    await abrirMenu(page);
    const largo = page.locator('#mobile-nav li > a', { hasText: 'Notificaciones de caducidades' }).first();
    test.skip((await largo.count()) === 0, 'Este menú no trae "Notificaciones de caducidades".');

    const alto = (await largo.boundingBox())!.height;
    // Con el ancho de un celular el texto se parte: el enlace debe medir más de 48 px (y seguir dentro de su fila).
    expect(alto).toBeGreaterThan(48);
    const fila = (await largo.locator('xpath=..').boundingBox())!;
    const enlace = (await largo.boundingBox())!;
    expect(enlace.y + enlace.height).toBeLessThanOrEqual(fila.y + fila.height + 1);
  });
});
