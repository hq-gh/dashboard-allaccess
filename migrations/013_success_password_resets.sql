-- 013_success_password_resets.sql
-- Tokens de "olvidé mi contraseña" para el Portal de Success (rw2.5t4d10.com).
-- Tabla propia (separada de la de finanzas), ligada a public.success_users.
-- Idempotente.

CREATE TABLE IF NOT EXISTS public.success_password_resets (
    id         serial PRIMARY KEY,
    user_id    integer NOT NULL REFERENCES public.success_users(id) ON DELETE CASCADE,
    token      varchar NOT NULL UNIQUE,
    expires_at timestamptz NOT NULL,
    used       boolean NOT NULL DEFAULT FALSE,
    created_at timestamptz NOT NULL DEFAULT now()
);

CREATE INDEX IF NOT EXISTS idx_success_pwd_resets_token ON public.success_password_resets (token) WHERE used = FALSE;
