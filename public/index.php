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
use App\Controllers\ComunidadController;
use App\Controllers\PlayController;
use App\Controllers\AlumnoController;

$router = new Router();

// --- Auth ---
$auth = new AuthController();
$router->get('/login',  fn() => $auth->showLogin());
$router->post('/login', fn() => $auth->doLogin());
$router->post('/logout',fn() => $auth->doLogout());
$router->get('/forgot', fn() => $auth->showForgot());
$router->post('/forgot',fn() => $auth->doForgot());
$router->get('/reset',  fn() => $auth->showReset());
$router->post('/reset', fn() => $auth->doReset());

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
// El INGRESS de Hotmart (POST) corre SOLO en el servicio dedicado 5t4d10_WEBHOOK
// (WEBHOOK_HOST=1). En rw2 (Dashboard de Success) NO se registra -> 404, para que un
// webhook no comparta proceso con el dashboard y lo bloquee. El visor de eventos
// (GET, solo lectura) sí queda disponible en ambos (lo usa el menú de rw2).
if (getenv('WEBHOOK_HOST') === '1') {
    $router->post('/webhook/hotmart', fn() => $webhook->hotmartIngress());
}
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
$router->get('/admin/classes',                  fn() => $admin->classesIndex());
$router->post('/admin/classes/update',          fn() => $admin->classesUpdate());

// --- Estadísticas alumnos ---
$est = new EstadisticasController();
$router->get('/estadisticas',                       fn() => $est->index());
$router->get('/estadisticas/alumnos/{email}',       fn(string $email) => $est->student($email));

// --- Comunidad (engagement Bettermode) — Success ---
$comunidad = new ComunidadController();
$router->get('/comunidad',            fn() => $comunidad->index());
$router->get('/comunidad/export.csv', fn() => $comunidad->exportCsv());

// --- Consumo PLAY (Tyris) — Success ---
$play = new PlayController();
$router->get('/play',               fn() => $play->index());
$router->post('/play/upload',       fn() => $play->upload());
$router->get('/play/en-riesgo.csv', fn() => $play->enRiesgoCsv());

// --- Suscripciones por alumno — Success (revisión de pagos y retrasos) ---
$alumno = new AlumnoController();
$router->get('/suscripciones', fn() => $alumno->index());

// --- Dashmail sync (interno; lo dispara el botón "Sincronizar ahora" de dashmail.5t4d10.com) ---
// Aquí porque este servicio (web) SÍ tiene pgsql; el web de dashmail no. Guardado por llave.
// La ingesta puede tardar minutos (mucho volumen), así que corre EN SEGUNDO PLANO (detached)
// y el endpoint responde al instante; el dashboard hace polling al JSON publicado.
$dashmailSync = function () {
    header('Content-Type: application/json; charset=utf-8');
    $expected = (string)getenv('DASHMAIL_SYNC_KEY');
    $given = (string)($_SERVER['HTTP_X_SYNC_KEY'] ?? ($_GET['key'] ?? ''));
    if ($expected === '' || !hash_equals($expected, $given)) { http_response_code(403); echo json_encode(['error' => 'forbidden']); return; }
    try {
        $pdo = App\Database::get();
        $pdo->exec("CREATE TABLE IF NOT EXISTS dashmail_ingest_state (k text PRIMARY KEY, v text)");
        $g = $pdo->prepare("SELECT v FROM dashmail_ingest_state WHERE k=?");
        $g->execute(['sync_lock']); $lock = (int)($g->fetchColumn() ?: 0);
        $running = $lock > 0 && (time() - $lock) < 300;
        if (!$running) {
            $pdo->prepare("INSERT INTO dashmail_ingest_state (k,v) VALUES ('sync_lock',?) ON CONFLICT (k) DO UPDATE SET v=EXCLUDED.v")->execute([(string)time()]);
            // lanzar la ingesta detached: sobrevive al cierre de esta request
            @exec('nohup php ' . escapeshellarg(__DIR__ . '/../bin/dashmail-cron.php') . ' >> /tmp/dashmail-sync.log 2>&1 &');
        }
        echo json_encode(['ok' => true, 'started' => !$running, 'already_running' => $running]);
    } catch (\Throwable $e) {
        error_log('[dashmail sync] ' . $e->getMessage());
        http_response_code(500); echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
    }
};
$router->get('/internal/dashmail-sync',  $dashmailSync);
$router->post('/internal/dashmail-sync', $dashmailSync);

$router->dispatch();
