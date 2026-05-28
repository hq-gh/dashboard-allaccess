<?php declare(strict_types=1);

namespace App\Controllers;

use App\Auth;
use App\View;

final class HomeController
{
    public function index(): void
    {
        Auth::requireLogin();
        View::render('home', [
            'title'  => 'Inicio',
            'active' => 'home',
            'user'   => Auth::user(),
        ]);
    }
}
