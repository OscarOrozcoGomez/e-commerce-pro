<?php
declare(strict_types=1);

require_once __DIR__ . '/config.php';

// Redirigir permanentemente al catálogo correcto en la carpeta /views
$queryString = !empty($_SERVER['QUERY_STRING']) ? '?' . $_SERVER['QUERY_STRING'] : '';
$newUrl = BASE_URL . 'views/catalogo.php' . $queryString;

header('Location: ' . $newUrl, true, 301);
exit;