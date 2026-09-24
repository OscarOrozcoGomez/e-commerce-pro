import { execFileSync } from 'node:child_process';
import { existsSync } from 'node:fs';
import path from 'node:path';

// Preparacion de datos de PRUEBA por CLI (scripts/e2e_*.php). Son atajos para montar un caso que por la UI exigiria
// mucho trabajo; cada script valida por su cuenta que solo toca datos de prueba (cuentas e2e-*, productos "Playwright E2E").

function resolverPhp(): string {
  if (process.env.PHP_BIN) return process.env.PHP_BIN;
  const xampp = 'C:\\xampp\\php\\php.exe';
  return existsSync(xampp) ? xampp : 'php';
}

export function correrPhp(script: string, args: string[] = []): string {
  return execFileSync(resolverPhp(), [path.join('scripts', script), ...args], {
    cwd: path.resolve(__dirname, '..', '..'),
    encoding: 'utf8',
  });
}

/** Integraciones externas configuradas en ESTE entorno (solo booleanos, nunca el valor). */
export function leerEntorno(): { google_maps_key: boolean } {
  return JSON.parse(correrPhp('e2e_entorno.php')) as { google_maps_key: boolean };
}

/** Fija precio_original de los renglones de prueba de un pedido (para ver el precio original tachado en Entregas). */
export function fijarPrecioOriginal(numeroPedido: string, precioOriginal: number): void {
  correrPhp('e2e_pedido_precio_original.php', [numeroPedido, String(precioOriginal)]);
}

// --- Consultas de SOLO LECTURA de lo que el servidor guardo (scripts/e2e_consulta.php) ---

export interface ProductoDB {
  id_producto: number;
  precio_venta: number;
  precio_costo: number;
  precio_oferta: number | null;
  en_ofertas: boolean;
  gestionado: boolean;
  stock: number;
}

export interface PedidoDB {
  id_pedido: number;
  estado: string;
  tipo_entrega: string;
  subtotal: number;
  descuento_total: number;
  costo_envio: number;
  total: number;
  renglones: Array<{ id_producto: number; nombre: string; cantidad: number; precio_original: number; precio_unitario: number; subtotal: number }>;
}

export interface LoteDB {
  id_lote: number;
  id_producto: number;
  estado: string;
  cantidad_restante: number;
  en_oferta: boolean;
  alerta_atendida: boolean;
  fecha_caducidad: string;
}

function consultar<T>(tipo: 'producto' | 'pedido' | 'lote' | 'regla', argumento: string): T | null {
  return JSON.parse(correrPhp('e2e_consulta.php', [tipo, argumento])) as T | null;
}

/** Producto "Playwright E2E ..." tal como esta en la BD (precios, stock, si esta en Ofertas). */
export const consultaProducto = (nombre: string) => consultar<ProductoDB>('producto', nombre);
/** Pedido por su numero (totales y renglones tal como quedaron guardados). */
export const consultaPedido = (numero: string) => consultar<PedidoDB>('pedido', numero);
export interface ReglaDB {
  total: number;
  ultima: { id_regla: number; contexto_o_pregunta: string; respuesta_o_accion_esperada: string; etiqueta_sugerida: string | null; activa: number | string };
}

/** Reglas de aprendizaje de Alex cuyo contexto empieza con el prefijo "Playwright..." (cuantas y la mas reciente). */
export const consultaRegla = (prefijo: string) => consultar<ReglaDB>('regla', prefijo);
/** Lote "E2E-..." (estado, cantidad, marcas). */
export const consultaLote = (codigo: string) => consultar<LoteDB>('lote', codigo);

/** Deja "Playwright E2E Oferta Product" con su lote a 20 dias y SIN oferta (scripts/e2e_preparar_oferta.php). */
export function prepararOferta(): void {
  correrPhp('e2e_preparar_oferta.php');
}

/** Conversaciones de WhatsApp "Playwright WA ..." (seguimientos, normal, LID...) y reglas "Playwright" limpias (scripts/e2e_preparar_whatsapp.php). */
export interface WhatsappDB {
  mes: string;
  sin_respuesta: number;
  respondio: number;
  no_salio: number;
  normal: number;
  dos_mensajes: number;
  sin_numero: number;
}
export const prepararWhatsapp = () => JSON.parse(correrPhp('e2e_preparar_whatsapp.php')) as WhatsappDB;
