<?php declare(strict_types=1);

namespace App;

/**
 * Router minimalista: match exacto y parámetros estilo {id}.
 *
 * Uso:
 *   $r = new Router();
 *   $r->get('/', fn() => ...);
 *   $r->get('/vip/corridas/{id}', fn($id) => ...);
 *   $r->dispatch();
 */
final class Router
{
    /** @var array<int, array{method:string, pattern:string, handler:callable}> */
    private array $routes = [];

    public function get(string $pattern, callable $handler): void
    {
        $this->routes[] = ['method' => 'GET', 'pattern' => $pattern, 'handler' => $handler];
    }

    public function post(string $pattern, callable $handler): void
    {
        $this->routes[] = ['method' => 'POST', 'pattern' => $pattern, 'handler' => $handler];
    }

    public function dispatch(): void
    {
        $method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
        $uri    = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?? '/';
        if ($uri !== '/' && substr($uri, -1) === '/') {
            $uri = rtrim($uri, '/');
        }

        foreach ($this->routes as $r) {
            if ($r['method'] !== $method) continue;
            $regex = '#^' . preg_replace('#\{([a-z_][a-z0-9_]*)\}#', '([^/]+)', $r['pattern']) . '$#i';
            if (preg_match($regex, $uri, $m)) {
                array_shift($m);
                ($r['handler'])(...$m);
                return;
            }
        }

        http_response_code(404);
        header('Content-Type: text/html; charset=utf-8');
        echo '<!doctype html><meta charset=utf-8><title>404</title>'
           . '<style>body{font-family:system-ui;background:#0a0a0b;color:#fff;display:grid;place-items:center;height:100vh}</style>'
           . '<div style="text-align:center"><h1>404</h1><p>No encontrado: ' . Security::e($uri) . '</p>'
           . '<p><a href="/" style="color:#FF6687">Volver al inicio</a></p></div>';
    }
}
