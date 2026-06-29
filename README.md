# Sistema POS Multi-Almacén

[![CI](https://github.com/OscarOrozcoGomez/e-commerce-pro/actions/workflows/ci.yml/badge.svg)](https://github.com/OscarOrozcoGomez/e-commerce-pro/actions/workflows/ci.yml)

## Estructura del Proyecto

```
ecommerce/
├── index.php              # Catálogo principal (punto de entrada)
├── logout.php             # Cerrar sesión
├── core/                  # Configuración y lógica central
│   ├── config.php         # Conexión BD y constantes
│   └── auth.php           # Funciones de autenticación
├── api/                   # Endpoints JSON
│   └── products.php       # API de productos
├── views/                 # Plantillas HTML
│   ├── login.php          # Formulario de login
│   └── includes/          # Componentes reutilizables
│       ├── header.php
│       └── footer.php
├── scripts/               # Scripts de utilidad
│   └── import_products.php # Importación masiva de productos
├── database.sql           # Esquema de base de datos
└── Exportaciones/         # Archivos CSV de Odoo
    ├── Variante del producto (product.product).csv
    ├── Clientes o contactos odoo.xlsx
    └── Exportar productos odoo.xlsx
```

## Instalación

1. Importar `database.sql` en MariaDB/MySQL
2. Configurar credenciales como variables de entorno en el servidor o en Secret Manager.
    - `DB_HOST`
    - `DB_NAME`
    - `DB_USER`
    - `DB_PASSWORD`
    - `DB_CHARSET` (opcional)
    - `MAPS_KEY` o `GOOGLE_MAPS_API_KEY`
3. Ejecutar `scripts/import_products.php` para cargar productos
4. Acceder a `index.php` (requiere login)

## Pruebas (local)

Para correr la suite de pruebas sin depender del `phpunit` viejo de XAMPP:

1. Instalar dependencias de desarrollo:
    - `C:\xampp\php\php.exe composer.phar install`
2. Ejecutar pruebas unitarias:
    - `C:\xampp\php\php.exe composer.phar test`

Notas:

- El workflow de CI en GitHub instala dependencias y ejecuta estas pruebas automáticamente en cada Pull Request a `main`.
- Las dependencias de pruebas (`vendor/`) y archivos de Composer están excluidos del despliegue al host de producción.

### Troubleshooting

- Si `composer install` falla por memoria:
    - `C:\xampp\php\php.exe -d memory_limit=-1 composer.phar install`
- Si falla por caché/lock corrupto:
    - `C:\xampp\php\php.exe composer.phar clear-cache`
    - Eliminar `vendor/` y volver a ejecutar install.
- Si cambiaste de versión de PHP en XAMPP y aparecen conflictos de dependencias:
    - Verifica versión activa: `C:\xampp\php\php.exe -v`
    - Reinstala dependencias: `C:\xampp\php\php.exe composer.phar install`
    - Si persiste, actualiza lock para tu versión local: `C:\xampp\php\php.exe composer.phar update --with-all-dependencies`
- Si aparece `php is not recognized`:
    - Usa siempre comandos con ruta completa de XAMPP (`C:\xampp\php\php.exe ...`) en lugar de `php`.

## Usuarios Iniciales

- **Admin**: admin@system.local / admin123
- **Encargado**: encargado@system.local / admin123
- **Vendedor**: vendedor@system.local / admin123

## Funcionalidades

- Autenticación multi-rol (admin, encargado, vendedor)
- Catálogo de productos con búsqueda AJAX
- Inventario multi-almacén
- Importación masiva desde CSV de Odoo

## Seguridad y despliegue

- Guía rápida para flujo con SFTP: `DEPLOY_SFTP_SECURE.md`

## Migraciones de base de datos (local + remoto)

Para mantener sincronizado el esquema entre XAMPP y el host remoto:

1. Crear una migración SQL nueva en `database/migrations/` con formato:
     - Default: `YYYYMMDD_000001_descripcion.sql` (consecutivo diario)
     - Opcional: `YYYYMMDD_HHMMSS_descripcion.sql` (modo timestamp)
2. Probar localmente en XAMPP:
     - `php scripts/migrate.php --dry-run`
     - `php scripts/migrate.php`
3. Hacer push a `main`.
4. El workflow `deploy.yml` sube archivos y luego ejecuta `api/run_migrations.php` en remoto.

### Generar plantilla de migración

- Modo por defecto (consecutivo diario):
    - `php scripts/make_migration.php "borrar tabla prueba"`
- Modo por hora exacta:
    - `php scripts/make_migration.php --mode=timestamp "borrar tabla prueba"`
- Alias corto para timestamp:
    - `php scripts/make_migration.php --timestamp "borrar tabla prueba"`
- Ver ayuda:
    - `php scripts/make_migration.php --help`

### Comandos locales

- Aplicar pendientes:
    - `php scripts/migrate.php`
- Simular sin aplicar:
    - `php scripts/migrate.php --dry-run`
- Aplicar solo hasta una versión:
    - `php scripts/migrate.php --to=20260628_000001`

### Variables requeridas en producción

- `MIGRATIONS_DEPLOY_TOKEN`: token secreto que valida el endpoint de migraciones.
- `MIGRATIONS_URL` (GitHub Secret): URL completa, por ejemplo:
    - `https://tu-dominio.com/api/run_migrations.php`

## Ambientes de trabajo (QA local + Producción)

El flujo actual está simplificado así:

- QA (pruebas): solo local en tu equipo con XAMPP.
- Producción: único entorno remoto en Neubox.

### Configuración local en XAMPP (QA)

1. Copia `core/app_secrets.qa.example.php` a `core/app_secrets.qa.php`.
2. Ajusta `DB_NAME` para tu base local de XAMPP (por ejemplo `beautyandwell_prod`).
3. Opcional: define `APP_ENV=qa` en el entorno de Apache/PHP.

Nota: En localhost/CLI ahora el valor por defecto de `APP_ENV` es `qa`.

### Configuración remota (Producción)

1. En hosting, configura `APP_ENV=production`.
2. Configura variables reales de DB (`DB_HOST`, `DB_NAME`, `DB_USER`, `DB_PASSWORD`).
3. Configura `MIGRATIONS_DEPLOY_TOKEN` con el mismo valor que en GitHub Secrets globales del repositorio.

### GitHub Secrets globales requeridos

Agrega estos secrets a nivel repositorio (sin GitHub Environments):

- `PTF_HOST`
- `PTF_USERNAME`
- `PTF_PASSWORD`
- `MIGRATIONS_URL`
- `MIGRATIONS_DEPLOY_TOKEN`

### Seguridad

- Nunca edites una migración ya aplicada; crea una nueva.
- El endpoint remoto acepta solo `POST` y valida token por header `X-Migrations-Token` o query param `token`.
- La tabla oficial de historial es `migration_history`; `schema_migrations` quedó como residuo legacy y se limpia con una migración posterior.

## Marketing y conversiones

- Página de agradecimiento: `views/gracias.php`
- El checkout redirige a `views/gracias.php?id={id_pedido}` para medir conversiones.
- Parámetros de campaña (`gclid`, `wbraid`, `gbraid`, `utm_*`) se guardan en `localStorage` para atribución.
- Para disparo directo de Google Ads con `gtag`, configura en servidor:
    - `GOOGLE_ADS_SEND_TO` (ejemplo: `AW-123456789/AbCdEfGhIjK`)

