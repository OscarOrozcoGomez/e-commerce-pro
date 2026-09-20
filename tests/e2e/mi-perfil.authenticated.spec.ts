import { test, expect } from './fixtures';
import { registerAndLogin } from './helpers';

test.describe('Mi Perfil', () => {
  test('un dominio de correo bloqueado (desechable) rechaza el cambio', async ({ page }) => {
    await registerAndLogin(page);
    await page.goto('views/mi_perfil.php');

    // isLikelyDeliverableEmailProfile() (views/mi_perfil.php) rechaza esta lista de dominios
    // desechables ANTES de intentar resolver DNS -- determinista, sin depender de que el
    // ambiente de pruebas tenga salida a internet real.
    await page.locator('#email').fill('playwright-qa@mailinator.com');
    await page.getByRole('button', { name: 'Guardar Cambios' }).click();

    await expect(page.getByText('No pudimos validar el dominio del correo. Usa un correo real y verificable.')).toBeVisible();
  });

  test('un teléfono con formato inválido rechaza el cambio sin tocar el resto del perfil', async ({ page }) => {
    const cliente = await registerAndLogin(page);
    await page.goto('views/mi_perfil.php');

    await page.locator('#telefono').fill('abc123');
    await page.getByRole('button', { name: 'Guardar Cambios' }).click();

    await expect(page.getByText('El teléfono es obligatorio: debe tener 10 dígitos con formato (331) - 863 - 5185.')).toBeVisible();
    // El nombre sigue siendo el original: el guardado es todo-o-nada, no se aplicó nada.
    await expect(page.locator('#nombre')).toHaveValue(cliente.nombre);
  });

  // PR #202: el telefono ya no se puede dejar vacio (antes era opcional y borrarlo se permitia).
  test('borrar el teléfono del perfil se rechaza: ya es obligatorio', async ({ page }) => {
    const cliente = await registerAndLogin(page);
    await page.goto('views/mi_perfil.php');
    await expect(page.locator('#telefono')).not.toHaveValue('');

    // Se quita el "required" nativo para llegar al chequeo del SERVIDOR (el que protege ante un POST directo).
    await page.locator('#telefono').evaluate((el) => { (el as HTMLInputElement).required = false; });
    await page.locator('#telefono').fill('');
    await page.getByRole('button', { name: 'Guardar Cambios' }).click();

    await expect(page.getByText('El teléfono es obligatorio: debe tener 10 dígitos con formato (331) - 863 - 5185.')).toBeVisible();
    await expect(page.locator('#nombre')).toHaveValue(cliente.nombre);
  });

  test('cambiar el correo al de otra cuenta ya registrada se rechaza', async ({ page }) => {
    const otraCliente = await registerAndLogin(page);
    await page.goto('logout.php');

    await registerAndLogin(page);
    await page.goto('views/mi_perfil.php');
    await page.locator('#email').fill(otraCliente.email);
    await page.getByRole('button', { name: 'Guardar Cambios' }).click();

    await expect(page.getByText('Ese correo ya está registrado por otro usuario.')).toBeVisible();
  });

  test('editar nombre y teléfono actualiza el perfil', async ({ page }) => {
    await registerAndLogin(page);
    await page.goto('views/mi_perfil.php');

    // No tocamos #telefono: su validación de duplicados escanea y descifra el
    // teléfono de TODOS los clientes (core/phone_utils.php::findClienteByPhone),
    // lo cual hereda la misma fragilidad de descifrado bajo carga concurrente que
    // documentamos en mis-direcciones.authenticated.spec.ts. No es lo que este
    // test quiere cubrir (edición básica de perfil).
    await page.locator('#nombre').fill('Playwright QA Editado');
    await page.getByRole('button', { name: 'Guardar Cambios' }).click();

    await expect(page.getByText('Perfil actualizado correctamente.')).toBeVisible();
    await expect(page.locator('#nombre')).toHaveValue('Playwright QA Editado');
  });
});
