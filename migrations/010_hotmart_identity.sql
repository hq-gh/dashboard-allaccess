-- =====================================================================
-- Portal rw2.5t4d10.com — Migración 010
-- Identidad Hotmart: un ucode (identificador estable del comprador) puede tener
-- VARIOS emails (de compra y de acceso). Bettermode usa el de acceso; las
-- ventas/suscripciones a veces traen el otro. Esta tabla mapea TODOS los emails
-- conocidos por ucode, para que la validación de permisos considere cualquiera
-- de ellos (resuelve "compré con A, accedo con B").
--   - email_type 'access'  : email de la cuenta/acceso (subscriptions/sales/history)
--   - email_type 'purchase': email de compra (GET /payments/api/v1/sales/users, rol BUYER)
-- Se enriquece con bin/sync-hotmart-identity (en DASHBOARD_5T4D10_PTT, vía Hotmart API).
-- =====================================================================
CREATE TABLE IF NOT EXISTS public.hotmart_identity (
    ucode      TEXT NOT NULL,
    email      TEXT NOT NULL,
    email_type TEXT NOT NULL DEFAULT 'access' CHECK (email_type IN ('access','purchase')),
    source     TEXT,
    updated_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    PRIMARY KEY (ucode, email)
);
CREATE INDEX IF NOT EXISTS idx_hi_email ON public.hotmart_identity (LOWER(email));
CREATE INDEX IF NOT EXISTS idx_hi_ucode ON public.hotmart_identity (ucode);

-- Seed con lo que YA tenemos (emails de acceso/cuenta + ucode) de subscriptions y sales.
INSERT INTO public.hotmart_identity (ucode, email, email_type, source)
SELECT DISTINCT subscriber_ucode, LOWER(TRIM(subscriber_email)), 'access', 'subscriptions'
  FROM public.subscriptions
 WHERE subscriber_ucode IS NOT NULL AND subscriber_ucode <> '' AND subscriber_email IS NOT NULL AND subscriber_email <> ''
ON CONFLICT (ucode, email) DO NOTHING;

INSERT INTO public.hotmart_identity (ucode, email, email_type, source)
SELECT DISTINCT buyer_ucode, LOWER(TRIM(buyer_email)), 'access', 'sales'
  FROM public.sales
 WHERE buyer_ucode IS NOT NULL AND buyer_ucode <> '' AND buyer_email IS NOT NULL AND buyer_email <> ''
ON CONFLICT (ucode, email) DO NOTHING;
