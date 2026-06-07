-- =====================================================================
-- Portal rw2.5t4d10.com — Migración 009
-- Lista blanca de cuentas protegidas: NUNCA se les revoca acceso por el sync de
-- permisos (cortesías, accesos manuales, equipo no detectado por rol, etc.).
-- Los admins/staff de Bettermode se excluyen automáticamente por rol; esta tabla
-- es para casos que no se detectan por rol.
-- =====================================================================
CREATE TABLE IF NOT EXISTS public.protected_members (
    email      TEXT PRIMARY KEY,
    reason     TEXT,
    created_at TIMESTAMPTZ NOT NULL DEFAULT NOW()
);
