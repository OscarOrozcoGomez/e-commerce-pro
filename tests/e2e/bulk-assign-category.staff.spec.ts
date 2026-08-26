import type { Page } from '@playwright/test';
import { test, expect } from './fixtures';
import { loginAsStaff, E2E_PRODUCT_NAME, E2E_LOW_STOCK_PRODUCT_NAME } from './helpers';

// cargarDependenciasBac() -> cargarProductosBac() encadena dos fetch antes de poblar
// #bac-tabla-body (ver views/bulk_assign_category.php); bajo carga paralela pesada esto
// puede tardar más que el resto de la interacción, así que hay que esperar a que la
// tabla real ya esté renderizada antes de buscar o seleccionar, o si no el filtro de
// búsqueda corre sobre una tabla todavía vacía (placeholder "Cargando productos...") y
// no vuelve a aplicarse cuando los productos por fin llegan.
async function esperarTablaBacCargada(page: Page): Promise<void> {
  await expect(page.locator('#bac-tabla-body tr[data-search]').first()).toBeAttached();
}

// #bac-buscar solo filtra en el evento 'keyup' (ver views/bulk_assign_category.php), que
// page.fill() no dispara por sí solo (asigna el value directo, sin simular tecleo real).
async function buscarEnBac(page: Page, texto: string): Promise<void> {
  const input = page.locator('#bac-buscar');
  await input.fill(texto);
  await input.dispatchEvent('keyup');
}

// Materialize dibuja este checkbox con pointer-events:none y tamaño 0x0 (el <span>
// hermano es lo visualmente clickeable), así que .check() nunca encuentra un punto
// clickeable real y hay que forzar el click directo sobre el input. Se confirma con el
// contador de selección (en vez de asumir que el click se registró) porque, bajo carga
// paralela, un click disparado antes de que termine de renderizar la fila no tiene efecto.
async function seleccionarProductoBac(page: Page, fila: ReturnType<Page['locator']>): Promise<void> {
  await fila.locator('.bac-check').click({ force: true });
  await expect(page.getByText(/^1 producto\(s\) seleccionado\(s\)$/)).toBeVisible();
}

test.describe('Asignación masiva de categorías', () => {
  test('un admin crea una categoría nueva y la asigna a un producto seleccionado', async ({ page }) => {
    await loginAsStaff(page, 'admin');
    await page.goto('views/bulk_assign_category.php');
    await esperarTablaBacCargada(page);

    const nombreCategoria = `Playwright BAC Categoria ${Date.now()}`;

    await buscarEnBac(page, E2E_PRODUCT_NAME);
    const fila = page.locator('#bac-tabla-body tr', { hasText: E2E_PRODUCT_NAME });
    await seleccionarProductoBac(page, fila);

    await page.locator('#bac-tab-nueva').click();
    await page.locator('#bac-nueva-categoria').fill(nombreCategoria);

    await page.locator('#bac-btn-asignar').click();
    await expect(page.getByText(/Categoría asignada a 1 producto/)).toBeVisible();
    await expect(page.locator('#bac-tabla-body tr', { hasText: E2E_PRODUCT_NAME }).getByText(nombreCategoria)).toBeVisible();
  });

  test('el botón de asignar permanece deshabilitado sin categoría o sin productos seleccionados', async ({ page }) => {
    await loginAsStaff(page, 'admin');
    await page.goto('views/bulk_assign_category.php');
    await esperarTablaBacCargada(page);

    const boton = page.locator('#bac-btn-asignar');
    await expect(boton).toBeDisabled();

    await buscarEnBac(page, E2E_PRODUCT_NAME);
    const fila = page.locator('#bac-tabla-body tr', { hasText: E2E_PRODUCT_NAME });
    await seleccionarProductoBac(page, fila);
    await expect(boton).toBeDisabled();

    await page.locator('#bac-tab-nueva').click();
    await page.locator('#bac-nueva-categoria').fill('Categoria que no se llega a enviar');
    await expect(boton).toBeEnabled();

    await fila.locator('.bac-check').click({ force: true });
    await expect(page.getByText('0 producto(s) seleccionado(s)')).toBeVisible();
    await expect(boton).toBeDisabled();
  });

  test('un encargado puede asignar una categoría existente pero no puede crear una nueva', async ({ page }) => {
    // Un encargado no puede crear categorías (solo admin), así que primero sembramos
    // una vía admin para que el encargado tenga algo que elegir en la pestaña "Existente".
    const nombreCategoria = `Playwright BAC Encargado Categoria ${Date.now()}`;
    await loginAsStaff(page, 'admin');
    await page.goto('views/bulk_assign_category.php');
    await esperarTablaBacCargada(page);
    await buscarEnBac(page, E2E_PRODUCT_NAME);
    await seleccionarProductoBac(page, page.locator('#bac-tabla-body tr', { hasText: E2E_PRODUCT_NAME }));
    await page.locator('#bac-tab-nueva').click();
    await page.locator('#bac-nueva-categoria').fill(nombreCategoria);
    await page.locator('#bac-btn-asignar').click();
    await expect(page.getByText(/Categoría asignada a 1 producto/)).toBeVisible();

    await loginAsStaff(page, 'encargado');
    await page.goto('views/bulk_assign_category.php');
    await esperarTablaBacCargada(page);

    // La pestaña "Nueva" le muestra un aviso en vez del input de texto.
    await page.locator('#bac-tab-nueva').click();
    await expect(page.getByText('Solo un administrador puede crear categorías nuevas.')).toBeVisible();
    await expect(page.locator('#bac-nueva-categoria')).toHaveCount(0);

    await page.locator('#bac-tab-existente').click();
    await page.locator('#bac-select-categoria').selectOption({ label: nombreCategoria });

    await buscarEnBac(page, E2E_LOW_STOCK_PRODUCT_NAME);
    const fila = page.locator('#bac-tabla-body tr', { hasText: E2E_LOW_STOCK_PRODUCT_NAME });
    await seleccionarProductoBac(page, fila);
    await page.locator('#bac-btn-asignar').click();

    await expect(page.getByText(/Categoría asignada a 1 producto/)).toBeVisible();
    await expect(page.locator('#bac-tabla-body tr', { hasText: E2E_LOW_STOCK_PRODUCT_NAME }).getByText(nombreCategoria)).toBeVisible();
  });
});
