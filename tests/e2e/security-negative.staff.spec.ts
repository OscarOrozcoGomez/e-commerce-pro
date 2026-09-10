import { test, expect } from './fixtures';
import { loginAsStaff } from './helpers';

// Pruebas NEGATIVAS de seguridad/robustez para vistas revisadas en esta sesión:
//  1. chat.php  — un nombre de cliente con payload XSS se renderiza como texto (escapado),
//                 nunca ejecuta script en el navegador del personal.
//  2. chat_handler.php — una acción que muta estado sin token CSRF es rechazada;
//                 con el token de la página sí funciona.
//  3. analytics.php — si api/analytics_data.php responde HTML/500, la vista muestra
//                 un estado de error con "Reintentar", no un spinner infinito.

test.describe('Seguridad — negativas (chat.php / analytics.php)', () => {
  test('chat.php: nombre de cliente con XSS se muestra escapado y no ejecuta script', async ({ page }) => {
    const XSS = '<img src=x onerror="window.__xssChat=1">';

    // Stub de todas las llamadas GET de chat_handler para un test hermético.
    await page.route('**/api/chat_handler.php**', (route) => {
      const url = new URL(route.request().url());
      const action = url.searchParams.get('action');
      if (action === 'fetch_clients') {
        return route.fulfill({
          json: {
            success: true,
            clientes: [
              { id_usuario: 999001, nombre: XSS, asignado_a: null, pendientes: 0, alertas_sistema: 0 },
            ],
          },
        });
      }
      if (action === 'get_staff') return route.fulfill({ json: { success: true, staff: [] } });
      if (action === 'chat_products') return route.fulfill({ json: { success: true, products: [] } });
      if (action === 'fetch_quick') return route.fulfill({ json: { success: true, responses: [] } });
      if (action === 'staff_alerts_summary') {
        return route.fulfill({ json: { success: true, unread_total: 0, unassigned_unread: 0 } });
      }
      return route.fulfill({ json: { success: true } });
    });

    await loginAsStaff(page, 'encargado');
    await page.goto('views/chat.php');

    const item = page.locator('#conversations-list .chat-user-name');
    await expect(item).toBeVisible();
    // El payload aparece como TEXTO literal (fue escapado), no como <img> en el DOM.
    await expect(item).toHaveText(XSS);
    await expect(page.locator('#conversations-list img')).toHaveCount(0);

    // El onerror nunca se disparó.
    await page.waitForTimeout(500);
    expect(await page.evaluate(() => (window as any).__xssChat)).toBeFalsy();
  });

  test('chat_handler.php: save_quick sin token CSRF se rechaza; con token funciona', async ({ page }) => {
    await loginAsStaff(page, 'encargado');
    await page.goto('views/chat.php');

    // Sin token -> rechazado (pero siempre JSON, nunca HTML).
    const sinToken = await page.request.post('api/chat_handler.php?action=save_quick', {
      data: { titulo: 'PW sin token', mensaje: 'no deberia guardarse' },
    });
    expect(sinToken.status()).toBe(200);
    const sinTokenBody = await sinToken.json();
    expect(sinTokenBody.success).toBe(false);
    expect(String(sinTokenBody.message ?? '')).toMatch(/token/i);

    // Con el token que la página expone a su propio JS.
    const token = await page.evaluate(() => (window as any).CHAT_CSRF ?? (typeof CHAT_CSRF !== 'undefined' ? CHAT_CSRF : ''));
    expect(token).toBeTruthy();

    const titulo = `PW neg ${Date.now()}`;
    const conToken = await page.request.post('api/chat_handler.php?action=save_quick', {
      headers: { 'X-CSRF-Token': String(token) },
      data: { titulo, mensaje: 'ok con token' },
    });
    expect((await conToken.json()).success).toBe(true);

    // Limpieza: borrar la respuesta rápida recién creada (delete_quick también exige token).
    const lista = await (await page.request.get('api/chat_handler.php?action=fetch_quick')).json();
    const creada = (lista.responses ?? []).find((r: any) => r.titulo === titulo);
    if (creada) {
      await page.request.get(
        `api/chat_handler.php?action=delete_quick&id_respuesta=${creada.id_respuesta}&csrf_token=${encodeURIComponent(String(token))}`
      );
    }
  });

  test('analytics.php: si la API responde HTML/500, muestra error con "Reintentar" y no spinner infinito', async ({ page }) => {
    await page.route('**/api/analytics_data.php', (route) =>
      route.fulfill({ status: 500, contentType: 'text/html', body: '<!DOCTYPE html><html><body>error 500</body></html>' })
    );

    await loginAsStaff(page, 'admin');
    await page.goto('views/analytics.php');

    await expect(page.getByRole('button', { name: 'Reintentar' })).toBeVisible();
    await expect(page.getByText('Analizando datos históricos...')).toHaveCount(0);
    // El contenido principal nunca se muestra porque nunca hubo éxito.
    await expect(page.locator('#analytics-app')).toBeHidden();
  });
});
