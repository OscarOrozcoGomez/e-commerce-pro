# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Qué es

"Sistema POS Multi-Almacén" — POS + catálogo e-commerce en **PHP 8.2 sin framework**. Se desarrolla en local con XAMPP (`C:\xampp`) y se despliega a un único entorno remoto (Neubox) por FTP vía GitHub Actions. Identificadores, comentarios y UI están en español; consérvalo así.

## Comandos

PHP no está en el PATH en Windows: usa siempre `C:\xampp\php\php.exe`.

```bash
# Dependencias (solo vendor: dompdf + phpunit)
C:\xampp\php\php.exe composer.phar install

# Toda la suite (equivale a lo que corre CI)
C:\xampp\php\php.exe composer.phar test          # vendor/bin/phpunit -c phpunit.xml --testdox
C:\xampp\php\php.exe composer.phar test:unit     # igual, sin --testdox

# Un archivo de test
vendor/bin/phpunit tests/Unit/LoteCaducidadUtilsTest.php

# Un método
vendor/bin/phpunit --filter testAlertaSaltaConAntelacion tests/Unit/LoteCaducidadUtilsTest.php

# Grupo ai_deepseek: excluido por defecto (ruido de error_log). Actívalo solo al tocar Alex/IA
vendor/bin/phpunit --group ai_deepseek
```

- No hay linter ni build. CI (`.github/workflows/ci.yml`) solo corre `composer test` en PHP 8.2 en cada PR a `main`.
- `phpunit.xml` inyecta `APP_ENV=qa`, `DISABLE_GSM=1` y una DB dummy. Los tests son unitarios puros: no tocan DB ni red reales.

### Migraciones de base de datos

```bash
C:\xampp\php\php.exe scripts/make_migration.php "descripcion"      # crea database/migrations/YYYYMMDD_NNNNNN_descripcion.sql
C:\xampp\php\php.exe scripts/make_migration.php --timestamp "..."  # variante YYYYMMDD_HHMMSS
C:\xampp\php\php.exe scripts/migrate.php --dry-run                 # simular
C:\xampp\php\php.exe scripts/migrate.php                          # aplicar pendientes
C:\xampp\php\php.exe scripts/migrate.php --to=20260907_120000     # aplicar hasta una versión
```

- Runner en `core/migrations.php`. Tabla de historial: `migration_history` (`schema_migrations` es residuo legacy).
- **Nunca edites una migración ya aplicada — crea una nueva.**
- En remoto las aplica `deploy.yml` llamando a `api/run_migrations.php` con el header `X-Migrations-Token`.

## Arquitectura

### No hay front controller ni autoload de código de la app

- `index.php` solo redirige a `views/catalogo.php`.
- **Cada archivo de `views/` es una página** servida directo por URL. Patrón: `require_once core/config.php` + `core/auth.php`, luego `requireAuth()` / `requirePermission('clave', $redirect)`, luego HTML.
- **Cada archivo de `api/` es un endpoint JSON.** Mismo patrón + chequeo de `$_SERVER['REQUEST_METHOD']` + `echo json_encode(...)`.
- **`core/*.php` son librerías de funciones** (procedural, no clases para lógica de dominio). Las funciones se agrupan por prefijo de dominio: `lote*`, `ai*`, `purchaseOrder*`, `stockTransfer*`, `wa*`/`whatsapp*`, `route*`, `delivery*`, `cliente*`, `alexInsights*`, `rp*` (roles/permisos), etc.
- `composer` autoload es **solo para `vendor/`**. El código propio se enlaza con `require_once` manual.

### Conexión a base de datos

`getPDO()` y `getMySqli()` en `core/config.php`. Credenciales desde variables de entorno o Google Secret Manager (`core/google_secret_manager.php`), con caché local. Muchas funciones de `core/` reciben `PDO $pdo` como primer parámetro (facilita el testeo).

### Auth y permisos — `core/auth.php`

Archivo grande (~2900 líneas). Helpers clave: `requireAuth()`, `hasPermission(string $clave)`, `requirePermission(string $clave, string $redirect='')`, `isAdmin()`, `isSuperAdmin()`, `isCliente()`. Las **claves de permiso son strings sembradas por migración** (p.ej. `gestionar_caducidades`, `asignar_entregas`, `transferir_stock`, `ver_conversaciones_whatsapp`). La administración de roles/permisos vive en `views/roles_permisos.php` + funciones `rp*`. CSRF: `getCsrfToken()`, `csrfInput()`, `validateCsrfToken()`.

### Entornos y secretos

- `APP_ENV` por defecto es `qa` en localhost/CLI, `production` en remoto.
- Local: copia `core/app_secrets.qa.example.php` a `core/app_secrets.qa.php`; con `DISABLE_GSM=1` no se llama a GSM.
- **Caché de secretos GSM:** TTL `GSM_CACHE_TTL_SECONDS` (default 7 días). Tras rotar un secreto, la caché stale produce errores tipo "… no configurado" o valores literales `ENCv1:` en la UI. Se limpia con `api/clear_secrets_cache.php` (o el workflow `clear-secrets-cache.yml`).
- `BASE_URL` (en `config.php`) autodetecta la subcarpeta para que un git worktree servido bajo `htdocs/` también funcione en el navegador local.

### Módulos de dominio destacados (`core/`)

| Archivo | Qué hace | UI / entrada |
|---|---|---|
| `ai_assistant.php` (~2800 líneas) | "Alex": asistente IA de WhatsApp/Telegram (backend DeepSeek, tool-calling, estado de conversación, reglas de aprendizaje) | `views/ai_assistant_settings.php`, `views/ai_diagnostics.php`, crons `scripts/whatsapp_*_cron.php` |
| `lote_caducidad_utils.php` + `caducidad_notificaciones_utils.php` | Control de caducidades por lote: proyección FEFO vs velocidad de venta, severidad por "runway" (caducidad − duración de tratamiento) | `views/caducidades.php`, cron `scripts/caducidades_notificacion_cron.php` |
| `purchase_order_utils.php` | Órdenes de compra + importación de pedido de proveedor (pegar correo / OCR) | `views/purchase_orders.php` |
| `stock_transfer_utils.php` | Transferencia de stock entre almacenes (multiproducto, lotes FEFO) | `views/transfer_stock.php` |
| `cliente_scope_utils.php` | Alcance de clientes/ventas por almacén (`clientes.id_almacen`) | — |
| `sale_delivery_mode.php` | Modo de venta "en sucursal" / mostrador (folio `MOS-`) | `views/sales.php`, `api/ventas.php` |
| `pii_crypto.php` | Cifrado de PII de clientes (`piiEncryptValue`/`piiDecryptValue`, prefijo `ENCv1:`) | `scripts/encrypt_customer_pii.php` |
| `delivery_route_utils.php` / `delivery_zone_utils.php` | Optimización de ruta (Google Maps + fallback local) y clasificación de zona / costo de envío | `api/optimize_delivery_route.php`, `api/delivery_zone_quote.php` |
| `blife_sync_utils.php` | Sincronización de productos contra `blifemx.myshopify.com` | — |
| `attribution.php` / `site_behavior.php` / `referrals.php` | Atribución de marketing, bandera de tráfico interno, códigos de referido | — |

### Tests

- PHPUnit 10.5, **solo `tests/Unit/`** (~60 archivos).
- `tests/bootstrap.php` hace `require_once` manual de ~30 librerías de `core/`. **Si añades una lib de `core/` que un test necesita, agrégala a `bootstrap.php`** o el test no la verá.
- Los tests que tocan DB usan PDO en memoria / datos sembrados en el propio test, no una DB real.

## Convenciones

- `declare(strict_types=1);` en todos los archivos.
- `esc()` para escapar salida HTML (definida en `config.php` y en el bootstrap de tests).
- Zona horaria fija `America/Mexico_City`. Log de errores en `app_error.log` en la raíz.
- Se trabaja con git worktrees hermanos bajo `C:\xampp\htdocs\` (uno por feature); los PRs van contra `main`.

## Graphify (grafo de conocimiento)

Si existe `graphify-out/`, es un grafo AST local del código (sin LLM, sin costo). Consúltalo con el skill `/graphify` o `graphify query "<pregunta>"` / `graphify affected "<símbolo>"` antes de hacer greps amplios.

- **Refresco:** `graphify update .` tras tus cambios (rápido, incremental). El hook `post-commit` de graphify solo corre en el repo principal `C:\xampp\htdocs\e-commerce-pro`; **en los worktrees enlazados hay que refrescar a mano.**
- **Worktree nuevo sin `graphify-out/`:** genéralo una vez con `graphify extract . --code-only` (o `/graphify .`).
- `graphify-out/` está en `.gitignore` y en `.git/info/exclude`; nunca se versiona ni se despliega.
