<?php
/**
 * Router para `php -S` (PHP built-in server / Railway Nixpacks).
 *
 * Sirve archivos estáticos reales (css, img, etc.) directo, y delega cualquier
 * otra ruta al front controller (`index.php`). Necesario porque sin esto, el
 * built-in server intenta servir directamente URLs con extensión (como las
 * rutas `/vip/altas-bajas.csv`) buscándolas como archivos físicos, lo cual
 * devuelve 404 antes de llegar al router de la app.
 */
$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?? '/';

if (PHP_SAPI === 'cli-server' && $path !== '/' && is_file(__DIR__ . $path)) {
    return false; // dejar que el built-in server lo sirva
}

require __DIR__ . '/index.php';
