# Setup rapido de ambientes QA y Produccion

## 1) GitHub Environments

Crea dos environments en el repositorio:

- qa
- production

En cada environment agrega estos secrets:

- PTF_HOST
- PTF_USERNAME
- PTF_PASSWORD
- MIGRATIONS_URL
- MIGRATIONS_DEPLOY_TOKEN

Sugerencias:

- qa:
  - MIGRATIONS_URL = https://tu-dominio.com/api/run_migrations.php (sitio QA)
  - server-dir ya esta configurado como /public_html_qa/ en deploy-qa.yml
- production:
  - MIGRATIONS_URL = https://tu-dominio.com/api/run_migrations.php (sitio produccion)
  - server-dir ya esta configurado como /public_html/ en deploy.yml

## 2) Hosting por ambiente

Define variables de entorno en cada ambiente remoto:

- APP_ENV=qa en QA
- APP_ENV=production en Produccion
- DB_HOST, DB_NAME, DB_USER, DB_PASSWORD, DB_CHARSET
- MIGRATIONS_DEPLOY_TOKEN (igual al secret en GitHub para ese ambiente)

## 3) Flujo diario

1. Crear migracion SQL en database/migrations.
2. Probar local en QA:
   - C:\xampp\php\php.exe scripts/migrate.php --dry-run
   - C:\xampp\php\php.exe scripts/migrate.php
3. Push a qa para validar en ambiente QA remoto.
4. Merge a main para desplegar a Produccion.

## 4) Validacion de migraciones

El endpoint responde JSON con:

- applied_count
- skipped_count
- lista de archivos aplicados/omitidos

Si una migracion aplicada se modifica, el checksum falla y bloquea el deploy.
