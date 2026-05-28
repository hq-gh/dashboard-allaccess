<?php declare(strict_types=1);

namespace App\Controllers;

use App\Auth;
use App\View;

final class EstadisticasController
{
    public function index(): void
    {
        Auth::requireLogin();
        View::render('estadisticas', [
            'title'  => 'Estadísticas alumnos',
            'active' => 'estadisticas',
        ]);
    }
}
