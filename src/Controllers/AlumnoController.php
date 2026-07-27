<?php declare(strict_types=1);

namespace App\Controllers;

use App\Auth;
use App\Repositories\AlumnoRepo;
use App\View;

/**
 * Módulo "Suscripciones" del portal de Success. Cami e Iza teclean el CORREO DE
 * COMPRA de un alumno y ven: su(s) suscripción(es) tipo "Detalles de la Suscripción"
 * de Hotmart, la tabla completa de Pagos Recurrentes y el histórico de pagos tardíos,
 * más (si existe) un segundo correo de acceso.
 */
final class AlumnoController
{
    public function index(): void
    {
        Auth::requireLogin();

        $compra = trim((string) ($_GET['compra'] ?? ''));
        $result = null;
        $error  = null;

        if ($compra !== '') {
            if (!filter_var($compra, FILTER_VALIDATE_EMAIL)) {
                $error = 'Ese no parece un correo válido. Revisa que esté bien escrito (ejemplo: nombre@dominio.com).';
            } else {
                $result = (new AlumnoRepo())->findByPurchaseEmail($compra);
            }
        }

        View::render('alumno/index', [
            'title'   => 'Suscripciones',
            'active'  => 'suscripciones',
            'compra'  => $compra,
            'result'  => $result,
            'error'   => $error,
        ]);
    }
}
