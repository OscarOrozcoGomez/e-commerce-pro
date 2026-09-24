import { test, expect } from './fixtures';
import { completeDomicilioCheckout } from './helpers';

// views/gracias.php: pantalla publica de "gracias" a la que redirige el checkout real
// (completeDomicilioCheckout/completeSucursalCheckout la visitan de pasada, pero nadie
// probaba su propio contenido condicional). Sin sesion ni tabla especial: usa solo
// $_SESSION['thanks_page_seen'] para deduplicar vistas repetidas de la misma orden. En
// localhost $trackingEnabled es false por defecto (ALLOW_MARKETING_LOCAL no esta seteado),
// asi que GTM/gtag ni se cargan -- no hace falta bloquearlos en la red para estos tests.

test.describe('Página de agradecimiento (gracias.php)', () => {
  test('sin "id" (o invalido) muestra la confirmación genérica de marketing', async ({ page }) => {
    await page.goto('views/gracias.php');
    await expect(page.getByText('Confirmación general')).toBeVisible();
    await expect(page.getByRole('heading', { name: '¡Gracias por tu interés en Belleza y Bienestar!' })).toBeVisible();
    // Sin pedido válido, los dos botones de acción ("primary" y "secondary") dicen lo mismo
    // ("Explorar catálogo") -- ver $detailLabel en views/gracias.php.
    await expect(page.getByRole('link', { name: 'Explorar catálogo' })).toHaveCount(2);

    await page.goto('views/gracias.php?id=0');
    await expect(page.getByText('Confirmación general')).toBeVisible();

    await page.goto('views/gracias.php?id=abc');
    await expect(page.getByText('Confirmación general')).toBeVisible();
  });

  test('con un pedido real del cliente autenticado, confirma el pedido y enlaza a su detalle; la segunda vista se marca como duplicada', async ({ page }) => {
    const idPedido = await completeDomicilioCheckout(page);

    // completeDomicilioCheckout() ya deja al cliente en gracias.php?id=... -- primera vista.
    await expect(page).toHaveURL(new RegExp(`gracias\\.php\\?id=${idPedido}`));
    await expect(page.getByText('Pedido confirmado')).toBeVisible();
    await expect(page.getByRole('heading', { name: '¡Tu compra quedó registrada y listo para seguir!' })).toBeVisible();

    const detalleLink = page.getByRole('link', { name: 'Ver detalle de mi pedido' });
    await expect(detalleLink).toBeVisible();
    await expect(detalleLink).toHaveAttribute('href', new RegExp(`detalle_compra\\.php\\?id=${idPedido}$`));

    // Recargar la MISMA url (misma sesión) -- ya está marcada como vista en
    // $_SESSION['thanks_page_seen'], así que ahora se considera duplicada.
    await page.reload();
    await expect(page.getByText('Pedido ya confirmado')).toBeVisible();
    await expect(page.getByRole('heading', { name: 'Tu compra ya fue registrada' })).toBeVisible();
  });

  test('tras cerrar sesión, revisitar la misma url ofrece iniciar sesión en vez del detalle', async ({ page }) => {
    const idPedido = await completeDomicilioCheckout(page);
    await page.goto('logout.php');

    await page.goto(`views/gracias.php?id=${idPedido}`);
    // Sigue mostrando "Pedido confirmado" (el id es válido), pero sin sesión no puede
    // enlazar directo a su detalle.
    await expect(page.getByText('Pedido confirmado')).toBeVisible();
    const loginLink = page.getByRole('link', { name: 'Iniciar sesión para ver mi pedido' });
    await expect(loginLink).toBeVisible();
    await expect(loginLink).toHaveAttribute('href', /views\/login\.php$/);
  });
});
