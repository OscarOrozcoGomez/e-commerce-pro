import { test } from 'node:test';
import assert from 'node:assert/strict';

/*
 * Regla exacta de views/products.php -> fetchBlifeData(), bloque "2b. Cápsulas por
 * envase / por porción". De aquí sale el autollenado del Control de Caducidades a
 * partir de la sincronización con B-Life.
 *
 * Si el regex de la vista cambia, actualizar también esta copia.
 */
const UNIDAD_RE = '(?:c[aá]ps?\\.?|c[aá]psulas?|softgels?|tabletas?|tabs?|comprimidos?)';
const PALABRAS = { un: 1, uno: 1, una: 1, dos: 2, tres: 3, cuatro: 4, cinco: 5, seis: 6 };

function parseCapsulas({ varTitle = '', title = '', tipoPres = '', modoUso = '', description = '' }) {
  const textoTipo = varTitle + ' ' + title;
  const esCapsulas = new RegExp('\\b' + UNIDAD_RE + '\\b', 'i').test(textoTipo)
    || ['Cápsulas', 'Softgels', 'Tabletas'].includes(tipoPres);
  if (!esCapsulas) return { envase: '', porcion: '' };

  let envase = '';
  const mEnv = varTitle.match(new RegExp('(\\d+)\\s*' + UNIDAD_RE + '\\b', 'i'))
    || title.match(new RegExp('(\\d+)\\s*' + UNIDAD_RE + '\\b', 'i'))
    || description.match(new RegExp('(\\d+)\\s*(?:c[aá]psulas?|softgels?|tabletas?)\\b', 'i'));
  if (mEnv) envase = mEnv[1];

  let porcion = '';
  let mPor = modoUso.match(new RegExp('\\((\\d+)\\)\\s*(?:\\([^)]*\\)\\s*)?' + UNIDAD_RE, 'i'))
    || modoUso.match(new RegExp('(\\d+)\\s*(?:\\([^)]*\\)\\s*)?' + UNIDAD_RE + '\\b', 'i'));
  if (mPor) {
    porcion = mPor[1];
  } else {
    const mw = modoUso.match(new RegExp('\\b(un|uno|una|dos|tres|cuatro|cinco|seis)\\s+(?:\\([^)]*\\)\\s*)?' + UNIDAD_RE, 'i'));
    if (mw) porcion = String(PALABRAS[mw[1].toLowerCase()]);
  }
  return { envase, porcion };
}

test('patrón dominante de B-Life: "dos (2) cápsulas (1.6 g)"', () => {
  assert.deepEqual(
    parseCapsulas({ varTitle: '180 Caps | 500 mg', tipoPres: 'Cápsulas', modoUso: 'Tomar dos (2) cápsulas (1.6 g) una vez al día' }),
    { envase: '180', porcion: '2' }
  );
});

test('"una (1) cápsula (0.81 g)" -> 1', () => {
  assert.equal(parseCapsulas({ varTitle: '180 caps', tipoPres: 'Cápsulas', modoUso: 'Tomar una (1) cápsula (0.81 g) una vez al día' }).porcion, '1');
});

test('sin dígito, número escrito en palabra: "Tomar dos cápsulas (1g)"', () => {
  assert.equal(parseCapsulas({ varTitle: '60 caps | 500 mg', tipoPres: 'Cápsulas', modoUso: 'Tomar dos cápsulas (1g) una vez al día' }).porcion, '2');
});

test('"Consumir 1 cápsula al día (0.32 g)." -> 1', () => {
  assert.equal(parseCapsulas({ varTitle: '300 Caps.', tipoPres: 'Cápsulas', modoUso: 'Consumir 1 cápsula al día (0.32 g).' }).porcion, '1');
});

test('"1 (una) cápsula" -> 1 (paréntesis con palabra intercalada)', () => {
  assert.equal(parseCapsulas({ varTitle: '50 Caps', tipoPres: 'Cápsulas', modoUso: 'Tomar 1 (una) cápsula al día' }).porcion, '1');
});

test('softgels cuentan como unidad de porción', () => {
  assert.deepEqual(
    parseCapsulas({ varTitle: '60 Softgels', tipoPres: 'Softgels', modoUso: 'Tomar 2 softgels al día.' }),
    { envase: '60', porcion: '2' }
  );
});

test('variante inútil ("1 Pza.") pero el nombre trae el conteo y tipoPres ayuda', () => {
  assert.deepEqual(
    parseCapsulas({ varTitle: '1 Pza.', title: 'Omega 3 | 90 cápsulas', tipoPres: 'Cápsulas', modoUso: 'Tomar una cápsula al día' }),
    { envase: '90', porcion: '1' }
  );
});

test('NEGATIVO: crema en ml -> ambos campos vacíos', () => {
  assert.deepEqual(
    parseCapsulas({ varTitle: '90 ml', title: 'Night Cream', tipoPres: 'Gramos (g)', modoUso: 'Aplicar en la noche sobre el rostro limpio.' }),
    { envase: '', porcion: '' }
  );
});

test('NEGATIVO: polvo "200 Porciones" -> no es cápsulas', () => {
  assert.deepEqual(
    parseCapsulas({ varTitle: '200 Porciones', title: 'Myo Inositol', tipoPres: 'Porciones', modoUso: 'Seguir la dosis sugerida de 1 porción (4.1 g) al día' }),
    { envase: '', porcion: '' }
  );
});

test('NEGATIVO: es cápsulas pero el modo de uso no menciona cantidad', () => {
  assert.deepEqual(
    parseCapsulas({ varTitle: '120 Caps', tipoPres: 'Cápsulas', modoUso: 'Consultar a su médico antes de usar.' }),
    { envase: '120', porcion: '' }
  );
});

test('NEGATIVO: entradas vacías no truenan', () => {
  assert.deepEqual(parseCapsulas({}), { envase: '', porcion: '' });
});

test('NEGATIVO: "cápsula" en el texto pero sin número por ningún lado', () => {
  assert.equal(parseCapsulas({ varTitle: 'Caps', tipoPres: 'Cápsulas', modoUso: 'Tomar cápsulas según indicación.' }).porcion, '');
});

test('no confunde los mg del título con el conteo de cápsulas', () => {
  // "500 mg" NO debe leerse como envase; "240 Caps" sí.
  assert.equal(parseCapsulas({ varTitle: '240 Caps | 500 mg', tipoPres: 'Cápsulas', modoUso: 'de 4 cápsulas al día' }).envase, '240');
});
