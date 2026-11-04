-- REVIEW-ONLY cleanup for the four positively identified legacy sample farmers.
-- This script performs no deletion unless @AGRIREACH_CONFIRM_DEMO_CLEANUP is changed to 1.
-- Review the SELECT result first and back up the database before enabling cleanup.

USE agrireach;
SET @AGRIREACH_CONFIRM_DEMO_CLEANUP = 0;

SELECT id, full_name, email, verification_status, date_registered
FROM farmers
WHERE password_hash IS NULL
  AND (full_name, email) IN (
      ('Ramon Dela Cruz', 'sample.ramon@agrireach.test'),
      ('Lourdes Santos', 'sample.lourdes@agrireach.test'),
      ('Joel Navarro', 'sample.joel@agrireach.test'),
      ('Marites Garcia', 'sample.marites@agrireach.test')
  );

START TRANSACTION;

DELETE FROM farmers
WHERE @AGRIREACH_CONFIRM_DEMO_CLEANUP = 1
  AND password_hash IS NULL
  AND (full_name, email) IN (
      ('Ramon Dela Cruz', 'sample.ramon@agrireach.test'),
      ('Lourdes Santos', 'sample.lourdes@agrireach.test'),
      ('Joel Navarro', 'sample.joel@agrireach.test'),
      ('Marites Garcia', 'sample.marites@agrireach.test')
  );

SELECT ROW_COUNT() AS legacy_demo_farmers_deleted;
COMMIT;
