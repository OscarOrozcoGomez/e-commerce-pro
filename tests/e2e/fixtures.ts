import { test as base, expect } from '@playwright/test';

// Varias vistas (cart.php, mis_direcciones.php, sales.php, etc.) cargan el script de
// Google Maps de forma incondicional, sin importar si el test usa el autocompletado.
// Lo bloqueamos a nivel de red para no consumir cuota real de la API key aunque
// todo esto corra en local/CI. Todas las specs deben importar test/expect de aquí,
// no directo de '@playwright/test'.
//
// fonts.googleapis.com/fonts.gstatic.com (icono Material Icons, views/includes/header.php)
// y static.cloudflareinsights.com (beacon de analitica de Cloudflare) se cargan igual de
// forma incondicional en cada pagina y son puramente cosmeticos/de metricas -- ningun test
// verifica que el icono renderice o que el beacon se envie. Se bloquean tambien porque el job
// e2e-tests de CI (GitHub Actions) mostro el evento "load" de la pagina colgandose ~30s en
// practicamente cada test (page.waitForURL/goto nunca resuelven) sin reproducirse igual en
// local -- consistente con uno de estos hosts respondiendo lento/colgado especificamente
// desde la red de los runners de GitHub. cdnjs.cloudflare.com NO se bloquea: sirve
// materialize.min.js, del que sí depende funcionalmente el comportamiento de modales/
// autocompletados que los tests ejercitan.
export const test = base.extend({
  page: async ({ page }, use) => {
    await page.route(/maps\.googleapis\.com|maps\.gstatic\.com/, (route) => route.abort());
    await page.route(/fonts\.googleapis\.com|fonts\.gstatic\.com|static\.cloudflareinsights\.com/, (route) => route.abort());
    await use(page);
  },
});

export { expect };
