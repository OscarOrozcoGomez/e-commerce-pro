import type { Page } from '@playwright/test';
import { test, expect } from './fixtures';
import { loginAsStaff } from './helpers';
import { consultaRegla, prepararWhatsapp, type WhatsappDB } from './db-utils';

// WhatsApp (views/whatsapp_seguimientos.php y views/whatsapp_contactos.php). SIN Alex ni modelo: solo pantallas sobre
// conversaciones SEMBRADAS (scripts/e2e_preparar_whatsapp.php, filas "Playwright WA ..."); no se manda nada por WhatsApp.
//   - Lista mensual de seguimientos de 24h (commit e561013): por defecto solo los SIN respuesta, con etiquetas y enlace
//     para abrir el chat; solo lectura.
//   - "Dar feedback" a Alex por mensaje (commits d6bfb77 y 7f91778): convierte una respuesta de Alex en regla de aprendizaje;
//     exige el permiso propio dar_feedback_asistente_ia (o gestionar el asistente).
// OJO: esta BD local trae conversaciones REALES de clientes. Las pruebas solo buscan las filas "Playwright WA ..." y NUNCA
// asumen contadores exactos ni imprimen nombres ajenos.

let datos: WhatsappDB;

// Todo el archivo en SERIE (un solo trabajador): beforeAll corre una vez POR TRABAJADOR y recrea las conversaciones sembradas,
// así que con dos trabajadores uno las borraría mientras el otro las usa.
test.describe.configure({ mode: 'serial' });

test.beforeAll(() => {
  datos = prepararWhatsapp();
});

const fila = (page: Page, nombre: string) => page.locator('.collection-item').filter({ hasText: nombre });
const numeroDeTarjeta = async (page: Page, indice: number) => Number((await page.locator('.card-panel > div').nth(indice * 2).innerText()).trim());

test.describe('Seguimientos del mes (whatsapp_seguimientos.php)', () => {
  test.describe.configure({ timeout: 90_000 });

  test('desde Contactos, el botón "Seguimientos del mes" lleva a la lista', async ({ page }) => {
    await loginAsStaff(page, 'admin');
    await page.goto('views/whatsapp_contactos.php');
    await page.getByRole('link', { name: 'Seguimientos del mes' }).click();
    await expect(page).toHaveURL(/views\/whatsapp_seguimientos\.php$/);
    await expect(page.getByRole('heading', { name: /Seguimientos del mes/ })).toBeVisible();
  });

  test('por defecto muestra solo los que NO respondieron: con etiqueta, y los que no salieron o no tienen número marcados', async ({ page }) => {
    await loginAsStaff(page, 'admin');
    await page.goto(`views/whatsapp_seguimientos.php?mes=${datos.mes}`);

    const sinRespuesta = fila(page, 'Playwright WA Sin Respuesta');
    await expect(sinRespuesta).toHaveCount(1);
    await expect(sinRespuesta).toContainText('Sin respuesta');
    await expect(sinRespuesta).toContainText('Playwright Pregunton'); // la etiqueta actual del contacto
    await expect(sinRespuesta).toContainText('Seguimiento:');

    await expect(fila(page, 'Playwright WA No Salio')).toContainText('No salió por WhatsApp');
    // Contacto sin número real (LID): aparece, pero sin el icono para abrir el chat.
    const sinNumero = fila(page, 'Playwright WA Sin Numero');
    await expect(sinNumero).toHaveCount(1);
    await expect(sinNumero.locator('a.whatsapp-business-link')).toHaveCount(0);

    // Los que respondieron y las conversaciones normales NO son "seguimientos sin respuesta".
    await expect(fila(page, 'Playwright WA Respondio')).toHaveCount(0);
    await expect(fila(page, 'Playwright WA Normal')).toHaveCount(0);
    await expect(fila(page, 'Playwright WA Dos Mensajes')).toHaveCount(0);
  });

  test('"Incluir los que sí respondieron" suma al que respondió, marcado como tal, sin traer conversaciones normales', async ({ page }) => {
    await loginAsStaff(page, 'admin');
    await page.goto(`views/whatsapp_seguimientos.php?mes=${datos.mes}`);
    await page.locator('input[name="todos"] + span').click();
    await page.getByRole('button', { name: 'Ver' }).click();

    await expect(page).toHaveURL(/todos=1/);
    await expect(page.locator('input[name="todos"]')).toBeChecked();
    await expect(fila(page, 'Playwright WA Respondio')).toContainText('Respondió');
    await expect(fila(page, 'Playwright WA Sin Respuesta')).toHaveCount(1);
    await expect(fila(page, 'Playwright WA Normal')).toHaveCount(0);
    await expect(fila(page, 'Playwright WA Dos Mensajes')).toHaveCount(0);
  });

  test('los contadores cuadran: enviados = sin respuesta + respondieron, y ninguno es negativo', async ({ page }) => {
    await loginAsStaff(page, 'admin');
    await page.goto(`views/whatsapp_seguimientos.php?mes=${datos.mes}`);
    const enviados = await numeroDeTarjeta(page, 0);
    const sinRespuesta = await numeroDeTarjeta(page, 1);
    const respondieron = await numeroDeTarjeta(page, 2);
    const noSalieron = await numeroDeTarjeta(page, 3);

    expect(enviados).toBe(sinRespuesta + respondieron);
    // Las sembradas aportan: 5 seguimientos (A, B, C, G... los de "Playwright WA") => al menos esos.
    expect(sinRespuesta).toBeGreaterThanOrEqual(3); // A, C y G
    expect(respondieron).toBeGreaterThanOrEqual(1); // B
    expect(noSalieron).toBeGreaterThanOrEqual(1); // C
    expect(noSalieron).toBeLessThanOrEqual(enviados);
  });

  test('un mes sin seguimientos dice "Sin seguimientos para revisar" y un mes inválido cae al mes actual sin error', async ({ page }) => {
    await loginAsStaff(page, 'admin');
    await page.goto('views/whatsapp_seguimientos.php?mes=2020-01');
    await expect(page.getByText('Sin seguimientos para revisar en este mes.')).toBeVisible();
    expect(await numeroDeTarjeta(page, 0)).toBe(0);

    const errores: string[] = [];
    page.on('pageerror', (e) => errores.push(e.message));
    await page.goto('views/whatsapp_seguimientos.php?mes=basura');
    await expect(page.getByRole('heading', { name: /Seguimientos del mes/ })).toBeVisible();
    await expect(page.locator('input#mes')).toHaveValue(datos.mes);
    expect(errores).toEqual([]);
  });

  test('el nombre abre la conversación y el icono verde abre el chat de WhatsApp con el número', async ({ page }) => {
    await loginAsStaff(page, 'admin');
    await page.goto(`views/whatsapp_seguimientos.php?mes=${datos.mes}`);
    const contacto = fila(page, 'Playwright WA Sin Respuesta');

    const enlaceWa = contacto.locator('a.whatsapp-business-link');
    await expect(enlaceWa).toHaveAttribute('href', /^https:\/\/wa\.me\/\d{10,13}$/);
    await expect(enlaceWa).toHaveAttribute('data-wa-phone', /^\d{10,13}$/);

    await contacto.getByRole('link', { name: 'Playwright WA Sin Respuesta' }).click();
    await expect(page).toHaveURL(new RegExp(`whatsapp_contactos\\.php\\?id=${datos.sin_respuesta}$`));
    await expect(page.getByText('Hola de nuevo, ¿pudiste revisar la informacion?')).toBeVisible();
  });

  test('es de solo lectura: la pantalla no tiene formularios de escritura ni manda ninguna petición POST', async ({ page }) => {
    const posts: string[] = [];
    page.on('request', (r) => {
      if (r.method() === 'POST') posts.push(r.url());
    });
    await loginAsStaff(page, 'admin');
    posts.length = 0; // el login sí es un POST
    await page.goto(`views/whatsapp_seguimientos.php?mes=${datos.mes}`);
    await expect(fila(page, 'Playwright WA Sin Respuesta')).toHaveCount(1);

    await expect(page.locator('form[method="post" i]')).toHaveCount(0);
    await expect(page.locator('form')).toHaveCount(1); // solo el filtro (GET) de mes
    // El ping de analítica de la página (log_activity.php) no es una escritura de negocio.
    expect(posts.filter((u) => !u.includes('api/log_activity.php'))).toEqual([]);
  });

  test('un vendedor (sin ver_conversaciones_whatsapp) es enviado al dashboard; un lector de WhatsApp sí entra', async ({ page }) => {
    await loginAsStaff(page, 'vendedor');
    await page.goto('views/whatsapp_seguimientos.php');
    await expect(page).toHaveURL(/views\/dashboard\.php/);

    await loginAsStaff(page, 'whatsappLector');
    await page.goto(`views/whatsapp_seguimientos.php?mes=${datos.mes}`);
    await expect(page).toHaveURL(/whatsapp_seguimientos\.php/);
    await expect(fila(page, 'Playwright WA Sin Respuesta')).toHaveCount(1);
  });
});

test.describe('"Dar feedback" a Alex en una conversación (whatsapp_contactos.php)', () => {
  test.describe.configure({ timeout: 90_000 });

  const abrirConversacion = async (page: Page, cual: Exclude<keyof WhatsappDB, 'mes'>) => {
    await page.goto(`views/whatsapp_contactos.php?id=${datos[cual]}`);
    await expect(page.getByText('Hola, quiero informes').first()).toBeVisible();
  };

  test('con el permiso, cada mensaje de Alex (y solo esos) trae el botón "Dar feedback"', async ({ page }) => {
    await loginAsStaff(page, 'whatsappFeedback');
    await abrirConversacion(page, 'sin_respuesta');
    // La conversación A tiene 2 mensajes de Alex y 1 del cliente.
    await expect(page.locator('.btn-feedback-alex')).toHaveCount(2);
  });

  test('sin el permiso (solo lectura) la conversación se ve pero NO hay botón ni modal de feedback', async ({ page }) => {
    await loginAsStaff(page, 'whatsappLector');
    await abrirConversacion(page, 'sin_respuesta');
    await expect(page.locator('.btn-feedback-alex')).toHaveCount(0);
    await expect(page.locator('#modal-feedback-alex')).toHaveCount(0);
  });

  test('el botón abre el modal ya con la pregunta del cliente y la respuesta de Alex; "Cancelar" lo cierra', async ({ page }) => {
    await loginAsStaff(page, 'whatsappFeedback');
    await abrirConversacion(page, 'sin_respuesta');

    await page.locator('.btn-feedback-alex').first().click();
    const modal = page.locator('#modal-feedback-alex');
    await expect(modal).toBeVisible();
    await expect(page.locator('#fb-contexto')).toHaveValue('Hola, quiero informes');
    await expect(page.locator('#fb-respuesta-actual')).toHaveValue('Claro, con gusto te ayudo.');
    await expect(page.locator('#fb-respuesta-actual')).toHaveAttribute('readonly', '');
    await expect(page.locator('#fb-respuesta-esperada')).toHaveValue('');

    // Commit 7f91778: el botón Cancelar estaba roto.
    await page.locator('#btn-cancelar-feedback-alex').click();
    await expect(modal).toBeHidden();
    // Y se puede volver a abrir sobre OTRO mensaje de Alex con sus propios datos.
    await page.locator('.btn-feedback-alex').nth(1).click();
    await expect(page.locator('#fb-respuesta-actual')).toHaveValue('Hola de nuevo, ¿pudiste revisar la informacion?');
  });

  test('guardar sin la respuesta esperada avisa y NO crea la regla', async ({ page }) => {
    await loginAsStaff(page, 'whatsappFeedback');
    await abrirConversacion(page, 'sin_respuesta');
    const avisos: string[] = [];
    page.on('dialog', (d) => {
      avisos.push(d.message());
      void d.accept();
    });
    let llamoApi = false;
    await page.route('**/api/ai_assistant_admin.php', (route) => {
      llamoApi = true;
      void route.continue();
    });

    await page.locator('.btn-feedback-alex').first().click();
    await page.locator('#btn-guardar-feedback-alex').click();
    await expect.poll(() => avisos.length).toBe(1);
    expect(avisos[0]).toBe('Completa la situacion y como debio responder Alex.');
    expect(llamoApi).toBe(false);
  });

  test('guardar con datos crea la regla de aprendizaje (activa) y avisa; el modal se cierra', async ({ page }) => {
    await loginAsStaff(page, 'whatsappFeedback');
    await abrirConversacion(page, 'sin_respuesta');
    const marca = Date.now();

    await page.locator('.btn-feedback-alex').first().click();
    await page.locator('#fb-contexto').fill(`Playwright feedback ${marca}: cliente pide informes`);
    await page.locator('#fb-respuesta-esperada').fill('Pedir el nombre y ofrecer el catálogo antes de dar precios.');
    await page.locator('#btn-guardar-feedback-alex').click();

    await expect(page.locator('.toast', { hasText: 'Regla guardada. Se le mostrara a Alex como ejemplo.' })).toBeVisible();
    await expect(page.locator('#modal-feedback-alex')).toBeHidden();

    const regla = consultaRegla(`Playwright feedback ${marca}`);
    expect(regla, 'la regla debe quedar guardada en la BD').not.toBeNull();
    expect(regla!.ultima.respuesta_o_accion_esperada).toBe('Pedir el nombre y ofrecer el catálogo antes de dar precios.');
    expect(Number(regla!.ultima.activa)).toBe(1);
  });

  test('API: quien solo da feedback puede crear reglas pero no administrar el asistente; sin permiso ni una regla; sin token, tampoco', async ({ page }) => {
    const token = async () => (await page.content()).match(/var csrfToken = "([^"]+)"/)?.[1] ?? '';
    const post = (data: Record<string, unknown>) =>
      page.request.post('api/ai_assistant_admin.php', { data, failOnStatusCode: false });
    const regla = (csrf: string, extra: Record<string, unknown> = {}) => ({
      action: 'create_learning_rule',
      contexto_o_pregunta: 'Playwright API regla',
      respuesta_o_accion_esperada: 'Respuesta de la API',
      csrf_token: csrf,
      ...extra,
    });

    // Con feedback: crea la regla...
    await loginAsStaff(page, 'whatsappFeedback');
    await abrirConversacion(page, 'sin_respuesta');
    const csrf = await token();
    expect(csrf.length).toBeGreaterThan(10);
    const creada = await post(regla(csrf));
    expect(creada.status()).toBe(200);
    expect((await creada.json()).success).toBe(true);
    // ...pero NO puede activar/desactivar reglas (eso sigue exigiendo gestionar_asistente_ia).
    expect((await post({ action: 'toggle_learning_rule', id_regla: 1, activa: false, csrf_token: csrf })).status()).toBe(403);
    // Sin token CSRF: rechazado (la app responde 419; este Apache local lo vuelve 500, así que se comprueba por el mensaje).
    const sinToken = await post(regla(''));
    expect([419, 500]).toContain(sinToken.status());
    expect(await sinToken.text()).toContain('Token de seguridad invalido');
    // Datos incompletos: 422; etiqueta demasiado larga: 422.
    expect((await post(regla(csrf, { respuesta_o_accion_esperada: '' }))).status()).toBe(422);
    expect((await post(regla(csrf, { etiqueta_sugerida: 'x'.repeat(61) }))).status()).toBe(422);

    // Lector (sin feedback): ni siquiera crear una regla.
    await loginAsStaff(page, 'whatsappLector');
    await abrirConversacion(page, 'sin_respuesta');
    expect((await post(regla(await token()))).status()).toBe(403);
  });
});
