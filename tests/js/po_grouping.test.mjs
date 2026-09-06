import { test } from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { fileURLToPath } from 'node:url';
import { dirname, resolve } from 'node:path';

/*
 * Ejercita renderRow() + renderTable() TAL CUAL están en views/purchase_orders.php
 * (se extraen del archivo y se evalúan) para verificar el agrupado por sucursal en
 * secciones colapsables sin romper los name= de los inputs ni los id de fila.
 */
const here = dirname(fileURLToPath(import.meta.url));
const src = readFileSync(resolve(here, '../../views/purchase_orders.php'), 'utf8');

function grab(name) {
  const i = src.indexOf('function ' + name + '(');
  assert.ok(i >= 0, `no se encontró function ${name} en views/purchase_orders.php`);
  let depth = 0;
  const start = src.indexOf('{', i);
  for (let k = start; k < src.length; k++) {
    if (src[k] === '{') depth++;
    else if (src[k] === '}') { depth--; if (depth === 0) return src.slice(i, k + 1); }
  }
  throw new Error('llave sin cerrar en ' + name);
}

const escHtml = (v) => String(v ?? '').replace(/[&<>"']/g, (c) => ({
  '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;'
}[c]));
let capturado = '';
const document = { getElementById: () => ({ set innerHTML(v) { capturado = v; } }) };
const M = undefined;
function bindQtyRecalculation() {}
function recalculateTotalInversion() {}

// En un módulo ESM, `eval('function x(){}')` no crea binding accesible después:
// hay que evaluar la expresión y asignarla a un const de ámbito de módulo.
// eslint-disable-next-line no-eval
const renderRow = eval('(' + grab('renderRow') + ')');
// eslint-disable-next-line no-eval
const renderTable = eval('(' + grab('renderTable') + ')');

function render(items) {
  capturado = '';
  renderTable(items);
  return capturado;
}

const central1 = { nombre: 'Omega 3', sku: 'O1', sucursal: 'Almacén Central', precio_venta: 399, precio_costo: 200, cantidad_actual: 1, stock_minimo: 2, stock_maximo: 6 };
const central2 = { nombre: 'Ashwagandha', sku: 'A1', sucursal: 'Almacén Central', precio_venta: 349, precio_costo: 180, cantidad_actual: 0, stock_minimo: 2, stock_maximo: 5 };
const liz1 = { nombre: 'Colágeno', sku: 'C1', sucursal: 'Papelería Liz', precio_venta: 249, precio_costo: 120, cantidad_actual: 0, stock_minimo: 1, stock_maximo: 4 };

test('una sección colapsable por sucursal', () => {
  const html = render([central1, central2, liz1]);
  assert.equal((html.match(/<li class="po-group active"/g) || []).length, 2);
  assert.match(html, /data-sucursal="Almacén Central"/);
  assert.match(html, /data-sucursal="Papelería Liz"/);
});

test('el conteo por sucursal es correcto', () => {
  const html = render([central1, central2, liz1]);
  const central = html.slice(html.indexOf('Almacén Central'));
  assert.match(central, /po-group-count[^>]*>2</);
  const liz = html.slice(html.indexOf('Papelería Liz'));
  assert.match(liz, /po-group-count[^>]*>1</);
});

test('los name= siguen siendo índices GLOBALES (no se rompe el submit)', () => {
  const html = render([central1, central2, liz1]);
  for (const i of [0, 1, 2]) {
    assert.ok(html.includes(`name="items[${i}][id_producto]"`), `falta items[${i}][id_producto]`);
    assert.ok(html.includes(`name="items[${i}][cantidad]"`), `falta items[${i}][cantidad]`);
  }
  assert.ok(html.includes('id="po-row-0"') && html.includes('id="po-row-2"'));
});

test('la columna "Sucursal" desaparece de la tabla', () => {
  const html = render([central1, liz1]);
  assert.doesNotMatch(html, /<th>Sucursal<\/th>/);
});

test('NEGATIVO: lista vacía no truena y no genera secciones', () => {
  assert.doesNotMatch(render([]), /<li class="po-group/);
});

test('NEGATIVO: sin sucursal cae en "Sin sucursal"', () => {
  const html = render([{ nombre: 'X', sku: 'X', precio_costo: 10, stock_minimo: 1, stock_maximo: 2, cantidad_actual: 0 }]);
  assert.match(html, /data-sucursal="Sin sucursal"/);
});

test('NEGATIVO: sucursal con HTML se escapa', () => {
  const html = render([{ ...central1, sucursal: '<b>Hack</b>' }]);
  assert.match(html, /data-sucursal="&lt;b&gt;Hack&lt;\/b&gt;"/);
  assert.doesNotMatch(html, /data-sucursal="<b>/);
});

test('cantidad a pedir = max(0, stock_maximo - stock_actual)', () => {
  const html = render([central1, central2]);
  assert.match(html, /name="items\[0\]\[cantidad\]" value="5"/); // 6 - 1
  assert.match(html, /name="items\[1\]\[cantidad\]" value="5"/); // 5 - 0
});

test('NEGATIVO: stock por encima del máximo -> cantidad 0, nunca negativa', () => {
  const html = render([{ ...central1, cantidad_actual: 99, stock_maximo: 6 }]);
  assert.match(html, /name="items\[0\]\[cantidad\]" value="0"/);
});

test('NEGATIVO: precios no numéricos no rompen el render', () => {
  const html = render([{ ...central1, precio_venta: 'abc', precio_costo: null }]);
  assert.match(html, /po-row-0/);
});
