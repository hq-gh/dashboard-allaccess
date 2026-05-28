<?php declare(strict_types=1);

namespace App\Controllers;

use App\Auth;
use App\Repositories\ProductMappingRepo;
use App\Repositories\SpacesRepo;
use App\Security;
use App\View;

/**
 * CRUD admin de configuración del webhook:
 *  - /admin/spaces      Listado + alta + edición + borrado de bettermode_spaces.
 *  - /admin/productos   Listado + alta + edición + borrado de hotmart_product_mapping.
 *
 * Solo accesible a role='administrador'. Sin JavaScript: forms HTML clásicos POST,
 * validación server-side, redirect-after-POST + flash message en sesión.
 */
final class AdminController
{
    // ============================================================
    // SPACES
    // ============================================================
    public function spacesIndex(): void
    {
        Auth::requireAdmin();
        Security::startSession();
        $flash = $_SESSION['admin_flash'] ?? null;
        unset($_SESSION['admin_flash']);

        $rows = (new SpacesRepo())->listAll();
        View::render('admin/spaces', [
            'title'  => 'Admin · Spaces',
            'active' => 'admin',
            'rows'   => $rows,
            'csrf'   => Security::csrfToken(),
            'flash'  => $flash,
        ]);
    }

    public function spacesCreate(): void
    {
        Auth::requireAdmin();
        $this->requireCsrf();

        $productKey = trim((string) ($_POST['product_key'] ?? ''));
        $spaceId    = trim((string) ($_POST['space_id'] ?? ''));
        $spaceName  = trim((string) ($_POST['space_name'] ?? ''));
        $sortOrder  = (int) ($_POST['sort_order'] ?? 0);
        $isActive   = !empty($_POST['is_active']);

        if ($productKey === '' || $spaceId === '' || $spaceName === '') {
            $this->flash('error', 'product_key, space_id y space_name son obligatorios.');
            $this->redirect('/admin/spaces');
            return;
        }

        try {
            (new SpacesRepo())->create($productKey, $spaceId, $spaceName, $sortOrder, $isActive);
            $this->flash('ok', "Space '{$spaceName}' guardado.");
        } catch (\Throwable $e) {
            $this->flash('error', 'Error: ' . $e->getMessage());
        }
        $this->redirect('/admin/spaces');
    }

    public function spacesUpdate(): void
    {
        Auth::requireAdmin();
        $this->requireCsrf();

        $id        = (int) ($_POST['id'] ?? 0);
        $spaceName = trim((string) ($_POST['space_name'] ?? ''));
        $sortOrder = (int) ($_POST['sort_order'] ?? 0);
        $isActive  = !empty($_POST['is_active']);

        if ($id <= 0 || $spaceName === '') {
            $this->flash('error', 'Datos inválidos.');
            $this->redirect('/admin/spaces');
            return;
        }
        try {
            (new SpacesRepo())->update($id, $spaceName, $sortOrder, $isActive);
            $this->flash('ok', 'Space actualizado.');
        } catch (\Throwable $e) {
            $this->flash('error', 'Error: ' . $e->getMessage());
        }
        $this->redirect('/admin/spaces');
    }

    public function spacesDelete(): void
    {
        Auth::requireAdmin();
        $this->requireCsrf();
        $id = (int) ($_POST['id'] ?? 0);
        if ($id > 0) {
            try { (new SpacesRepo())->delete($id); $this->flash('ok', 'Space eliminado.'); }
            catch (\Throwable $e) { $this->flash('error', 'Error: ' . $e->getMessage()); }
        }
        $this->redirect('/admin/spaces');
    }

    // ============================================================
    // PRODUCTOS (hotmart_product_mapping)
    // ============================================================
    public function productosIndex(): void
    {
        Auth::requireAdmin();
        Security::startSession();
        $flash = $_SESSION['admin_flash'] ?? null;
        unset($_SESSION['admin_flash']);

        $rows = (new ProductMappingRepo())->listAll();
        View::render('admin/productos', [
            'title'  => 'Admin · Productos Hotmart',
            'active' => 'admin',
            'rows'   => $rows,
            'csrf'   => Security::csrfToken(),
            'flash'  => $flash,
        ]);
    }

    public function productosUpsert(): void
    {
        Auth::requireAdmin();
        $this->requireCsrf();

        $pid  = trim((string) ($_POST['hotmart_product_id'] ?? ''));
        $pkey = trim((string) ($_POST['product_key'] ?? ''));
        $name = trim((string) ($_POST['product_name'] ?? ''));
        $act  = !empty($_POST['is_active']);

        if ($pid === '' || $pkey === '') {
            $this->flash('error', 'hotmart_product_id y product_key son obligatorios.');
            $this->redirect('/admin/productos');
            return;
        }
        try {
            (new ProductMappingRepo())->upsert($pid, $pkey, $name !== '' ? $name : null, $act);
            $this->flash('ok', "Producto {$pid} guardado.");
        } catch (\Throwable $e) {
            $this->flash('error', 'Error: ' . $e->getMessage());
        }
        $this->redirect('/admin/productos');
    }

    public function productosDelete(): void
    {
        Auth::requireAdmin();
        $this->requireCsrf();
        $pid = trim((string) ($_POST['hotmart_product_id'] ?? ''));
        if ($pid !== '') {
            try { (new ProductMappingRepo())->delete($pid); $this->flash('ok', 'Producto eliminado.'); }
            catch (\Throwable $e) { $this->flash('error', 'Error: ' . $e->getMessage()); }
        }
        $this->redirect('/admin/productos');
    }

    // ============================================================
    // HELPERS
    // ============================================================
    private function requireCsrf(): void
    {
        if (!Security::csrfValidate($_POST['csrf_token'] ?? null)) {
            http_response_code(403);
            echo 'CSRF inválido. Recarga la página.';
            exit;
        }
    }

    private function flash(string $type, string $msg): void
    {
        Security::startSession();
        $_SESSION['admin_flash'] = ['type' => $type, 'msg' => $msg];
    }

    private function redirect(string $to): void
    {
        header('Location: ' . $to, true, 303);
        exit;
    }
}
