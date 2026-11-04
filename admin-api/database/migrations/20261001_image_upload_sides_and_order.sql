USE agrireach;

ALTER TABLE farmer_ids
    ADD COLUMN IF NOT EXISTS front_image_path VARCHAR(255) NULL AFTER image_path,
    ADD COLUMN IF NOT EXISTS back_image_path VARCHAR(255) NULL AFTER front_image_path;

ALTER TABLE report_images
    ADD COLUMN IF NOT EXISTS display_order SMALLINT UNSIGNED NOT NULL DEFAULT 0 AFTER image_path;

-- Existing ID images remain in image_path as legacy single-side uploads.
-- Existing report images retain deterministic id order when display_order is 0.
