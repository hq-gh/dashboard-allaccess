-- 011_team_based_rules.sql
-- Motor de permisos v2: agrega modelo team_based, columnas de vigencia calculada,
-- y el catálogo completo de programas 5T4D10 (8EIGHT standalone + MOMMY team_based).
-- Idempotente: re-ejecutable sin efectos secundarios.
-- Reglas: ~/Desktop/Notes/20260613_ReglasNuevasBettermode.txt

BEGIN;

-- ============================================================
-- 1.1 program_config: nuevo access_type team_based + source club_students + subdomain
-- ============================================================
ALTER TABLE public.program_config DROP CONSTRAINT IF EXISTS program_config_access_type_check;
ALTER TABLE public.program_config ADD CONSTRAINT program_config_access_type_check
  CHECK (access_type IN ('subscription','team_based','fixed_days'));

ALTER TABLE public.program_config DROP CONSTRAINT IF EXISTS program_config_source_table_check;
ALTER TABLE public.program_config ADD CONSTRAINT program_config_source_table_check
  CHECK (source_table IN ('subscriptions','sales','club_students'));

ALTER TABLE public.program_config ADD COLUMN IF NOT EXISTS subdomain TEXT;

-- ============================================================
-- 1.2 user_program_validity: columnas de vigencia calculada (regla 3.4)
-- ============================================================
ALTER TABLE public.user_program_validity ADD COLUMN IF NOT EXISTS access_type  TEXT;
ALTER TABLE public.user_program_validity ADD COLUMN IF NOT EXISTS access_start TIMESTAMPTZ;
ALTER TABLE public.user_program_validity ADD COLUMN IF NOT EXISTS access_end   TIMESTAMPTZ;
CREATE INDEX IF NOT EXISTS idx_upv_run_valid
  ON public.user_program_validity (run_id, product_key) WHERE is_valid;

-- ============================================================
-- 1.3 program_config: catálogo (10 product_keys)
--   subscription = ACTIVE estricto (sin gracia, decisión de Rub).
--   team_based = vigencia por Team del Club (subdomain).
--   fixed_days = N días desde compra.
-- ============================================================
INSERT INTO public.program_config
  (product_key, access_type, source_table, requires_active_subscription,
   valid_days, requires_program_key, subdomain, valid_statuses, is_active, sort_order) VALUES
  ('infinity',        'subscription','subscriptions', TRUE,  NULL, NULL,       NULL,                   ARRAY['ACTIVE'],               TRUE, 1),
  ('infinity_vip',    'subscription','subscriptions', TRUE,  NULL, 'infinity', NULL,                   ARRAY['ACTIVE'],               TRUE, 2),
  ('mommy_comeback',  'team_based',  'club_students', FALSE, NULL, NULL,       'mommycomeback-mnlkpy', ARRAY[]::text[],               TRUE, 3),
  ('8eight_fit',      'team_based',  'club_students', FALSE, NULL, NULL,       '8eightfit1',           ARRAY[]::text[],               TRUE, 4),
  ('8eight_fullbody', 'team_based',  'club_students', FALSE, NULL, NULL,       '8eightfit2',           ARRAY[]::text[],               TRUE, 5),
  ('8eight_mix',      'team_based',  'club_students', FALSE, NULL, NULL,       '8eightmix',            ARRAY[]::text[],               TRUE, 6),
  ('8eight_burn',     'team_based',  'club_students', FALSE, NULL, NULL,       '8eightburn',           ARRAY[]::text[],               TRUE, 7),
  ('8eight_strong',   'team_based',  'club_students', FALSE, NULL, NULL,       '8eightstrong',         ARRAY[]::text[],               TRUE, 8),
  ('8eight_max',      'team_based',  'club_students', FALSE, NULL, NULL,       '8eightmax',            ARRAY[]::text[],               TRUE, 9),
  ('xtreme_burn',     'fixed_days',  'sales',         FALSE, 24,   NULL,       NULL,                   ARRAY['COMPLETE','APPROVED'],  TRUE, 10)
ON CONFLICT (product_key) DO UPDATE SET
  access_type=EXCLUDED.access_type, source_table=EXCLUDED.source_table,
  requires_active_subscription=EXCLUDED.requires_active_subscription,
  valid_days=EXCLUDED.valid_days, requires_program_key=EXCLUDED.requires_program_key,
  subdomain=EXCLUDED.subdomain, valid_statuses=EXCLUDED.valid_statuses,
  is_active=TRUE, sort_order=EXCLUDED.sort_order, updated_at=NOW();

-- ============================================================
-- 1.4 hotmart_product_mapping: product_id -> product_key (para webhook/identidad)
-- ============================================================
INSERT INTO public.hotmart_product_mapping (hotmart_product_id, product_key, product_name) VALUES
  ('6454766','infinity','5T4D10 Infinity'),
  ('7065704','infinity','Totalplay Infinity M.'),
  ('6952229','infinity','Totalplay Infinity A'),
  ('6587403','infinity_vip','Infinity VIP'),
  ('7005612','infinity_vip','Totalplay Infinity VIP A'),
  ('7005981','infinity_vip','Totalplay Infinity VIP M'),
  ('7455277','mommy_comeback','MOMMY COMEBACK'),
  ('6119893','8eight_fit','-8EIGHT FIT-'),
  ('6129636','8eight_fullbody','-8EIGHT FULLBODY-'),
  ('6518692','8eight_mix','-8EIGHT MIX-'),
  ('7057252','8eight_burn','-8EIGHT BURN-'),
  ('7500249','8eight_strong','-8EIGHT STRONG-'),
  ('7916708','8eight_max','-8EIGHT MAX-'),
  ('7815025','xtreme_burn','XTREME BURN')
ON CONFLICT (hotmart_product_id) DO UPDATE SET
  product_key=EXCLUDED.product_key, product_name=EXCLUDED.product_name,
  is_active=TRUE, updated_at=NOW();

-- ============================================================
-- 1.5 bettermode_spaces
--   (a) Espacios COMUNES (8) para los programas que aún no los tienen completos:
--       mommy/xtreme (reactiva Soporte, antes desactivado) + los 6 nuevos 8EIGHT.
--       infinity ya tiene sus 20; infinity_vip recibe los comunes vía dependencia.
-- ============================================================
WITH common(space_id, space_name, so) AS (VALUES
    ('r7VKMrEnyrlG','Inicio',1),
    ('oOxC5CuPgfKw','Guía de Inicio 5T4D10',2),
    ('g0tgqm8Q5H12','Preséntate a la Familia 5T4D10',3),
    ('Wt2AcOQKyfBI','Normas y Reglamentos',4),
    ('balhmNDyOHq1','Noticias Importantes',5),
    ('NaqbL4p9ozU0','Guías y Tutoriales',6),
    ('GN5WeKwix0ia','Nutrición Adicional',7),
    ('RSsaEWDqbmuh','Soporte 5T4D10',8)
),
pk(product_key) AS (VALUES
    ('mommy_comeback'),('xtreme_burn'),
    ('8eight_fit'),('8eight_fullbody'),('8eight_mix'),('8eight_burn'),('8eight_strong'),('8eight_max')
)
INSERT INTO public.bettermode_spaces (product_key, space_id, space_name, sort_order, is_active)
SELECT pk.product_key, common.space_id, common.space_name, common.so, TRUE
  FROM pk CROSS JOIN common
ON CONFLICT (product_key, space_id) DO UPDATE SET
  is_active=TRUE, space_name=EXCLUDED.space_name, sort_order=EXCLUDED.sort_order, updated_at=NOW();

--   (b) Espacios PROPIOS de cada 8EIGHT standalone (mismos space_id que en infinity).
INSERT INTO public.bettermode_spaces (product_key, space_id, space_name, sort_order, is_active) VALUES
  ('8eight_fit',      'nReLMzJ5zyso','8EIGHT FIT',       10, TRUE),
  ('8eight_fit',      'zFbx6oNrHBT3','8EIGHT FIT.',      11, TRUE),
  ('8eight_fullbody', 'DHV6bnpsshCQ','8EIGHT FULLBODY',  10, TRUE),
  ('8eight_fullbody', 'KrphidYqm1zz','8EIGHT FULLBODY.', 11, TRUE),
  ('8eight_mix',      '15POpODC8df3','8EIGHT MIX',       10, TRUE),
  ('8eight_mix',      'izBz5Sek7dje','8EIGHT MIX.',      11, TRUE),
  ('8eight_burn',     'UvJyY05GXiso','8EIGHT BURN',      10, TRUE),
  ('8eight_burn',     'oIRTek99y1Rs','8EIGHT BURN.',     11, TRUE),
  ('8eight_strong',   'pASlHStGZrdQ','8EIGHT STRONG',    10, TRUE),
  ('8eight_strong',   '8stPptRCUOfd','8EIGHT STRONG.',   11, TRUE),
  ('8eight_max',      'TB0GKqbip0ia','8EIGHT MAX',       10, TRUE),
  ('8eight_max',      'ofXathJUIaDh','8EIGHT MAX.',      11, TRUE)
ON CONFLICT (product_key, space_id) DO UPDATE SET
  is_active=TRUE, space_name=EXCLUDED.space_name, sort_order=EXCLUDED.sort_order, updated_at=NOW();

COMMIT;
