-- =====================================================================
-- Portal rw2.5t4d10.com — Migración 003
-- Tabla product_keys_config: configuración por product_key (campo de miembro,
-- descripción). Habilita que cada "grupo" (infinity/mommy_comeback/etc.) tenga
-- su propio member field a actualizar tras un grant, sin hardcodear en código.
--
-- Además: seed para mommy_comeback (10 spaces) + nuevo producto Hotmart 7455277.
-- =====================================================================

CREATE TABLE IF NOT EXISTS public.product_keys_config (
    product_key       TEXT PRIMARY KEY,
    member_field_key  TEXT,                              -- key del custom field en Bettermode (NULL = no actualizar)
    description       TEXT,
    is_active         BOOLEAN NOT NULL DEFAULT TRUE,
    created_at        TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    updated_at        TIMESTAMPTZ NOT NULL DEFAULT NOW()
);

DROP TRIGGER IF EXISTS trg_pkc_updated_at ON public.product_keys_config;
CREATE TRIGGER trg_pkc_updated_at BEFORE UPDATE ON public.product_keys_config
    FOR EACH ROW EXECUTE FUNCTION public.tg_set_updated_at();

-- Seed: configuración por product_key conocido
INSERT INTO public.product_keys_config (product_key, member_field_key, description) VALUES
    ('infinity',       'Infinity', 'Producto Infinity (19 spaces). Webhook setea campo Infinity=true.'),
    ('mommy_comeback', 'MCB',      'Producto Mommy Comeback (10 spaces). Webhook setea campo MCB=true.'),
    ('infinity_vip',   NULL,       'Producto Infinity VIP (1 space). NO setea campo; el cron diario reconcilia.')
ON CONFLICT (product_key) DO UPDATE SET
    member_field_key = EXCLUDED.member_field_key,
    description      = EXCLUDED.description;

-- Mapeo Hotmart product_id -> mommy_comeback
INSERT INTO public.hotmart_product_mapping (hotmart_product_id, product_key, product_name) VALUES
    ('7455277', 'mommy_comeback', '- Mommy Comeback -')
ON CONFLICT (hotmart_product_id) DO UPDATE SET
    product_key  = EXCLUDED.product_key,
    product_name = EXCLUDED.product_name;

-- Spaces de Mommy Comeback (10)
INSERT INTO public.bettermode_spaces (product_key, space_id, space_name, sort_order) VALUES
    ('mommy_comeback', 'r7VKMrEnyrlG', 'Inicio',                          1),
    ('mommy_comeback', 'oOxC5CuPgfKw', 'Guía de Inicio 5T4D10',           2),
    ('mommy_comeback', 'g0tgqm8Q5H12', 'Preséntate a la Familia 5T4D10',  3),
    ('mommy_comeback', 'Wt2AcOQKyfBI', 'Normas y Reglamentos',            4),
    ('mommy_comeback', 'balhmNDyOHq1', 'Noticias Importantes',            5),
    ('mommy_comeback', 'NaqbL4p9ozU0', 'Guías y Tutoriales',              6),
    ('mommy_comeback', 'GN5WeKwix0ia', 'Nutrición Adicional',             7),
    ('mommy_comeback', 'RSsaEWDqbmuh', 'Soporte 5T4D10',                  8),
    ('mommy_comeback', 'xweN84nq5IPu', 'MOMMY COMEBACK',                 10),
    ('mommy_comeback', 'z4sFKKBvyPa5', 'MOMMY COMEBACK 2',               11)
ON CONFLICT (product_key, space_id) DO NOTHING;
