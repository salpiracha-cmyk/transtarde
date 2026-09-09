-- Run once during the approved clean deployment.
-- This clears only the V3 shared Export operational payload/history and protected-document metadata.
-- Login, user, permission and application configuration tables are not touched.
START TRANSACTION;
DELETE FROM tt_export_documents;
DELETE FROM tt_operation_history WHERE storage_key = 'transtrade_export_v3_operational';
DELETE FROM tt_operation_records WHERE storage_key = 'transtrade_export_v3_operational';
COMMIT;
