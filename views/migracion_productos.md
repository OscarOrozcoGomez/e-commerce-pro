# Guía de Migración de Productos (CSV a Base de Datos)

Este documento detalla el procedimiento para importar y actualizar los productos y sus imágenes desde el archivo `@Variante del producto (product.product).csv` a la base de datos `beautyandwell_prod`.

## 1. Preparación de la Base de Datos

Antes de comenzar, es necesario asegurar que la columna de imagen en la tabla `productos` tenga la capacidad suficiente para almacenar las cadenas en Base64 de alta resolución (1024px).

Ejecutar en la consola SQL de phpMyAdmin:
```sql
ALTER TABLE productos 
MODIFY imagen LONGTEXT,
ADD COLUMN IF NOT EXISTS modo_uso TEXT NULL AFTER descripcion,
ADD COLUMN IF NOT EXISTS ingredientes TEXT NULL AFTER modo_uso,
ADD COLUMN IF NOT EXISTS tabla_nutrimental TEXT NULL AFTER ingredientes,
ADD COLUMN IF NOT EXISTS mostrar_tabla TINYINT(1) DEFAULT 1 AFTER tabla_nutrimental;
/* Asegurar que el valor por defecto sea siempre visible (1) */
UPDATE productos SET mostrar_tabla = 1 WHERE mostrar_tabla IS NULL;
```

## 2. Creación de la Tabla Temporal de Importación

Para evitar que el proceso de importación sea lento o bloquee la tabla principal, utilizaremos una tabla intermedia que coincida exactamente con las columnas del archivo CSV.

```sql
DROP TABLE IF EXISTS tmp_productos_import;
CREATE TABLE tmp_productos_import (
    id_externo VARCHAR(255),
    nombre VARCHAR(255),
    sku VARCHAR(120),
    codigo_barras VARCHAR(120),
    costo DECIMAL(12,2),
    precio_venta DECIMAL(12,2),
    stock_actual DECIMAL(12,2),
    stock_previsto DECIMAL(12,2),
    imagen_1024 LONGTEXT,
    imagen_128 LONGTEXT,
    imagen_256 LONGTEXT,
    imagen_512 LONGTEXT
) DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_spanish_ci;
```

## 3. Pasos para Importar en phpMyAdmin

> **Nota importante:** Si un intento previo falló, asegúrate de vaciar la tabla antes de reintentar ejecutando:  
> `TRUNCATE TABLE tmp_productos_import;`

> **Limpieza de datos:** Antes de sincronizar, elimina registros con SKU vacío para evitar errores de duplicados:
> ```sql
> DELETE FROM tmp_productos_import WHERE sku IS NULL OR sku = '';
> ```

1.  Selecciona la tabla `tmp_productos_import`.
2.  Haz clic en la pestaña **Importar**.
3.  Selecciona el archivo `@Variante del producto (product.product).csv`.
4.  En **Formato**, selecciona **CSV**.
5.  En el campo **Número de filas a saltar desde el inicio**, escribe `1` (para ignorar los encabezados).
6.  Asegúrate de que el formato de los datos coincida (comas o punto y coma según tu archivo).
7.  Haz clic en **Importar**.

## 4. Sincronización con la Tabla Principal

Una vez cargados los datos en la tabla temporal, ejecutamos la migración definitiva.

### A. Actualizar productos existentes (Basado en SKU)
Este paso vincula la imagen de alta resolución (1024) y actualiza precios de los productos que ya están en tu sistema.

```sql
UPDATE productos p
INNER JOIN tmp_productos_import t ON TRIM(p.sku) = TRIM(t.sku) COLLATE utf8mb4_general_ci
SET p.imagen = t.imagen_1024,
    p.precio_costo = t.costo,
    p.precio_venta = t.precio_venta,
    p.nombre = t.nombre,
    p.codigo_barras = t.codigo_barras
WHERE p.estado = 'activo';
```

### B. Insertar productos nuevos
Si hay productos en el CSV que no existen en la base de datos, se insertan con este comando:

```sql
INSERT INTO productos (nombre, sku, codigo_barras, precio_costo, precio_venta, imagen, estado)
SELECT t.nombre, t.sku, t.codigo_barras, t.costo, t.precio_venta, t.imagen_1024, 'activo'
FROM tmp_productos_import t
LEFT JOIN productos p ON t.sku = p.sku COLLATE utf8mb4_spanish_ci
WHERE p.sku IS NULL
  AND t.sku IS NOT NULL
  AND t.sku <> '';
```

## 5. Limpieza
Una vez verificado que los cambios se ven correctamente en el POS y en la lista de productos, puedes borrar la tabla temporal:

`DROP TABLE tmp_productos_import;`