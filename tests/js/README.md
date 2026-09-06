# Pruebas de lógica JS (front)

Cubren reglas de parseo que viven en las vistas y no pasan por PHPUnit.

```bash
node --test "tests/js/*.test.mjs"
```

Requiere Node 18+ (usa el runner integrado `node:test`). No forman parte del
suite de PHPUnit ni del CI de PHP; correlas a mano al tocar el front de
`views/products.php` (sincronización con B-Life) o `views/purchase_orders.php`
(lista de compra agrupada por sucursal).

Cada archivo reimplementa la regla exacta que usa la vista y la ejercita con
casos límite / negativos: si alguien cambia el regex en la vista sin actualizar
aquí, la prueba deja de reflejar la realidad — mantener ambos en sync.
