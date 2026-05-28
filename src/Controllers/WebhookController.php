<?php declare(strict_types=1);

namespace App\Controllers;

use App\Auth;
use App\Repositories\ProductKeysConfigRepo;
use App\Repositories\ProductMappingRepo;
use App\Repositories\SpacesRepo;
use App\Repositories\WebhookEventsRepo;
use App\View;
use App\Webhook\HotmartHandler;

final class WebhookController
{
    /** Endpoint público (sin auth web; lo protege el hottok del payload). */
    public function hotmartIngress(): void
    {
        (new HotmartHandler(
            new ProductMappingRepo(),
            new SpacesRepo(),
            new WebhookEventsRepo(),
            new ProductKeysConfigRepo()
        ))->handle();
    }

    /** Auditoría: tabla filtrable de eventos (requiere login). */
    public function eventos(): void
    {
        Auth::requireLogin();
        $filters = [
            'email'       => $_GET['email']       ?? '',
            'status'      => $_GET['status']      ?? '',
            'event_type'  => $_GET['event_type']  ?? '',
            'product_key' => $_GET['product_key'] ?? '',
            'desde'       => $_GET['desde']       ?? '',
            'hasta'       => $_GET['hasta']       ?? '',
        ];
        $events = (new WebhookEventsRepo())->listRecent($filters, 500);
        View::render('webhook/eventos', [
            'title'   => 'Webhook · Eventos',
            'active'  => 'webhook',
            'events'  => $events,
            'filters' => $filters,
        ]);
    }
}
