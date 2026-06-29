# Setup rapido: QA local + Produccion

## 1) GitHub Secrets (globales de repositorio)

Agrega estos secrets en Settings > Secrets and variables > Actions:

- PTF_HOST
- PTF_USERNAME
- PTF_PASSWORD
- MIGRATIONS_URL
- MIGRATIONS_DEPLOY_TOKEN

Sugerencias para Produccion:

- MIGRATIONS_URL = https://tu-dominio.com/api/run_migrations.php
- El deploy FTP ya apunta a /public_html/ en deploy.yml

## 2) Hosting de Produccion

Define variables de entorno en el host remoto:

- APP_ENV=production en Produccion
- DB_HOST, DB_NAME, DB_USER, DB_PASSWORD, DB_CHARSET
- MIGRATIONS_DEPLOY_TOKEN (igual al secret en GitHub para ese ambiente)

## 3) Flujo diario

1. Crear migracion SQL en database/migrations.
2. Probar local en QA:
   - C:\xampp\php\php.exe scripts/migrate.php --dry-run
   - C:\xampp\php\php.exe scripts/migrate.php
3. Push a main para desplegar a Produccion.

## 4) Validacion de migraciones

El endpoint responde JSON con:

- applied_count
- skipped_count
- lista de archivos aplicados/omitidos

Si una migracion aplicada se modifica, el checksum falla y bloquea el deploy.
