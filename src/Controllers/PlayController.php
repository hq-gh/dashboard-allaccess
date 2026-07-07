<?php declare(strict_types=1);

namespace App\Controllers;

use App\Auth;
use App\Repositories\PlayRepo;
use App\Security;
use App\View;

/**
 * Consumo de PLAY (Tyris/Galgo) — sección de Success. Carga MANUAL semanal del CSV
 * "Consumo por usuario" (upsert idempotente) + KPIs, embudo de retención y alumnos en riesgo.
 */
final class PlayController
{
    public function index(): void
    {
        Auth::requireLogin();
        Security::startSession();
        $flash = $_SESSION['play_flash'] ?? null;
        unset($_SESSION['play_flash']);

        $repo = new PlayRepo();
        $repo->ensureTable();
        $dias = max(1, (int) ($_GET['dias'] ?? 14));

        View::render('play/index', [
            'title'     => 'Consumo PLAY',
            'active'    => 'play',
            'kpis'      => $repo->kpis(),
            'funnel'    => $repo->funnel(),
            'distrib'   => $repo->distrib(),
            'enRiesgo'  => $repo->enRiesgo($dias, 500),
            'dias'      => $dias,
            'csrf'      => Security::csrfToken(),
            'flash'     => $flash,
        ]);
    }

    public function upload(): void
    {
        Auth::requireLogin();
        if (!Security::csrfValidate($_POST['csrf_token'] ?? null)) { http_response_code(403); echo 'CSRF inválido. Recarga la página.'; exit; }

        if (!isset($_FILES['csv']) || $_FILES['csv']['error'] !== UPLOAD_ERR_OK) {
            $this->flash('error', 'No se recibió el archivo (¿muy grande? revisa el CSV).');
            $this->redirect('/play');
        }
        try {
            $res = (new PlayRepo())->importCsv($_FILES['csv']['tmp_name']);
            $this->flash('ok', "Carga OK: {$res['leidas']} filas leídas, {$res['nuevas']} nuevas (el resto ya existían).");
        } catch (\Throwable $e) {
            $this->flash('error', 'Error al importar: ' . $e->getMessage());
        }
        $this->redirect('/play');
    }

    public function enRiesgoCsv(): void
    {
        Auth::requireLogin();
        $dias = max(1, (int) ($_GET['dias'] ?? 14));
        $rows = (new PlayRepo())->enRiesgo($dias, 5000);
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="play_en_riesgo_' . $dias . 'd.csv"');
        $out = fopen('php://output', 'w');
        fwrite($out, "\xEF\xBB\xBF");
        fputcsv($out, ['correo', 'ultima_vez', 'dias_sin_ver', 'sesiones', 'horas', 'max_semana', 'ultimo_programa']);
        foreach ($rows as $r) fputcsv($out, [$r['email'], $r['ultima'], $r['dias_sin_ver'], $r['sesiones'], $r['horas'], $r['max_semana'], $r['ultimo_programa']]);
        fclose($out);
    }

    private function flash(string $type, string $msg): void
    {
        Security::startSession();
        $_SESSION['play_flash'] = ['type' => $type, 'msg' => $msg];
    }

    private function redirect(string $to): void
    {
        header('Location: ' . $to, true, 303);
        exit;
    }
}
