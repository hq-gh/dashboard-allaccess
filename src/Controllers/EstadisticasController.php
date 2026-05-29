<?php declare(strict_types=1);

namespace App\Controllers;

use App\Auth;
use App\Repositories\StudentsRepo;
use App\View;

final class EstadisticasController
{
    public function index(): void
    {
        Auth::requireLogin();

        $filters = [
            'search'   => (string) ($_GET['q']        ?? ''),
            'product'  => (string) ($_GET['producto'] ?? ''),
            'page'     => (int)    ($_GET['page']     ?? 1),
            'per_page' => (int)    ($_GET['per_page'] ?? 15),
        ];

        $repo  = new StudentsRepo();
        $stats = $repo->stats($filters);
        $list  = $repo->listStudents($filters);
        $productos = $repo->listProductNames();

        View::render('estadisticas/index', [
            'title'     => 'Estadísticas alumnos',
            'active'    => 'estadisticas',
            'stats'     => $stats,
            'list'      => $list,
            'productos' => $productos,
            'filters'   => $filters,
        ]);
    }

    public function student(string $email): void
    {
        Auth::requireLogin();

        $email = urldecode($email);
        $data  = (new StudentsRepo())->getByEmail($email);

        if ($data['summary'] === null) {
            http_response_code(404);
            View::render('estadisticas/student_not_found', [
                'title'  => 'Alumno no encontrado',
                'active' => 'estadisticas',
                'email'  => $email,
            ]);
            return;
        }

        View::render('estadisticas/student', [
            'title'    => $data['summary']['name'] ?? $email,
            'active'   => 'estadisticas',
            'summary'  => $data['summary'],
            'products' => $data['products'],
        ]);
    }
}
