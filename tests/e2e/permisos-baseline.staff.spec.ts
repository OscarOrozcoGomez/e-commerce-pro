import { test, expect } from './fixtures';
import { E2E_STAFF_EMAILS } from './helpers';
import { CLAVES_SOLO_ADMIN, leerPermisosBD } from './permisos-datos';

// LINEA BASE de permisos: complementa a permisos-matriz. La matriz prueba que la app haga cumplir lo que la
// BD dice; esta prueba que lo que la BD dice sea lo que se QUIERE. Sin ella, si alguien le da por error
// "gestionar_usuarios" a un vendedor, la matriz seguiria en verde (la app lo dejaria pasar "correctamente").
//
// Si un cambio de permisos es a proposito, se actualiza la linea de aqui abajo: el fallo es la alarma.

const permisos = leerPermisosBD();
const rol = (nombre: string): string[] => permisos.por_rol[nombre] ?? [];

test.describe('Línea base de permisos por rol', () => {
  test('vendedor: exactamente lo de mostrador (ventas, liquidación, chat, pickup, dashboard)', () => {
    expect(rol('vendedor')).toEqual(
      ['atender_chat', 'declarar_liquidacion', 'realizar_ventas', 'ver_dashboard', 'ver_notificaciones_pickup']
    );
  });

  test('repartidor: exactamente entregas y chat', () => {
    expect(rol('repartidor')).toEqual(['atender_chat', 'ver_entregas']);
  });

  test('cliente: ningún permiso de personal', () => {
    expect(rol('cliente')).toEqual([]);
  });

  test('encargado: conserva todo lo que el helper de rol le daba antes de que "todo fuera por permiso" (PR #205)', () => {
    const debeTener = [
      'apartar_productos',
      'asignar_categorias_masivo',
      'asignar_entregas',
      'atender_chat',
      'gestionar_blogs',
      'gestionar_caducidades',
      'gestionar_cancelaciones',
      'gestionar_clientes',
      'inventario',
      'realizar_ventas',
      'vender_sin_inventario',
      'ver_dashboard',
      'ver_notificaciones_pickup',
      'ver_reportes',
    ];
    const faltan = debeTener.filter((clave) => !rol('encargado').includes(clave));
    expect(faltan, `al encargado le faltan: ${faltan.join(', ')}`).toEqual([]);
  });

  test('ningún rol que no sea admin trae permisos reservados a administración (escalada de privilegios)', () => {
    const escaladas: string[] = [];
    for (const nombre of ['encargado', 'vendedor', 'repartidor', 'cliente']) {
      for (const clave of CLAVES_SOLO_ADMIN) {
        if (rol(nombre).includes(clave)) escaladas.push(`${nombre} tiene ${clave}`);
      }
    }
    expect(escaladas, escaladas.join('\n')).toEqual([]);
  });

  // transferir_stock y gestionar_productos se le retiran al encargado A PROPOSITO en las migraciones
  // (20260829_000005 y 20260920_000001), pero un admin SÍ puede dárselos desde Roles y Permisos y eso es
  // legitimo. Por eso no falla: deja un AVISO visible en el reporte para que se revise que sea intencional.
  test('encargado: avisa si trae permisos que las migraciones le retiran a propósito', () => {
    const retiradosAProposito = ['transferir_stock', 'gestionar_productos'];
    const conAviso = retiradosAProposito.filter((clave) => rol('encargado').includes(clave));
    for (const clave of conAviso) {
      test.info().annotations.push({
        type: 'AVISO',
        description: `El rol encargado TIENE "${clave}" (las migraciones no se lo dan). Revísalo en Roles y Permisos si no fue intencional.`,
      });
    }
    expect(Array.isArray(conAviso)).toBe(true);
  });
});

test.describe('Línea base de las cuentas de prueba (overrides individuales)', () => {
  const cuenta = (clave: keyof typeof E2E_STAFF_EMAILS) => {
    const c = permisos.cuentas[E2E_STAFF_EMAILS[clave]];
    if (!c) throw new Error(`Falta la cuenta ${E2E_STAFF_EMAILS[clave]} (¿seed_e2e_staff_accounts.php?)`);
    return c;
  };

  test('admin y superadmin tienen TODO el catálogo activo', () => {
    expect([...cuenta('admin').efectivos].sort()).toEqual([...permisos.catalogo].sort());
    expect([...cuenta('superadmin').efectivos].sort()).toEqual([...permisos.catalogo].sort());
  });

  test('auditor: un encargado al que un override individual le concede ver_auditoria, y nada más de administración', () => {
    const auditor = cuenta('auditor');
    expect(auditor.rol).toBe('encargado');
    expect(auditor.overrides).toContain('conceder:ver_auditoria');
    expect(auditor.efectivos).toContain('ver_auditoria');
    for (const clave of CLAVES_SOLO_ADMIN.filter((c) => c !== 'ver_auditoria')) {
      expect(auditor.efectivos, `el auditor no debería tener ${clave}`).not.toContain(clave);
    }
  });

  test('un override "denegar" le gana al rol: el encargado normal NO tiene ver_auditoria ni transferir_stock', () => {
    for (const clave of ['encargado', 'encargadoPickup'] as const) {
      const c = cuenta(clave);
      expect(c.efectivos, `${clave} no debería tener transferir_stock`).not.toContain('transferir_stock');
    }
    expect(cuenta('encargado').efectivos).not.toContain('ver_auditoria');
    expect(cuenta('encargado').overrides).toContain('denegar:transferir_stock');
  });

  test('encargados con un permiso menos: pierden SOLO ese permiso y conservan el resto de su rol', () => {
    const sinVentas = cuenta('encargadoSinVentas');
    expect(sinVentas.efectivos).not.toContain('realizar_ventas');
    expect(sinVentas.efectivos).toContain('gestionar_clientes');
    expect(sinVentas.overrides).toEqual(['denegar:realizar_ventas']);

    const sinAgendar = cuenta('encargadoSinAgendar');
    expect(sinAgendar.efectivos).not.toContain('asignar_entregas');
    expect(sinAgendar.efectivos).toContain('realizar_ventas');
    expect(sinAgendar.overrides).toEqual(['denegar:asignar_entregas']);
  });

  test('el vendedor y el repartidor de prueba no arrastran overrides que falseen la línea base', () => {
    expect(cuenta('vendedor').overrides).toEqual([]);
    expect(cuenta('repartidor').overrides).toEqual([]);
    expect(cuenta('permisosVivo').overrides).toEqual([]);
  });
});
