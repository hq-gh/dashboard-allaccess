<?php declare(strict_types=1);

namespace App\Controllers;

use App\Auth;
use App\Repositories\PecadoresRepo;
use App\View;

final class PecadoresController
{
    public function index(): void
    {
        Auth::requireLogin();
        $sort = (string) ($_GET['sort'] ?? 'name');
        $dir  = (string) ($_GET['dir']  ?? 'asc');

        $repo  = new PecadoresRepo();
        $data  = $repo->list($sort, $dir);
        $stats = $repo->stats();

        View::render('pecadores', [
            'title'  => 'Verificador Pecadores',
            'active' => 'pecadores',
            'rows'   => $data['list'],
            'sort'   => $data['sort'],
            'dir'    => $data['dir'],
            'stats'  => $stats,
        ]);
    }

    public function exportCsv(): void
    {
        Auth::requireLogin();
        $sort = (string) ($_GET['sort'] ?? 'name');
        $dir  = (string) ($_GET['dir']  ?? 'asc');

        $data = (new PecadoresRepo())->list($sort, $dir);
        $rows = $data['list'];

        $filename = 'pecadores_' . date('Y-m-d_H-i') . '.csv';
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        header('Cache-Control: no-store');
        header('X-Content-Type-Options: nosniff');

        echo "\xEF\xBB\xBF"; // BOM UTF-8 para Excel
        $headers = ['Nombre','Email','País','Teléfono','Código Transacción','Fecha/Hora','Plan','Precio','Moneda'];
        echo $this->csvLine($headers);
        foreach ($rows as $r) {
            $fecha = !empty($r['fecha_hora']) ? date('Y-m-d H:i:s', strtotime((string)$r['fecha_hora'])) : '';
            echo $this->csvLine([
                $r['name'] ?? '', $r['email'] ?? '', $r['country'] ?? '', $r['phone'] ?? '',
                $r['codigo_transaccion'] ?? '', $fecha, $r['plan'] ?? '',
                $r['precio'] ?? '', $r['moneda'] ?? '',
            ]);
        }
    }

    private function csvLine(array $fields): string
    {
        $esc = function ($v): string {
            $s = (string) ($v ?? '');
            return '"' . str_replace('"', '""', $s) . '"';
        };
        return implode(',', array_map($esc, $fields)) . "\r\n";
    }
}
