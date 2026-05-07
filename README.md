# Dashboard Infinity VIP → INFINITY

Sistema 5T4D10 para identificar usuarios con Infinity VIP que aún no tienen INFINITY (oportunidades de conversión).

**Producción**: https://rw2.5t4d10.com/

## Stack

- FrankenPHP 8.4.20 (Railway)
- PostgreSQL (Neon.tech)
- HTML/CSS/JS vanilla

## Variables de entorno requeridas

Sin estas variables, la app **no arranca** (ya no hay fallbacks hardcoded):

```
DASHBOARD_USER       Usuario del dashboard
DASHBOARD_PASS       Contraseña del dashboard
DB_HOST              Host PostgreSQL Neon
DB_NAME              Nombre de la base de datos
DB_USER              Usuario PostgreSQL
DB_PASS              Password PostgreSQL
```

Configurar en Railway → Service → Variables.

## Estructura

```
├── index.php           Dashboard principal (requiere auth)
├── login.php           Login con CSRF + rate limit
├── logout.php          Destrucción de sesión
├── sync.php            API JSON de datos
├── export-excel.php    Exportación CSV (RFC 4180)
├── config.php          Conexión BD + helpers de seguridad
├── composer.json       Dependencias PHP
└── .gitignore          Ignora .env y archivos debug
```

## Limpieza pendiente en producción

Si existen estos archivos en el servidor, **eliminarlos**:

- `debug.php`
- `debug-auth.php`
- `debug-status.php`
- `investigar-estructura.php`

```bash
# Desde el repo local
git rm -f debug.php debug-auth.php debug-status.php investigar-estructura.php
git commit -m "Eliminar archivos debug de producción"
git push
```

## Seguridad implementada

- ✅ Sin credenciales hardcoded (env vars obligatorias)
- ✅ `hash_equals()` para comparación de password (constant-time)
- ✅ `session_regenerate_id(true)` post-login (anti session-fixation)
- ✅ Rate limit: 5 intentos / 15 min de bloqueo
- ✅ Token CSRF en login
- ✅ Cookie de sesión: HttpOnly + Secure + SameSite=Lax
- ✅ Timeout de sesión: 8 horas
- ✅ Headers: CSP, X-Frame-Options, HSTS, X-Content-Type-Options
- ✅ Escape HTML en JS (anti-XSS)
- ✅ CSV escape RFC 4180
- ✅ Logs a stderr (visibles en Railway)
- ✅ TLS forzado en conexión PostgreSQL (`sslmode=require`)

## Lógica de negocio

- **Pecadores**: Infinity VIP activo SIN INFINITY → oportunidades
- **No Pecadores**: Infinity VIP activo CON INFINITY → ya convertidos
- **Product IDs**:
  - Infinity VIP: `6587403`
  - INFINITY: `6454766`, `7065704`, `6952229`

Query principal en `sync.php` y `export-excel.php` (idénticas).

## Cambios v3.1.0

- Hardening completo de seguridad
- CSRF + rate limiting + constant-time compare
- Headers de seguridad estándar
- Locale `es-MX` en frontend
- Key `total_infinity_vip` en API (con fallback a `total_all_access`)
- Escape CSV correcto RFC 4180
- Logs estructurados a stderr
