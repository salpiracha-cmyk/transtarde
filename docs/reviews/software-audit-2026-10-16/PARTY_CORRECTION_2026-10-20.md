# Authorised party correction and payment plan — 20 October afternoon

## Confirmed production correction

The owner explicitly requested consolidation of two names for the same freight provider, one duplicated B/F and three existing invoices. Connected local Chrome was used as the authenticated owner. This authorisation was specific to the actual correction; it did not authorise invented payment/test-stock postings.

The historical full party name was absent from Business Parties, including inactive records, while the original opening and two bills remained in Accounts. The current active freight master used the shortened name. Bill posting requires an active correctly categorised master, which explains the missing choice. The available evidence does not establish whether the old full-name master was never registered or was subsequently removed.

The active master was restored to the requested full name without creating another master ID. The third invoice was amended through its original freight-bill workflow, preserving its public Post ID, invoice number/date, shipment, rates, charges and total. The duplicate opening was then cancelled through the deployed in-page reason form and the existing server-generated balanced accounting preview. Original/reversal history remains; the original full-name B/F was retained. No bill was cancelled or copied and no bank/cash payment was created.

Fresh actual ledger reads show one party choice and exactly four active postings: one original opening and all three invoices. The provider's payment screen independently shows the same opening and all three original unpaid invoices, with unchanged totals and no bill payments. Production details and the private verification screenshot are intentionally excluded from this public source report.

## Causes and deployed prevention — PR425

- Existing comparison accepted exact/near spellings but not a shortened name versus a descriptive Shipping Services suffix. It also lacked the missing master's historical name when checking a new party.
- Opening duplicate checks used an exact party key; the shortened party could therefore receive a second opening under the same creditor control.
- Permanent removal protected records referenced by master ID, while older creditor/bill sources can retain only the party name. This is a confirmed control gap, not proof that a deletion caused this incident.

The patch compares descriptive suffix variants, checks historical bill/creditor names, permits explicit restoration of a known full name, protects name-only history from purge, and rejects similar-name duplicate creditor openings. Current-master uniqueness is rechecked under its write lock. No fuzzy automatic ledger merge or historical journal migration is performed. The cancellation reason is now an in-page form with Keep/Escape abandonment, followed by the unchanged accounting preview and audit-preserving cancellation engine.

Actual-auth disposable API tests cover current and historical duplicates, exact restoration, name-only purge refusal, duplicate-opening rejection and unchanged financial bytes. The original register browser regression now exercises the form and preview/commit boundary. Existing role mapping, Soda compatibility, opening/JV, party-ledger and amendment suites remain green.

Feature7ad016eb98fe7e19a4e11887468c30b9f718a853 passed full Accounts39415871664 and Milling/Exports39415871760. PR425 merged as aaf6887c2fec720a4ac94d9acded77fe1826988c, preserving the concurrent salary detail repair. Exact-main Accounts39416378412 (both jobs) and Milling/Exports39416378410 passed. Deploy39416812482/job118607728501 installed that exact SHA, changed zero users and zero journal IDs, and passed login/provider health. Startup39416923198 passed. Final protected live39416923230/job118608085978 passed23/23, reconciling53account ledgers across3companies with zero suite-originated financial writes. These measured read-only-suite writes are separate from the explicitly authorised manual correction above.

## Partial payments — requested plan, not an implementation claim

[Partial expense payments plan](PARTIAL_EXPENSE_PAYMENTS_2026-10-20.md) maps existing service-bill/B/F support and missing payment-only expense capture to full payment, explicit partial payment with every remaining amount kept open, independent B/F selection, agreed discount, advance and invoice-capture/pay-later. A disposable/unsaved preview in actual Chrome demonstrated that a round partial payment retains the unallocated invoice amount; it was closed without Post Payment.

The existing automatic discount for a settlement difference below PKR100 needs an explicit partial-payment intent that bypasses it. Ordinary utility/general expense forms also need a full original invoice-liability source before they can correctly track later payments. Preserve the specialised salary, rent, reimbursement, credit-card and TG engines; never charge an expense again when settling it.

## Honest audit movement

Scope37 (all production payments/cancellations) advances from **not tested** to **partial** on one approved actual business correction. It is not complete coverage of every payment/cancellation workflow. Other matrices and original-source decisions remain open. Totals remain156scopes,125passed and31open:23partial,3failed,2not tested,2blocked and1intermittent. Newly fixed defects do not inflate the passed-scope count.
