import { test, expect } from './fixtures';
import { loginAsStaff } from './helpers';

// views/roles_permisos.php es un formulario clasico que se auto-postea (sin AJAX): cada
// accion recarga la misma pagina con $error/$success embebidos en el HTML. requirePermission
// ('gestionar_usuarios') la abre para cualquier admin; el catalogo de permisos (crear/editar/
// desactivar clave) exige ademas isSuperAdmin() (usuarios.es_superadmin=1, no solo el rol
// 'admin' -- ver E2E_STAFF_EMAILS.superadmin en helpers.ts). Los 5 roles de scripts/database.sql
// son todos es_sistema=1 (no se pueden borrar ni renombrar), asi que los tests que mutan un rol
// de verdad crean uno propio desechable y lo eliminan al final -- nunca tocan admin/encargado/
// vendedor/repartidor/cliente, de los que dependen todos los demas specs de esta suite.

async function getCsrfToken(page: import('@playwright/test').Page): Promise<string> {
  return page.locator('input[name="csrf_token"]').first().inputValue();
}

test.describe('Roles y Permisos (roles_permisos.php)', () => {
  test('un vendedor no puede acceder al panel de roles y permisos', async ({ page }) => {
    await loginAsStaff(page, 'vendedor');
    await page.goto('views/roles_permisos.php');
    await expect(page).toHaveURL(/views\/dashboard\.php/);
  });

  test('un encargado no puede acceder al panel de roles y permisos', async ({ page }) => {
    await loginAsStaff(page, 'encargado');
    await page.goto('views/roles_permisos.php');
    await expect(page).toHaveURL(/views\/dashboard\.php/);
  });

  test('un repartidor no puede acceder al panel de roles y permisos', async ({ page }) => {
    await loginAsStaff(page, 'repartidor');
    await page.goto('views/roles_permisos.php');
    await expect(page).toHaveURL(/views\/dashboard\.php/);
  });

  test('un admin normal (no superadmin) no ve los controles del catálogo de permisos', async ({ page }) => {
    await loginAsStaff(page, 'admin');
    await page.goto('views/roles_permisos.php');

    await page.locator('.tabs a[href="#rp-tab-cat"]').click();
    await expect(page.locator('a.modal-trigger[href="#rpModalNuevoPermiso"]')).toHaveCount(0);
    // El <th> extra de acciones (desactivar) solo se renderiza si isSuperAdmin() -- 4
    // columnas (Clave/Categoría/Roles/Estado) para un admin normal, 5 para superadmin.
    await expect(page.locator('#rp-tab-cat table thead th')).toHaveCount(4);
  });

  test('un admin normal que manda crear_permiso por POST directo es rechazado', async ({ page }) => {
    await loginAsStaff(page, 'admin');
    await page.goto('views/roles_permisos.php');
    const csrfToken = await getCsrfToken(page);

    const res = await page.request.post('views/roles_permisos.php', {
      form: {
        csrf_token: csrfToken,
        accion: 'crear_permiso',
        clave: 'playwright_no_deberia_crearse',
        nombre: 'No debería crearse',
        categoria: 'Otros',
        descripcion: '',
      },
    });
    expect(await res.text()).toContain('Solo un super admin puede editar el catálogo de permisos.');
  });

  test('crear un rol con nombre inválido falla con un mensaje claro', async ({ page }) => {
    await loginAsStaff(page, 'admin');
    await page.goto('views/roles_permisos.php');

    await page.locator('a.modal-trigger[href="#rpModalNuevoRol"]').click();
    await page.locator('#nr_nombre').fill('Rol Con Espacios Y Mayúsculas');
    await page.getByRole('button', { name: 'Crear rol' }).click();

    await expect(page.getByText('El nombre del rol debe tener 2-50 caracteres')).toBeVisible();
  });

  test('ciclo completo de un rol: crear, activarle un permiso, y eliminarlo', async ({ page }) => {
    await loginAsStaff(page, 'admin');
    await page.goto('views/roles_permisos.php');

    const nombreRol = 'playwright_rol_' + Date.now();

    await page.locator('a.modal-trigger[href="#rpModalNuevoRol"]').click();
    await page.locator('#nr_nombre').fill(nombreRol);
    await page.getByRole('button', { name: 'Crear rol' }).click();

    await expect(page.getByText('Rol creado correctamente.')).toBeVisible();
    // guardar_rol deja $selRol = id del rol recien creado -> su editor queda activo.
    await expect(page.locator('.rp-role.active .rp-role-name')).toHaveText(nombreRol);

    // Activa el primer permiso de la categoria "Catalogo" (barato, sin efectos colaterales
    // reales: ningun archivo decide con el todavia, ver PERMISOS_EN_USO).
    const casilla = page.locator('input[name="permisos[]"][data-clave="agregar_carrito"]');
    await expect(casilla).not.toBeChecked();
    // Materialize esconde el checkbox nativo detras de su "switch" (.lever) visible; se
    // clickea el lever (lo que un usuario real vería y tocaría), no el input oculto.
    await page.locator('label:has(input[name="permisos[]"][data-clave="agregar_carrito"]) .lever').click();
    await expect(casilla).toBeChecked();

    await page.locator('#rpBtnGuardar').click();
    await expect(page.locator('#rpDiffLista')).toContainText('+ agregar_carrito');
    await page.locator('#rpDiffAplicar').click();

    await expect(page.getByText('Rol actualizado.')).toBeVisible();
    await expect(page.locator('input[name="permisos[]"][data-clave="agregar_carrito"]')).toBeChecked();

    // Elimina el rol (recien creado, 0 usuarios, no es de sistema -> boton disponible).
    page.once('dialog', (dialog) => dialog.accept());
    await page.getByRole('button', { name: 'Eliminar rol' }).click();

    await expect(page.getByText('Rol eliminado.')).toBeVisible();
    await expect(page.locator('.rp-role-name', { hasText: nombreRol })).toHaveCount(0);
  });

  test('eliminar un rol del sistema por POST directo es rechazado (la UI no expone el botón)', async ({ page }) => {
    await loginAsStaff(page, 'admin');
    await page.goto('views/roles_permisos.php');
    const csrfToken = await getCsrfToken(page);

    // id_rol=3 = 'vendedor' en database.sql (es_sistema=1); si el seed alguna vez cambia el
    // orden, el mensaje de rechazo sigue siendo el mismo sin importar cual rol de sistema sea.
    const res = await page.request.post('views/roles_permisos.php', {
      form: { csrf_token: csrfToken, accion: 'eliminar_rol', id_rol: '3' },
    });
    expect(await res.text()).toContain('No se puede eliminar un rol del sistema.');
  });

  test('superadmin: crear un permiso con nombre muy similar a uno existente falla sin confirmar', async ({ page }) => {
    await loginAsStaff(page, 'superadmin');
    await page.goto('views/roles_permisos.php');

    await page.locator('.tabs a[href="#rp-tab-cat"]').click();
    await page.locator('a.modal-trigger[href="#rpModalNuevoPermiso"]').click();
    // "Gestionar usuario" (singular) es >65% similar a "Gestionar usuarios" (gestionar_usuarios).
    await page.locator('#rpModalNuevoPermiso input[name="clave"]').fill('playwright_dup_test_' + Date.now());
    await page.locator('#rpModalNuevoPermiso input[name="nombre"]').fill('Gestionar usuario');
    await page.getByRole('button', { name: 'Crear' }).click();

    await expect(page.getByText('Ya existe un permiso parecido')).toBeVisible();
  });

  test('superadmin: desactivar un permiso en uso por código es rechazado por POST directo', async ({ page }) => {
    await loginAsStaff(page, 'superadmin');
    await page.goto('views/roles_permisos.php');

    // El checkbox del formulario de rol expone el id_permiso incluso para claves "en uso"
    // (la tabla del catálogo solo renderiza el botón de desactivar para las que NO lo están).
    const idPermiso = await page.locator('input[name="permisos[]"][data-clave="gestionar_usuarios"]').getAttribute('value');
    expect(idPermiso).toBeTruthy();

    const csrfToken = await getCsrfToken(page);
    const res = await page.request.post('views/roles_permisos.php', {
      form: { csrf_token: csrfToken, accion: 'desactivar_permiso', id_permiso: idPermiso! },
    });
    expect(await res.text()).toContain('está en uso');
  });

  test('superadmin: ciclo completo de un permiso: crear una clave nueva y desactivarla', async ({ page }) => {
    await loginAsStaff(page, 'superadmin');
    await page.goto('views/roles_permisos.php');

    const clave = 'playwright_permiso_' + Date.now();
    const nombreVisible = 'Playwright Permiso ' + Date.now();

    await page.locator('.tabs a[href="#rp-tab-cat"]').click();
    await page.locator('a.modal-trigger[href="#rpModalNuevoPermiso"]').click();
    await page.locator('#rpModalNuevoPermiso input[name="clave"]').fill(clave);
    await page.locator('#rpModalNuevoPermiso input[name="nombre"]').fill(nombreVisible);
    await page.getByRole('button', { name: 'Crear' }).click();

    await expect(page.getByText('Permiso creado.')).toBeVisible();
    await page.locator('.tabs a[href="#rp-tab-cat"]').click();
    const fila = page.locator('#rp-tab-cat table tbody tr').filter({ hasText: clave });
    await expect(fila).toBeVisible();
    await expect(fila.getByText('sin efecto')).toBeVisible();

    page.once('dialog', (dialog) => dialog.accept());
    await fila.locator('button[title="Desactivar (no se usa en código)"]').click();

    await expect(page.getByText('desactivado. Ya no aparece en la matriz')).toBeVisible();
    await page.locator('.tabs a[href="#rp-tab-cat"]').click();
    await expect(page.locator('#rp-tab-cat table tbody tr').filter({ hasText: clave })).toHaveCount(0);
  });

  test('"¿Quién puede...?" muestra el resultado de la búsqueda para un permiso conocido', async ({ page }) => {
    await loginAsStaff(page, 'admin');
    await page.goto('views/roles_permisos.php');

    await page.locator('.tabs a[href="#rp-tab-cat"]').click();
    // Materialize esconde el <select> nativo detras de su propio dropdown; forzar evita
    // depender de que ese dropdown se haya inicializado bien dentro de una pestaña oculta.
    await page.locator('select[name="quien"]').selectOption('gestionar_usuarios', { force: true });

    // El <select> onchange="this.form.submit()" es un form method="GET" sin hash: la
    // navegacion resultante vuelve a la pestana activa por defecto (#rp-tab-roles) aunque
    // el resultado viva en #rp-tab-cat -- hay que volver a entrar a esa pestana.
    await expect(page).toHaveURL(/quien=gestionar_usuarios/);
    await page.locator('.tabs a[href="#rp-tab-cat"]').click();
    await expect(page.getByText('Roles que lo otorgan')).toBeVisible();
    await expect(page.getByText('Ajustes individuales', { exact: true })).toBeVisible();
  });
});
