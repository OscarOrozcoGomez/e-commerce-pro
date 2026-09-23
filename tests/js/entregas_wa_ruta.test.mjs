// Pruebas del aviso de WhatsApp de la ruta en views/entregas.php (routeBuildWaMessage y el
// handler del checkbox "no cobrar el envio"). Extrae las funciones REALES del archivo PHP y las
// corre en node con un DOM minimo falso. Se ejecuta desde tests/Unit/EntregasWaRutaJsTest.php.
import { test } from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { fileURLToPath } from 'node:url';
import vm from 'node:vm';

const source = readFileSync(fileURLToPath(new URL('../../views/entregas.php', import.meta.url)), 'utf8');

function extraerFuncion(nombre) {
    const inicio = source.indexOf(`function ${nombre}(`);
    assert.notEqual(inicio, -1, `no se encontro function ${nombre} en entregas.php`);
    let i = source.indexOf('{', inicio);
    let nivel = 0;
    for (; i < source.length; i++) {
        if (source[i] === '{') nivel++;
        if (source[i] === '}' && --nivel === 0) break;
    }
    return source.slice(inicio, i + 1);
}

const FUNCIONES = ['routeSafeText', 'routeFormatEtaHora', 'routeFormatMoney', 'routeBuildWaMessage', 'routeActualizarWaPorEnvio'];

// Contexto aislado por prueba: estado global limpio (routeEnvioQuitado, etc.).
function crearContexto({ datosVivos = {}, paradas = {}, links = {} } = {}) {
    const aplicados = [];
    const ctx = {
        routeDatosVivos: datosVivos,
        routeEnvioQuitado: {},
        routeUltimasParadas: paradas,
        CSS: { escape: (s) => String(s).replace(/["\\]/g, '\\$&') },
        document: {
            querySelector: (sel) => {
                const m = /data-route-pedido="(.*)"\]$/.exec(sel);
                return m ? (links[m[1]] || null) : null;
            },
        },
        window: { waApplyBusinessLinks: (root) => aplicados.push(root) },
        encodeURIComponent,
        aplicados,
    };
    vm.createContext(ctx);
    vm.runInContext(FUNCIONES.map(extraerFuncion).join('\n\n'), ctx);
    return ctx;
}

function linkFalso(phone = '523312345678') {
    const attrs = { 'data-wa-phone': phone };
    return {
        attrs,
        parentElement: { soy: 'div' },
        getAttribute: (k) => (k in attrs ? attrs[k] : null),
        setAttribute: (k, v) => { attrs[k] = String(v); },
    };
}

function checkbox({ clase = 'cambio-quitar-envio', idPedido = '10', checked = true } = {}) {
    return {
        checked,
        matches: (sel) => sel.split(',').some((s) => s.trim() === '.' + clase),
        getAttribute: (k) => (k === 'data-id-pedido' ? idPedido : null),
    };
}

const paradaGuardada = {
    id_pedido: 10,
    numero_pedido: 'PED-10',
    eta_estimada: '2026-09-23 14:35:00',
    total: 500,
    productos: [{ nombre: 'Omega 3', cantidad: 1 }, { nombre: 'Colageno', cantidad: 2 }],
};

const vivo10 = { productos: [{ nombre: 'Omega 3', cantidad: 1 }], total: 350, costo_envio: 50 };

// --- routeBuildWaMessage ---------------------------------------------------------------

test('usa productos y total vivos en lugar de los guardados con la ruta', () => {
    const ctx = createCtx10();
    const msg = ctx.routeBuildWaMessage(paradaGuardada);
    assert.match(msg, /• 1x Omega 3/);
    assert.doesNotMatch(msg, /Colageno/);
    assert.match(msg, /Total: \$350\.00/);
    assert.doesNotMatch(msg, /\$500/);
    assert.match(msg, /Hora estimada de entrega: 14:35 hrs\./);
});

test('sin datos vivos del pedido cae a lo guardado con la ruta (comportamiento anterior)', () => {
    const ctx = crearContexto();
    const msg = ctx.routeBuildWaMessage(paradaGuardada);
    assert.match(msg, /2x Colageno/);
    assert.match(msg, /Total: \$500\.00/);
});

test('con envio quitado resta el costo de envio del total', () => {
    const ctx = createCtx10();
    ctx.routeEnvioQuitado['10'] = true;
    assert.match(ctx.routeBuildWaMessage(paradaGuardada), /Total: \$300\.00/);
});

test('envio quitado sin datos vivos no inventa descuento', () => {
    const ctx = crearContexto();
    ctx.routeEnvioQuitado['10'] = true;
    assert.match(ctx.routeBuildWaMessage(paradaGuardada), /Total: \$500\.00/);
});

test('envio quitado con costo_envio 0 deja el total igual', () => {
    const ctx = crearContexto({ datosVivos: { 10: { ...vivo10, costo_envio: 0 } } });
    ctx.routeEnvioQuitado['10'] = true;
    assert.match(ctx.routeBuildWaMessage(paradaGuardada), /Total: \$350\.00/);
});

test('costo de envio mayor al total nunca da total negativo', () => {
    const ctx = crearContexto({ datosVivos: { 10: { ...vivo10, total: 30, costo_envio: 50 } } });
    ctx.routeEnvioQuitado['10'] = true;
    assert.match(ctx.routeBuildWaMessage(paradaGuardada), /Total: \$0\.00/);
});

test('decimales: 350.10 - 50.05 = 300.05 sin basura de punto flotante', () => {
    const ctx = crearContexto({ datosVivos: { 10: { ...vivo10, total: 350.1, costo_envio: 50.05 } } });
    ctx.routeEnvioQuitado['10'] = true;
    assert.match(ctx.routeBuildWaMessage(paradaGuardada), /Total: \$300\.05\n/);
});

test('el envio quitado de OTRO pedido no afecta a este', () => {
    const ctx = createCtx10();
    ctx.routeEnvioQuitado['11'] = true;
    assert.match(ctx.routeBuildWaMessage(paradaGuardada), /Total: \$350\.00/);
});

test('id_pedido numerico en la parada encuentra la llave string de los datos vivos', () => {
    const ctx = crearContexto({ datosVivos: JSON.parse('{"10": {"productos": [], "total": 99, "costo_envio": 0}}') });
    assert.match(ctx.routeBuildWaMessage({ ...paradaGuardada, id_pedido: 10 }), /Total: \$99\.00/);
});

test('datos vivos sin productos cae al numero de pedido, no a los productos viejos', () => {
    const ctx = crearContexto({ datosVivos: { 10: { productos: [], total: 350, costo_envio: 0 } } });
    const msg = ctx.routeBuildWaMessage(paradaGuardada);
    assert.match(msg, /tu pedido es el siguiente: PED-10\./i);
    assert.doesNotMatch(msg, /Colageno|Omega/);
});

test('sin ETA no pone la linea de hora pero si el aviso de horario aproximado', () => {
    const ctx = createCtx10();
    const msg = ctx.routeBuildWaMessage({ ...paradaGuardada, eta_estimada: null });
    assert.doesNotMatch(msg, /Hora estimada/);
    assert.match(msg, /Este horario es aproximado/);
});

test('nombre cifrado (ENCv1:) no se filtra al mensaje', () => {
    const ctx = crearContexto({ datosVivos: { 10: { productos: [{ nombre: 'ENCv1:abc', cantidad: 1 }], total: 1, costo_envio: 0 } } });
    const msg = ctx.routeBuildWaMessage(paradaGuardada);
    assert.doesNotMatch(msg, /ENCv1/);
    assert.match(msg, /1x Producto/);
});

// --- routeActualizarWaPorEnvio (checkbox "no cobrar el envio") -------------------------

test('palomear el checkbox rehace href y data-wa-text del link de esa parada sin el envio', () => {
    const link = linkFalso();
    const ctx = crearContexto({ datosVivos: { 10: vivo10 }, paradas: { 10: paradaGuardada }, links: { 10: link } });
    ctx.routeActualizarWaPorEnvio({ target: checkbox({ checked: true }) });
    assert.match(link.attrs['data-wa-text'], /Total: \$300\.00/);
    assert.ok(link.attrs.href.startsWith('https://wa.me/523312345678?text='));
    assert.match(decodeURIComponent(link.attrs.href.split('?text=')[1]), /Total: \$300\.00/);
    assert.equal(ctx.aplicados.length, 1, 'reaplica el link de WhatsApp Business (Android)');
});

test('despalomear vuelve a incluir el envio', () => {
    const link = linkFalso();
    const ctx = crearContexto({ datosVivos: { 10: vivo10 }, paradas: { 10: paradaGuardada }, links: { 10: link } });
    ctx.routeActualizarWaPorEnvio({ target: checkbox({ checked: true }) });
    ctx.routeActualizarWaPorEnvio({ target: checkbox({ checked: false }) });
    assert.match(link.attrs['data-wa-text'], /Total: \$350\.00/);
});

test('el checkbox del formulario de entrega (quitar-cargo-periferico) tambien cuenta', () => {
    const link = linkFalso();
    const ctx = crearContexto({ datosVivos: { 10: vivo10 }, paradas: { 10: paradaGuardada }, links: { 10: link } });
    ctx.routeActualizarWaPorEnvio({ target: checkbox({ clase: 'quitar-cargo-periferico', checked: true }) });
    assert.match(link.attrs['data-wa-text'], /Total: \$300\.00/);
});

test('otros checkboxes/inputs de la pagina se ignoran', () => {
    const link = linkFalso();
    const ctx = crearContexto({ datosVivos: { 10: vivo10 }, paradas: { 10: paradaGuardada }, links: { 10: link } });
    ctx.routeActualizarWaPorEnvio({ target: checkbox({ clase: 'otro-checkbox', checked: true }) });
    ctx.routeActualizarWaPorEnvio({ target: {} }); // p.ej. un nodo sin matches()
    assert.equal(link.attrs['data-wa-text'], undefined);
    assert.deepEqual({ ...ctx.routeEnvioQuitado }, {});
});

test('sin ruta generada todavia: recuerda el estado y lo aplica al generar la ruta', () => {
    const ctx = crearContexto({ datosVivos: { 10: vivo10 } });
    assert.doesNotThrow(() => ctx.routeActualizarWaPorEnvio({ target: checkbox({ checked: true }) }));
    assert.equal(ctx.routeEnvioQuitado['10'], true);
    assert.match(ctx.routeBuildWaMessage(paradaGuardada), /Total: \$300\.00/);
});

test('pedido que no esta en la ruta (sin link) no truena', () => {
    const ctx = crearContexto({ datosVivos: { 10: vivo10 }, paradas: { 10: paradaGuardada }, links: {} });
    assert.doesNotThrow(() => ctx.routeActualizarWaPorEnvio({ target: checkbox({ checked: true }) }));
    assert.equal(ctx.aplicados.length, 0);
});

test('checkbox sin data-id-pedido no truena ni toca links', () => {
    const link = linkFalso();
    const ctx = crearContexto({ datosVivos: { 10: vivo10 }, paradas: { 10: paradaGuardada }, links: { 10: link } });
    assert.doesNotThrow(() => ctx.routeActualizarWaPorEnvio({ target: checkbox({ idPedido: null, checked: true }) }));
    assert.equal(link.attrs['data-wa-text'], undefined);
});

test('solo cambia el link del pedido palomeado, no los de otras paradas', () => {
    const link10 = linkFalso();
    const link11 = linkFalso('523300000000');
    link11.setAttribute('data-wa-text', 'original 11');
    const ctx = crearContexto({
        datosVivos: { 10: vivo10, 11: { productos: [{ nombre: 'Zinc', cantidad: 1 }], total: 200, costo_envio: 50 } },
        paradas: { 10: paradaGuardada, 11: { ...paradaGuardada, id_pedido: 11 } },
        links: { 10: link10, 11: link11 },
    });
    ctx.routeActualizarWaPorEnvio({ target: checkbox({ idPedido: '10', checked: true }) });
    assert.equal(link11.attrs['data-wa-text'], 'original 11');
});

test('el mensaje con caracteres especiales sobrevive el encodeURIComponent del href', () => {
    const link = linkFalso();
    const nombre = 'Té & "Café" #1 ?x=y';
    const ctx = crearContexto({
        datosVivos: { 10: { productos: [{ nombre, cantidad: 1 }], total: 10, costo_envio: 0 } },
        paradas: { 10: paradaGuardada },
        links: { 10: link },
    });
    ctx.routeActualizarWaPorEnvio({ target: checkbox({ checked: false }) });
    assert.ok(decodeURIComponent(link.attrs.href.split('?text=')[1]).includes(nombre));
});

function createCtx10() {
    return crearContexto({ datosVivos: { 10: vivo10 } });
}
