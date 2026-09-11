// @ts-check
/**
 * UN SOLO COMANDO para llenar el carrito de mayoreo.blife.mx. NO paga: deja el
 * carrito listo y el navegador abierto para que TÚ revises y pagues.
 *
 *   node scripts/mayoreo_llenar_carrito.mjs --orden OC-20260910-193145-1   (o el id numerico)
 *   node scripts/mayoreo_llenar_carrito.mjs --almacen 1
 *   node scripts/mayoreo_llenar_carrito.mjs --items "BLIFE-APP-017:6,BLIFE-CITMAG-120:4"
 *   node scripts/mayoreo_llenar_carrito.mjs --admin        (todas las sucursales)
 *   node scripts/mayoreo_llenar_carrito.mjs --fresh        (forzar login de cero)
 *
 * --orden es lo normal despues de "Generar Orden de Compra" en la Lista de Compra:
 * esa orden ya no aparece en la lista sugerida (se marca como ya pedida), asi que
 * hay que decirle explicitamente cual orden llenar.
 *
 * Hace TODO solo:
 *   1) arma la lista (de la orden, de la Lista de Compra Sugerida, o de --items) llamando a PHP
 *   2) por cada renglon: abre el producto (URL guardada o busca), elige la
 *      variante por nombre_variante, pone la cantidad, "Agregar al carrito";
 *      si esta agotado lo marca y sigue
 *   3) guarda las URLs que aprendio en productos.mayoreo_url (llama a PHP)
 *
 * Requiere node_modules/playwright + Chromium de Playwright, y C:\\xampp\\php\\php.exe.
 */

import { chromium } from 'playwright';
import fs from 'node:fs';
import path from 'node:path';
import readline from 'node:readline';
import { spawnSync } from 'node:child_process';
import { fileURLToPath } from 'node:url';

const __dirname = path.dirname(fileURLToPath(import.meta.url));
const BASE = 'https://mayoreo.blife.mx';
const DATA_DIR = path.join(__dirname, '.mayoreo');
const SESSION_FILE = path.join(DATA_DIR, 'session.json');
const CARRITO_FILE = path.join(DATA_DIR, 'carrito.json');
const FRESH = process.argv.includes('--fresh');

// --- Ruta a PHP (XAMPP en Windows; con respaldo al "php" del PATH) ---
const PHP = [
    'C:\\xampp\\php\\php.exe',
    '/c/xampp/php/php.exe',
    'php',
].find((p) => p === 'php' || fs.existsSync(p)) || 'php';

/** Corre un script PHP del proyecto mostrando su salida. Devuelve true si exit 0. */
function runPhp(scriptRel, extraArgs) {
    const script = path.join(__dirname, scriptRel);
    const r = spawnSync(PHP, [script, ...extraArgs], { stdio: 'inherit', cwd: path.join(__dirname, '..') });
    return r.status === 0;
}

/** Reenvia --orden / --items / --almacen / --admin al armador de lista. */
function argsParaLista() {
    const a = process.argv.slice(2);
    const out = [];
    const o = a.indexOf('--orden');
    if (o >= 0 && a[o + 1]) out.push('--orden', a[o + 1]);
    const i = a.indexOf('--items');
    if (i >= 0 && a[i + 1]) out.push('--items', a[i + 1]);
    const j = a.indexOf('--almacen');
    if (j >= 0 && a[j + 1]) out.push('--almacen', a[j + 1]);
    if (a.includes('--admin')) out.push('--admin');
    return out;
}

const stamp = new Date().toISOString().replace(/[:.]/g, '-').slice(0, 19);
const RUN_DIR = path.join(DATA_DIR, `carrito-run-${stamp}`);

const log = (...a) => console.log(...a);
/** @param {string} m */
function enter(m) {
    const rl = readline.createInterface({ input: process.stdin, output: process.stdout });
    return new Promise((r) => rl.question(m, () => { rl.close(); r(undefined); }));
}
const shot = async (page, name) => { try { await page.screenshot({ path: path.join(RUN_DIR, name + '.png'), fullPage: true }); } catch { /* */ } };
const nombreBusqueda = (n) => String(n || '').split('|')[0].split('.')[0].trim() || String(n || '').trim();
const primerNumero = (s) => { const m = String(s || '').match(/\d{1,4}/); return m ? m[0] : ''; };

async function contentoListo(page) {
    await page.waitForLoadState('networkidle', { timeout: 20000 }).catch(() => {});
    await page.waitForFunction(() => document.querySelectorAll('.css-wot6g1').length === 0, { timeout: 12000 }).catch(() => {});
    await page.waitForTimeout(1500);
}

/** Lee el numero del badge del carrito (boton .lg:w-10 en el header, texto = numero). */
async function cartCount(page) {
    try {
        return await page.evaluate(() => {
            const btns = Array.from(document.querySelectorAll('button'));
            for (const b of btns) {
                const t = (b.textContent || '').trim();
                if (/^\d{1,3}$/.test(t) && /w-10|cart|carrito/i.test('' + b.className) && b.getBoundingClientRect().top < 120) {
                    return parseInt(t, 10);
                }
            }
            // Respaldo: "N Articulo(s)" en la pagina del carrito.
            const m = (document.body.innerText || '').match(/(\d+)\s*Art[ií]culo/i);
            return m ? parseInt(m[1], 10) : null;
        });
    } catch { return null; }
}

async function abrirProducto(page, row) {
    if (row.mayoreo_url && /\/producto\//.test(row.mayoreo_url)) {
        await page.goto(row.mayoreo_url, { waitUntil: 'domcontentloaded' }).catch(() => {});
        await contentoListo(page);
        return /\/producto\//.test(page.url());
    }

    // --- Buscar via URL directa de resultados: /search?busqueda=<nombre> ---
    const q = nombreBusqueda(row.nombre);
    const slug = row.sku.replace(/[^\w-]+/g, '_');
    // Los numeros se conservan aunque sean cortos (60/80/200 Billion Probiotics
    // son productos DISTINTOS: el numero es la señal que mas importa).
    const tokens = q.toLowerCase().split(/\s+/).filter((t) => t.length >= 3 || /^\d+$/.test(t));

    await page.goto(BASE + '/search?busqueda=' + encodeURIComponent(q), { waitUntil: 'domcontentloaded' }).catch(() => {});
    await contentoListo(page);
    await page.waitForFunction(
        () => document.querySelectorAll('img[alt*="Imagen principal" i]').length > 0,
        { timeout: 12000 }
    ).catch(() => {});
    await page.waitForTimeout(1200);

    try { fs.writeFileSync(path.join(RUN_DIR, `search_${slug}.html`), await page.content()); } catch { /* */ }
    await shot(page, `search_${slug}`);

    const clickResultado = () => page.evaluate((tks) => {
        const imgs = Array.from(document.querySelectorAll('img[alt]'));
        const score = (a) => tks.reduce((s, t) => s + (a.includes(t) ? 1 : 0), 0);
        // B Life a veces nombra la imagen mas corto que el nombre del catalogo
        // (ej. catalogo "80 Billion Probiotics Platinum", alt solo "80 Billion
        // Probiotics"). Se acepta el MEJOR match parcial, no solo el exacto;
        // minimo la mitad de las palabras (o al menos 1). Empate: "principal"
        // sobre "secundaria", y el alt mas corto (mas especifico) gana.
        const minimo = Math.max(1, Math.ceil(tks.length / 2));
        let best = null; let bestScore = 0; let bestAlt = '';
        for (const im of imgs) {
            const a = (im.getAttribute('alt') || '').toLowerCase();
            if (!/imagen (principal|secundaria)|producto/.test(a)) continue;
            const sc = score(a);
            if (sc < minimo) continue;
            const mejorQue = sc > bestScore
                || (sc === bestScore && /principal/.test(a) && !/principal/.test(bestAlt))
                || (sc === bestScore && a.length < bestAlt.length);
            if (mejorQue) { best = im; bestScore = sc; bestAlt = a; }
        }
        if (!best) return 'no-result';
        let el = best;
        for (let k = 0; k < 8 && el; k++) {
            if (el !== best && typeof el.className === 'string' && /(aspect-1|cursor-pointer|border-brand)/.test(el.className)) break;
            el = el.parentElement;
        }
        const objetivo = el || best;
        objetivo.scrollIntoView({ block: 'center' });
        objetivo.dispatchEvent(new MouseEvent('click', { bubbles: true, cancelable: true, view: window }));
        return 'ok:' + (objetivo.className || objetivo.tagName);
    }, tokens).catch(() => 'err');

    for (let intento = 0; intento < 3; intento++) {
        const r = await clickResultado();
        await page.waitForTimeout(2000);
        await contentoListo(page);
        if (/\/producto\//.test(page.url())) return true;
        if (r === 'no-result') await page.waitForTimeout(1500);
    }
    return false;
}

/**
 * Elige la variante cuyo texto trae el numero de nombre_variante (60 / 120 / 200).
 * Las variantes son tarjetas en un carrusel con texto tipo "120 Caps" + un precio.
 * Solo se toca una tarjeta corta que combine el numero con Caps/tomas/porciones/ml.
 */
async function elegirVariante(page, nombreVariante) {
    const numero = primerNumero(nombreVariante);
    if (!numero) return;
    try {
        await page.evaluate((num) => {
            const re = new RegExp('\\b' + num + '\\b\\s*(caps|c[aá]psulas|tomas|porciones|ml|pz|piezas)', 'i');
            const nodos = Array.from(document.querySelectorAll('div,button,span,label,p'));
            for (const el of nodos) {
                const t = (el.textContent || '').replace(/\s+/g, ' ').trim();
                if (t.length > 60 || !re.test(t)) continue;
                // Sube al contenedor clicable de la tarjeta de variante.
                let c = el;
                for (let k = 0; k < 4 && c; k++, c = c.parentElement) {
                    if (/cursor-pointer|border|rounded/.test('' + (c.className || ''))) break;
                }
                (c || el).dispatchEvent(new MouseEvent('click', { bubbles: true, cancelable: true, view: window }));
                return;
            }
        }, numero);
        await page.waitForTimeout(900);
    } catch { /* */ }
}

/**
 * Pone la cantidad en el stepper del producto. Estructura real de B Life:
 *   <div class="flex items-center gap-1 ...">
 *     <button disabled><svg d="M5 12h14"/></button>       (-)
 *     <input inputmode="tel" type="text" value="1">
 *     <button><svg d="M12 4.5v15m7.5-7.5h-15"/></button>  (+)
 *   </div>
 * El input es controlado por React: fill() no siempre pega, asi que se usa el "+".
 */
async function ponerCantidad(page, cantidad) {
    if (cantidad <= 1) return 1;

    const input = page.locator('input[inputmode="tel"]').first();
    if (!(await input.count().catch(() => 0))) return await qtyDeInput(page) ?? 1;

    // Botones exactos por el "d" del <path> del svg (recon):  +  =  M12 4.5v15m7.5-7.5h-15
    const masBtn = page.locator('button:has(svg path[d="M12 4.5v15m7.5-7.5h-15"])').first();

    // Intento A: escribir en el input.
    try {
        await input.click();
        await input.press('Control+A');
        await input.press('Delete');
        await input.pressSequentially(String(cantidad), { delay: 60 });
        await input.press('Tab');
        await page.waitForTimeout(500);
        if ((await qtyDeInput(page)) === cantidad) return cantidad;
    } catch { /* */ }

    // Intento B: clic en "+" (svg exacto) hasta llegar.
    if (await masBtn.count().catch(() => 0)) {
        for (let guard = 0; guard < cantidad + 4; guard++) {
            const actual = await qtyDeInput(page);
            if (actual !== null && actual >= cantidad) break;
            await masBtn.click({ timeout: 2500 }).catch(() => {});
            await page.waitForTimeout(240);
        }
    }
    return await qtyDeInput(page) ?? 1;
}

/** Lee el valor del input de cantidad del stepper. */
async function qtyDeInput(page) {
    try {
        const v = await page.locator('input[inputmode="tel"]').first().inputValue();
        const n = parseInt(v, 10);
        return Number.isFinite(n) ? n : null;
    } catch { return null; }
}

async function main() {
    // 1) Armar la lista (PHP). Si no se pasa --items ni --almacen ni --admin y no
    //    hay carrito.json previo, avisa.
    const listaArgs = argsParaLista();
    if (listaArgs.length > 0 || !fs.existsSync(CARRITO_FILE)) {
        log('== Paso 1: armar la lista de compra ==');
        const okLista = runPhp('mayoreo_carrito_desde_lista.php', listaArgs.length ? listaArgs : ['--almacen', '1']);
        if (!okLista) {
            console.error('\n No se pudo armar la lista. Pasa --items "SKU:CANT,..." o --almacen N.');
            process.exit(1);
        }
    }

    if (!fs.existsSync(CARRITO_FILE)) {
        console.error(` No existe ${CARRITO_FILE}.`);
        process.exit(1);
    }
    const carrito = JSON.parse(fs.readFileSync(CARRITO_FILE, 'utf8'));
    if (!Array.isArray(carrito) || carrito.length === 0) {
        console.error(' La lista quedo vacia (nada bajo el minimo, o SKUs invalidos).');
        process.exit(1);
    }
    fs.mkdirSync(RUN_DIR, { recursive: true });

    log('\n==========================================================');
    log(` Llenar carrito B Life Mayoreo  (${carrito.length} renglones)`);
    log('==========================================================\n');

    const browser = await chromium.launch({ headless: false, args: ['--start-maximized'] });
    const context = await browser.newContext({ viewport: null, storageState: (!FRESH && fs.existsSync(SESSION_FILE)) ? SESSION_FILE : undefined });
    const page = await context.newPage();

    // Se arranca en /dashboard (area de mayorista logueado): sirve de chequeo de
    // sesion y trae el buscador del header.
    await page.goto(BASE + '/dashboard', { waitUntil: 'domcontentloaded' }).catch(() => {});
    await page.waitForTimeout(2500);
    log(' Si te pide login: Correo -> codigo de 6 digitos. Si entraste directo, nada.');
    await enter(' Cuando estes DENTRO, presiona ENTER para empezar a llenar el carrito... ');
    try { await context.storageState({ path: SESSION_FILE }); } catch { /* */ }

    // Deja que el cliente re-reconozca la sesion antes de empezar.
    await contentoListo(page);
    await page.waitForTimeout(1500);

    const reporte = [];
    /** @type {Record<string,string>} */
    const urls = fs.existsSync(path.join(DATA_DIR, 'mayoreo_urls.json'))
        ? JSON.parse(fs.readFileSync(path.join(DATA_DIR, 'mayoreo_urls.json'), 'utf8')) : {};

    for (let i = 0; i < carrito.length; i++) {
        const row = carrito[i];
        const tag = `[${i + 1}/${carrito.length}] ${row.sku}  ${nombreBusqueda(row.nombre)}  x${row.cantidad}`;
        log('\n' + tag);
        const res = { sku: row.sku, nombre: row.nombre, cantidad: row.cantidad, estado: '', url: '', precio_txt: '', nota: '' };

        try {
            const abierto = await abrirProducto(page, row);
            if (!abierto || !/\/producto\//.test(page.url())) {
                res.estado = 'NO_ENCONTRADO';
                res.nota = 'no llegue a una pagina de producto';
                await shot(page, `${String(i + 1).padStart(2, '0')}_${row.sku}_noencontrado`);
                reporte.push(res); log('   -> NO ENCONTRADO'); continue;
            }
            res.url = page.url();
            urls[row.sku] = res.url;
            try { fs.writeFileSync(path.join(RUN_DIR, `producto_${String(i + 1).padStart(2, '0')}_${row.sku}.html`), await page.content()); } catch { /* */ }

            const bodyTxt = (await page.evaluate(() => document.body.innerText || '')) || '';
            const hayAgregar = await page.getByRole('button', { name: /agregar al carrito/i }).count().catch(() => 0);
            const hayProximamente = await page.getByRole('button', { name: /pr[oó]ximamente/i }).count().catch(() => 0);
            if (!hayAgregar && (hayProximamente || /agotado|sin stock|no disponible/i.test(bodyTxt))) {
                res.estado = 'AGOTADO';
                res.nota = hayProximamente ? 'boton dice "Proximamente" (esa presentacion aun no esta a la venta)' : '';
                await shot(page, `${String(i + 1).padStart(2, '0')}_${row.sku}_agotado`);
                reporte.push(res); log(`   -> AGOTADO${res.nota ? ' (' + res.nota + ')' : ''}`); continue;
            }

            // precio mayorista (reporte)
            const mPrecio = bodyTxt.match(/\$\s*[\d,]+\.\d{2}\s*\n?\s*Mayorista/i) || bodyTxt.match(/Mayorista\s*\n?\s*\$\s*[\d,]+\.\d{2}/i);
            if (mPrecio) res.precio_txt = mPrecio[0].replace(/\s+/g, ' ').trim();

            await elegirVariante(page, row.nombre_variante);
            await ponerCantidad(page, row.cantidad);

            const antes = await cartCount(page);
            let btn = page.getByRole('button', { name: 'Agregar al carrito', exact: true }).first();
            if (!(await btn.count().catch(() => 0))) btn = page.getByRole('button', { name: /agregar al carrito/i }).first();
            if (!(await btn.count().catch(() => 0))) {
                res.estado = 'SIN_BOTON';
                res.nota = 'no encontre "Agregar al carrito"';
                await shot(page, `${String(i + 1).padStart(2, '0')}_${row.sku}_sinboton`);
                reporte.push(res); log('   -> SIN BOTON AGREGAR'); continue;
            }
            await btn.scrollIntoViewIfNeeded().catch(() => {});
            await btn.click({ timeout: 8000 }).catch(() => {});
            await page.waitForTimeout(2200);
            const despues = await cartCount(page);
            res.cantidad_puesta = await qtyDeInput(page);

            res.estado = (antes !== null && despues !== null && despues > antes) ? 'AGREGADO' : 'AGREGADO?';
            if (res.estado === 'AGREGADO?') res.nota = `no pude confirmar por el badge (antes=${antes}, despues=${despues})`;
            await shot(page, `${String(i + 1).padStart(2, '0')}_${row.sku}_ok`);
            log(`   -> ${res.estado}  ${res.precio_txt}`);
        } catch (e) {
            res.estado = 'ERROR';
            res.nota = (e && e.message) ? e.message : String(e);
            await shot(page, `${String(i + 1).padStart(2, '0')}_${row.sku}_error`);
            log('   -> ERROR: ' + res.nota);
        }
        reporte.push(res);
    }

    // Ir al carrito.
    for (const p of ['/cart', '/carrito', '/checkout']) {
        await page.goto(BASE + p, { waitUntil: 'domcontentloaded' }).catch(() => {});
        await contentoListo(page);
        if (/carrito|resumen|checkout|subtotal/i.test((await page.evaluate(() => document.body.innerText || '')) || '')) break;
    }
    await shot(page, '99_carrito_final');

    fs.writeFileSync(path.join(DATA_DIR, 'mayoreo_urls.json'), JSON.stringify(urls, null, 2));
    fs.writeFileSync(path.join(RUN_DIR, 'reporte.json'), JSON.stringify(reporte, null, 2));

    // 3) Guardar en el catalogo las URLs que se aprendieron (para que la proxima
    //    corrida vaya directa sin buscar).
    log('\n== Paso 3: guardar URLs aprendidas en el catalogo ==');
    runPhp('mayoreo_guardar_urls.php', ['--aplicar']);

    const c = (s) => reporte.filter((r) => r.estado === s).length;
    log('\n==========================================================');
    log(` AGREGADO: ${c('AGREGADO') + c('AGREGADO?')}   AGOTADO: ${c('AGOTADO')}   PROBLEMAS: ${c('NO_ENCONTRADO') + c('SIN_BOTON') + c('ERROR')}`);
    log(` Reporte y capturas: ${RUN_DIR}`);
    log('');
    log(' REVISA EL CARRITO en el navegador y paga TÚ.');
    log('==========================================================\n');
    await enter(' ENTER para cerrar el navegador (deja la pestaña si vas a pagar)... ');
    await browser.close();
}

main().catch((e) => { console.error('\n ERROR:', e && e.stack ? e.stack : e); process.exit(1); });
