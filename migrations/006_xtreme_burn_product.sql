-- =====================================================================
-- Portal rw2.5t4d10.com — Migración 006
-- Lanzamiento producto StandAlone "XTREME BURN" (Hotmart product_id 7815025).
--   - Nuevo product_key 'xtreme_burn' (replica patrón mommy_comeback:
--     8 spaces comunes de onboarding + 2 spaces propios del programa = 10).
--   - Mapeo Hotmart 7815025 -> xtreme_burn. El webhook, al recibir
--     PURCHASE_APPROVED/COMPLETE de ese product_id, crea la cuenta en
--     Bettermode si no existe, verifica el email y asigna los 10 spaces.
--   - member_field_key = NULL (no setea custom field; igual que infinity_vip).
-- Además: completa infinity_vip con "-INFINITY VIP- XTREME BURN".
--   (8EIGHT MAX. y "-INFINITY VIP- Retos y Más" ya estaban en la BD por panel admin.)
-- Idempotente: ON CONFLICT DO NOTHING / DO UPDATE.
-- =====================================================================

-- 1) infinity_vip: agregar el space que faltaba
INSERT INTO public.bettermode_spaces (product_key, space_id, space_name, sort_order) VALUES
    ('infinity_vip', 'lt3hvpzqHzJS', '-INFINITY VIP- XTREME BURN', 3)
ON CONFLICT (product_key, space_id) DO NOTHING;

-- 2) product_keys_config: nuevo product_key xtreme_burn (sin member field)
INSERT INTO public.product_keys_config (product_key, member_field_key, description) VALUES
    ('xtreme_burn', NULL, 'Producto StandAlone XTREME BURN (10 spaces: 8 comunes + 2 del programa). Hotmart 7815025. NO setea member field.')
ON CONFLICT (product_key) DO UPDATE SET
    member_field_key = EXCLUDED.member_field_key,
    description      = EXCLUDED.description;

-- 3) Mapeo Hotmart product_id -> xtreme_burn
INSERT INTO public.hotmart_product_mapping (hotmart_product_id, product_key, product_name) VALUES
    ('7815025', 'xtreme_burn', 'XTREME BURN')
ON CONFLICT (hotmart_product_id) DO UPDATE SET
    product_key  = EXCLUDED.product_key,
    product_name = EXCLUDED.product_name;

-- 4) Spaces de xtreme_burn (8 comunes de onboarding + 2 propios) = 10
INSERT INTO public.bettermode_spaces (product_key, space_id, space_name, sort_order) VALUES
    ('xtreme_burn', 'r7VKMrEnyrlG', 'Inicio',                          1),
    ('xtreme_burn', 'oOxC5CuPgfKw', 'Guía de Inicio 5T4D10',           2),
    ('xtreme_burn', 'g0tgqm8Q5H12', 'Preséntate a la Familia 5T4D10',  3),
    ('xtreme_burn', 'Wt2AcOQKyfBI', 'Normas y Reglamentos',            4),
    ('xtreme_burn', 'balhmNDyOHq1', 'Noticias Importantes',            5),
    ('xtreme_burn', 'NaqbL4p9ozU0', 'Guías y Tutoriales',              6),
    ('xtreme_burn', 'GN5WeKwix0ia', 'Nutrición Adicional',             7),
    ('xtreme_burn', 'RSsaEWDqbmuh', 'Soporte 5T4D10',                  8),
    ('xtreme_burn', 'GD8b7iMXB8jv', 'XTREME BURN',                    10),
    ('xtreme_burn', 'VAo3TjguHoIt', 'XTREME BURN.',                   11)
ON CONFLICT (product_key, space_id) DO NOTHING;
