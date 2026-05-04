# Sistema POS Multi-Almacén

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
2. Configurar credenciales en `core/config.php`
3. Ejecutar `scripts/import_products.php` para cargar productos
4. Acceder a `index.php` (requiere login)

## Usuarios Iniciales

- **Admin**: admin@system.local / admin123
- **Encargado**: encargado@system.local / admin123
- **Vendedor**: vendedor@system.local / admin123

## Funcionalidades

- Autenticación multi-rol (admin, encargado, vendedor)
- Catálogo de productos con búsqueda AJAX
- Inventario multi-almacén
- Importación masiva desde CSV de Odoo

