<?php declare(strict_types=1);

namespace App;

/**
 * Helper minimalista para renderizar templates PHP en src/Views/.
 *
 * Uso:
 *   View::render('pecadores', ['title'=>'Pecadores', 'rows'=>$rows]);
 *
 * Si la view incluye $useLayout=false al inicio, se renderiza sin layout.
 * El layout espera la variable $content (HTML del cuerpo) + $title + $active.
 */
final class View
{
    private const VIEWS_DIR  = __DIR__ . '/Views';
    private const LAYOUT     = '_layout';

    public static function render(string $view, array $vars = [], bool $useLayout = true): void
    {
        Security::applyHtmlHeaders();
        header('Content-Type: text/html; charset=utf-8');

        $file = self::VIEWS_DIR . '/' . $view . '.php';
        if (!is_file($file)) {
            throw new \RuntimeException("View no encontrada: {$view}");
        }

        if (!$useLayout) {
            extract($vars, EXTR_SKIP);
            require $file;
            return;
        }

        // Capturar contenido del view, luego renderizar layout.
        ob_start();
        extract($vars, EXTR_SKIP);
        require $file;
        $content = ob_get_clean();

        $title  = (string) ($vars['title']  ?? 'Portal 5T4D10');
        $active = (string) ($vars['active'] ?? '');

        require self::VIEWS_DIR . '/' . self::LAYOUT . '.php';
    }
}
