import { execFileSync } from 'node:child_process';
import { existsSync } from 'node:fs';
import path from 'node:path';

// Datos compartidos por permisos-matriz / permisos-baseline / permisos-en-vivo (.staff.spec.ts).
//
// Idea: la BD dice que permisos tiene cada cuenta (scripts/e2e_permisos_efectivos.php, con SQL propio),
// y la matriz comprueba que la APLICACION se comporte igual: lo que la cuenta tiene, funciona; lo que no
// tiene, queda bloqueado de verdad. Asi no hay que "adivinar" que debe poder hacer cada rol: si alguien
// cambia un permiso desde Roles y Permisos, la prueba sigue siendo valida.

export interface Vista {
  /** Ruta relativa a la raiz del sitio. */
  ruta: string;
  /** Basta con UNA de estas claves para entrar (guardas "A o B", p.ej. bulk_assign_category.php). */
  anyOf: string[];
  /** Ruta donde termina quien SÍ tiene permiso, si la propia vista lo redirige a otra pantalla. */
  destino?: string;
  /** También la puede usar un cliente (p.ej. el chat de ayuda); el personal necesita el permiso. */
  paraCliente?: boolean;
}

export interface Endpoint {
  ruta: string;
  anyOf: string[];
  paraCliente?: boolean;
}

export interface CuentaPermisos {
  rol: string;
  es_admin: boolean;
  es_superadmin: boolean;
  efectivos: string[];
  overrides: string[];
}

export interface PermisosBD {
  catalogo: string[];
  por_rol: Record<string, string[]>;
  cuentas: Record<string, CuentaPermisos>;
}

// Paginas de personal y la clave que las abre (verificado contra la guarda de cada archivo de views/).
export const VISTAS: Vista[] = [
  { ruta: 'views/activity_logs.php', anyOf: ['ver_auditoria'] },
  { ruta: 'views/ai_assistant_settings.php', anyOf: ['gestionar_asistente_ia'] },
  { ruta: 'views/ai_diagnostics.php', anyOf: ['gestionar_asistente_ia'] },
  { ruta: 'views/alex_insights.php', anyOf: ['ver_insights_ia'] },
  { ruta: 'views/alex_playground.php', anyOf: ['gestionar_asistente_ia'] },
  { ruta: 'views/analytics.php', anyOf: ['ver_analitica_negocio'] },
  { ruta: 'views/asignar_entregas.php', anyOf: ['asignar_entregas'] },
  { ruta: 'views/bulk_assign_category.php', anyOf: ['asignar_categorias_masivo', 'gestionar_productos'] },
  { ruta: 'views/caducidades.php', anyOf: ['gestionar_caducidades'] },
  { ruta: 'views/calendario_campanas.php', anyOf: ['gestionar_campanas'] },
  { ruta: 'views/cancelaciones_pedidos.php', anyOf: ['gestionar_cancelaciones'] },
  // Chat de soporte (PR #205): el cliente siempre tiene el suyo; el personal solo con atender_chat.
  { ruta: 'views/chat.php', anyOf: ['atender_chat'], paraCliente: true },
  { ruta: 'views/cleanup_reservations.php', anyOf: ['inventario'] },
  { ruta: 'views/comportamiento_sitio.php', anyOf: ['ver_comportamiento_sitio'] },
  { ruta: 'views/entregas.php', anyOf: ['ver_entregas'] },
  { ruta: 'views/inventario_entradas.php', anyOf: ['inventario'] },
  { ruta: 'views/manage_blogs.php', anyOf: ['gestionar_blogs'] },
  { ruta: 'views/manage_branches.php', anyOf: ['gestionar_sucursales'] },
  { ruta: 'views/manage_customers.php', anyOf: ['gestionar_clientes'] },
  { ruta: 'views/notificaciones_caducidades.php', anyOf: ['configurar_notificaciones'] },
  { ruta: 'views/notificaciones_pedidos.php', anyOf: ['configurar_notificaciones'] },
  { ruta: 'views/pickup_notifications.php', anyOf: ['ver_notificaciones_pickup'] },
  // Sin ?id la vista manda a quien SÍ tiene permiso a la lista de compras (views/process_inbound.php, línea 13).
  { ruta: 'views/process_inbound.php', anyOf: ['inventario'], destino: 'views/purchase_orders.php' },
  { ruta: 'views/productos_incompletos.php', anyOf: ['gestionar_productos'] },
  { ruta: 'views/products.php', anyOf: ['gestionar_productos'] },
  { ruta: 'views/purchase_orders.php', anyOf: ['inventario'] },
  { ruta: 'views/reportes.php', anyOf: ['ver_reportes'] },
  { ruta: 'views/reservations.php', anyOf: ['apartar_productos'] },
  { ruta: 'views/roles_permisos.php', anyOf: ['gestionar_usuarios'] },
  { ruta: 'views/sales.php', anyOf: ['realizar_ventas'] },
  { ruta: 'views/salud_sistema.php', anyOf: ['ver_salud_sistema'] },
  { ruta: 'views/trafico_visitas.php', anyOf: ['ver_trafico_campanas'] },
  { ruta: 'views/transfer_stock.php', anyOf: ['transferir_stock'] },
  { ruta: 'views/users.php', anyOf: ['gestionar_usuarios'] },
  { ruta: 'views/ventas_features_config.php', anyOf: ['configurar_iniciativas_ventas'] },
  { ruta: 'views/whatsapp_contactos.php', anyOf: ['ver_conversaciones_whatsapp'] },
];

// Endpoints de api/ y la clave que los abre. Se llaman con GET SIN parametros: en todos los que se
// revisaron el permiso se valida ANTES que el CSRF y que cualquier accion, asi que un GET vacio nunca
// escribe nada (a lo sumo devuelve una lista, un error de validacion o 405).
export const ENDPOINTS: Endpoint[] = [
  { ruta: 'api/ai_assistant_admin.php', anyOf: ['gestionar_asistente_ia'] },
  { ruta: 'api/alex_playground.php', anyOf: ['gestionar_asistente_ia'] },
  { ruta: 'api/analytics_data.php', anyOf: ['ver_analitica_negocio'] },
  { ruta: 'api/batch_inbound.php', anyOf: ['inventario'] },
  { ruta: 'api/catalog_performance_report.php', anyOf: ['ver_reportes'] },
  { ruta: 'api/chat_handler.php', anyOf: ['atender_chat'], paraCliente: true },
  { ruta: 'api/cleanup_reservations.php', anyOf: ['inventario'] },
  { ruta: 'api/create_customer.php', anyOf: ['gestionar_clientes'] },
  { ruta: 'api/create_po.php', anyOf: ['inventario'] },
  { ruta: 'api/entrega_publicacion.php', anyOf: ['ver_entregas'] },
  { ruta: 'api/inventory_handler.php', anyOf: ['inventario'] },
  { ruta: 'api/lote_ocr.php', anyOf: ['gestionar_caducidades'] },
  { ruta: 'api/lotes_manager.php', anyOf: ['gestionar_caducidades'] },
  { ruta: 'api/optimize_delivery_route.php', anyOf: ['ver_entregas'] },
  { ruta: 'api/postpone_purchase_items.php', anyOf: ['inventario'] },
  { ruta: 'api/postpone_reactivate.php', anyOf: ['inventario'] },
  { ruta: 'api/postponed_items_data.php', anyOf: ['inventario'] },
  { ruta: 'api/process_inbound.php', anyOf: ['inventario'] },
  { ruta: 'api/products.php', anyOf: ['gestionar_productos'] },
  {
    ruta: 'api/products_manager.php',
    anyOf: ['ajustar_inventario_producto', 'asignar_categorias_masivo', 'crear_categorias', 'gestionar_productos'],
  },
  { ruta: 'api/purchase_order_cancel.php', anyOf: ['inventario'] },
  { ruta: 'api/purchase_order_create.php', anyOf: ['inventario'] },
  { ruta: 'api/purchase_order_import_commit.php', anyOf: ['inventario'] },
  { ruta: 'api/purchase_order_import_preview.php', anyOf: ['inventario'] },
  { ruta: 'api/purchase_order_mayoreo_preview.php', anyOf: ['inventario'] },
  { ruta: 'api/purchase_order_receive.php', anyOf: ['inventario'] },
  { ruta: 'api/purchase_orders_data.php', anyOf: ['inventario'] },
  { ruta: 'api/purchase_orders_open.php', anyOf: ['inventario'] },
  { ruta: 'api/transfer_stock.php', anyOf: ['transferir_stock'] },
  { ruta: 'api/update_customer_phone.php', anyOf: ['gestionar_clientes'] },
  { ruta: 'api/update_thresholds.php', anyOf: ['inventario'] },
  { ruta: 'api/users_handler.php', anyOf: ['gestionar_usuarios'] },
  { ruta: 'api/vendor_settlement.php', anyOf: ['declarar_liquidacion'] },
  { ruta: 'api/ventas.php', anyOf: ['asignar_entregas', 'realizar_ventas', 'vender_sin_inventario'] },
];

// Descargas de reportes: sin permiso redirigen (302) al dashboard; con permiso responden 200.
export const DESCARGAS: Endpoint[] = [
  { ruta: 'views/export_reports.php', anyOf: ['ver_reportes'] },
  { ruta: 'views/export_reports_pdf.php', anyOf: ['ver_reportes'] },
];

/** Claves que solo un admin debe tener por rol: si un rol comun las trae, es una escalada de privilegios. */
export const CLAVES_SOLO_ADMIN = [
  'gestionar_usuarios',
  'configurar_usuarios',
  'ver_auditoria',
  'gestionar_sucursales',
  'ver_salud_sistema',
  'gestionar_asistente_ia',
  'ver_conversaciones_whatsapp',
  'ver_insights_ia',
  'ver_analitica_negocio',
  'ver_trafico_campanas',
  'ver_comportamiento_sitio',
  'gestionar_campanas',
  'configurar_notificaciones',
  'configurar_iniciativas_ventas',
  'ajustar_inventario_producto',
  'crear_categorias',
];

function resolverPhp(): string {
  if (process.env.PHP_BIN) return process.env.PHP_BIN;
  const xampp = 'C:\\xampp\\php\\php.exe';
  return existsSync(xampp) ? xampp : 'php';
}

function correrPhp(script: string, args: string[] = []): string {
  return execFileSync(resolverPhp(), [path.join('scripts', script), ...args], {
    cwd: path.resolve(__dirname, '..', '..'),
    encoding: 'utf8',
  });
}

/** Foto de los permisos reales en la BD (rol + overrides) de las cuentas E2E. */
export function leerPermisosBD(): PermisosBD {
  return JSON.parse(correrPhp('e2e_permisos_efectivos.php')) as PermisosBD;
}

/** Concede/deniega/quita un permiso individual a una cuenta E2E (solo cuentas e2e-*@playwright.test). */
export function fijarOverride(email: string, clave: string, efecto: 'conceder' | 'denegar' | 'quitar'): void {
  correrPhp('e2e_permiso_override.php', [email, clave, efecto]);
}

export function tienePermiso(efectivos: string[], anyOf: string[]): boolean {
  return anyOf.some((clave) => efectivos.includes(clave));
}
