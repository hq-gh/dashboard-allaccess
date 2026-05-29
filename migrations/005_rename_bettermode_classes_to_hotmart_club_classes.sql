-- ============================================================================
-- 005_rename_bettermode_classes_to_hotmart_club_classes.sql
--
-- Rename: la tabla NO tiene nada que ver con Bettermode. Es mapeo class_id
-- → class_name del Hotmart Club. Naming correcto = hotmart_club_classes
-- (paralelo a club_students).
--
-- Datos, índices, constraints, secuencia y FKs se preservan automáticamente
-- al hacer RENAME en Postgres.
-- ============================================================================

ALTER TABLE  IF EXISTS public.bettermode_classes  RENAME TO hotmart_club_classes;

ALTER INDEX  IF EXISTS public.idx_bmc_subdomain   RENAME TO idx_hcc_subdomain;
ALTER INDEX  IF EXISTS public.idx_bmc_class_id    RENAME TO idx_hcc_class_id;

-- Trigger (Postgres permite renombrar via ALTER TRIGGER ... ON ... RENAME TO).
ALTER TRIGGER trg_bmc_updated_at ON public.hotmart_club_classes RENAME TO trg_hcc_updated_at;

-- Constraints (los nombres auto-generados por Postgres referencian el nombre viejo).
ALTER TABLE public.hotmart_club_classes
    RENAME CONSTRAINT bettermode_classes_pkey TO hotmart_club_classes_pkey;
ALTER TABLE public.hotmart_club_classes
    RENAME CONSTRAINT bettermode_classes_subdomain_class_id_key TO hotmart_club_classes_subdomain_class_id_key;
