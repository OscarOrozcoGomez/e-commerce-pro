import type { Page } from '@playwright/test';
import { test, expect } from './fixtures';
import { loginAsStaff } from './helpers';

// complete_account.php resuelve el cliente pendiente por telefono, tomado de
// $_SESSION['account_completion'] (lo deja ahi register.php cuando el telefono
// capturado ya pertenece a un cliente walk-in sin cuenta) o, como fallback que la
// propia vista acepta, de un ?telefono= por GET -- eso es lo que usamos aqui para
// probarla de forma aislada, sin tener que reproducir el flujo completo de
// register.php cada vez.
function telefonoUnico(): string {
  // 10 digitos, unico por corrida -- varios tests de admin-customers.staff.spec.ts
  // reutilizan el mismo telefono fijo '3312345678' para sus clientes de prueba, asi
  // que findClienteByPhone() (primer match por orden de tabla) podria resolver a
  // uno de esos en vez del que creamos aqui si usaramos ese mismo numero.
  const ts = Date.now().toString();
  return ('33' + ts.slice(-8)).slice(0, 10);
}

/** Crea un cliente walk-in (sin cuenta) desde manage_customers.php y regresa su telefono. */
async function crearClienteWalkIn(page: Page, nombre: string): Promise<string> {
  await loginAsStaff(page, 'admin');
  await page.goto('views/manage_customers.php');

  const telefono = telefonoUnico();
  await page.getByRole('link', { name: 'Nuevo cliente' }).click();
  const modal = page.locator('#modal-crear-cliente');
  await modal.locator('input[name="nombre"]').fill(nombre);
  await modal.locator('input[name="telefono"]').fill(telefono);
  await modal.getByRole('button', { name: /Crear|Guardar/ }).click();
  await expect(page.locator('.manage-customers-name', { hasText: nombre })).toBeVisible({ timeout: 20000 });

  return telefono;
}

test.describe('Completar Cuenta (complete_account.php)', () => {
  test('sin telefono pendiente muestra el error y no el formulario', async ({ page }) => {
    await page.goto('logout.php');
    await page.goto('views/complete_account.php');
    await expect(page.getByText('No encontramos un teléfono pendiente de completar. Vuelve al registro e inténtalo otra vez.')).toBeVisible();
    await expect(page.locator('#complete-account-form')).toHaveCount(0);
  });

  test('un telefono sin cliente asociado muestra el error correspondiente', async ({ page }) => {
    await page.goto('logout.php');
    await page.goto(`views/complete_account.php?telefono=${telefonoUnico()}`);
    await expect(page.getByText('No encontramos un cliente asociado a ese teléfono.')).toBeVisible();
    await expect(page.locator('#complete-account-form')).toHaveCount(0);
  });

  test('un usuario ya autenticado es redirigido en vez de ver el formulario', async ({ page }) => {
    await loginAsStaff(page, 'admin');
    await page.goto('views/complete_account.php');
    await expect(page).not.toHaveURL(/complete_account\.php/);
  });

  test('completar la cuenta de un cliente walk-in permite iniciar sesion con las nuevas credenciales', async ({ page }) => {
    const nombreCliente = `Playwright Complete Cuenta ${Date.now()}`;
    const telefono = await crearClienteWalkIn(page, nombreCliente);

    await page.goto('logout.php');
    await page.goto(`views/complete_account.php?telefono=${telefono}`);

    // El nombre capturado por el admin se precarga en el formulario.
    await expect(page.locator('#nombre')).toHaveValue(nombreCliente);
    await expect(page.locator('#telefono')).toBeDisabled();

    const email = `playwright-complete-${Date.now()}@example.com`;
    const password = 'E2eComplete!2026';
    await page.locator('#email').fill(email);
    await page.locator('#password').fill(password);
    await page.locator('#confirm_password').fill(password);
    await page.getByRole('button', { name: 'COMPLETAR CUENTA' }).click();

    await expect(page).toHaveURL(/views\/login\.php/);
    await expect(page.getByText('Cuenta completada con éxito. Ya puedes iniciar sesión.')).toBeVisible();

    await page.locator('#email').fill(email);
    await page.locator('#password').fill(password);
    await page.getByRole('button', { name: 'Iniciar Sesión' }).click();
    await page.waitForURL((url) => !url.toString().includes('views/login.php'));
  });

  test('un telefono cuyo cliente ya tiene cuenta activa redirige a login sin mostrar el formulario', async ({ page }) => {
    const nombreCliente = `Playwright Complete Repetido ${Date.now()}`;
    const telefono = await crearClienteWalkIn(page, nombreCliente);

    await page.goto('logout.php');
    await page.goto(`views/complete_account.php?telefono=${telefono}`);
    const email = `playwright-complete-repetido-${Date.now()}@example.com`;
    const password = 'E2eComplete!2026';
    await page.locator('#email').fill(email);
    await page.locator('#password').fill(password);
    await page.locator('#confirm_password').fill(password);
    await page.getByRole('button', { name: 'COMPLETAR CUENTA' }).click();
    await expect(page).toHaveURL(/views\/login\.php/);

    // El mismo telefono, ahora que el cliente ya tiene id_usuario, debe mandar
    // directo a login (nunca mostrar el formulario de nuevo).
    await page.goto(`views/complete_account.php?telefono=${telefono}`);
    await expect(page).toHaveURL(/views\/login\.php/);
    await expect(page.getByText('Ese teléfono ya tiene una cuenta activa. Inicia sesión para continuar.')).toBeVisible();
  });

  test('un correo ya registrado no deja completar la cuenta', async ({ page }) => {
    const nombreCliente = `Playwright Complete Correo Dup ${Date.now()}`;
    const telefono = await crearClienteWalkIn(page, nombreCliente);

    await page.goto('logout.php');
    await page.goto(`views/complete_account.php?telefono=${telefono}`);
    await page.locator('#email').fill('e2e-admin@playwright.test');
    await page.locator('#password').fill('E2eComplete!2026');
    await page.locator('#confirm_password').fill('E2eComplete!2026');
    await page.getByRole('button', { name: 'COMPLETAR CUENTA' }).click();

    await expect(page.getByText('Ese correo electrónico ya está registrado.')).toBeVisible();
    await expect(page).toHaveURL(/complete_account\.php/);
  });

  test('una contrasena debil mantiene deshabilitado el boton de completar cuenta', async ({ page }) => {
    const nombreCliente = `Playwright Complete Debil ${Date.now()}`;
    const telefono = await crearClienteWalkIn(page, nombreCliente);

    await page.goto('logout.php');
    await page.goto(`views/complete_account.php?telefono=${telefono}`);

    await page.locator('#email').fill(`playwright-complete-debil-${Date.now()}@example.com`);
    await page.locator('#password').fill('abc');
    await page.locator('#confirm_password').fill('abc');
    await expect(page.getByRole('button', { name: 'COMPLETAR CUENTA' })).toBeDisabled();
  });
});
