# Ex-Mill correction and provisional-ledger visibility — 18 October 2026

Two connected defects were reproduced against original main cd737af:

- The actual lifting endpoint ignores `preview` and writes a liability. A changed
  Rice lifting overwrites its original journal. Corn amendments are held, without
  a supported native correction route.
- The posted receipt status `Posted / Bill Pending` is included in management
  reports but excluded from Accounts ledgers/trial balances. A real disposable
  Rs10,000 receipt produces an empty Accounts trial balance. Because the final
  bill debits GRNI and posts only the inventory adjustment, omission of the
  original provisional journal also misstates the final inventory/GRNI balances.

New receipts use Posted journal status and a separate Pending bill dimension.
Accounts ledgers and reports also recognise the exact historical Ex-Mill status
without changing stored journals. Other draft/pending journal types are excluded.
This is a read projection, not a data migration or a historical re-post.

An unbilled receipt correction requires the original workflow's Edit permission,
company/location scope, current journal identity and a recorded reason. The
container and shipment allocation cannot be reassigned. A physical correction
uses the accepted original unit rate; a later Soda amendment cannot silently
reprice that receipt. Corn still validates its issued Export instruction and
packing/weight deductions. Limits exclude the receipt being corrected.

The original journal remains byte-for-byte unchanged. A separate reversal and
replacement carry the source key, reason, actor and history. The existing event
and lifting point at the replacement. A public-Post-ID mapping resolves the
current entry, and generic journal amendment is refused for these source-owned
receipts. An unchanged retry adds no write or second reversal; stale expected
posting IDs, missing/deleted/already-reversed journals and billed source changes
are held. Cancellation and correction after supplier billing remain separate
reconciliation gates, not automatically permitted actions.

The native loading Save requests a server preview, then shows a centred green
Debit/Credit review. Cancel retains the draft and creates no financial or shared
source save. Confirm posts the reviewed values. The date and all other values
are captured before review, so later changes cannot alter that reviewed payload.
A fingerprint of reviewed accounting facts is checked again under the store lock; a changed source rate/amount stops Confirm with no write and requires a fresh review. An acknowledged retry reuses the server result without another posting. A posted
row exposes a correction-reason field; history and current journal link persist.

Real-auth disposable API validation covers original legacy visibility, no-write
new/correction previews, preserved source rate, permission/location/entity denials,
original/reversal/replacement accounting, current Post ID lookup, generic-edit
refusal, stale/lost-response retries, final source billing and exact inventory,
GRNI and supplier balances. The Corn fixture also exercises explicit correction
against its issued source. Actual form/review functions in JSDOM cover green
review, Cancel, draft/date retention, confirmed Save, correction reason/current
journal identity, complete loading and Rice/packed isolation.

Twenty-four related local checks passed, with focused checks repeated after the
last permission/actor and form assertions. Old-code tests reproduce both the
preview write and the missing Rs10,000 trial balance independently. Positive
financial cases remain disposable; no production posting or correction is used
for acceptance. Source/Accounts persistence still uses an acknowledged handoff;
a missing physical-source acknowledgement must be retried/reconciled, and the
Daily report holds mismatched financial/physical receipts instead of assuming
completion. Full application, mixed historical sources, current positive business
reconciliation, migration and formal security gates remain open.
