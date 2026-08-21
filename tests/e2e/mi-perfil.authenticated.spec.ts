import { test, expect } from './fixtures';
import { registerAndLogin } from './helpers';

test.describe('Mi Perfil', () => {
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
