<?php
declare(strict_types=1);

/**
 * Reporte de solo lectura del control de caducidades por lote.
 *
 * Lista, agrupados por severidad, los lotes cuya proyeccion indica que no
 * alcanzaran a venderse antes de caducar (comparando la cantidad restante
 * contra la velocidad de venta de los ultimos 90 dias, con consumo FEFO).
 *
 * Uso:  C:\xampp\php\php.exe scripts\caducidades_audit.php
 * Base para un futuro cron de aviso por correo.
 */

require __DIR__ . '/../core/config.php';
require __DIR__ . '/../core/auth.php';
require __DIR__ . '/../core/lote_caducidad_utils.php';

$pdo = getPDO();

if (!loteTablaExiste($pdo, 'lotes_inventario')) {
    fwrite(STDERR, "La tabla lotes_inventario no existe. Corre: php scripts/migrate.php\n");
    exit(1);
}

$proy = loteFetchProyecciones($pdo);
$lotes = $proy['lotes'];

$grupos = [
    'caducado' => [], 'critico' => [], 'urgente' => [], 'planificar' => [],
    'vigilar' => [], 'sin_rotacion' => [], 'sin_historico' => [], 'ok' => [],
];
foreach ($lotes as $l) {
    $grupos[$l['severidad']][] = $l;
}

$hoy = date('Y-m-d H:i:s');
echo "==================================================================\n";
echo " CONTROL DE CADUCIDADES  |  {$hoy}  |  ventana {$proy['ventana_dias']} dias\n";
echo "==================================================================\n\n";

foreach ($grupos as $sev => $filas) {
    printf("%-14s %d\n", strtoupper($sev), count($filas));
}
echo "\n";

$detalle = ['caducado', 'critico', 'urgente', 'planificar', 'vigilar', 'sin_rotacion'];
foreach ($detalle as $sev) {
    if (empty($grupos[$sev])) {
        continue;
    }
    echo "------ " . strtoupper($sev) . " (" . count($grupos[$sev]) . ") ------\n";
    foreach ($grupos[$sev] as $l) {
        $exc = $l['excedente_proyectado'] === null ? '?' : $l['excedente_proyectado'];
        $desc = $l['descuento_sugerido_pct'] ? "  sug -{$l['descuento_sugerido_pct']}%" : '';
        $descuadre = !empty($l['descuadre']) ? "  [descuadre lotes {$l['stock_lotes']} vs stock {$l['stock_sistema']}]" : '';
        $rinde = $l['dias_tratamiento_envase'] !== null
            ? sprintf('  rinde %dd (margen %+dd)', $l['dias_tratamiento_envase'], (int) $l['margen_consumo_dias'])
            : '';
        $nv = !empty($l['no_vendible']) ? '  *** NO VENDIBLE ***' : '';
        printf(
            "  %-34s lote %-14s caduca %s (%+d d)  resta %-5d  vende %.2f/d  excedente %s%s%s%s%s\n",
            mb_substr((string) $l['producto_nombre'], 0, 34),
            (string) $l['codigo_lote'],
            (string) $l['fecha_caducidad'],
            (int) $l['dias_hasta_caducar'],
            (int) $l['cantidad_restante'],
            (float) $l['vel_diaria'],
            (string) $exc,
            $desc,
            $rinde,
            $descuadre,
            $nv
        );
    }
    echo "\n";
}

$accionables = count($grupos['caducado']) + count($grupos['critico']) + count($grupos['urgente'])
    + count($grupos['planificar']) + count($grupos['vigilar']) + count($grupos['sin_rotacion']);
echo "Lotes que requieren atencion: {$accionables}\n";
exit(0);
