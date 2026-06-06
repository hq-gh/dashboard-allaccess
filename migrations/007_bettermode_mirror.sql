-- =====================================================================
-- Portal rw2.5t4d10.com — Migración 007
-- Espejo en BD de Bettermode (diez.5t4d10.com):
--   - bettermode_members        : 1 fila por miembro de la red (perfil).
--   - bettermode_member_spaces  : 1 fila por (miembro x espacio) -> membresía.
-- Se pueblan vía bin/bettermode-mirror-sync.php (API GraphQL de Bettermode).
-- =====================================================================

-- Perfil de cada miembro (≈6.2k)
CREATE TABLE IF NOT EXISTS public.bettermode_members (
    member_id      TEXT PRIMARY KEY,
    email          TEXT,
    name           TEXT,
    username       TEXT,
    status         TEXT,                 -- VERIFIED / UNVERIFIED / Suspended / DELETED
    email_status   TEXT,
    role_id        TEXT,
    locale         TEXT,
    time_zone      TEXT,
    external_id    TEXT,
    relative_url   TEXT,
    bm_created_at  TIMESTAMPTZ,
    bm_updated_at  TIMESTAMPTZ,
    last_seen_at   TIMESTAMPTZ,
    verified_at    TIMESTAMPTZ,
    raw            JSONB,
    synced_at      TIMESTAMPTZ NOT NULL DEFAULT NOW()
);
CREATE INDEX IF NOT EXISTS idx_bm_members_email  ON public.bettermode_members (LOWER(email));
CREATE INDEX IF NOT EXISTS idx_bm_members_status ON public.bettermode_members (status);

-- Membresía: 1 fila por (miembro x espacio) (≈102k)
CREATE TABLE IF NOT EXISTS public.bettermode_member_spaces (
    member_id   TEXT NOT NULL,
    space_id    TEXT NOT NULL,
    space_name  TEXT,
    email       TEXT,
    synced_at   TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    PRIMARY KEY (member_id, space_id)
);
CREATE INDEX IF NOT EXISTS idx_bm_ms_space  ON public.bettermode_member_spaces (space_id);
CREATE INDEX IF NOT EXISTS idx_bm_ms_email  ON public.bettermode_member_spaces (LOWER(email));
CREATE INDEX IF NOT EXISTS idx_bm_ms_member ON public.bettermode_member_spaces (member_id);
