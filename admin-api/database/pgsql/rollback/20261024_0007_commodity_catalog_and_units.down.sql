-- ROLLBACK for 20261024_0007_commodity_catalog_and_units. NOT applied by scripts/pgsql-migrate.php (it only
-- reads database/pgsql/*.sql, not this rollback/ folder). Run it by hand only after restoring the previous
-- PHP and mobile code:
--   psql "<connection>" -v ON_ERROR_STOP=1 -f database/pgsql/rollback/20261024_0007_commodity_catalog_and_units.down.sql
--
-- It REFUSES (and changes nothing) while any production record uses a unit other than kg or a commodity added by
-- 0007, because the 0006 schema could not hold those records. Export or remove them first.
-- Otherwise it restores the 0006 commodity names and statuses, removes the added commodities, units and columns.

BEGIN;

DO $$
BEGIN
    IF EXISTS (SELECT 1 FROM public.production_records pr JOIN public.commodities c ON c.id = pr.commodity_id
               WHERE pr.unit_code <> 'kg' OR c.id > 30) THEN
        RAISE EXCEPTION 'Rollback refused: production records use non-kg units or commodities added by 0007.';
    END IF;
END;
$$;

ALTER TABLE public.production_records
    DROP CONSTRAINT fk_production_records_unit,
    DROP CONSTRAINT chk_production_records_quantity_positive,
    ALTER COLUMN quantity_kg SET NOT NULL,
    DROP COLUMN quantity,
    DROP COLUMN unit_code;

DROP TABLE public.commodity_units;
DELETE FROM public.commodities WHERE id > 30;
UPDATE public.commodities SET status = 'Active';
UPDATE public.commodities SET name = 'Rice' WHERE agricultural_category = 'Crops' AND name = 'Palay (Rice)';
UPDATE public.commodities SET name = 'Cassava' WHERE agricultural_category = 'Crops' AND name = 'Cassava (Kamoteng Kahoy)';
UPDATE public.commodities SET name = 'Sweet Potato' WHERE agricultural_category = 'Crops' AND name = 'Sweet Potato (Kamote)';
UPDATE public.commodities SET name = 'Taro' WHERE agricultural_category = 'Crops' AND name = 'Taro (Gabi)';
UPDATE public.commodities SET name = 'Peanut' WHERE agricultural_category = 'Crops' AND name = 'Peanut (Mani)';
UPDATE public.commodities SET name = 'Eggplant' WHERE agricultural_category = 'Crops' AND name = 'Eggplant (Talong)';
UPDATE public.commodities SET name = 'Tomato' WHERE agricultural_category = 'Crops' AND name = 'Tomato (Kamatis)';
UPDATE public.commodities SET name = 'String Beans' WHERE agricultural_category = 'Crops' AND name = 'String Beans (Sitaw)';
UPDATE public.commodities SET name = 'Squash' WHERE agricultural_category = 'Crops' AND name = 'Squash (Kalabasa)';
UPDATE public.commodities SET name = 'Pig' WHERE agricultural_category = 'Livestock' AND name = 'Pig / Hog (Fattener)';
UPDATE public.commodities SET name = 'Bangus (Milkfish)' WHERE agricultural_category = 'Fishpond' AND name = 'Milkfish (Bangus)';
UPDATE public.commodities SET name = 'Mud Crab' WHERE agricultural_category = 'Fishpond' AND name = 'Mud Crab (Alimango)';
DROP INDEX public.idx_commodities_category_group;
ALTER TABLE public.commodities
    DROP CONSTRAINT fk_commodities_default_unit,
    DROP CONSTRAINT chk_commodities_product_type,
    DROP COLUMN commodity_group,
    DROP COLUMN product_type,
    DROP COLUMN default_unit_code,
    DROP COLUMN search_names;
DROP TABLE public.measurement_units;
SELECT setval(pg_get_serial_sequence('public.commodities', 'id'), 30);
DELETE FROM public.agrireach_schema_migrations WHERE version = '20261024_0007_commodity_catalog_and_units';

COMMIT;
