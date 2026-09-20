import type { APIResponse, Page } from '@playwright/test';
import { test, expect } from './fixtures';
import { loginAsStaff, registerAndLogin, E2E_STAFF_EMAILS } from './helpers';
import {
  DESCARGAS,
  ENDPOINTS,
  VISTAS,
  leerPermisosBD,
  tienePermiso,
  type Endpoint,
  type Vista,
} from './permisos-datos';

// MATRIZ DE PERMISOS: por cada persona (cuenta real) y por cada pagina / endpoint / descarga de personal,
// se comprueba que la aplicacion se comporte EXACTAMENTE como dicen sus permisos efectivos en la BD:
//   - lo que su permiso abre  -> de verdad abre (no redirige, no da error);
//   - lo que su permiso NO abre -> de verdad queda bloqueado.
// Los permisos efectivos salen de scripts/e2e_permisos_efectivos.php (SQL propio, no los helpers de la app).
// Si alguien quita/da un permiso desde Roles y Permisos, la matriz sigue siendo valida sin editar nada.
//
// Cada test recorre todo con UN solo login y junta los desajustes en una lista legible, en vez de fallar
// en el primero: asi una corrida muestra de una vez todos los huecos de una cuenta.

const permisosBD = leerPermisosBD();

type Persona = { id: string; etiqueta: string; efectivos: string[]; entrar: (page: Page) => Promise<void> };

function personaStaff(id: keyof typeof E2E_STAFF_EMAILS, etiqueta: string): Persona {
  const cuenta = permisosBD.cuentas[E2E_STAFF_EMAILS[id]];
  if (!cuenta) {
    throw new Error(`La cuenta ${E2E_STAFF_EMAILS[id]} no existe en la BD. ¿Corriste scripts/seed_e2e_staff_accounts.php?`);
  }
  return { id, etiqueta, efectivos: cuenta.efectivos, entrar: (page) => loginAsStaff(page, id) };
}

const PERSONAS_STAFF: Persona[] = [
  personaStaff('admin', 'admin'),
  personaStaff('superadmin', 'superadmin'),
  personaStaff('encargado', 'encargado'),
  personaStaff('encargadoPickup', 'encargado de pickup'),
  personaStaff('vendedor', 'vendedor'),
  personaStaff('repartidor', 'repartidor'),
  personaStaff('auditor', 'auditor (encargado + ver_auditoria)'),
  personaStaff('encargadoSinVentas', 'encargado sin realizar_ventas'),
  personaStaff('encargadoSinAgendar', 'encargado sin asignar_entregas'),
];

/** Bloqueado = redireccion / 401 / 403, o el mensaje de "no autorizado" que devuelven los endpoints JSON. */
async function estaBloqueada(res: APIResponse): Promise<boolean> {
  const estado = res.status();
  if (estado === 401 || estado === 403 || (estado >= 300 && estado < 400)) return true;
  const cuerpo = (await res.text()).slice(0, 2000);
  return /no autorizado|no autenticad|no tienes permiso|acceso denegado|sin permiso/i.test(cuerpo);
}

async function pedir(page: Page, ruta: string): Promise<APIResponse> {
  return page.request.get(ruta, { failOnStatusCode: false, maxRedirects: 0, timeout: 60_000 });
}

async function desajustesPaginas(page: Page, efectivos: string[], vistas: Vista[], esCliente = false): Promise<string[]> {
  const fallos: string[] = [];
  for (const vista of vistas) {
    const debeEntrar = esCliente ? vista.paraCliente === true : tienePermiso(efectivos, vista.anyOf);
    const respuesta = await page.goto(vista.ruta, { waitUntil: 'domcontentloaded', timeout: 60_000 });
    const rutaFinal = new URL(page.url()).pathname;
    const seQuedo = rutaFinal.endsWith(`/${vista.destino ?? vista.ruta}`);
    const estado = respuesta?.status() ?? 0;

    if (debeEntrar && (!seQuedo || estado >= 400)) {
      fallos.push(`DEBIA ENTRAR y no pudo: ${vista.ruta} (necesita ${vista.anyOf.join(' | ')}) -> terminó en ${rutaFinal} [HTTP ${estado}]`);
    } else if (!debeEntrar && seQuedo && estado < 400) {
      fallos.push(`DEBIA QUEDAR BLOQUEADO y entró: ${vista.ruta} (necesita ${vista.anyOf.join(' | ')})`);
    }
  }
  return fallos;
}

async function desajustesEndpoints(page: Page, efectivos: string[], endpoints: Endpoint[], esCliente = false): Promise<string[]> {
  const fallos: string[] = [];
  for (const endpoint of endpoints) {
    const debeAbrir = esCliente ? endpoint.paraCliente === true : tienePermiso(efectivos, endpoint.anyOf);
    const res = await pedir(page, endpoint.ruta);
    const estado = res.status();
    const bloqueada = await estaBloqueada(res);

    if (estado === 429) {
      fallos.push(`RATE LIMIT (429) en ${endpoint.ruta}: no se puede saber si el permiso se aplicó`);
    } else if (estado >= 500) {
      fallos.push(`ERROR ${estado} en ${endpoint.ruta} (${debeAbrir ? 'debía abrir' : 'debía bloquear'})`);
    } else if (debeAbrir && bloqueada) {
      fallos.push(`DEBIA ABRIR y lo bloqueó: ${endpoint.ruta} (necesita ${endpoint.anyOf.join(' | ')}) [HTTP ${estado}]`);
    } else if (!debeAbrir && !bloqueada) {
      fallos.push(`DEBIA BLOQUEAR y respondió: ${endpoint.ruta} (necesita ${endpoint.anyOf.join(' | ')}) [HTTP ${estado}]`);
    }
  }
  return fallos;
}

test.describe('Matriz de permisos: cada cuenta hace lo que su permiso dice, y solo eso', () => {
  // La cobertura recorre ~35 paginas y ~33 endpoints por cuenta: con paginas pesadas necesita holgura.
  test.describe.configure({ timeout: 300_000 });

  for (const persona of PERSONAS_STAFF) {
    test(`${persona.etiqueta}: páginas de personal`, async ({ page }) => {
      await persona.entrar(page);
      const fallos = await desajustesPaginas(page, persona.efectivos, VISTAS);

      const abre = VISTAS.filter((v) => tienePermiso(persona.efectivos, v.anyOf)).length;
      test.info().annotations.push({ type: 'cobertura', description: `${abre} páginas permitidas, ${VISTAS.length - abre} bloqueadas` });
      expect(fallos, fallos.join('\n')).toEqual([]);
    });

    test(`${persona.etiqueta}: endpoints y descargas`, async ({ page }) => {
      await persona.entrar(page);
      const fallos = [
        ...(await desajustesEndpoints(page, persona.efectivos, ENDPOINTS)),
        ...(await desajustesEndpoints(page, persona.efectivos, DESCARGAS)),
      ];
      expect(fallos, fallos.join('\n')).toEqual([]);
    });
  }

  test('un cliente (sin ningún permiso de personal) queda bloqueado en todo lo de personal, y solo conserva su chat', async ({ page }) => {
    await registerAndLogin(page);
    const fallos = [
      ...(await desajustesPaginas(page, [], VISTAS, true)),
      ...(await desajustesEndpoints(page, [], ENDPOINTS, true)),
      ...(await desajustesEndpoints(page, [], DESCARGAS, true)),
    ];
    expect(fallos, fallos.join('\n')).toEqual([]);
  });

  test('un visitante sin sesión queda bloqueado en TODAS las páginas y endpoints', async ({ page }) => {
    await page.goto('logout.php');
    const fallos = [
      ...(await desajustesPaginas(page, [], VISTAS)),
      ...(await desajustesEndpoints(page, [], ENDPOINTS)),
      ...(await desajustesEndpoints(page, [], DESCARGAS)),
    ];
    expect(fallos, fallos.join('\n')).toEqual([]);
  });

  test('la matriz no es vacía: hay permisos que unas cuentas tienen y otras no, y permisos que solo admin tiene', async () => {
    // Guarda contra una matriz "verde por vacía": si todas las cuentas tuvieran (o no tuvieran) TODO,
    // las pruebas de arriba pasarian sin probar nada. (Un permiso que TODO el personal tiene, como atender_chat,
    // es valido: su lado "bloqueado" lo cubren el visitante y el cliente.)
    const clavesProbadas = [...new Set([...VISTAS, ...ENDPOINTS].flatMap((v) => v.anyOf))];
    const noAdmin = PERSONAS_STAFF.filter((p) => p.id !== 'admin' && p.id !== 'superadmin');
    const soloAdmin = clavesProbadas.filter((c) => !noAdmin.some((p) => p.efectivos.includes(c)));
    const mezcladas = clavesProbadas.filter(
      (c) => noAdmin.some((p) => p.efectivos.includes(c)) && noAdmin.some((p) => !p.efectivos.includes(c))
    );

    expect(noAdmin.length).toBeGreaterThanOrEqual(4);
    expect(soloAdmin.length, 'debe haber permisos exclusivos de admin que probar como bloqueados').toBeGreaterThan(5);
    expect(mezcladas.length, 'debe haber permisos repartidos de forma desigual entre roles').toBeGreaterThan(4);
  });
});
