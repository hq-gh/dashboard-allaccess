-- =====================================================================
-- Portal rw2.5t4d10.com — Migración 001
-- Extiende la tabla public.users (compartida con DASHBOARD_5T4D10_PTT).
-- Agrega columnas SIN romper schema existente.
-- =====================================================================

ALTER TABLE public.users
    ADD COLUMN IF NOT EXISTS role          TEXT NOT NULL DEFAULT 'usuario',
    ADD COLUMN IF NOT EXISTS updated_at    TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    ADD COLUMN IF NOT EXISTS last_login_at TIMESTAMPTZ;

-- Restricción de valores de role (idempotente).
DO $$
BEGIN
    IF NOT EXISTS (SELECT 1 FROM pg_constraint WHERE conname = 'users_role_check') THEN
        ALTER TABLE public.users
            ADD CONSTRAINT users_role_check CHECK (role IN ('administrador', 'usuario'));
    END IF;
END $$;

CREATE INDEX IF NOT EXISTS idx_users_email_lower ON public.users (LOWER(email));
CREATE INDEX IF NOT EXISTS idx_users_role        ON public.users (role);

-- Trigger updated_at.
CREATE OR REPLACE FUNCTION public.users_set_updated_at() RETURNS TRIGGER AS $$
BEGIN
    NEW.updated_at = NOW();
    RETURN NEW;
END;
$$ LANGUAGE plpgsql;

DROP TRIGGER IF EXISTS trg_users_updated_at ON public.users;
CREATE TRIGGER trg_users_updated_at
    BEFORE UPDATE ON public.users
    FOR EACH ROW EXECUTE FUNCTION public.users_set_updated_at();

-- Asignar rol administrador al superusuario.
UPDATE public.users SET role = 'administrador' WHERE LOWER(email) = 'hq@5t4d10.com';
