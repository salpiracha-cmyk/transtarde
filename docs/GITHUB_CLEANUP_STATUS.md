# GitHub cleanup status — 8 October 2026

Audited against MAIN `e1876d5d8469ee55dd4599c892de4517c9c26d27` using GitHub commit ancestry. MAIN is the production source deployed by GitHub → Hostinger. Branch names alone do not prove that work is merged.

## Standing rules

Preserve roles, icon permissions, company restrictions, Super Admin authority, Director approvals in both consoles, operational records and historical snapshots. Amend the authoritative implementation on MAIN. Never deploy an old branch wholesale, restore an old backup over production, clear browser storage or delete/recreate shipments as cleanup.

## Recovered scope and current batch

- Earlier permission cleanup: `4145a711` and `ee93ad3e`; broader shared Export-document and legacy approval review remains a separate audit item. Do not treat a successful test suite as proof that every historical endpoint has been reviewed.
- Direct expense/navigation consolidation: `97bc23b`; bank entry/finance, named subaccounts, Head of Accounts, tax reporting and investment routing subsequently deployed in `e1876d5`.
- This batch removes unused export document/product/charge data from Accounts startup, keeps canonical records intact, uses a compact charge-seeding response, records sign-in audit/timestamp in one transaction, and routes document authentication through the shared API guard so previews release read-session locks.
- Source ownership: authentication/session rules in `auth_store.php` / `session_store.php`; shared masters in `master_store.php`; exported document target permissions in `api/export_permission_policy.php`; live Exports/Milling entry route in `module.php`; Accounts entry route in `accounts/index.php`. Asset delivery wrappers assemble these sources and are not independent business-data copies.

## Branch classification

91 branches checked: one production branch, two intentional rollback references, 51 non-rollback branches fully contained in MAIN, and 37 divergent historical branches requiring preservation/review. Removed all 51 fully contained non-rollback branch references using the authenticated GitHub branch controls, which offered Restore after each deletion. A fresh API inventory confirmed 40 remaining branches and preservation of all 37 divergent heads, both rollback references and MAIN. No open pull requests existed. Fully contained means zero unique ancestor commits, not permission to delete rollback references. Divergent work is preserved; it must not be blindly merged or classified obsolete.

| Branch | Classification | Unique commits outside MAIN |
|---|---|---:|
| accounts-default-payments-20261001 | REMOVED — fully contained in MAIN | 0 |
| accounts-desk-testing-20260923 | HISTORICAL ARCHIVE — review unique work | 2 |
| accounts-numeric-review-20261001 | REMOVED — fully contained in MAIN | 0 |
| accounts-property-assets-20261001 | REMOVED — fully contained in MAIN | 0 |
| accounts-register-scope-20261001 | REMOVED — fully contained in MAIN | 0 |
| accounts-review-split-20261001 | REMOVED — fully contained in MAIN | 0 |
| accounts-tg-remittance-review-20261003 | REMOVED — fully contained in MAIN | 0 |
| cleanup/fi-hotfix-base | REMOVED — fully contained in MAIN | 0 |
| cleanup/fi-hotfix-run | HISTORICAL ARCHIVE — review unique work | 1 |
| codex/customs-fi-refresh | REMOVED — fully contained in MAIN | 0 |
| codex/finish-accounts-local-tg-20261004 | REMOVED — fully contained in MAIN | 0 |
| codex/finish-options-20261001 | HISTORICAL ARCHIVE — review unique work | 13 |
| codex/finish-options-rebased-20261002 | HISTORICAL ARCHIVE — review unique work | 11 |
| codex/master-console-ui-20261001 | HISTORICAL ARCHIVE — review unique work | 13 |
| codex/migrate-existing-post-ids-20261004 | REMOVED — fully contained in MAIN | 0 |
| codex/post-id-deploy-20261004 | REMOVED — fully contained in MAIN | 0 |
| codex/universal-post-id-20261004 | REMOVED — fully contained in MAIN | 0 |
| export-hs-finish-20260930 | HISTORICAL ARCHIVE — review unique work | 4 |
| feat/master-product-kat-soda-20260919 | REMOVED — fully contained in MAIN | 0 |
| feature/export-indentor-commission-20260920 | HISTORICAL ARCHIVE — review unique work | 7 |
| feature/form-opening-dependent-fields-20261002 | REMOVED — fully contained in MAIN | 0 |
| feature/office-backup-agent-20260929 | REMOVED — fully contained in MAIN | 0 |
| feature/super-admin-director-approvals-20261001 | REMOVED — fully contained in MAIN | 0 |
| fix/accounts2-soda-milling-routing-20260920 | HISTORICAL ARCHIVE — review unique work | 1 |
| fix/accounts-bank-payment-ledger-handover-20261001 | REMOVED — fully contained in MAIN | 0 |
| fix/accounts-linkage-qa-20260921 | HISTORICAL ARCHIVE — review unique work | 1 |
| fix/accounts-soda-live-dropdowns-20260921 | HISTORICAL ARCHIVE — review unique work | 1 |
| fix/accounts-soda-masters-20260921 | HISTORICAL ARCHIVE — review unique work | 1 |
| fix/backup-export-integrity-20260929 | HISTORICAL ARCHIVE — review unique work | 5 |
| fix/bank-save-retention-20261007 | REMOVED — fully contained in MAIN | 0 |
| fix/complete-account-linkages-20260920 | HISTORICAL ARCHIVE — review unique work | 16 |
| fix/complete-master-export-headings-20260929 | HISTORICAL ARCHIVE — review unique work | 1 |
| fix/console-deploy-path-20260919 | REMOVED — fully contained in MAIN | 0 |
| fix/export-indentor-step5-validation-20260920 | HISTORICAL ARCHIVE — review unique work | 2 |
| fix/exports-focus-preservation | HISTORICAL ARCHIVE — review unique work | 2 |
| fix/linked-usd-retention-20261007 | REMOVED — fully contained in MAIN | 0 |
| fix/logout-500-20260929 | REMOVED — fully contained in MAIN | 0 |
| fix/master-back-navigation-20260926 | REMOVED — fully contained in MAIN | 0 |
| fix/master-route-fresh-runtime | REMOVED — fully contained in MAIN | 0 |
| fix/product-kat-broker-brokery-20260920 | HISTORICAL ARCHIVE — review unique work | 16 |
| fix/product-master-cache-bust-20260919 | REMOVED — fully contained in MAIN | 0 |
| fix/purchase-master-readable-fields-20260920 | HISTORICAL ARCHIVE — review unique work | 4 |
| fix/super-admin-master-ui-cache-20260920 | HISTORICAL ARCHIVE — review unique work | 3 |
| fix/upload-content-hardening-20260929 | HISTORICAL ARCHIVE — review unique work | 1 |
| fix-export-hs-finish-init-20260930 | HISTORICAL ARCHIVE — review unique work | 2 |
| gemini-document-reader | HISTORICAL ARCHIVE — review unique work | 12 |
| main | PRODUCTION | 0 |
| office-agent-final-archive-zips | REMOVED — fully contained in MAIN | 0 |
| office-agent-phase-1-3 | REMOVED — fully contained in MAIN | 0 |
| ops-milling-reset-20260917 | HISTORICAL ARCHIVE — review unique work | 13 |
| owner-reset-20260917-final | REMOVED — fully contained in MAIN | 0 |
| owner-reset-20260917-impl | REMOVED — fully contained in MAIN | 0 |
| owner-reset-20260917 | REMOVED — fully contained in MAIN | 0 |
| owner-reset-live-20260917-actual | REMOVED — fully contained in MAIN | 0 |
| owner-reset-live-20260917-do | REMOVED — fully contained in MAIN | 0 |
| owner-reset-live-20260917-files | REMOVED — fully contained in MAIN | 0 |
| owner-reset-live-20260917-run | REMOVED — fully contained in MAIN | 0 |
| owner-reset-live-20260917-script | REMOVED — fully contained in MAIN | 0 |
| owner-reset-live-20260917-work | REMOVED — fully contained in MAIN | 0 |
| owner-reset-live-20260917-x | REMOVED — fully contained in MAIN | 0 |
| owner-reset-live-20260917-y | REMOVED — fully contained in MAIN | 0 |
| owner-reset-live-20260917 | REMOVED — fully contained in MAIN | 0 |
| qa/accounts-completion-20260927 | REMOVED — fully contained in MAIN | 0 |
| qa/accounts-milling-20260920 | HISTORICAL ARCHIVE — review unique work | 8 |
| qa/accounts-receipt-currency-invoice-20260927 | REMOVED — fully contained in MAIN | 0 |
| qa/freight-agreement-ambiguity-20260927 | REMOVED — fully contained in MAIN | 0 |
| qa/linkage-audit-20260921-5ab2ee5 | HISTORICAL ARCHIVE — review unique work | 2 |
| qa/local-customer-cheques-20260927 | REMOVED — fully contained in MAIN | 0 |
| qa/tg-accounts-shared-refs-20260927 | REMOVED — fully contained in MAIN | 0 |
| qa/tg-freight-link-20260927 | REMOVED — fully contained in MAIN | 0 |
| refactor/authoritative-module-runtime-20260929 | REMOVED — fully contained in MAIN | 0 |
| refactor/master-store-separation-20260929 | REMOVED — fully contained in MAIN | 0 |
| release/export-documents-20260917 | REMOVED — fully contained in MAIN | 0 |
| repair/super-admin-delete-state-20260923 | HISTORICAL ARCHIVE — review unique work | 12 |
| repair/v3-accounts-links | REMOVED — fully contained in MAIN | 0 |
| rollback/exmill-soda-badge-20260925 | ROLLBACK-BACKUP | 0 |
| rollback/milling-soda-20260925 | ROLLBACK-BACKUP | 0 |
| salary-monthly-completion-20261001 | REMOVED — fully contained in MAIN | 0 |
| salary-popup-visibility-20261001 | REMOVED — fully contained in MAIN | 0 |
| security/allow-missing-recovery-migration | HISTORICAL ARCHIVE — review unique work | 3 |
| security/authentication-hardening-20260929 | HISTORICAL ARCHIVE — review unique work | 9 |
| security/backup-hardening-20260929 | HISTORICAL ARCHIVE — review unique work | 7 |
| security/master-permission-fallback-20260929 | HISTORICAL ARCHIVE — review unique work | 7 |
| security/qa-account-hardening-20260929 | HISTORICAL ARCHIVE — review unique work | 1 |
| security/recovery-hash-migration-fix | HISTORICAL ARCHIVE — review unique work | 3 |
| security/remove-production-bulk-qa-20260929 | HISTORICAL ARCHIVE — review unique work | 1 |
| security/session-web-hardening-1h | HISTORICAL ARCHIVE — review unique work | 2 |
| security/setup-storage-hardening-20260929 | HISTORICAL ARCHIVE — review unique work | 2 |
| super-admin-bank-controls-20260930 | HISTORICAL ARCHIVE — review unique work | 17 |
| tg-credit-advice-overdraft-20261001 | REMOVED — fully contained in MAIN | 0 |
| workbench/exports-documents-20260919 | HISTORICAL ARCHIVE — review unique work | 9 |
