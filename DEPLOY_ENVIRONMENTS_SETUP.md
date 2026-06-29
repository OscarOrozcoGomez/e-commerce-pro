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
- MIGRATIONS_DEPLOY_TOKEN (igual al secret global de GitHub del repositorio)

## 3) Flujo diario

1. Crear migracion SQL en database/migrations.
2. Probar local en QA.
3. Push a main para desplegar a Produccion (migraciones se ejecutan automatico en host).

## 4) Paso a paso local de migraciones (recomendado)

Este flujo es el que debes usar siempre antes de mandar cambios a Produccion.

1. Abre terminal en la raiz del proyecto.
2. Genera el archivo de migracion.
   - Modo default (consecutivo diario):
     - C:\xampp\php\php.exe scripts/make_migration.php "descripcion de cambio"
   - Modo opcional por hora exacta:
     - C:\xampp\php\php.exe scripts/make_migration.php --timestamp "descripcion de cambio"
3. Edita el archivo nuevo dentro de database/migrations y escribe tu SQL.
4. Simula la ejecucion sin aplicar cambios:
   - C:\xampp\php\php.exe scripts/migrate.php --dry-run
5. Si el dry-run sale bien, aplica localmente:
   - C:\xampp\php\php.exe scripts/migrate.php
6. Verifica resultado en consola.
   - Debes ver migraciones aplicadas u omitidas de forma esperada.
7. Prueba funcionalmente la app local.
   - Verifica pantallas o procesos afectados por el cambio de esquema.
8. Haz commit y push a main.
   - El workflow despliega y llama automaticamente al endpoint remoto de migraciones.

Checklist rapido local antes de push:

- La migracion tiene nombre claro y no se edito ninguna migracion vieja.
- El dry-run no reporta errores.
- La ejecucion real local termino sin error.
- El codigo de app es compatible con el nuevo esquema.
- Si el cambio es destructivo, existe respaldo o estrategia segura.

## 5) Validacion de migraciones

El endpoint responde JSON con:

- applied_count
- skipped_count
- lista de archivos aplicados/omitidos

Si una migracion aplicada se modifica, el checksum falla y bloquea el deploy.

## 6) Tabla oficial de historial

- La tabla oficial del sistema es `migration_history`.
- Si ves `schema_migrations`, es una tabla legacy de una migracion inicial.
- La limpieza correcta no es editar migraciones viejas, sino crear una nueva migracion para eliminarla.

Ejemplo real de limpieza tecnica ya aplicado en el repo:

- `database/migrations/20260628_000004_drop_schema_migrations_table.sql`

## 7) Como hacer migraciones destructivas

Una migracion destructiva es una que elimina o cambia datos o estructura de forma irreversible, por ejemplo:

- borrar una columna
- borrar una tabla
- renombrar una columna sin copiar datos antes
- cambiar tipo de dato a uno incompatible

Reglas recomendadas:

1. Verifica primero que el codigo ya no use esa columna o tabla.
2. Prueba siempre localmente con:
   - C:\xampp\php\php.exe scripts/migrate.php --dry-run
   - C:\xampp\php\php.exe scripts/migrate.php
3. Si la tabla tiene datos importantes, respalda antes de eliminar.
4. Nunca edites una migracion ya aplicada; crea una nueva.
5. Si el cambio es riesgoso, hazlo en dos pasos:
   - primero dejar de usar el campo en codigo
   - despues eliminarlo en otra migracion

### Ejemplo: eliminar una columna

Archivo sugerido:

- database/migrations/20260629_120000_drop_campo_obsoleto_from_productos.sql

Contenido:

```sql
ALTER TABLE productos
DROP COLUMN campo_obsoleto;
```

### Ejemplo: eliminar una tabla

Archivo sugerido:

- database/migrations/20260629_121000_drop_tabla_temporal.sql

Contenido:

```sql
DROP TABLE IF EXISTS tabla_temporal;
```

### Ejemplo mas seguro: respaldar y luego eliminar

Si necesitas borrar una tabla con datos pero quieres dejar respaldo dentro de la misma BD:

```sql
CREATE TABLE IF NOT EXISTS pedidos_backup_20260629 AS
SELECT * FROM pedidos;

DROP TABLE pedidos;
```

Usa este patron solo cuando realmente lo necesites, porque duplicar tablas grandes en produccion puede ser costoso.
