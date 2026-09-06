# Pruebas de lógica JS (front)

Cubren reglas de parseo/render que viven en las vistas y no pasan por PHPUnit.

```bash
node --test "tests/js/*.test.mjs"
```

Requiere Node 18+ (usa el runner integrado `node:test`). No forman parte del
suite de PHPUnit ni del CI de PHP; correlas a mano al tocar el front
correspondiente:

- `blife_capsule_parsing.test.mjs` — `views/products.php`, autollenado de
  "cápsulas por envase/porción" desde la sincronización con B-Life.
- `po_grouping.test.mjs` — `views/purchase_orders.php`, Lista de Compra
  agrupada por sucursal en secciones colapsables.

`po_grouping` extrae las funciones directo del `.php`; `blife_capsule_parsing`
reimplementa la regla exacta que usa la vista — si alguien cambia el regex en la
vista sin actualizar aquí, la prueba deja de reflejar la realidad.
