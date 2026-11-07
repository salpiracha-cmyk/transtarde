# Accounts form and posting review — 11 October 2026

Scope: every JavaScript asset in the authenticated Accounts bundle was inventoried for form controls, dropdown ownership, API routes and mutation/refresh handlers. Form-family regression suites below check executable flows in isolated fixtures; they do not constitute reconciliation of every live customer balance. No production transactions are created by these tests.

## Findings fixed

- Live smoke exposed a top error notice covering the Master Records button; notices now sit below the actual header. The startup currency read omitted its required entity; it now uses an authorised company without weakening API access checks. Live smoke now runs after successful production deployment, checking the deployed revision rather than racing ahead against an older release.
- Party-role alias used an absent option list; explicit party fields now show saved parties and permission-aware Add/Edit.
- General/local bags and legacy supplier settlement native supplier selectors now offer authorised Add/Edit without replacing their change handlers.
- Hidden cheque reference retained native required validation in cash/third-party Supplier Payment. Hidden controls no longer block those sources.
- Cash settlement showed bank method and post-dated cheque controls; those now follow the selected source/method.
- TG date edits rebuilt the entire transfer form; rate loading now preserves the draft and guards stale responses/posting.
- Supplier settlement and internal-transfer loads could apply data for the previous company; rendered screens now verify company/request. Service, transport, freight and due-date forms also reject superseded/company responses, with a regression proving an old service response cannot overwrite a new typed draft.
- Checkbox/radio dimensions and field sizing varied across form families; shared screen-only rules preserve print styles.
- Shared red remove controls missed plain Delete actions and had an incorrect icon-only plus CSS selector. Both corrected with handlers retained.
- Supplier settlement concession missing from bill and planned payments; separate cash/discount allocations, accounting lines tagged to the actual bill payee, reason, fingerprint and correction are now tested.

## Regression coverage by form family

| Forms | Validation / accounting evidence |
|---|---|
| Bank receipt/payment, petty cash, finance, multi-party payment | bank_entries_http_test.py, bank_entries_browser_test.py, accounts_layout_regression_browser_test.js |
| Internal and cross-company transfers, TG conversion | module_form_consistency_test.js, intercompany_transfer_ui_browser_qa.js, bank_entries_http_test.py |
| Supplier/service/bag bill settlement, B/F, cheques, advances | supplier_discount_test.py, supplier_opening_http_test.py, supplier_opening_browser_test.js, supplier_cheque_lifecycle_test.php |
| Supplier/broker planning, on-account, payer receivables | payment_plans_test.py, payment_plans_browser_test.js |
| Export credit advice, partial/TG/customer allocation, retention | fi_credit_advice_http_test.py, tg_remittance_http_test.py, linked_retention_bank_test.py, export_receipt_scope_test.js |
| Carry-forward shipment invoices and expenses | carry_forward_http_test.py, export_v3_linkage_test.js |
| Commodity bills/Soda, other purchases, general/export bags | ready_rice_bill_http_test.py, general_bag_bill_http_test.py, purchase_handler_browser_test.js, other_purchases_fixed_assets_test.js |
| Clearing/freight/transport/fumigation/inspection | freight_metadata_test.php, ledger_narration_test.php, end_to_end_audit.js, clean_ui_test.js |
| General expense, utility, credit card, personal deduction/recovery | direct_expense_http_test.py, recurring_bills_profit_test.py, unified_expense_browser_test.js, expense_forms_browser_test.js |
| Salary advances/month, rent, donations | salary_month_http_test.py, rent_recurring_test.js, salary_donations_test.js, expense_forms_browser_test.js |
| JV/opening/amendment/cancellation, ledgers/print/Excel | journal_vouchers_test.js, post_amendment_test.php, post_register_browser_test.js, ledger_posting_rows_test.js, ledger_workbook_test.js |
| Assets/property/instalments, investments and routed funds | assets_registry_http_test.py, assets_registry_test.php, company_investments_test.py |
| Stock/costing/recognition and reports | inventory_reconciliation_http_test.py, stock_reconciliation_review_http_test.py, rules_engine_test.js, end_to_end_audit.js |
| Master records, approval routes, restricted permissions | accounts_master_controls_test.js, accounts_master_popup_browser_test.js, director_approvals_http_test.py, approval_integrity_test.py |

## Complete loaded-asset inventory

Counts identify source form declarations, not distinct rendered forms. Helper files legitimately have zero.

| Asset | Input/select/textarea declarations | API endpoints |
|---|---:|---|
| accounts-notifications.js | 0 | Shared helper / delegated route |
| accounts-row-controls.js | 0 | Shared helper / delegated route |
| accounts-form-layout.js | 0 | Shared helper / delegated route |
| accounts-uppercase.js | 0 | Shared helper / delegated route |
| print-dialog.js | 0 | Shared helper / delegated route |
| post-confirmation-ui.js | 0 | accounts_ledger_browser.php |
| bill-layout.js | 0 | Shared helper / delegated route |
| currency-master-ui.js | 0 | currency_master.php |
| accounts-runtime.js | 0 | Shared helper / delegated route |
| accounts-live.js | 0 | accounts.php |
| accounts-enhancements.js | 0 | accounts.php |
| accounts-navigation-ui.js | 0 | Shared helper / delegated route |
| expense-editor-owner.js | 0 | Shared helper / delegated route |
| rent-salary-ui.js | 47 | rent_salary.php |
| donations-ui.js | 6 | donations.php, expenses_v1.php |
| expenses-v1-ui.js | 83 | expenses_v1.php |
| expense-desk-ui.js | 21 | accounts_subaccounts.php, expenses_v1.php |
| jv-workflow-ui.js | 25 | accounts_chart_word.php, journal_vouchers.php |
| export-realization-master-bootstrap.js | 0 | export_realization_master.php |
| export-realization-master-ui.js | 11 | export_realization_master.php |
| export-tax-certificates-ui.js | 13 | bank_accounts.php, export_tax_certificates.php |
| export-tax-certificates-fix.js | 0 | Shared helper / delegated route |
| bank-accounts-ui.js | 8 | bank_accounts.php |
| bank-reconciliation-ui.js | 5 | bank_reconciliation.php |
| bank-direct-entry-ui.js | 7 | bank_direct_entries.php, bank_reconciliation.php |
| tg-bank-transfer-ui.js | 5 | internal_bank_transfers.php, tg_bank_transfer.php |
| tg-bank-transactions-ui.js | 34 | tg_bank_transactions.php |
| tg-customer-receipts-ui.js | 13 | tg_bank_transactions.php |
| internal-bank-transfers-ui.js | 5 | internal_bank_transfers.php |
| tg-liabilities-ui.js | 18 | export_accounting.php, tg_liabilities.php |
| tg-year-end-revaluation-ui.js | 2 | tg_year_end_revaluation.php |
| export-receipts-ui.js | 28 | accounts_receipt_file.php, bank_accounts.php, carry_forward_shipments.php, export_bank_shortfall.php, export_realization_master.php, export_receipts.php, tg_bank_transactions.php |
| retention-remittance-ui.js | 13 | retention_remittances.php |
| bill-smart-ui-v2.js | 33 | accounts_workflows_v1.php, commodity_bills.php, commodity_lookup.php |
| purchase-commodity-split-ui.js | 0 | Shared helper / delegated route |
| bag-ops-sync-ui.js | 0 | Shared helper / delegated route |
| bag-purchases-ui.js | 34 | bag_bill_file.php, bag_purchases.php |
| nonwoven-bag-ui.js | 11 | nonwoven_bag_bills.php |
| bag-bill-review-ui.js | 0 | bag_purchases.php |
| sales-tax-refund-ui.js | 5 | bank_accounts.php, sales_tax_refunds.php |
| other-purchases-ui.js | 16 | other_purchases.php |
| bill-payment-terms.js | 0 | Shared helper / delegated route |
| payables-commodity-split-ui.js | 0 | Shared helper / delegated route |
| bag-supplier-payments-ui.js | 0 | Shared helper / delegated route |
| other-supplier-payments-ui.js | 0 | Shared helper / delegated route |
| supplier-payment-planning-ui.js | 2 | bank_accounts.php, payables_planning.php |
| payment-planning-bank-funds.js | 0 | bank_accounts.php |
| bank-payment-details.js | 3 | Shared helper / delegated route |
| supplier-settlement-ui.js | 41 | bank_accounts.php, payables_planning.php, supplier_settlements.php, tg_bank_transactions.php |
| carry-forward-entry-ui.js | 0 | carry_forward_shipments.php |
| payables-bill-summary-ui.js | 2 | payables_planning.php, supplier_settlements.php |
| rice-payment-overpayment-guard.js | 0 | Shared helper / delegated route |
| supplier-payment-broker-summary-ui.js | 0 | payables_planning.php |
| supplier-payment-history-ui.js | 1 | supplier_payment_history.php |
| supplier-ledger-ui.js | 1 | supplier_ledger.php |
| rice-payables-link-ui.js | 0 | Shared helper / delegated route |
| brokerage-wht-ui.js | 8 | bank_accounts.php, brokerage_transactions.php |
| local-sales-control-ui.js | 0 | local_sales_control.php |
| local-sales-payment-ui.js | 1 | local_sales_payments.php |
| local-customer-ledger-ui.js | 1 | local_customer_ledger.php |
| local-customer-receipts-ui.js | 10 | local_customer_receipts.php |
| local-sales-costing-ui.js | 4 | local_sales_costing.php |
| production-costing-ui.js | 0 | production_costing.php |
| management-costing-ui.js | 0 | management_costing.php, management_costing_attach.php |
| production-fixed-overhead-ui.js | 0 | production_costing.php, production_fixed_overhead.php |
| production-inventory-transfer-ui.js | 0 | production_inventory_transfer.php |
| export-recognition-ui.js | 7 | export_accounting.php |
| export-costing-ui.js | 4 | export_costing.php |
| costing-sheet-cogs-ui.js | 0 | export_costing.php, local_sales_costing.php, management_costing_attach.php |
| accounts-v1-workflow-ui.js | 69 | accounts_workflows_v1.php, location-master.php |
| commodity-calculation-ui.js | 8 | accounts_workflows_v1.php |
| transport-controls-ui.js | 0 | accounts_workflows_v1.php |
| reports-ui.js | 10 | accounts_reports.php |
| all-ledgers-ui.js | 23 | accounts_ledger_browser.php, accounts_post_amend.php, accounts_search.php |
| profitability-ui.js | 0 | shipment_profitability.php |
| accounts-clean-ui.js | 0 | Shared helper / delegated route |
| master-autocomplete.js | 4 | masters.php |
| accounts-accounting-desk.js | 79 | accounts_dashboard.php, accounts_ledger_browser.php, accounts_reviews.php, accounts_sales_tax.php, accounts_search.php, accounts_shipment_lookup.php, accounts_workflows_v1.php, expenses_v1.php, location-master.php, masters.php, payables_planning.php, purchase_sodas.php |
| assets-registry-ui.js | 64 | assets_registry.php |
| company-investments-ui.js | 24 | company_investments.php |
| account-management-ui.js | 47 | accounts_chart_word.php |
| tg-remittances-ui.js | 8 | tg_remittances.php |
| payment-plans-ui.js | 17 | payment_plans.php |

Limitations: the initial review could not inspect the live browser because another extension UI was open. The follow-up below did inspect the live payment flow and the supplied screenshot. Fixture coverage and targeted live inspection do not establish that every live form has been manually inspected. Fixed enumerations and filtered open-invoice/stock choices do not get arbitrary Add/Edit. Their records are created or amended in their source workflow. Existing financial/legal-entity/approval checks remain in force.

## Follow-up after the owner's duplicate payment panel report

The supplied 14:32 screenshot shows On Account Payment Posted still open after the journal confirmation was closed. Read-only inspection of the existing live tab confirmed the payment workflow result and separate Posting saved dialog simultaneously visible. No live transaction was created, amended or reversed during investigation.

- Payment plans now own their result surface and show the shared journal details inline. The shared fetch hook honours this ownership. Full-plan completion is also inline rather than a second modal.
- Automatic and explicit confirmation requests coalesce. Bill results cancel pending journal popups. Navigation/closed-form responses cannot resurrect confirmation panels.
- Bank/TG transfer/TG transactions/retention share a request owner. Older native bank loads cannot automatically open the TG payment form over a later transfer. Explicit opens retire the old form immediately. Retention reopens restore the hidden attribute as well as the hidden class.
- Purchase and legacy settlement repeated loads reject superseded results; deferred editor and Accounts desk navigation reject abandoned callbacks.
- The bundle now includes bank-workspace-owner.js, bringing the loaded-asset count to 84.

Added executable regressions: post_confirmation_ownership_test.js and workspace_response_ownership_test.js. payment_plans_browser_test.js now loads the real shared confirmation implementation and asserts one dialog for both on-account posting and plan completion. Financial posting payloads, ledger calculations and approvals are unchanged by this follow-up.

The same audit also found TG receipt/payment date changes rebuilding the form. This now refreshes rates in place, preserving the entered draft and manual rate. The workspace ownership regression covers the actual date handler.

### Ledger cancellation and Trial Balance follow-up

Ordinary ledger views now use a read-only projection of completed reversal pairs, including legacy metadata links. Both accounting legs must offset in the same company, bank/cash/subaccount and party. Incomplete reversals remain visible; original journals remain visible for as-of dates before cancellation. Source supplier, local customer and brokerage statements also reject cancelled/reversed source records and fully reversed journal references. Audit journals remain stored. Financial report period movements retain their original dates.

Screen statements remain newest first; print, real XLSX and API CSV statements are oldest first. The general-ledger report also exports/prints chronologically. Ledgers now provides Trial Balance, Chart of Accounts & Details, and Subaccounts & Details using existing permissions. Trial Balance has one as-of date and account, party and account-type views; party totals include unassigned company legs.

Added disposable reversal/as-of/account-identity and trial-balance UI tests; updated fixture dependencies and ledger ordering regression expectations. Production records are not altered by these tests.

Live Trial Balance follow-up: policy-only export bank charge/FED accounts were appearing as numeric names and Other type. The canonical chart now includes missing settlement/export-policy accounts without overwriting existing chart codes, so Chart of Accounts and Trial Balance share the saved names and expense classifications. Existing donations 7200 remains intact.
