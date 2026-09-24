import type { Page } from '@playwright/test';
import { test, expect } from './fixtures';
import { loginAsStaff } from './helpers';

// views/analytics.php: tabla "Qué hacer" (Comprar / Aparador / Mover) con reglas explicables, banner de
// productos sin precio o costo, y tope de 15 en "Mover" (commits 27469f7 y 472b265). Las REGLAS de clasificación
// ya las cubre InventoryRecommendationsTest (PHPUnit); aquí se prueba lo que ve la persona: que la pantalla
// pinte bien lo que manda el API (con respuestas simuladas, deterministas) y que el API real cumpla su contrato
// y deje fuera los productos de prueba.

interface Fila {
  id_producto: number;
  nombre: string;
  etiqueta: string;
  motivo: string;
  stock: number;
  v90: number;
  visitantes30: number;
  cobertura_dias: number | null;
  margen_pct: number | null;
  capital: number;
  falta_config: string | null;
}

function fila(numero: number, sobrescribir: Partial<Fila> = {}): Fila {
  return {
    id_producto: 9000 + numero,
    nombre: `Producto Simulado ${numero}`,
    etiqueta: 'Se acaba pronto',
    motivo: `Motivo simulado ${numero}`,
    stock: 3,
    v90: 6,
    visitantes30: 4,
    cobertura_dias: 12,
    margen_pct: 35.5,
    capital: 150,
    falta_config: null,
    ...sobrescribir,
  };
}

interface Maqueta {
  comprar?: Fila[];
  aparador?: Fila[];
  mover?: Fila[];
  sinConfiguracion?: Array<{ id_producto: number; nombre: string; falta: string; stock: number; total_vendido: number }>;
  resumen?: Partial<Record<'comprar' | 'aparador' | 'mover' | 'sin_accion' | 'sin_configuracion' | 'capital_parado', number>>;
}

async function simularApi(page: Page, m: Maqueta): Promise<void> {
  const comprar = m.comprar ?? [];
  const aparador = m.aparador ?? [];
  const mover = m.mover ?? [];
  const sinConfiguracion = m.sinConfiguracion ?? [];
  await page.route('**/api/analytics_data.php', (route) =>
    route.fulfill({
      json: {
        success: true,
        ventas_mensuales: [0, 0, 0, 0, 0, 0, 0, 0, 1200, 800, 0, 0],
        top_productos: [{ id_producto: 1, nombre: 'Top Simulado', cantidad: 7 }],
        recomendaciones: {
          resumen: {
            comprar: comprar.length,
            aparador: aparador.length,
            mover: mover.length,
            sin_accion: 0,
            sin_configuracion: sinConfiguracion.length,
            capital_parado: 0,
            ...m.resumen,
          },
          comprar,
          aparador,
          mover,
          sin_configuracion: sinConfiguracion,
          contexto: {
            pedidos_reales: 42,
            piezas_vendidas: 130,
            productos_vendidos: 25,
            dias_historial: 60,
            supuestos: {
              ventana_dias: 90,
              dias_entrega_proveedor: 14,
              dias_colchon: 7,
              dias_sin_venta_mover: 90,
              dias_caducidad_mover: 60,
              margen_alto_pct: 40,
              min_visitantes_interes: 5,
              limite_mover: 15,
            },
          },
        },
      },
    })
  );
}

async function abrirAnalitica(page: Page): Promise<void> {
  await loginAsStaff(page, 'admin');
  await page.goto('views/analytics.php');
  await expect(page.locator('#analytics-app')).toBeVisible();
  await expect(page.locator('#loader-analytics')).toBeHidden();
}

test.describe('Analítica: qué comprar, poner en aparador y mover (analytics.php)', () => {
  test('el resumen y los contadores de las pestañas muestran los totales reales, aunque la lista traiga menos', async ({ page }) => {
    await simularApi(page, {
      comprar: [fila(1, { etiqueta: 'Agotado' }), fila(2)],
      aparador: [fila(3, { etiqueta: 'Se vende' })],
      mover: [fila(4, { etiqueta: 'Por caducar', capital: 480 })],
      // El resumen cuenta TODOS (ej. 30 por comprar) aunque la lista solo traiga los más prioritarios.
      resumen: { comprar: 30, aparador: 8, mover: 22, capital_parado: 12345 },
    });
    await abrirAnalitica(page);

    await expect(page.locator('#kpi-comprar')).toHaveText('30');
    await expect(page.locator('#kpi-aparador')).toHaveText('8');
    await expect(page.locator('#kpi-mover')).toHaveText('22');
    await expect(page.locator('#kpi-capital')).toHaveText('$12,345');
    await expect(page.locator('#badge-comprar')).toHaveText('30');
    await expect(page.locator('#badge-aparador')).toHaveText('8');
    await expect(page.locator('#badge-mover')).toHaveText('22');
    // Cuánta evidencia hay detrás: no se vende como pronóstico.
    await expect(page.locator('#evidencia-texto')).toContainText('42 pedidos reales, 130 piezas de 25 productos');
  });

  test('Comprar es la pestaña inicial; Aparador y Mover cambian de panel y cada fila trae su sugerencia y su motivo', async ({ page }) => {
    await simularApi(page, {
      comprar: [fila(1, { etiqueta: 'Agotado', motivo: 'Se vendió y ya no hay', cobertura_dias: null })],
      aparador: [fila(2, { etiqueta: 'Se vende', motivo: 'Se vendieron 5 piezas', margen_pct: 52.3 })],
      mover: [fila(3, { etiqueta: 'Estancado', motivo: 'Sin ventas en 120 días', capital: 999 })],
    });
    await abrirAnalitica(page);

    await expect(page.locator('.rec-tab.is-active')).toContainText('Comprar');
    await expect(page.locator('#panel-comprar')).toBeVisible();
    await expect(page.locator('#panel-aparador')).toBeHidden();

    const filaComprar = page.locator('#body-comprar tr').first();
    await expect(filaComprar.locator('.rec-nombre')).toContainText('Producto Simulado 1');
    await expect(filaComprar.locator('.rec-chip')).toHaveText('Agotado');
    await expect(filaComprar.locator('.rec-motivo')).toHaveText('Se vendió y ya no hay');
    // "Alcanza (días)" sin dato se muestra como raya, no como "null".
    await expect(filaComprar.locator('td[data-label="Alcanza (días)"]')).toHaveText('—');
    await expect(filaComprar.getByTitle('Abrir en productos')).toHaveAttribute('href', /9001$/);

    await page.locator('.rec-tab', { hasText: 'Aparador' }).click();
    await expect(page.locator('.rec-tab.is-active')).toContainText('Aparador');
    await expect(page.locator('#panel-aparador')).toBeVisible();
    await expect(page.locator('#panel-comprar')).toBeHidden();
    await expect(page.locator('#body-aparador tr').first().locator('td[data-label="Margen"]')).toHaveText('52.3%');

    await page.locator('.rec-tab', { hasText: 'Mover' }).click();
    await expect(page.locator('#panel-mover')).toBeVisible();
    await expect(page.locator('#body-mover tr').first().locator('td[data-label="Capital parado"]')).toHaveText('$999');
    await expect(page.locator('#body-mover tr').first().locator('.rec-chip')).toHaveText('Estancado');
  });

  test('"Mover" muestra como máximo 15 y avisa cuántos hay en total', async ({ page }) => {
    const quince = Array.from({ length: 15 }, (_, i) => fila(i + 1, { etiqueta: 'Estancado' }));
    await simularApi(page, { mover: quince, resumen: { mover: 22 } });
    await abrirAnalitica(page);

    await page.locator('.rec-tab', { hasText: 'Mover' }).click();
    await expect(page.locator('#body-mover tr:not(.rec-vacio)')).toHaveCount(15);
    await expect(page.locator('#body-mover .rec-vacio')).toContainText('Se muestran los 15 más prioritarios de 22.');
  });

  test('productos sin precio de venta o costo: banner plegable con la lista y aviso "Falta configurar" en su fila', async ({ page }) => {
    await simularApi(page, {
      comprar: [fila(1, { falta_config: 'costo' })],
      sinConfiguracion: [
        { id_producto: 501, nombre: 'Crema Sin Costo', falta: 'costo', stock: 4, total_vendido: 9 },
        { id_producto: 502, nombre: 'Jabón Sin Precio', falta: 'precio de venta', stock: 0, total_vendido: 0 },
      ],
      resumen: { sin_configuracion: 35 },
    });
    await abrirAnalitica(page);

    const banner = page.locator('#config-row');
    await expect(banner).toBeVisible();
    await expect(page.locator('#config-resumen')).toContainText('35 productos activos sin precio de venta o costo');
    // Plegable: cerrado de inicio, se abre al tocar el resumen.
    await expect(page.locator('#config-lista')).toBeHidden();
    await page.locator('#config-detalle summary').click();
    await expect(page.locator('#config-lista')).toBeVisible();
    await expect(page.locator('#config-lista li').first()).toContainText('Crema Sin Costo');
    await expect(page.locator('#config-lista li').first()).toContainText('falta costo; ya se ha vendido; 4 pza en stock');
    await expect(page.locator('#config-lista li').first().getByRole('link')).toHaveAttribute('href', /501$/);
    await expect(page.locator('#config-lista li').nth(1)).toContainText('falta precio de venta');
    await expect(page.locator('#config-lista li').nth(1)).not.toContainText('ya se ha vendido');
    // Se muestran 2 de 35: avisa que hay más.
    await expect(page.locator('#config-lista li').last()).toContainText('Y 33 más.');

    // Y la fila afectada lo avisa también donde se decide.
    await expect(page.locator('#body-comprar tr').first()).toContainText('Falta configurar costo');
  });

  test('sin nada que hacer: cada lista dice "Nada por aquí por ahora." y no aparece el banner de configuración', async ({ page }) => {
    await simularApi(page, {});
    await abrirAnalitica(page);

    for (const clave of ['comprar', 'aparador', 'mover']) {
      await page.locator('.rec-tab', { hasText: new RegExp(clave, 'i') }).click();
      await expect(page.locator(`#body-${clave} .rec-vacio`)).toContainText('Nada por aquí por ahora.');
    }
    await expect(page.locator('#config-row')).toBeHidden();
    await expect(page.locator('#kpi-capital')).toHaveText('$0');
  });

  test('las reglas y supuestos usados se muestran con los valores reales del servidor', async ({ page }) => {
    await simularApi(page, {});
    await abrirAnalitica(page);

    const reglas = page.locator('#reglas-texto');
    await expect(reglas).toContainText('No es un pronóstico');
    await expect(reglas).toContainText('~21 días'); // 14 de entrega + 7 de colchón
    await expect(reglas).toContainText('Supuestos a validar');
  });

  test('un nombre de producto con HTML se muestra como texto, no como código', async ({ page }) => {
    let dialogo = false;
    page.on('dialog', (d) => {
      dialogo = true;
      void d.dismiss();
    });
    await simularApi(page, { comprar: [fila(1, { nombre: '<img src=x onerror=alert(1)>Crema', motivo: '<script>alert(2)</script>' })] });
    await abrirAnalitica(page);

    await expect(page.locator('#body-comprar tr').first().locator('.rec-nombre')).toContainText('<img src=x onerror=alert(1)>Crema');
    await expect(page.locator('#body-comprar img')).toHaveCount(0);
    expect(dialogo).toBe(false);
  });

  test('si el servidor responde con error, la pantalla lo dice y ofrece Reintentar', async ({ page }) => {
    await page.route('**/api/analytics_data.php', (route) => route.fulfill({ json: { success: false, message: 'Falla simulada del servidor' } }));
    await loginAsStaff(page, 'admin');
    await page.goto('views/analytics.php');

    await expect(page.locator('#loader-analytics')).toContainText('Falla simulada del servidor');
    await expect(page.getByRole('button', { name: 'Reintentar' })).toBeVisible();
    await expect(page.locator('#analytics-app')).toBeHidden();
  });

  test('si el servidor responde algo que no es JSON, el mensaje no es un error técnico críptico', async ({ page }) => {
    await page.route('**/api/analytics_data.php', (route) => route.fulfill({ status: 502, contentType: 'text/html', body: '<html>502 Bad Gateway</html>' }));
    await loginAsStaff(page, 'admin');
    await page.goto('views/analytics.php');

    await expect(page.locator('#loader-analytics')).toContainText('Respuesta no válida del servidor (HTTP 502)');
    await expect(page.getByRole('button', { name: 'Reintentar' })).toBeVisible();
  });

  // DATOS REALES (sin simular): el contrato del API y la exclusión de productos de prueba.
  test('con datos reales: el API cumple su contrato y deja fuera los productos de prueba (Playwright/E2E)', async ({ page }) => {
    await loginAsStaff(page, 'admin');
    const res = await page.request.get('api/analytics_data.php');
    expect(res.status()).toBe(200);
    const cuerpo = await res.json();

    expect(cuerpo.success).toBe(true);
    expect(cuerpo.ventas_mensuales).toHaveLength(12);
    const rec = cuerpo.recomendaciones;
    for (const clave of ['comprar', 'aparador', 'mover', 'sin_configuracion']) {
      expect(Array.isArray(rec[clave]), `recomendaciones.${clave} debe ser lista`).toBe(true);
    }
    for (const clave of ['comprar', 'aparador', 'mover', 'sin_accion', 'sin_configuracion', 'capital_parado']) {
      expect(typeof rec.resumen[clave], `resumen.${clave} debe ser número`).toBe('number');
    }
    // Tope de "Mover" y coherencia: la lista nunca trae más que el total que cuenta el resumen.
    expect(rec.mover.length).toBeLessThanOrEqual(15);
    expect(rec.mover.length).toBeLessThanOrEqual(rec.resumen.mover);
    expect(rec.comprar.length).toBeLessThanOrEqual(rec.resumen.comprar);
    expect(rec.contexto.supuestos.limite_mover).toBe(15);

    // Los productos de las pruebas automatizadas (sembrados por seed_e2e_test_data.php, con stock y sin precio, que
    // de otro modo llenarían las listas y el banner de "sin configurar") NO deben aparecer en ninguna.
    const nombres = [
      ...rec.comprar,
      ...rec.aparador,
      ...rec.mover,
      ...rec.sin_configuracion,
      ...cuerpo.top_productos,
    ].map((p: { nombre: string }) => p.nombre);
    const deLaPrueba = nombres.filter((n) => /playwright|e2e/i.test(n));
    expect(deLaPrueba, `productos de prueba que se filtraron: ${deLaPrueba.join(', ')}`).toEqual([]);
  });

  test('con datos reales: la pantalla carga sin errores y sus contadores coinciden con el API', async ({ page }) => {
    await loginAsStaff(page, 'admin');
    const errores: string[] = [];
    page.on('pageerror', (e) => errores.push(e.message));
    const respuesta = page.waitForResponse('**/api/analytics_data.php');
    await page.goto('views/analytics.php');
    const cuerpo = await (await respuesta).json();

    await expect(page.locator('#analytics-app')).toBeVisible();
    await expect(page.locator('#kpi-comprar')).toHaveText(String(cuerpo.recomendaciones.resumen.comprar));
    await expect(page.locator('#kpi-mover')).toHaveText(String(cuerpo.recomendaciones.resumen.mover));
    await expect(page.locator('#chartVentas')).toBeVisible();
    await expect(page.locator('#chartTop')).toBeVisible();
    expect(errores).toEqual([]);
  });
});
