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
- Check visible operational collection versions every 15 seconds while a Milling workflow is visible; fetch a full snapshot only when it changes. This closes the cross-device foreground refresh gap across Milling without repeatedly downloading all operational records.
- Listen on the actual window event emitted by inbound sync, without clearing drafts or a selected Pohanch source.
- Capture the original edit snapshot so a background refresh cannot silently rebase a stale form.
- Display a saved server reference after confirmed success.

## Verification

Local tests passed: original-device HTTP and UI fixtures; failed initial-read/cache retention; incomplete and lost acknowledgement retry; changed-draft and stale amendment protection; API action policy and permissions; shared-key authorization; operations read, root, company and mill scope; mill cash authorization; arrival withdrawal; gate pass and recovery; operational status, date controls, reports, reference permissions, company/mill isolation; shared Exports save persistence; static runtime security audit and PHP lint.

The normal Accounts V1 QA and Milling/Exports QA workflows are required before merge/deployment. Production checks must remain read-only; do not create live test arrivals or alter financial records.

## Original phone handoff

Keep the same original phone browser profile and website data. Reload Milling under the same account and open Arrival List → Recover phone entries. Pending original save intents are copied into the private recovery inbox on page load, return to the tab, or reconnection when connectivity/session/permissions allow; legacy cached rows can be preserved explicitly. Confirm the original truck/date/quantities before an explicit original-ID save. If no original exists, investigation remains open; never invent a replacement entry or claim historical recovery succeeded.

## Extended Milling save audit

| Workflow | Save and recovery boundary | Verification |
| --- | --- | --- |
| Arrival List | Original-ID acknowledged action, stale edit/source protection, duplicate-trip approval, private device recovery | HTTP, UI, native mobile/PC browser regression |
| Pohanch | Original edit/source snapshots; selected truck product; stable receipt identity; locked duplicate queue linkage | HTTP duplicate denial, native retry and amendment, print regression |
| Production / PALTAI | Isolated saved record/version; stable request and original edit baseline; atomic stock/bag/labour derivatives | Production source/UI, native PALTAI lost-ack regression |
| New / Other Exporter Bags | Existing scoped supplier receipts, quarantine, request keys and transfers preserved | Receipt/transfer HTTP and UI suites, inventory MySQL CI |
| Used Bags / Petty entries | Same strict eight-action save helper, retained intent, operational/financial separation | All action-key transport tests, native inward/outward/petty browser test |
| Local Sales / Fellow Exporter dispatch | Same original Mill source and strict helper; edited loading identity remains stable if row indexes change | Native local save/payment/load, Directors handoff and quantity/gate tests |
| Own Export / Ex-Mill loading | Stale row snapshots; original legacy collection journal; server-fact confirmation and safe replay; dependent Exports return waits for physical source | Legacy recovery tests with server loading metadata; bridge source-failure ordering; existing loading/corn suites |
| Labour / Electricity / Processing / Reprocessing / Rent and advance references | All six bill collections require saved value and advancing version; original-ID retry and preserved forms | Six-key incomplete-ack tests; source/UI/HTTP amendment and reference suites |
| Physical stock confirmation | Stable original identity, legacy journal, source-version hold before replay; existing server reconciliation policy preserved | Inventory/reconciliation and legacy transport suites |
| Milling setup | Quality defaults, labour rates, rent/cost and operational salary reference buttons now wait for isolated server acknowledgements. Partial two-key rent setup retries only missing writes; open setup holds its baseline. Financial Salary Master remains managed in Accounts. | Native action wiring; source acknowledgement, stale setup and partial retry fixtures; existing server Master/entity permission suite |
| Reports / Users | Existing read/print guards and Super Admin permission-management boundary preserved. Live redraw updates saved summaries only. | Company/mill scope and report rendering security tests |

All Milling API requests now have a 45-second confirmation timeout covering response body reads. Timeout is an uncertain outcome, never proof of failure: keep the original ID and check the server before retry. Legacy pending copies are retained per signed-in user and explicitly recovered; a newer source produces a review hold rather than automatic overwrite. Ex-Mill completion no longer stages an opposite rollback after a lost response.

This closes the identified save/sync gaps; it is not a claim that a disconnected device can save live, or that every future failure is impossible. Browser/device storage must remain intact until an original is confirmed. Separate isolated browser contexts exercise mobile and desktop storage; no production arrivals are created for QA.
