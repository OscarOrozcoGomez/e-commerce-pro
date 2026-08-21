import { test as base, expect } from '@playwright/test';

// Varias vistas (cart.php, mis_direcciones.php, sales.php, etc.) cargan el script de
// Google Maps de forma incondicional, sin importar si el test usa el autocompletado.
// Lo bloqueamos a nivel de red para no consumir cuota real de la API key aunque
// todo esto corra en local/CI. Todas las specs deben importar test/expect de aquí,
// no directo de '@playwright/test'.
export const test = base.extend({
  page: async ({ page }, use) => {
    await page.route(/maps\.googleapis\.com|maps\.gstatic\.com/, (route) => route.abort());
    await use(page);
  },
});

export { expect };
