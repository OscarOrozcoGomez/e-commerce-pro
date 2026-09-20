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
