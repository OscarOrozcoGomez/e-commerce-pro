// @ts-check
/**
 * Lee tus pedidos de mayoreo.blife.mx y los deja como JSON limpio para meterlos al
 * sistema como Orden de Compra "por llegar".
 *
 * NO hace el login ni el pago: abre un navegador VISIBLE, tú entras con tu correo +
 * el codigo de un solo uso, presionas ENTER, y el script:
 *   - guarda la sesion (la proxima vez ya no pides codigo, hasta que caduque),
 *   - va SOLO a "Mis pedidos",
 *   - abre el/los pedido(s) y saca sus renglones (nombre, presentacion, cantidad,
 *     precio unitario) leyendo la pantalla,
 *   - escribe scripts/.mayoreo/pedido-<NUMERO>.json (uno por pedido) + pedidos.json.
 *
 * Uso (en C:\xampp\htdocs\e-commerce-pro):
 *   node scripts/mayoreo_pedidos.mjs                 # solo el pedido mas reciente
 *   node scripts/mayoreo_pedidos.mjs --todos         # todos los que aparezcan
 *   node scripts/mayoreo_pedidos.mjs --pedido BLM015728
 *   node scripts/mayoreo_pedidos.mjs --fresh         # ignora la sesion guardada
 *
 * Requiere node_modules/playwright + Chromium de Playwright (ya instalados).
 */

import { chromium } from 'playwright';
import fs from 'node:fs';
import path from 'node:path';
import readline from 'node:readline';
import { fileURLToPath } from 'node:url';

const __dirname = path.dirname(fileURLToPath(import.meta.url));
const BASE = 'https://mayoreo.blife.mx';
const ORDERS_URL = `${BASE}/orders`;

const DATA_DIR = path.join(__dirname, '.mayoreo');
const SESSION_FILE = path.join(DATA_DIR, 'session.json');

const args = process.argv.slice(2);
const FRESH = args.includes('--fresh');
const TODOS = args.includes('--todos');
const pedIdx = args.indexOf('--pedido');
const PEDIDO = pedIdx >= 0 ? String(args[pedIdx + 1] || '').replace(/^#/, '').toUpperCase() : '';

const log = (...a) => console.log(...a);
/** @param {string} msg */
function esperarEnter(msg) {
    const rl = readline.createInterface({ input: process.stdin, output: process.stdout });
    return new Promise((res) => rl.question(msg, () => { rl.close(); res(undefined); }));
}
const num = (s) => {
    const m = String(s == null ? '' : s).replace(/[^\d.,-]/g, '').replace(/,/g, '');
    const n = parseFloat(m);
    return Number.isFinite(n) ? n : 0;
};

async function esperarContenido(page) {
    await page.waitForLoadState('networkidle', { timeout: 20000 }).catch(() => {});
    await page.waitForFunction(() => document.querySelectorAll('.css-wot6g1').length === 0, { timeout: 12000 }).catch(() => {});
    await page.waitForTimeout(2500);
}

/** Espera a que aparezca al menos un numero de pedido (BLM####) en la pantalla. */
async function esperarPedidos(page) {
    await page.waitForFunction(
        () => /BLM\d{4,}/.test(document.body.innerText || ''),
        { timeout: 20000 }
    ).catch(() => {});
    await page.waitForTimeout(1500);
}

/**
 * Lee la lista de pedidos del texto renderizado. Los numeros son "BLM" + digitos
 * (el DOM pega "LLegó" justo despues, por eso el patron es BLM\d+, sin \w).
 */
async function leerLista(page) {
    return page.evaluate(() => {
        const txt = document.body.innerText || '';
        const nums = Array.from(new Set(txt.match(/BLM\d{4,}/g) || []));
        return nums.map((numero) => {
            const idx = txt.indexOf(numero);
            const win = txt.slice(Math.max(0, idx - 280), idx + 140).replace(/\s+/g, ' ');
            return {
                numero,
                estatus: (win.match(/\b(Enviado|Pagado|Preparando|Pendiente|Pago Fallido|Cancelado)\b/) || [, ''])[1],
                total_txt: (win.match(/\$\s*[\d.,]+/) || [''])[0].trim(),
                articulos: parseInt((win.match(/\((\d+)\s*Art/) || [, '0'])[1], 10) || 0,
                fecha_txt: (win.match(/(\d{2}\/\d{2}\/\d{4})/) || [, ''])[1],
            };
        });
    });
}

/** Con el detalle de un pedido abierto, saca sus renglones. */
async function leerDetalle(page) {
    return page.evaluate(() => {
        const norm = (s) => (s || '').replace(/\s+/g, ' ').trim();
        // Cada renglon: un div que contiene un <p> en negrita (nombre), un <p> con "$" (precio)
        // y un <p> chico redondo (cantidad).
        const filas = [];
        document.querySelectorAll('div').forEach((row) => {
            const ps = Array.from(row.querySelectorAll(':scope > div p, :scope p'));
            if (row.querySelectorAll('div').length > 6) return; // solo contenedores hoja-ish
            const bold = Array.from(row.querySelectorAll('p')).find((p) => /font-bold/.test(p.className) && !/\$/.test(p.textContent || '') && !/^\d+$/.test(norm(p.textContent)));
            const precioP = Array.from(row.querySelectorAll('p')).find((p) => /^\$\s*[\d.,]+$/.test(norm(p.textContent)));
            const cantP = Array.from(row.querySelectorAll('p')).find((p) => /rounded-full/.test(p.className) && /^\d+$/.test(norm(p.textContent)));
            if (!bold || !precioP || !cantP) return;
            const nombre = norm(bold.textContent);
            // La presentacion es el <p> hermano justo despues del nombre (mismo flex-col).
            let presentacion = '';
            const sib = bold.nextElementSibling;
            if (sib && sib.tagName === 'P') presentacion = norm(sib.textContent);
            const img = row.querySelector('img[alt]');
            filas.push({
                nombre,
                presentacion,
                cantidad: parseInt(norm(cantP.textContent), 10) || 0,
                precio_unitario: parseFloat(norm(precioP.textContent).replace(/[^\d.]/g, '')) || 0,
                img_alt: img ? (img.getAttribute('alt') || '') : '',
                img_src: img ? (img.getAttribute('src') || '') : '',
            });
        });
        // Dedup por (nombre, precio) conservando el orden — el DOM a veces repite nodos.
        const seen = new Set();
        const items = [];
        for (const f of filas) {
            const k = f.nombre + '|' + f.presentacion + '|' + f.precio_unitario + '|' + f.cantidad;
            if (seen.has(k)) continue;
            seen.add(k);
            items.push(f);
        }
        const body = document.body.innerText;
        const g = (re) => (body.match(re) || [, ''])[1];
        return {
            numero: (g(/Pedido:\s*#(BLM\d+)/) || ''),
            fecha_compra: (g(/Fecha de compra:\s*([^\n]+)/) || '').trim(),
            total_pedido_txt: (g(/Total del pedido:\s*\n?\s*([^\n]+)/) || '').trim(),
            subtotal_txt: (g(/Subtotal\s*\n?\s*(\$[\d.,]+)/) || '').trim(),
            pago: (g(/Detalle de pago\s*\n+\s*([^\n]+)/) || '').trim(),
            items,
        };
    });
}

async function abrirDetalle(page, numero, lista) {
    await page.goto(ORDERS_URL, { waitUntil: 'domcontentloaded' }).catch(() => {});
    await esperarContenido(page);
    await esperarPedidos(page);

    // El indice del pedido en la lista == indice del boton/enlace "Ver pedido".
    let idx = Array.isArray(lista) ? lista.findIndex((p) => p.numero === numero) : -1;
    if (idx < 0) idx = 0;

    // Probamos varios selectores por si "Ver pedido" es button o link.
    const cands = [
        page.getByRole('button', { name: /ver pedido/i }),
        page.getByRole('link', { name: /ver pedido/i }),
        page.locator('button, a, [role="button"]').filter({ hasText: /^\s*Ver pedido\s*$/i }),
    ];
    let clicked = false;
    for (const loc of cands) {
        const n = await loc.count().catch(() => 0);
        if (n > 0) {
            await loc.nth(Math.min(idx, n - 1)).click({ timeout: 8000 }).catch(() => {});
            clicked = true;
            break;
        }
    }

    const ok = await page.waitForFunction(
        () => /Compraste\s+\d+\s+productos|Fecha de compra/i.test(document.body.innerText || ''),
        { timeout: 15000 }
    ).then(() => true).catch(() => false);

    if (!ok) {
        log(`   No pude abrir el detalle de #${numero} solo.`);
        log('   En el navegador, haz clic TU en ese pedido y espera a que cargue.');
        await esperarEnter('   Cuando veas los productos del pedido, presiona ENTER... ');
    }
    await esperarContenido(page);
    return clicked;
}

async function main() {
    fs.mkdirSync(DATA_DIR, { recursive: true });
    const usaSesion = !FRESH && fs.existsSync(SESSION_FILE);

    log('\n==========================================================');
    log(' B Life Mayoreo -> pedidos');
    log('==========================================================');
    log(usaSesion ? ' Reusando sesion guardada.' : ' Sin sesion: login a mano una vez.');
    log(TODOS ? ' Modo: TODOS los pedidos.' : PEDIDO ? ` Modo: solo #${PEDIDO}.` : ' Modo: solo el pedido mas reciente.');
    log('');

    const browser = await chromium.launch({ headless: false, args: ['--start-maximized'] });
    const context = await browser.newContext({ viewport: null, storageState: usaSesion ? SESSION_FILE : undefined });
    const page = await context.newPage();

    await page.goto(ORDERS_URL, { waitUntil: 'domcontentloaded' }).catch(() => {});
    await page.waitForTimeout(2500);

    log('----------------------------------------------------------');
    log(' Login: si te lo pide -> Correo -> codigo de 6 digitos.');
    log(' Si entraste directo, no hagas nada.');
    log('----------------------------------------------------------');
    await esperarEnter(' Cuando veas el menu con "Mis pedidos", presiona ENTER... ');

    try { await context.storageState({ path: SESSION_FILE }); log(' Sesion guardada.'); }
    catch (e) { log(' Aviso: no se guardo sesion: ' + (e && e.message)); }

    log('\n Leyendo la lista de pedidos...');
    await page.goto(ORDERS_URL, { waitUntil: 'domcontentloaded' }).catch(() => {});
    await esperarContenido(page);
    await esperarPedidos(page);
    let lista = await leerLista(page);
    fs.writeFileSync(path.join(DATA_DIR, 'pedidos_lista.json'), JSON.stringify(lista, null, 2));

    if (!lista.length) {
        // Volcado de emergencia para depurar y ultimo intento tras un respiro.
        try { fs.writeFileSync(path.join(DATA_DIR, 'pedidos_lista_debug.txt'), await page.evaluate(() => document.body.innerText || '')); } catch { /* noop */ }
        await page.waitForTimeout(4000);
        lista = await leerLista(page);
    }

    if (!lista.length) {
        log(' No pude leer la lista de pedidos automaticamente.');
        log(' En el navegador, asegurate de estar en "Mis pedidos" y que se vean las tarjetas.');
        await esperarEnter(' Cuando veas tus pedidos en pantalla, presiona ENTER... ');
        lista = await leerLista(page);
    }

    if (!lista.length) {
        log(' Sigo sin poder leer la lista. Revisa scripts/.mayoreo/pedidos_lista_debug.txt y mandamelo.');
        await esperarEnter(' ENTER para cerrar... ');
        await browser.close();
        return;
    }

    log(` Pedidos encontrados: ${lista.map((p) => p.numero + (p.estatus ? ' (' + p.estatus + ')' : '')).join(', ')}`);

    let objetivo = lista;
    if (PEDIDO) objetivo = lista.filter((p) => p.numero.toUpperCase() === PEDIDO);
    else if (!TODOS) objetivo = lista.slice(0, 1);

    if (!objetivo.length) {
        log(` No encontre el pedido #${PEDIDO} en la lista.`);
        await esperarEnter(' ENTER para cerrar... ');
        await browser.close();
        return;
    }

    const resultados = [];
    for (const p of objetivo) {
        log(`\n Abriendo #${p.numero}...`);
        await abrirDetalle(page, p.numero, lista);
        const det = await leerDetalle(page);
        if (!det.numero) det.numero = p.numero;
        det.estatus = p.estatus || '';
        det.total_lista_txt = p.total_txt || '';
        det.articulos_lista = p.articulos || 0;
        const file = path.join(DATA_DIR, `pedido-${det.numero}.json`);
        fs.writeFileSync(file, JSON.stringify(det, null, 2));
        resultados.push(det);
        log(`   ${det.items.length} renglon(es) -> ${file}`);
        for (const it of det.items) {
            log(`     - ${it.nombre}${it.presentacion ? ' [' + it.presentacion + ']' : ''}  x${it.cantidad}  $${it.precio_unitario}`);
        }
    }

    fs.writeFileSync(path.join(DATA_DIR, 'pedidos.json'), JSON.stringify(resultados, null, 2));

    log('\n==========================================================');
    log(` Listo. ${resultados.length} pedido(s) en scripts/.mayoreo/pedido-*.json`);
    log(' Pasale a Claude esos archivos (o dile que los lea de esa carpeta).');
    log('==========================================================\n');
    await esperarEnter(' ENTER para cerrar el navegador... ');
    await browser.close();
}

main().catch((e) => { console.error('\n ERROR:', e && e.stack ? e.stack : e); process.exit(1); });
