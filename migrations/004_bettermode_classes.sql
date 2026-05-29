-- =====================================================================
-- Portal rw2.5t4d10.com — Migración 004
-- Tabla bettermode_classes: mapeo (subdomain, class_id) -> class_name.
--
-- El nombre humano del class (ej. "Team 39 -MCB-") NO viene en la API
-- de Hotmart Club: ni en /v1/users ni en /v1/modules. Solo aparece
-- embebido en /v2/modules/{id}/pages.dripping_configs[].classes[] cuando
-- la page tiene dripping POR CLASE configurado — caso minoritario.
--
-- Solución: seed automático de class_ids descubiertos en club_students,
-- y nombres llenados a mano desde /admin/classes (UI CRUD).
-- =====================================================================

CREATE TABLE IF NOT EXISTS public.bettermode_classes (
    id           BIGSERIAL PRIMARY KEY,
    subdomain    TEXT NOT NULL,
    class_id     TEXT NOT NULL,
    class_name   TEXT,
    is_active    BOOLEAN NOT NULL DEFAULT TRUE,
    created_at   TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    updated_at   TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    UNIQUE (subdomain, class_id)
);
CREATE INDEX IF NOT EXISTS idx_bmc_subdomain ON public.bettermode_classes (subdomain);
CREATE INDEX IF NOT EXISTS idx_bmc_class_id  ON public.bettermode_classes (class_id);

DROP TRIGGER IF EXISTS trg_bmc_updated_at ON public.bettermode_classes;
CREATE TRIGGER trg_bmc_updated_at BEFORE UPDATE ON public.bettermode_classes
    FOR EACH ROW EXECUTE FUNCTION public.tg_set_updated_at();

-- Seed automático: todos los (subdomain, class_id) ya presentes en club_students.
INSERT INTO public.bettermode_classes (subdomain, class_id)
SELECT DISTINCT subdomain, class_id
  FROM public.club_students
 WHERE class_id IS NOT NULL AND class_id <> ''
ON CONFLICT (subdomain, class_id) DO NOTHING;
