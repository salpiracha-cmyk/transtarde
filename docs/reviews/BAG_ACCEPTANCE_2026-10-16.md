# Bag acceptance and cumulative PO billing — 16 October 2026

Implemented locally on `fix/bag-billing-closure-20261016`. This change has not been deployed or verified in live Chrome.

## Resulting behaviour

- One PO supports multiple invoices. Pending and posted invoices across ordinary and Non-Woven bag routes reserve quantities under the same Accounts write lock. Cancelled invoices release capacity. Each posting rechecks the packing line’s PO quantity and actual receipts.
- An invoice that exceeds the cumulative PO quantity is saved on hold, prominently marked **Director approval required — bill not posted**. Accounts records its supplier contact/settlement. Director or Super Admin can then approve the exact current exception through the same endpoint. The decision records its actual actor, reason and evidence; changed bill/receipt evidence requires a new review. Approval retries reuse the original posting.
- Receipt completion closes a PO with no more than 20 bags short across the entire PO after receipts begin. Twenty-one short remains open. This tolerance does not fabricate a receipt, invoice, liability or stock quantity.
- Users can explicitly save **Keep PO open** without receiving another bag. Short-closed POs remain available for later genuine invoices; normal receipt/specification checks still apply.
- PO rates do not determine posting and no longer appear as an invoice-rate comparison or prefill. Actual supplier invoice rates remain part of invoice valuation.
- Printing, material, quality and unresolved tare discrepancies hold posting. A corrected lower-weight invoice is reassessed against measured receipts, including prior per-bag and per-kg invoices. Weight allocation uses the measured average where individual receipt-lot allocation is unavailable; an exception requires explicit review.
- Current committed Mill evidence is checked even if the handoff snapshot is delayed. Multi-field Mill bridge identifiers now map to the correct packing line, with master bags kept separate. Stored tare labels such as `75 g` are read as grams.
- Posted historical invoices and their original journals are preserved. Historical/general entry treatment remains separate.

## Verification completed

- All 96 commands in the Accounts rule/control QA step passed locally.
- New endpoint tests: three bills across both routes, cumulative excess, unposted hold, unauthorized/stale/cross-company approval refusal, Director posting and replay, unchanged receipts, late invoice, user keep-open choice and corrupt evidence rollback.
- Short-close tests: 20/21 boundary, whole-PO tolerance across multiple packing lines, small unreceived final packing line, reopen without quantity changes.
- Receipt handoff tests: real multi-field identifiers, ordinary/master separation, measured weights, quality flags, no write on opening and synchronization only after explicit acknowledged save.
- Director UI tests: settlement prerequisite, exact fingerprint and company, shared endpoint, safe text and removal after decision.
- Existing general/historical bag HTTP and UI, supplier selection, approval concurrency/integrity, authorization and authentication tests passed. Changed PHP/JavaScript and Mill inline syntax passed.

## Release status and remaining work

The local source changes are committed and the user authorized publication on 16 October. The GitHub connection cannot modify Actions workflows, so the publication branch preserves the existing workflow unchanged. New regression tests are retained and passed locally; the existing Accounts and Milling/Exports suites run on the PR. Hosted results and any authorized deployment must be verified separately. No deployment or live Chrome verification is claimed here.

The actual-input daily production/recovery and brand/contract recovery reporting batch remains unfinished and is not included in this bag change. This report does not claim that the entire Accounts review or recovery-reporting work is complete.
