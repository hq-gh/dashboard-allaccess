-- 012_success_users.sql
-- Separa los usuarios del portal de Success (rw2.5t4d10.com) de los del dashboard
-- de finanzas (dashboard.5t4d10.com). Antes AMBOS autenticaban contra public.users
-- (riesgo de seguridad: un usuario de un portal entraba al otro). Ahora rw2 usa su
-- PROPIA tabla public.success_users; public.users queda exclusiva de finanzas.
-- Idempotente.

CREATE TABLE IF NOT EXISTS public.success_users (
    id            serial PRIMARY KEY,
    email         varchar NOT NULL UNIQUE,
    name          varchar,
    password_hash varchar NOT NULL,
    created_at    timestamptz DEFAULT now(),
    role          text NOT NULL DEFAULT 'usuario' CHECK (role IN ('administrador','usuario')),
    updated_at    timestamptz,
    last_login_at timestamptz
);

-- Siembra inicial: copia los usuarios actuales (decisión de Rub: copiar los 4 con
-- su mismo password_hash para no perder acceso; luego se depura quién pertenece a
-- cada portal). Solo inserta los que falten (por email), así que re-correr no duplica.
INSERT INTO public.success_users (email, name, password_hash, created_at, role, updated_at, last_login_at)
SELECT u.email, u.name, u.password_hash, u.created_at, u.role, u.updated_at, u.last_login_at
  FROM public.users u
 WHERE NOT EXISTS (
     SELECT 1 FROM public.success_users s WHERE LOWER(s.email) = LOWER(u.email)
 );
