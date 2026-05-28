<?php declare(strict_types=1);
/**
 * Front controller del portal rw2.5t4d10.com.
 * Todas las requests pasan por aquí (Railway sirve via `php -S` con -t public).
 */

// errores: nunca al cliente, siempre a stderr (Railway logs)
error_reporting(E_ALL);
ini_set('display_errors', '0');
ini_set('log_errors', '1');
ini_set('error_log', 'php://stderr');

date_default_timezone_set('America/Mexico_City');

require __DIR__ . '/../vendor/autoload.php';

use App\Router;
use App\Controllers\AuthController;
use App\Controllers\HomeController;
use App\Controllers\PecadoresController;
use App\Controllers\EstadisticasController;
use App\Controllers\VipController;
use App\Controllers\WebhookController;
use App\Controllers\AdminController;

$router = new Router();

// --- Auth ---
$auth = new AuthController();
$router->get('/login',  fn() => $auth->showLogin());
$router->post('/login', fn() => $auth->doLogin());
$router->post('/logout',fn() => $auth->doLogout());

// --- Home ---
$home = new HomeController();
$router->get('/', fn() => $home->index());

// --- Pecadores ---
$pec = new PecadoresController();
$router->get('/pecadores',            fn() => $pec->index());
$router->get('/pecadores/export.csv', fn() => $pec->exportCsv());

// --- Dashboard VIP (Verificador InfinityVIP -> Bettermode) ---
$vip = new VipController();
$router->get('/vip',                 fn() => $vip->index());
$router->get('/vip/corridas',        fn() => $vip->corridas());
$router->get('/vip/corridas/{id}',   fn(string $id) => $vip->corridaDetail($id));
$router->get('/vip/movimientos',     fn() => $vip->movimientos());
$router->get('/vip/movimientos.csv', fn() => $vip->movimientosCsv());
$router->get('/vip/altas-bajas.csv', fn() => $vip->altasBajasCsv());
$router->get('/vip/estado',          fn() => $vip->estado());
$router->get('/vip/estado.csv',      fn() => $vip->estadoCsv());

// --- Webhook Hotmart -> Bettermode ---
$webhook = new WebhookController();
$router->post('/webhook/hotmart', fn() => $webhook->hotmartIngress());
$router->get('/webhook/eventos',  fn() => $webhook->eventos());

// --- Admin (solo administrador) ---
$admin = new AdminController();
$router->get('/admin',                          fn() => $admin->index());
$router->get('/admin/usuarios',                 fn() => $admin->usuariosIndex());
$router->post('/admin/usuarios/create',         fn() => $admin->usuariosCreate());
$router->post('/admin/usuarios/update',         fn() => $admin->usuariosUpdate());
$router->post('/admin/usuarios/reset-password', fn() => $admin->usuariosResetPassword());
$router->post('/admin/usuarios/delete',         fn() => $admin->usuariosDelete());
$router->get('/admin/spaces',                   fn() => $admin->spacesIndex());
$router->post('/admin/spaces/create',           fn() => $admin->spacesCreate());
$router->post('/admin/spaces/update',           fn() => $admin->spacesUpdate());
$router->post('/admin/spaces/delete',           fn() => $admin->spacesDelete());
$router->get('/admin/productos',                fn() => $admin->productosIndex());
$router->post('/admin/productos/upsert',        fn() => $admin->productosUpsert());
$router->post('/admin/productos/delete',        fn() => $admin->productosDelete());

// --- Estadísticas (placeholder) ---
$est = new EstadisticasController();
$router->get('/estadisticas', fn() => $est->index());

$router->dispatch();
