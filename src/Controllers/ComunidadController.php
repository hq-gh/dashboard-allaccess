<?php declare(strict_types=1);

namespace App\Controllers;

use App\Auth;
use App\Bettermode\BettermodeClient;
use App\Config;
use App\View;

/**
 * Dashboard de Comunidad (engagement de diez.5t4d10.com) para el equipo de Success.
 * Vista ejecutiva: KPIs del periodo (comentarios, posts, miembros activos) + rankings
 * de top comentaristas y top autores. Datos en vivo desde la API Analytics de Bettermode
 * (DSL validado: count(reply)/count(post) agrupados por persona; entities.person es objeto).
 * Reacciones se omiten: la API las rechaza en este DSL.
 */
final class ComunidadController
{
    private const TZ = 'America/Mexico_City';

    public function index(): void
    {
        Auth::requireLogin();
        [$from, $to, $fromMs, $toMs] = $this->range();

        $error = null; $rows = [];
        try {
            $rows = $this->fetch($fromMs, $toMs);
        } catch (\Throwable $e) {
            $error = $e->getMessage();
        }

        $topCom = $rows;
        usort($topCom, static fn($a, $b) => $b['comentarios'] <=> $a['comentarios']);
        $topCom = array_slice(array_values(array_filter($topCom, static fn($r) => $r['comentarios'] > 0)), 0, 25);

        $topPost = $rows;
        usort($topPost, static fn($a, $b) => $b['posts'] <=> $a['posts']);
        $topPost = array_slice(array_values(array_filter($topPost, static fn($r) => $r['posts'] > 0)), 0, 25);

        View::render('comunidad/index', [
            'title'   => 'Comunidad',
            'active'  => 'comunidad',
            'from'    => $from,
            'to'      => $to,
            'kpis'    => $this->kpis($rows),
            'topCom'  => $topCom,
            'topPost' => $topPost,
            'error'   => $error,
        ]);
    }

    public function exportCsv(): void
    {
        Auth::requireLogin();
        [$from, $to, $fromMs, $toMs] = $this->range();
        $rows = $this->fetch($fromMs, $toMs);
        usort($rows, static fn($a, $b) => $b['comentarios'] <=> $a['comentarios']);

        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="comunidad_' . $from . '_a_' . $to . '.csv"');
        echo "\xEF\xBB\xBF"; // BOM UTF-8 (acentos en Excel)
        $out = fopen('php://output', 'w');
        fputcsv($out, ['posicion', 'nombre', 'email', 'username', 'comentarios', 'posts']);
        $i = 0;
        foreach ($rows as $r) {
            $i++;
            fputcsv($out, [$i, $r['nombre'], $r['email'], $r['username'], $r['comentarios'], $r['posts']]);
        }
        fclose($out);
    }

    /** @return array{0:string,1:string,2:int,3:int} [from, to, fromMs, toMs] */
    private function range(): array
    {
        $tz = new \DateTimeZone(self::TZ);
        $to   = $this->validDate($_GET['to']   ?? '') ?: (new \DateTime('now', $tz))->format('Y-m-d');
        $from = $this->validDate($_GET['from'] ?? '') ?: (new \DateTime('now', $tz))->format('Y-m-01');
        $fromMs = (new \DateTime($from . ' 00:00:00', $tz))->getTimestamp() * 1000;
        $toMs   = (new \DateTime($to   . ' 23:59:59', $tz))->getTimestamp() * 1000;
        if ($fromMs > $toMs) { [$from, $to] = [$to, $from]; [$fromMs, $toMs] = [$toMs, $fromMs]; }
        return [$from, $to, $fromMs, $toMs];
    }

    private function validDate($s): ?string
    {
        $s = (string) $s;
        $d = \DateTime::createFromFormat('Y-m-d', $s);
        return ($d && $d->format('Y-m-d') === $s) ? $s : null;
    }

    /**
     * Trae comentarios + posts por persona en el rango (una sola query analytics).
     * @return array<int, array{nombre:string,email:string,username:string,url:string,comentarios:int,posts:int}>
     */
    private function fetch(int $fromMs, int $toMs, int $limit = 2000): array
    {
        $net = (string) Config::get('BETTERMODE_NETWORK_ID');
        if ($net === '') throw new \RuntimeException('BETTERMODE_NETWORK_ID no configurado.');

        $dsl = "select person as person, count(reply) as comentarios, count(post) as posts\n"
             . "timeFrame from {$fromMs} to {$toMs}\n"
             . "where network = '{$net}' and space_type in ('GROUP','BROADCAST') and person != null\n"
             . "group by person\n"
             . "order by comentarios desc\n"
             . "limit {$limit}";
        $gql = 'query A($q: [String!]!) { analytics(queries: $q) { records { payload { key value } entities { person { id name displayName username email relativeUrl } } } } }';

        $bm = new BettermodeClient(static fn($a, $b, $c) => null);
        $d  = $bm->query($gql, ['q' => [$dsl]]);
        $recs = $d['analytics'][0]['records'] ?? [];
        if (!is_array($recs)) throw new \RuntimeException('Respuesta de analytics inesperada.');

        $rows = [];
        foreach ($recs as $r) {
            $p = [];
            foreach (($r['payload'] ?? []) as $kv) { if (isset($kv['key'])) $p[$kv['key']] = $kv['value']; }
            $c  = (int) ($p['comentarios'] ?? 0);
            $po = (int) ($p['posts'] ?? 0);
            if ($c <= 0 && $po <= 0) continue; // ignorar miembros sin actividad
            $per = $r['entities']['person'] ?? [];
            $rows[] = [
                'nombre'      => (string) ($per['name'] ?? $per['displayName'] ?? ''),
                'email'       => (string) ($per['email'] ?? ''),
                'username'    => (string) ($per['username'] ?? ''),
                'url'         => (string) ($per['relativeUrl'] ?? ''),
                'comentarios' => $c,
                'posts'       => $po,
            ];
        }
        return $rows;
    }

    /** @return array{comentarios:int,posts:int,activos:int} */
    private function kpis(array $rows): array
    {
        $com = 0; $po = 0;
        foreach ($rows as $r) { $com += $r['comentarios']; $po += $r['posts']; }
        return ['comentarios' => $com, 'posts' => $po, 'activos' => count($rows)];
    }
}
