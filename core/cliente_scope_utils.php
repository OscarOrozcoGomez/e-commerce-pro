<?php
declare(strict_types=1);

/**
 * Alcance de visibilidad de clientes por sucursal.
 *
 * `clientes.id_almacen` marca a que sucursal "pertenece" un cliente: la del
 * encargado/vendedor que lo dio de alta (o, para los registros historicos, la de su
 * primer pedido, via el backfill de la migracion que agrega la columna).
 *
 * Reglas de negocio:
 *  - admin: ve y edita TODOS los clientes, incluidos los que no tienen sucursal
 *    (`id_almacen IS NULL`, tipicamente cuentas que se registraron solas en el sitio).
 *  - encargado / vendedor: SOLO los clientes de su propia sucursal. Los `NULL` quedan
 *    fuera a proposito -- los administra un admin hasta que se les asigne sucursal.
 *  - usuario sin sucursal en sesion y que no es admin: no ve ningun cliente.
 *
 * Este modulo es logica pura (sin PDO) para poder probarlo a fondo; quien llama arma
 * la consulta y hace los binds.
 */

/**
 * Fragmento SQL (sin `WHERE` / `AND`) y sus params para limitar una consulta de
 * clientes al alcance del usuario actual.
 *
 * @param int|null $almacenId Sucursal del usuario en sesion (getCurrentAlmacenId()).
 * @param bool     $isAdmin   Si el usuario puede ver todo.
 * @param string   $alias     Alias de la tabla `clientes` en la consulta.
 * @param string   $ph        Placeholder a usar (cambialo si la consulta ya lo ocupa).
 * @return array{sql: string, params: array<string, int>}
 */
function clienteScopeSqlFilter(?int $almacenId, bool $isAdmin, string $alias = 'c', string $ph = ':cli_scope_almacen'): array
{
    if ($isAdmin) {
        return ['sql' => '1=1', 'params' => []];
    }

    if ($almacenId === null || $almacenId <= 0) {
        // No-admin sin sucursal asignada: no puede ver clientes.
        return ['sql' => '1=0', 'params' => []];
    }

    return [
        'sql' => $alias . '.id_almacen = ' . $ph,
        'params' => [$ph => $almacenId],
    ];
}

/**
 * Decide si el usuario actual puede ver/editar un cliente concreto.
 *
 * @param int|null $clienteAlmacenId Valor de `clientes.id_almacen` del cliente objetivo.
 * @param int|null $almacenId        Sucursal del usuario en sesion.
 * @param bool     $isAdmin          Si el usuario puede ver todo.
 */
function clienteScopeAllows(?int $clienteAlmacenId, ?int $almacenId, bool $isAdmin): bool
{
    if ($isAdmin) {
        return true;
    }

    if ($almacenId === null || $almacenId <= 0) {
        return false;
    }

    return $clienteAlmacenId !== null && $clienteAlmacenId > 0 && $clienteAlmacenId === $almacenId;
}

/**
 * Sucursal que debe quedar grabada en un cliente recien creado: la del usuario en
 * sesion. Si no hay ninguna (p. ej. un admin sin sucursal), se crea "sin dueno"
 * (`NULL`) y solo lo veran los admins hasta que se le asigne una.
 *
 * @param int|null $almacenId Sucursal del usuario en sesion.
 * @return int|null
 */
function clienteScopeAlmacenParaNuevo(?int $almacenId): ?int
{
    return ($almacenId !== null && $almacenId > 0) ? $almacenId : null;
}
