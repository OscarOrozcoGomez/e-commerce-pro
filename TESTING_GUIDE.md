# 🧪 GUÍA DE PRUEBAS - SISTEMA POS CON DASHBOARDS POR ROL

## 📋 CREDENCIALES DE PRUEBA

```
Base de datos: beautyandwell_prod
Usuario: root
Contraseña: (sin contraseña)
```

### Usuarios de Prueba Precargados:

| Email | Contraseña | Rol | Almacén |
|-------|-----------|-----|---------|
| admin@system.local | password123 | admin | N/A |
| encargado@system.local | password123 | encargado | Sucursal 1 |
| vendedor@system.local | password123 | vendedor | Sucursal 1 |

*Nota: Las contraseñas están hasheadas en la base de datos con bcrypt*

---

## ✅ PRUEBAS DE AUTENTICACIÓN

### Test 1: Login como Admin
1. Navegar a `http://localhost/e-commerce-pro/views/login.php`
2. Ingresar: `admin@system.local` / `password123`
3. ✅ Esperado: Redirigir a `/e-commerce-pro/views/dashboard.php`
4. ✅ Esperado: Ver dashboard con 6 tarjetas de estadísticas globales
5. ✅ Esperado: Ver botones: "Gestionar Productos", "Gestionar Usuarios", "Ver Reportes"

### Test 2: Login como Encargado
1. Navegar a login
2. Ingresar: `encargado@system.local` / `password123`
3. ✅ Esperado: Dashboard con 4 tarjetas (Ventas, Productos, Stock, Ingresos)
4. ✅ Esperado: Ver botones: "Gestionar Productos", "Realizar Venta", "Mis Apartados"

### Test 3: Login como Vendedor
1. Navegar a login
2. Ingresar: `vendedor@system.local` / `password123`
3. ✅ Esperado: Dashboard simplificado con 3 tarjetas
4. ✅ Esperado: Ver solo "Realizar Venta" y "Ver Catálogo"

---

## 🔐 PRUEBAS DE PERMISOS

### Test 4: Vendedor intenta acceder a /views/products.php
1. Loguearse como vendedor
2. Navegar directamente a `http://localhost/e-commerce-pro/views/products.php`
3. ✅ Esperado: Redirigir a dashboard (acceso denegado)

### Test 5: Vendedor intenta acceder a /views/users.php
1. Loguearse como vendedor
2. Navegar directamente a `http://localhost/e-commerce-pro/views/users.php`
3. ✅ Esperado: Redirigir a dashboard (acceso denegado)

### Test 6: Encargado intenta acceder a /views/users.php
1. Loguearse como encargado
2. Navegar a `http://localhost/e-commerce-pro/views/users.php`
3. ✅ Esperado: Redirigir a dashboard (acceso denegado)

---

## 📦 PRUEBAS DE FUNCIONALIDAD - PRODUCTOS

### Test 7: Crear Producto (Admin o Encargado)
1. Loguearse como admin o encargado
2. Click en "Gestionar Productos"
3. Llenar formulario:
   - Nombre: "Champú Hidratante Pro"
   - SKU: "CHAMP-001"
   - Código de Barras: "1234567890123"
   - Descripción: "Champú hidratante profesional"
   - Unidad: "Botella"
   - Precio Costo: 5.50
   - Precio Venta: 12.99
   - Categoría: "Cuidado del Cabello"
4. ✅ Esperado: Mensaje de éxito "Producto agregado correctamente"
5. ✅ Esperado: Producto aparece en el listado

### Test 8: Verificar que Vendedor NO puede agregar productos
1. Loguearse como vendedor
2. Intentar navegar a `/views/products.php`
3. ✅ Esperado: Redirigir a dashboard

---

## 💰 PRUEBAS DE VENTAS

### Test 9: Realizar una Venta (Encargado)
1. Loguearse como encargado
2. Click en "Realizar Venta"
3. Click en "Agregar Producto"
4. Seleccionar un producto del dropdown
5. Ingresar cantidad: 2
6. Precio unitario se auto-completa ✅
7. Click "Agregar Producto" otra vez
8. Seleccionar otro producto, cantidad: 1
9. En resumen del lado derecho:
   - ✅ Subtotal se actualiza dinámicamente
   - ✅ Total se recalcula
10. Ingresar Descuento: 5.00
11. ✅ Total debe reducirse en 5
12. Seleccionar Método de Pago: "Efectivo"
13. Click "Procesar Venta"
14. ✅ Esperado: Éxito JSON con número de pedido
15. ✅ Esperado: Pedido registrado en base de datos
16. ✅ Esperado: Inventario reducido

### Test 10: Verificar que Vendedor SÍ puede hacer ventas
1. Loguearse como vendedor
2. Click "Realizar Venta"
3. ✅ Esperado: Acceso permitido
4. Realizar venta similar al Test 9

---

## 👥 PRUEBAS DE USUARIOS (ADMIN SOLAMENTE)

### Test 11: Crear Nuevo Usuario (Admin)
1. Loguearse como admin
2. Click en "Gestionar Usuarios"
3. Llenar formulario:
   - Nombre: "Juan García"
   - Email: "juan.garcia@test.local"
   - Contraseña: "SecurePass123!"
   - Rol: "vendedor"
   - Almacén: "Sucursal 1"
4. Click "Crear Usuario"
5. ✅ Esperado: Mensaje de éxito
6. ✅ Esperado: Nuevo usuario aparece en tabla
7. Verificar en base de datos que contraseña está hasheada (NO en texto plano)

### Test 12: Verificar que Encargado NO puede crear usuarios
1. Loguearse como encargado
2. Intentar navegar a `/views/users.php`
3. ✅ Esperado: Redirigir a dashboard

---

## 📊 PRUEBAS DE REPORTES

### Test 13: Ver Reportes (Admin)
1. Loguearse como admin
2. Click en "Ver Reportes"
3. ✅ Esperado: Ver tabla con historial de ventas
4. Cambiar fecha inicio y fin
5. Click "Filtrar"
6. ✅ Esperado: Tabla actualizada con nuevas fechas

### Test 14: Acceso a Reportes por Rol
1. Loguearse como encargado
2. Intentar navegar a `/views/reportes.php`
3. ✅ Esperado: Redirigir a dashboard (acceso denegado)

---

## 🛡️ PRUEBAS DE SEGURIDAD

### Test 15: SQL Injection en búsqueda (si aplica)
1. Loguearse como admin
2. Si hay campo de búsqueda, intentar: `' OR '1'='1`
3. ✅ Esperado: Treated como texto literal, sin ejecución SQL

### Test 16: XSS en formularios
1. Loguearse como admin (crear producto)
2. En campo "Nombre" ingresar: `<script>alert('XSS')</script>`
3. Guardar
4. ✅ Esperado: Script escapado, mostrado como texto
5. ✅ Esperado: NO se ejecuta JavaScript

### Test 17: Validación de Stock
1. Loguearse como encargado
2. Intentar vender más cantidad de la disponible
3. ✅ Esperado: Error "Stock insuficiente"

### Test 18: Validación de Contraseñas
1. Crear usuario con contraseña "test"
2. ✅ Esperado: Contraseña hasheada en BD (comenzando con $2y$)

---

## 🔗 APARTADOS Y RESERVACIONES

### Test 19: Ver Apartados (Encargado)
1. Loguearse como encargado
2. Click "Mis Apartados"
3. ✅ Esperado: Ver tabla con pedidos pendientes
4. Click en "Ver" en una fila
5. ✅ Esperado: Modal con detalles del apartado

---

## 📱 PRUEBAS DE RESPONSIVIDAD

### Test 20: Dashboard en Celular
1. Abrir DevTools (F12)
2. Activar modo dispositivo móvil
3. ✅ Esperado: Grid de Materialize responde correctamente
4. ✅ Esperado: Tarjetas se apilan en una columna
5. ✅ Esperado: Navegación sigue siendo accesible

---

## 🔍 VERIFICACIONES EN BASE DE DATOS

Después de las pruebas, ejecutar en MySQL:

```sql
-- Verificar que hay nuevos productos
SELECT COUNT(*) FROM productos;

-- Verificar que hay nuevos pedidos
SELECT numero_pedido, total, fecha_creacion FROM pedidos ORDER BY fecha_creacion DESC LIMIT 5;

-- Verificar que movimientos de inventario están registrados
SELECT * FROM movimientos_inventario WHERE tipo_movimiento = 'salida' LIMIT 5;

-- Verificar que contraseñas están hasheadas
SELECT email, SUBSTRING(contrasena, 1, 10) as hash_prefix FROM usuarios;

-- Verificar detalles de pedidos
SELECT * FROM detalle_pedidos LIMIT 5;
```

---

## ✅ CHECKLIST DE PRUEBAS COMPLETADAS

- [ ] Test 1: Login como Admin
- [ ] Test 2: Login como Encargado
- [ ] Test 3: Login como Vendedor
- [ ] Test 4: Vendedor NO puede acceder a products
- [ ] Test 5: Vendedor NO puede acceder a users
- [ ] Test 6: Encargado NO puede acceder a users
- [ ] Test 7: Crear producto
- [ ] Test 8: Vendedor NO puede agregar productos
- [ ] Test 9: Realizar venta como Encargado
- [ ] Test 10: Realizar venta como Vendedor
- [ ] Test 11: Crear usuario como Admin
- [ ] Test 12: Encargado NO puede crear usuarios
- [ ] Test 13: Ver reportes como Admin
- [ ] Test 14: Encargado NO puede ver reportes
- [ ] Test 15: SQL Injection
- [ ] Test 16: XSS en formularios
- [ ] Test 17: Validación de stock
- [ ] Test 18: Verificar contraseñas hasheadas
- [ ] Test 19: Ver apartados
- [ ] Test 20: Responsividad mobile

---

## 📞 SOPORTE

Si alguna prueba falla:
1. Revisar logs de Apache en `C:\xampp\apache\logs\error.log`
2. Revisar logs de base de datos
3. Verificar que la base de datos está ejecutándose
4. Limpiar cookies del navegador y reintentar login

