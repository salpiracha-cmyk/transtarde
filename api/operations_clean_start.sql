-- Run once during the approved clean deployment.
-- This clears only the shared Export operational payload/history and protected-document metadata.
-- Login, user, permission and application configuration tables are not touched.
START TRANSACTION;
DELETE FROM tt_export_documents;
DELETE FROM tt_operation_history;
DELETE FROM tt_operation_records;
COMMIT;
