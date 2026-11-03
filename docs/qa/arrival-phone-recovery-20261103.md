# Arrival phone-save investigation — 3 November 2026

## Evidence and limits

Production base: f7efc72c9e303bea8167fc0e63add4b5f99c56e2 (PR 663).
The connected Mac session was signed in as the affected Mill Manager. An empty-form validation check reached required-field validation without creating a record. The authenticated canonical queue read returned no rows for 30 October–3 November and queue version 1935, last updated 17 October. This establishes that the entries are not merely hidden by the Super Admin date filter. It does not prove why the original phone save failed; the original phone browser is not connected.

## Repairs

- Require an actual canonical collection, version and matching saved record before clearing a Milling form. An incomplete acknowledgement retains the same retry identity, so a later GET can confirm a committed save without posting twice.
- Preserve superseded rejected pending originals and the legacy device queue before authoritative startup downloads. Recovery copies never post stock or money automatically.
- Block Milling startup after a failed authoritative read rather than showing a misleading empty working screen.
- Add a private, authenticated recovery-copy inbox. Mill queue View is required; users see their own scoped copies, Super Admin can investigate all. CSRF, size limits and assigned company/mill scope apply. Actual recovery posting uses the existing Create/Edit policy and original record ID through operations.mysql.php.
- Show five recent arrival days with an explicit date range and dates on each row; restrict queue, report and pending Pohanch options to the selected company/mill.
- Listen on the actual window event emitted by inbound sync, without clearing drafts or a selected Pohanch source.
- Capture the original edit snapshot so a background refresh cannot silently rebase a stale form.
- Display a saved server reference after confirmed success.

## Verification

Local tests passed: original-device HTTP and UI fixtures; failed initial-read/cache retention; incomplete and lost acknowledgement retry; changed-draft and stale amendment protection; API action policy and permissions; shared-key authorization; operations read, root, company and mill scope; mill cash authorization; arrival withdrawal; gate pass and recovery; operational status, date controls, reports, reference permissions, company/mill isolation; shared Exports save persistence; static runtime security audit and PHP lint.

The normal Accounts V1 QA and Milling/Exports QA workflows are required before merge/deployment. Production checks must remain read-only; do not create live test arrivals or alter financial records.

## Original phone handoff

Keep the same original phone browser profile and website data. Reload Milling under the same account and open Arrival List → Recover phone entries. Pending original save intents are copied into the private recovery inbox when connectivity/session/permissions allow; legacy cached rows can be preserved explicitly. Confirm the original truck/date/quantities before an explicit original-ID save. If no original exists, investigation remains open; never invent a replacement entry or claim historical recovery succeeded.
