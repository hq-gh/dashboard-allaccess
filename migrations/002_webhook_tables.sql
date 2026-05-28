-- =====================================================================
-- Portal rw2.5t4d10.com — Migración 002
-- Webhook Hotmart -> Bettermode: tablas de configuración + auditoría.
-- =====================================================================

-- 1) Mapeo producto Hotmart -> grupo interno (product_key)
CREATE TABLE IF NOT EXISTS public.hotmart_product_mapping (
    hotmart_product_id  TEXT PRIMARY KEY,
    product_key         TEXT NOT NULL,
    product_name        TEXT,
    is_active           BOOLEAN NOT NULL DEFAULT TRUE,
    created_at          TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    updated_at          TIMESTAMPTZ NOT NULL DEFAULT NOW()
);
CREATE INDEX IF NOT EXISTS idx_hpm_product_key ON public.hotmart_product_mapping (product_key) WHERE is_active = TRUE;

-- 2) Spaces de Bettermode por product_key
CREATE TABLE IF NOT EXISTS public.bettermode_spaces (
    id           BIGSERIAL PRIMARY KEY,
    product_key  TEXT NOT NULL,
    space_id     TEXT NOT NULL,
    space_name   TEXT NOT NULL,
    is_active    BOOLEAN NOT NULL DEFAULT TRUE,
    sort_order   INT NOT NULL DEFAULT 0,
    created_at   TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    updated_at   TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    UNIQUE (product_key, space_id)
);
CREATE INDEX IF NOT EXISTS idx_bms_product_key ON public.bettermode_spaces (product_key) WHERE is_active = TRUE;

-- 3) Auditoría de eventos del webhook
CREATE TABLE IF NOT EXISTS public.infinity_webhook_events (
    id                   BIGSERIAL PRIMARY KEY,
    received_at          TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    event_type           TEXT NOT NULL,
    hotmart_product_id   TEXT,
    product_key          TEXT,
    email                TEXT,
    member_id            TEXT,
    transaction_id       TEXT,
    action_taken         TEXT NOT NULL CHECK (action_taken IN ('grant', 'revoke', 'ignored', 'failed')),
    spaces_ok            INT NOT NULL DEFAULT 0,
    spaces_failed        INT NOT NULL DEFAULT 0,
    status               TEXT NOT NULL CHECK (status IN ('success', 'partial', 'failed', 'ignored', 'invalid')),
    message              TEXT,
    payload_json         JSONB,
    dedup_key            TEXT UNIQUE
);
CREATE INDEX IF NOT EXISTS idx_iwe_received   ON public.infinity_webhook_events (received_at DESC);
CREATE INDEX IF NOT EXISTS idx_iwe_email      ON public.infinity_webhook_events (email);
CREATE INDEX IF NOT EXISTS idx_iwe_status     ON public.infinity_webhook_events (status);
CREATE INDEX IF NOT EXISTS idx_iwe_event_type ON public.infinity_webhook_events (event_type);

-- Triggers updated_at
CREATE OR REPLACE FUNCTION public.tg_set_updated_at() RETURNS TRIGGER AS $$
BEGIN
    NEW.updated_at = NOW();
    RETURN NEW;
END;
$$ LANGUAGE plpgsql;

DROP TRIGGER IF EXISTS trg_hpm_updated_at ON public.hotmart_product_mapping;
CREATE TRIGGER trg_hpm_updated_at BEFORE UPDATE ON public.hotmart_product_mapping
    FOR EACH ROW EXECUTE FUNCTION public.tg_set_updated_at();

DROP TRIGGER IF EXISTS trg_bms_updated_at ON public.bettermode_spaces;
CREATE TRIGGER trg_bms_updated_at BEFORE UPDATE ON public.bettermode_spaces
    FOR EACH ROW EXECUTE FUNCTION public.tg_set_updated_at();

-- =====================================================================
-- SEED
-- =====================================================================

-- Mapeo productos Hotmart -> grupo
INSERT INTO public.hotmart_product_mapping (hotmart_product_id, product_key, product_name) VALUES
    ('6454766', 'infinity',     '5T4D10 Infinity'),
    ('7065704', 'infinity',     'Totalplay Infinity M.'),
    ('6952229', 'infinity',     'Infinity (otro)'),
    ('6587403', 'infinity_vip', 'Infinity VIP'),
    ('7005612', 'infinity_vip', 'Infinity VIP (variante)'),
    ('7005981', 'infinity_vip', 'Infinity VIP (variante)')
ON CONFLICT (hotmart_product_id) DO UPDATE SET
    product_key  = EXCLUDED.product_key,
    product_name = EXCLUDED.product_name;

-- Spaces Bettermode: Infinity (19 espacios)
INSERT INTO public.bettermode_spaces (product_key, space_id, space_name, sort_order) VALUES
    ('infinity', 'r7VKMrEnyrlG', 'Inicio',                          1),
    ('infinity', 'oOxC5CuPgfKw', 'Guía de Inicio 5T4D10',           2),
    ('infinity', 'g0tgqm8Q5H12', 'Preséntate a la Familia 5T4D10',  3),
    ('infinity', 'Wt2AcOQKyfBI', 'Normas y Reglamentos',            4),
    ('infinity', 'balhmNDyOHq1', 'Noticias Importantes',            5),
    ('infinity', 'NaqbL4p9ozU0', 'Guías y Tutoriales',              6),
    ('infinity', 'GN5WeKwix0ia', 'Nutrición Adicional',             7),
    ('infinity', 'RSsaEWDqbmuh', 'Soporte 5T4D10',                  8),
    ('infinity', 'nReLMzJ5zyso', '8EIGHT FIT',                     10),
    ('infinity', 'zFbx6oNrHBT3', '8EIGHT FIT.',                    11),
    ('infinity', 'DHV6bnpsshCQ', '8EIGHT FULLBODY',                12),
    ('infinity', 'KrphidYqm1zz', '8EIGHT FULLBODY.',               13),
    ('infinity', '15POpODC8df3', '8EIGHT MIX',                     14),
    ('infinity', 'izBz5Sek7dje', '8EIGHT MIX.',                    15),
    ('infinity', 'UvJyY05GXiso', '8EIGHT BURN',                    16),
    ('infinity', 'oIRTek99y1Rs', '8EIGHT BURN.',                   17),
    ('infinity', 'pASlHStGZrdQ', '8EIGHT STRONG',                  18),
    ('infinity', '8stPptRCUOfd', '8EIGHT STRONG.',                 19),
    ('infinity', 'TB0GKqbip0ia', '8EIGHT MAX',                     20)
ON CONFLICT (product_key, space_id) DO NOTHING;

-- Spaces Bettermode: Infinity VIP (1 espacio)
INSERT INTO public.bettermode_spaces (product_key, space_id, space_name, sort_order) VALUES
    ('infinity_vip', 'OnOLt4PLDpGe', 'Espacio VIP - ALL ACCESS', 1)
ON CONFLICT (product_key, space_id) DO NOTHING;
