-- AgriReach: category-specific damage details (Crops, Livestock, Poultry, Fishpond).
-- BACKWARD COMPATIBLE:
--   * New table public.damage_report_details, one row per NEW report (older reports simply have none).
--     The farmer's submitted values and the admin-verified values live in separate columns, so a
--     verification never overwrites what the farmer reported.
--   * damage_reports.estimated_damage_cost becomes NULLable (default kept): new reports no longer ask
--     the farmer to guess a peso amount. No existing value is changed or removed.
-- Nothing is dropped and no existing row is rewritten.
--
-- Apply with:   php scripts/pgsql-migrate.php --connection=supabase
-- Roll back:    database/pgsql/rollback/20261023_0004_damage_report_details.down.sql (see its notes)

BEGIN;

CREATE TABLE public.damage_report_details (
    report_id integer PRIMARY KEY,
    details_version smallint NOT NULL DEFAULT 1,
    -- crop_percent (Crops), head_count (Livestock), bird_count (Poultry), fish_count / fish_weight_kg (Fishpond)
    measure_type varchar(16) NOT NULL,
    subject_type varchar(100) NOT NULL,
    suspected_cause varchar(60) NOT NULL,
    suspected_cause_other varchar(255),
    crop_damage_percent numeric(5,2),
    planted_area_ha numeric(12,4),
    affected_area_ha numeric(12,4),
    total_before numeric(14,2),
    amount_lost numeric(14,2),
    -- Admin-verified values: same measure type and unit as the farmer's submission.
    verified_cause varchar(60),
    verified_cause_other varchar(255),
    verified_crop_damage_percent numeric(5,2),
    verified_planted_area_ha numeric(12,4),
    verified_affected_area_ha numeric(12,4),
    verified_total_before numeric(14,2),
    verified_amount_lost numeric(14,2),
    review_notes text,
    verified_by_admin_id integer,
    verified_at timestamp(0) without time zone,
    created_at timestamp(0) without time zone NOT NULL DEFAULT public.agrireach_local_now(),
    updated_at timestamp(0) without time zone NOT NULL DEFAULT public.agrireach_local_now(),
    CONSTRAINT fk_damage_report_details_report FOREIGN KEY (report_id) REFERENCES public.damage_reports (id) ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT fk_damage_report_details_verifier FOREIGN KEY (verified_by_admin_id) REFERENCES public.admins (id) ON DELETE SET NULL ON UPDATE CASCADE,
    CONSTRAINT chk_damage_report_details_measure CHECK (measure_type IN ('crop_percent', 'head_count', 'bird_count', 'fish_count', 'fish_weight_kg')),
    CONSTRAINT chk_damage_report_details_percent CHECK (crop_damage_percent BETWEEN 0 AND 100 AND verified_crop_damage_percent BETWEEN 0 AND 100),
    CONSTRAINT chk_damage_report_details_nonnegative CHECK (planted_area_ha >= 0 AND affected_area_ha >= 0 AND total_before >= 0 AND amount_lost >= 0
        AND verified_planted_area_ha >= 0 AND verified_affected_area_ha >= 0 AND verified_total_before >= 0 AND verified_amount_lost >= 0),
    CONSTRAINT chk_damage_report_details_lost_within_total CHECK (amount_lost <= total_before AND verified_amount_lost <= verified_total_before)
);
CREATE INDEX fk_damage_report_details_verifier ON public.damage_report_details (verified_by_admin_id);
CREATE TRIGGER trg_damage_report_details_updated_at BEFORE UPDATE ON public.damage_report_details FOR EACH ROW EXECUTE FUNCTION public.agrireach_touch_updated_at();

ALTER TABLE public.damage_reports ALTER COLUMN estimated_damage_cost DROP NOT NULL;

-- Same lockout as every other AgriReach table (PHP connects directly; the Supabase Data API gets nothing).
ALTER TABLE public.damage_report_details ENABLE ROW LEVEL SECURITY;
DO $$
BEGIN
    IF EXISTS (SELECT 1 FROM pg_catalog.pg_roles WHERE rolname = 'anon') THEN
        REVOKE ALL ON public.damage_report_details FROM anon;
    END IF;
    IF EXISTS (SELECT 1 FROM pg_catalog.pg_roles WHERE rolname = 'authenticated') THEN
        REVOKE ALL ON public.damage_report_details FROM authenticated;
    END IF;
END;
$$;

INSERT INTO public.agrireach_schema_migrations (version) VALUES ('20261023_0004_damage_report_details');

COMMIT;
