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

### ⚠️ WhatsApp/Alex: nunca ráfagas de mensajes (obligatorio revisar en CADA cambio)

**Incidente real (2026-09-13):** el cron `scripts/whatsapp_followup_cron.php` mandó ~24 mensajes
idénticos a WhatsApp en el mismo segundo (backlog acumulado de la reactivación automática de
24h). WhatsApp lo detectó como automatización/spam y puso la cuenta de negocio **en revisión
(bloqueada)**. El puente (`wa-bridge`, Baileys — cliente NO oficial) ya de por sí corre ese
riesgo constante; una ráfaga real es lo que lo dispara.

Reglas ya aplicadas para que no se repita — **cualquier cambio a Alex, al puente de WhatsApp,
o a un cron/script que le mande algo a un cliente por WhatsApp debe verificar que se sigan
cumpliendo, y si se toca ese código, se tiene que volver a razonar explícitamente si sigue
cumpliéndolas**:

1. **El seguimiento de 24h de Alex (el cliente no escribió primero, es puro reenganche para
   rescatar una venta a medias) nunca pasa de UNO por hora**, sin importar qué tan grande sea
   el backlog ni cuántas veces corra el cron mientras tanto (`scripts/whatsapp_followup_cron.php`
   corre cada 20 min, pero solo manda algo si `aiPuedeEnviarProactivoAhora()` lo permite — ver
   `AI_PROACTIVO_INTERVALO_MIN_MINUTOS` en `ai_assistant.php`). Un backlog grande se vacía a lo
   largo de varios días si hace falta, nunca de un jalón ni sostenido hora tras hora. El texto
   del seguimiento de 24h además se genera distinto cada vez (`aiGenerarTextoSeguimientoUnico()`,
   vía DeepSeek con el historial real) — mandar siempre el mismo texto fijo a distintos
   destinatarios es en sí mismo un patrón detectable, aunque vaya espaciado. Cualquier futura
   funcionalidad de "mensaje a varios clientes" (campañas/broadcast) debe pasar por esta misma
   disciplina: cadencia de horas, no de segundos, y texto variado.
   El **catch-up de horario** (contestar con retraso algo que el cliente YA escribió mientras
   Alex estaba callado a propósito de 10pm a 7am, ver `aiEstaEnHorarioAtencion()`) es un cupo
   **aparte** desde 2026-09-18 (`aiPuedeResponderCatchupAhora()`/`aiRegistrarEnvioCatchup()`,
   timestamp propio `ultimo_envio_catchup_en`), con su propia cadencia de ~5 minutos
   (`AI_CATCHUP_INTERVALO_MIN_MINUTOS`) — no comparte el tope de 1/hora del seguimiento: no es
   contacto no solicitado, y antes de separarlos un cliente nuevo de madrugada podía esperar
   horas su primerísima respuesta si el cupo compartido ya lo había gastado un seguimiento.
   Sigue sin riesgo de ráfaga: nunca instantáneo para todo el backlog de la noche a la vez,
   siempre espaciado ~5 min entre cada cliente distinto, muy por debajo del patrón real del
   incidente de 2026-09-13 (~24 mensajes idénticos en el mismo segundo). Para que este ritmo
   de 5 min se note de verdad hace falta que el cron mismo corra seguido (ver el crontab del
   VPS, `*/N * * * * ... whatsapp_followup_cron.php`) — si corre cada 20 min, el catch-up en
   la práctica sigue limitado a como mucho 1 cada 20 min aunque el código ya permita 1 cada 5.
   "Cupos independientes" es solo la cadencia de cada uno **entre corridas** — `whatsapp_followup_cron.php`
   sigue mandando como máximo **un** mensaje real por corrida (si el catch-up tuvo algo que
   contestar, el seguimiento de 24h espera a la siguiente corrida), para que abrir el horario
   con ambos cupos libres a la vez nunca mande 2 mensajes reales en la misma ejecución.
2. **Las respuestas de Alex en vivo (conversación normal) se mandan con un retraso humano
   deliberado** (60-120s aleatorios, ver `enviarReplyParts`/el delay antes de llamarla dentro
   de `messages.upsert` en `/opt/wa-bridge/app/index.js` — código del puente, vive en el VPS,
   sin control de versiones en este repo) — un bot que contesta en milisegundos, siempre, es
   en sí mismo una señal de automatización.
3. Antes de dar por terminado cualquier cambio que toque `ai_assistant.php` (specialmente
   `aiSendFollowupMessage`, `aiRunAssistantTurn`, cualquier tool nueva, o cualquier cron
   `scripts/whatsapp_*`), pregúntate explícitamente: *¿este cambio puede hacer que se manden
   varios mensajes reales a WhatsApp en una ráfaga, o que se sostenga un volumen alto de
   mensajes proactivos por muchas horas seguidas?* Si la respuesta no es un "no" claro y
   verificado, hay que agregar pausa/tope antes de considerarlo terminado.

Ver `private/HOWTO_VPS_BD.md` sección 8 para logs/edición del puente (`journalctl -u
wa-bridge`, `/opt/wa-bridge/app/index.js`).

### Tests

- PHPUnit 10.5, **solo `tests/Unit/`** (~60 archivos).
- `tests/bootstrap.php` hace `require_once` manual de ~30 librerías de `core/`. **Si añades una lib de `core/` que un test necesita, agrégala a `bootstrap.php`** o el test no la verá.
- Los tests que tocan DB usan PDO en memoria / datos sembrados en el propio test, no una DB real.

## Auditoría (quién hizo qué)

Toda operación que **modifique datos** (precios, ofertas, inventario, clientes, usuarios, permisos, configuración…) debe dejar rastro en `logs_auditoria`; se consulta en `views/activity_logs.php` (pestaña "Movimientos").

- `logAudit($accion, $tabla, $id, $detalles, $antes, $despues, $opciones)` en `core/auth.php`. Toma solo el usuario, rol, IP, dispositivo, sesión y URL de la petición. Los 4 primeros parámetros son los de siempre; `$antes`/`$despues` son arrays con los campos que cambiaron.
- Para cambios sobre un registro usa **`logAuditCambios()`** con "fotos" antes/después (`auditSnapshotProducto/Cliente/Direccion/Usuario/Almacen`, `auditSnapshotFila` en `core/audit_snapshots.php`): calcula el diff, no escribe nada si no hubo cambio real y enmascara PII (teléfono/correo/dirección) y oculta secretos. **Nunca** metas contraseñas, tokens ni PII en claro en `$detalles`, `$antes` o `$despues` (los arrays pasados a `logAudit` directo NO se enmascaran; los de `auditDiff` sí).
- Registra **después** del commit, nunca dentro de la transacción de negocio. `logAudit` no lanza excepciones.
- Cada acción nueva (constante en MAYÚSCULAS, ≤ 50 caracteres) va en `auditMapaEtiquetasAccion()` de `core/audit_utils.php` con su nombre legible; `tests/Unit/AuditUtilsTest.php` falla si falta.
- Red de seguridad: `auditIniciarRegistroPeticiones()` registra como `PETICION_ESCRITURA` cualquier POST/PUT/DELETE con sesión que no haya registrado nada propio (payload sin secretos). Endpoints que no deben entrar (sondeos, chat, login) van en `AUDIT_ENDPOINTS_SIN_REGISTRO`.
- Las columnas nuevas de `logs_auditoria` las agrega `20260919_000001_*`; `logAudit` y la vista toleran que aún no existan (deploy en curso).

## Convenciones

- `declare(strict_types=1);` en todos los archivos.
- `esc()` para escapar salida HTML (definida en `config.php` y en el bootstrap de tests).
- Zona horaria fija `America/Mexico_City`. Log de errores en `app_error.log` en la raíz.
- Se trabaja con git worktrees hermanos bajo `C:\xampp\htdocs\` (uno por feature); los PRs van contra `main`.
- **Un cliente nunca se da de alta sin teléfono** (staff, registro web, Mi perfil y Alex): la ruta de entrega y los avisos por WhatsApp dependen de él. Helpers en `core/cliente_telefono_utils.php`. En pedidos creados por Alex el número REAL del chat (`aiTelefonoRealDelChat()`) manda sobre lo que el modelo dicte (`telefonoResolverParaPedido()`) y un número de relleno (3312345678) se rechaza. Alex le dice al cliente con qué número se le avisará y le deja corregirlo (`aiBuildTelefonoChatContextLine()`; si el cliente pide otro, el modelo manda `telefono` + `telefono_alterno_confirmado=true`). Al corregir el teléfono de un cliente, sus pedidos abiertos con el número viejo, inválido o de relleno se actualizan solos (`clienteSincronizarTelefonoPedidosAbiertos()`).

## Graphify (grafo de conocimiento)

Si existe `graphify-out/`, es un grafo AST local del código (sin LLM, sin costo). Consúltalo con el skill `/graphify` o `graphify query "<pregunta>"` / `graphify affected "<símbolo>"` antes de hacer greps amplios.

- **Refresco:** `graphify update .` tras tus cambios (rápido, incremental). El hook `post-commit` de graphify solo corre en el repo principal `C:\xampp\htdocs\e-commerce-pro`; **en los worktrees enlazados hay que refrescar a mano.**
- **Worktree nuevo sin `graphify-out/`:** genéralo una vez con `graphify extract . --code-only` (o `/graphify .`).
- `graphify-out/` está en `.gitignore` y en `.git/info/exclude`; nunca se versiona ni se despliega.
