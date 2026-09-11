// @ts-check
/**
 * Reconocimiento del sitio mayoreo.blife.mx: recorre los pasos que necesita el
 * llenado de carrito y en CADA uno deja HTML + captura + un JSON con los
 * elementos interactivos (botones, inputs, tarjetas clicables, resultados de
 * busqueda, stepper de cantidad, boton "Agregar al carrito", variantes).
 *
 * Corre esto UNA vez, haz el login, y sigue las instrucciones (te pide ENTER en
 * cada punto). Luego pasale a Claude la carpeta scripts/.mayoreo/recon-<fecha>/
 *
 *   node scripts/mayoreo_recon.mjs
 */

import { chromium } from 'playwright';
import fs from 'node:fs';
import path from 'node:path';
import readline from 'node:readline';
import { fileURLToPath } from 'node:url';

const __dirname = path.dirname(fileURLToPath(import.meta.url));
const BASE = 'https://mayoreo.blife.mx';
const DATA_DIR = path.join(__dirname, '.mayoreo');
const SESSION_FILE = path.join(DATA_DIR, 'session.json');
const stamp = new Date().toISOString().replace(/[:.]/g, '-').slice(0, 19);
const OUT = path.join(DATA_DIR, `recon-${stamp}`);

const log = (...a) => console.log(...a);
function enter(m) {
    const rl = readline.createInterface({ input: process.stdin, output: process.stdout });
    return new Promise((r) => rl.question(m, () => { rl.close(); r(undefined); }));
}

// --- Extractor de elementos interactivos de la pagina actual ---
const EXTRACT = () => {
    const short = (s) => String(s || '').replace(/\s+/g, ' ').trim().slice(0, 120);
    const cls = (el) => (typeof el.className === 'string' ? el.className : '').split(/\s+/).filter(Boolean).slice(0, 8).join(' ');
    const box = (el) => { const r = el.getBoundingClientRect(); return { x: Math.round(r.x), y: Math.round(r.y), w: Math.round(r.width), h: Math.round(r.height), vis: r.width > 0 && r.height > 0 }; };
    const cssPath = (el) => {
        const parts = [];
        let n = el;
        for (let d = 0; n && n.nodeType === 1 && d < 5; d++, n = n.parentElement) {
            let s = n.tagName.toLowerCase();
            if (n.id) { s += '#' + n.id; parts.unshift(s); break; }
            const c = (typeof n.className === 'string' ? n.className : '').split(/\s+/).filter(Boolean)[0];
            if (c) s += '.' + CSS.escape(c);
            const sib = n.parentElement ? Array.from(n.parentElement.children).filter((x) => x.tagName === n.tagName) : [];
            if (sib.length > 1) s += `:nth-of-type(${sib.indexOf(n) + 1})`;
            parts.unshift(s);
        }
        return parts.join(' > ');
    };

    const out = { url: location.href, title: document.title };

    out.buttons = Array.from(document.querySelectorAll('button')).slice(0, 60).map((b) => ({
        text: short(b.textContent), aria: b.getAttribute('aria-label') || '', type: b.getAttribute('type') || '',
        disabled: b.disabled, hasSvg: !!b.querySelector('svg'), svgPath: short(b.querySelector('svg path')?.getAttribute('d') || ''),
        cls: cls(b), box: box(b), path: cssPath(b),
    }));

    out.inputs = Array.from(document.querySelectorAll('input, textarea, [role="spinbutton"], [contenteditable="true"]')).slice(0, 30).map((i) => ({
        tag: i.tagName.toLowerCase(), type: i.getAttribute('type') || '', inputmode: i.getAttribute('inputmode') || '',
        placeholder: i.getAttribute('placeholder') || '', name: i.getAttribute('name') || '', value: short(i.value ?? ''),
        cls: cls(i), box: box(i), path: cssPath(i),
    }));

    // Tarjetas clicables (imagen de producto + su contenedor).
    out.cards = Array.from(document.querySelectorAll('img[alt]')).slice(0, 40).map((im) => {
        const alt = im.getAttribute('alt') || '';
        let clicker = im;
        for (let d = 0; d < 6 && clicker; d++, clicker = clicker.parentElement) {
            if (clicker !== im && /cursor-pointer|border-brand/.test('' + clicker.className)) break;
        }
        return {
            alt: short(alt),
            imgSrc: short(im.getAttribute('src') || '').slice(0, 90),
            clickerTag: clicker ? clicker.tagName.toLowerCase() : '',
            clickerCls: clicker ? cls(clicker) : '',
            clickerPath: clicker ? cssPath(clicker) : '',
            clickerHasOnclick: clicker ? !!clicker.onclick : false,
        };
    }).filter((c) => /principal|producto/i.test(c.alt) || c.alt.length > 3);

    // Links a /producto/
    out.productLinks = Array.from(document.querySelectorAll('a[href*="/producto/"]')).slice(0, 20).map((a) => ({
        href: a.getAttribute('href'), text: short(a.textContent),
    }));

    // Texto plano (recortado) para ver el contenido.
    out.innerTextSample = short(document.body.innerText).slice(0, 0) || document.body.innerText.slice(0, 4000);

    return out;
};

async function dump(page, n, nombre) {
    const base = `${String(n).padStart(2, '0')}_${nombre}`;
    try { fs.writeFileSync(path.join(OUT, base + '.html'), await page.content()); } catch { /* */ }
    try { await page.screenshot({ path: path.join(OUT, base + '.png'), fullPage: true }); } catch { /* */ }
    try {
        const data = await page.evaluate(EXTRACT);
        fs.writeFileSync(path.join(OUT, base + '.json'), JSON.stringify(data, null, 2));
        log(`   [${base}]  ${data.url}  (${data.buttons.length} botones, ${data.inputs.length} inputs, ${data.cards.length} tarjetas)`);
    } catch (e) { log('   (no se pudo extraer: ' + (e && e.message) + ')'); }
}

async function settle(page) {
    await page.waitForLoadState('networkidle', { timeout: 15000 }).catch(() => {});
    await page.waitForFunction(() => document.querySelectorAll('.css-wot6g1').length === 0, { timeout: 10000 }).catch(() => {});
    await page.waitForTimeout(2000);
}

async function main() {
    fs.mkdirSync(OUT, { recursive: true });
    log('\n== Reconocimiento mayoreo.blife.mx ==');
    log('Carpeta: ' + OUT + '\n');

    const browser = await chromium.launch({ headless: false, args: ['--start-maximized'] });
    const context = await browser.newContext({ viewport: null, storageState: fs.existsSync(SESSION_FILE) ? SESSION_FILE : undefined });
    const page = await context.newPage();

    await page.goto(BASE + '/dashboard', { waitUntil: 'domcontentloaded' }).catch(() => {});
    await page.waitForTimeout(2500);
    await enter(' 1) Haz login si te lo pide. Cuando estes DENTRO, ENTER... ');
    try { await context.storageState({ path: SESSION_FILE }); } catch { /* */ }

    await settle(page);
    await dump(page, 1, 'dashboard');

    log('\n Yendo a la home /...');
    await page.goto(BASE + '/', { waitUntil: 'domcontentloaded' }).catch(() => {});
    await settle(page);
    await dump(page, 2, 'home');

    // Buscar "Ashwagandha" automaticamente.
    log('\n Escribiendo "Ashwagandha" en el buscador...');
    let box = page.getByPlaceholder(/buscar productos|encuentra/i).first();
    if (!(await box.count().catch(() => 0))) box = page.locator('header input, input[type="text"]').first();
    await box.click().catch(() => {});
    await box.pressSequentially('Ashwagandha', { delay: 80 }).catch(() => {});
    await page.waitForTimeout(2800);
    await settle(page);
    await dump(page, 3, 'home_busqueda_ashwagandha');

    await enter('\n 2) MIRA el navegador. Si NO aparecieron resultados de "Ashwagandha", escribelo tu\n    en el buscador y espera. Cuando veas los resultados, ENTER... ');
    await settle(page);
    await dump(page, 4, 'busqueda_resultados_manual');

    await enter('\n 3) Ahora HAZ CLIC tu en el resultado "Ashwagandha" para entrar a su detalle.\n    Cuando cargue la pagina del producto, ENTER... ');
    await settle(page);
    await dump(page, 5, 'producto_detalle_simple');

    await enter('\n 4) Ve a un producto CON VARIANTES (busca "Citrate Mag" y entra). En la pagina\n    del producto, ENTER (sin elegir variante todavia)... ');
    await settle(page);
    await dump(page, 6, 'producto_con_variantes');

    await enter('\n 5) Elige la variante de 120 (o la que sea) y sube la cantidad a 3 con el "+".\n    Cuando lo tengas, ENTER... ');
    await settle(page);
    await dump(page, 7, 'producto_variante_y_cantidad');

    await enter('\n 6) Dale "Agregar al carrito". Cuando se agregue, ENTER... ');
    await settle(page);
    await dump(page, 8, 'tras_agregar');

    log('\n Yendo al carrito...');
    await page.goto(BASE + '/cart', { waitUntil: 'domcontentloaded' }).catch(() => {});
    await settle(page);
    await dump(page, 9, 'carrito');

    log('\n== LISTO ==');
    log(' Pasale a Claude TODA la carpeta:');
    log('   ' + OUT);
    log(' (los .json son lo mas util; tambien sirven los .png)');
    await enter('\n ENTER para cerrar... ');
    await browser.close();
}

main().catch((e) => { console.error('\n ERROR:', e && e.stack ? e.stack : e); process.exit(1); });
