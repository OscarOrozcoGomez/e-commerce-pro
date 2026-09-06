# Pruebas de lógica JS (front)

Cubren reglas que viven en las vistas y no pasan por PHPUnit.

```bash
node --test "tests/js/*.test.mjs"
```

Requiere Node 18+ (usa el runner integrado `node:test`). No forman parte del
suite de PHPUnit ni del CI de PHP; correlas a mano al tocar el front
correspondiente.
