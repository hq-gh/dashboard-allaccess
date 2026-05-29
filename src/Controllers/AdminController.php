<?php declare(strict_types=1);

namespace App\Controllers;

use App\Auth;
use App\Repositories\ClassesRepo;
use App\Repositories\ProductMappingRepo;
use App\Repositories\SpacesRepo;
use App\Repositories\UsersRepo;
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
    // CLASSES (Bettermode)
    // ============================================================
    public function classesIndex(): void
    {
        Auth::requireAdmin();
        Security::startSession();
        $flash = $_SESSION['admin_flash'] ?? null;
        unset($_SESSION['admin_flash']);

        $repo = new ClassesRepo();
        $subdomain   = (string) ($_GET['subdomain'] ?? '');
        $onlyMissing = !empty($_GET['only_missing']);

        $rows        = $repo->listAllWithCounts($subdomain ?: null, $onlyMissing);
        $subdomains  = $repo->listSubdomains();

        View::render('admin/classes', [
            'title'        => 'Admin · Classes (Teams)',
            'active'       => 'admin',
            'rows'         => $rows,
            'subdomains'   => $subdomains,
            'subdomain'    => $subdomain,
            'only_missing' => $onlyMissing,
            'csrf'         => Security::csrfToken(),
            'flash'        => $flash,
        ]);
    }

    public function classesUpdate(): void
    {
        Auth::requireAdmin();
        $this->requireCsrf();

        $id        = (int) ($_POST['id'] ?? 0);
        $className = trim((string) ($_POST['class_name'] ?? ''));
        $isActive  = !empty($_POST['is_active']);

        if ($id <= 0) {
            $this->flash('error', 'ID inválido.');
            $this->redirect('/admin/classes');
            return;
        }
        try {
            (new ClassesRepo())->update($id, $className !== '' ? $className : null, $isActive);
            $this->flash('ok', "Class actualizada.");
        } catch (\Throwable $e) {
            $this->flash('error', 'Error: ' . $e->getMessage());
        }
        $this->redirect('/admin/classes' . ($_POST['return_subdomain'] ?? '' ? '?subdomain=' . urlencode((string) $_POST['return_subdomain']) : ''));
    }

    // ============================================================
    // ADMIN LANDING
    // ============================================================
    public function index(): void
    {
        Auth::requireAdmin();
        View::render('admin/index', ['title' => 'Administración', 'active' => 'admin']);
    }

    // ============================================================
    // USUARIOS
    // ============================================================
    public function usuariosIndex(): void
    {
        Auth::requireAdmin();
        Security::startSession();
        $flash = $_SESSION['admin_flash'] ?? null;
        unset($_SESSION['admin_flash']);

        $rows = (new UsersRepo())->listAll();
        $current = Auth::user();
        View::render('admin/usuarios', [
            'title'        => 'Admin · Usuarios',
            'active'       => 'admin',
            'rows'         => $rows,
            'csrf'         => Security::csrfToken(),
            'flash'        => $flash,
            'current_id'   => $current['id'] ?? 0,
        ]);
    }

    public function usuariosCreate(): void
    {
        Auth::requireAdmin();
        $this->requireCsrf();

        $name     = trim((string) ($_POST['name'] ?? ''));
        $email    = strtolower(trim((string) ($_POST['email'] ?? '')));
        $role     = trim((string) ($_POST['role'] ?? 'usuario'));
        $password = (string) ($_POST['password'] ?? '');

        if ($name === '' || $email === '' || $password === '') {
            $this->flash('error', 'Nombre, email y contraseña son obligatorios.');
            $this->redirect('/admin/usuarios');
            return;
        }
        if (!in_array($role, ['administrador', 'usuario'], true)) {
            $this->flash('error', 'Rol inválido.');
            $this->redirect('/admin/usuarios');
            return;
        }
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $this->flash('error', 'Email inválido.');
            $this->redirect('/admin/usuarios');
            return;
        }
        if (strlen($password) < 8) {
            $this->flash('error', 'La contraseña debe tener al menos 8 caracteres.');
            $this->redirect('/admin/usuarios');
            return;
        }
        $repo = new UsersRepo();
        if ($repo->existsByEmail($email)) {
            $this->flash('error', "Ya existe un usuario con email {$email}.");
            $this->redirect('/admin/usuarios');
            return;
        }
        try {
            $hash = password_hash($password, PASSWORD_BCRYPT);
            $id   = $repo->create($name, $email, $role, $hash);
            $this->flash('ok', "Usuario {$email} creado (id={$id}).");
        } catch (\Throwable $e) {
            $this->flash('error', 'Error: ' . $e->getMessage());
        }
        $this->redirect('/admin/usuarios');
    }

    public function usuariosUpdate(): void
    {
        Auth::requireAdmin();
        $this->requireCsrf();

        $id   = (int) ($_POST['id'] ?? 0);
        $name = trim((string) ($_POST['name'] ?? ''));
        $role = trim((string) ($_POST['role'] ?? 'usuario'));

        if ($id <= 0 || $name === '' || !in_array($role, ['administrador', 'usuario'], true)) {
            $this->flash('error', 'Datos inválidos.');
            $this->redirect('/admin/usuarios');
            return;
        }
        $repo = new UsersRepo();
        $target = $repo->findById($id);
        if ($target === null) {
            $this->flash('error', 'Usuario no encontrado.');
            $this->redirect('/admin/usuarios');
            return;
        }
        // Defensa: no permitir quitarse el rol admin al ultimo admin.
        $current = Auth::user();
        if ($current && (int) $current['id'] === $id && $target['role'] === 'administrador' && $role !== 'administrador') {
            if ($repo->countAdmins() <= 1) {
                $this->flash('error', 'No puedes degradarte: eres el único administrador.');
                $this->redirect('/admin/usuarios');
                return;
            }
        }
        try {
            $repo->update($id, $name, $role);
            $this->flash('ok', "Usuario {$target['email']} actualizado.");
        } catch (\Throwable $e) {
            $this->flash('error', 'Error: ' . $e->getMessage());
        }
        $this->redirect('/admin/usuarios');
    }

    public function usuariosResetPassword(): void
    {
        Auth::requireAdmin();
        $this->requireCsrf();

        $id = (int) ($_POST['id'] ?? 0);
        $password = (string) ($_POST['password'] ?? '');
        if ($id <= 0 || strlen($password) < 8) {
            $this->flash('error', 'Password mínimo 8 caracteres.');
            $this->redirect('/admin/usuarios');
            return;
        }
        $repo = new UsersRepo();
        $target = $repo->findById($id);
        if ($target === null) {
            $this->flash('error', 'Usuario no encontrado.');
            $this->redirect('/admin/usuarios');
            return;
        }
        try {
            $hash = password_hash($password, PASSWORD_BCRYPT);
            $repo->updatePassword($id, $hash);
            $this->flash('ok', "Password de {$target['email']} actualizado.");
        } catch (\Throwable $e) {
            $this->flash('error', 'Error: ' . $e->getMessage());
        }
        $this->redirect('/admin/usuarios');
    }

    public function usuariosDelete(): void
    {
        Auth::requireAdmin();
        $this->requireCsrf();

        $id = (int) ($_POST['id'] ?? 0);
        if ($id <= 0) {
            $this->flash('error', 'ID inválido.');
            $this->redirect('/admin/usuarios');
            return;
        }
        $current = Auth::user();
        if ($current && (int) $current['id'] === $id) {
            $this->flash('error', 'No puedes eliminar tu propia cuenta.');
            $this->redirect('/admin/usuarios');
            return;
        }
        $repo = new UsersRepo();
        $target = $repo->findById($id);
        if ($target === null) {
            $this->flash('error', 'Usuario no encontrado.');
            $this->redirect('/admin/usuarios');
            return;
        }
        // Defensa: no permitir eliminar al ultimo administrador.
        if ($target['role'] === 'administrador' && $repo->countAdmins() <= 1) {
            $this->flash('error', 'No puedes eliminar al único administrador.');
            $this->redirect('/admin/usuarios');
            return;
        }
        try {
            $repo->delete($id);
            $this->flash('ok', "Usuario {$target['email']} eliminado.");
        } catch (\Throwable $e) {
            $this->flash('error', 'Error: ' . $e->getMessage());
        }
        $this->redirect('/admin/usuarios');
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
