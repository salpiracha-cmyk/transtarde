# Transtrade Permanent UI Rules

## Header / logout rule

This rule applies across Super Admin, Milling, Exports, Accounts, Directors, staff landing pages and all future Transtrade modules.

1. Logout must always be represented by the power/off icon in the top-right header area.
2. The logout control must be visually integrated into the page header/topbar. It must not float in a separate capsule, card, overlay or detached box above/beside the header.
3. Where the page uses a dark/navy header, the user/module identity and power icon sit inside that same dark header line and are vertically centered.
4. Preferred right-side order is: date/status where applicable, user/module identity, divider, power icon.
5. The power icon should be visually restrained: transparent/inherited header background, no large white button treatment, and only a subtle divider/hover treatment.
6. Logout must never appear as a normal button/form at the bottom of a page.
7. Bottom-right page space remains clean. Do not place Logout, Updates or notifications there.
8. Notifications/updates belong in the header or another intentional top-area control.
9. Future modules must follow this rule by default; do not reintroduce floating logout UI without explicit owner approval.

## Current implementation

- Super Admin already keeps logout inside its topbar.
- Module wrapper integrates the authenticated user identity and power icon into the existing Milling/Exports header instead of the former floating capsule.
- Staff landing page uses the same header-integrated pattern.
- Accounts and Directors must inherit this pattern when their live interfaces are connected.

## Manual save only — whole project

- Timed/background autosave and timed background refresh are prohibited in every module.
- Typing, selecting, changing a field, or waiting must never commit data or rebuild/clear an active form.
- Data is committed only by the screen's final explicit action, such as **Next**, **Save**, **Confirm**, **Issue**, **Post**, **Create**, **Complete**, or the equivalent action for that workflow.
- Shared cross-module synchronization may run as a consequence of that explicit committed action, but it must not independently save a draft or replace a form being edited.
- A page may load the latest shared state once when opened. Later remote changes require an intentional user refresh/reopen action; they must not interrupt active entry.
- The acknowledgement of the user's explicit Save/Next/Confirm may update that record's committed status and version. It must preserve entered values, focus and scroll, and must not rebuild another active form or save another draft. Navigation occurs only as part of the action selected by the user.
- The customer-master read described below is a narrow exception for committed reference data. It is not permission for timed transaction refresh or autosave. Such reads must preserve active entry; transaction changes from other users are reconciled on intentional refresh/reopen, with conflicts handled rather than overwritten.

## Committed customer master corrections — Exports

- A saved customer/notify master amendment automatically updates the party details used by active shipments and generated documents. Exports may read committed customer masters on opening, returning to the page and every 30 seconds while visible.
- This reads customer masters only. It must not reload a workspace, replace the form objects, save unfinished entries or refresh transaction data. Reconcile the master fields separately in saved storage and the open form's memory.
- Completed, closed and cancelled shipments retain saved buyer/notify snapshots. Capture those details when closing a lot and before applying a master correction to existing closed records. Different lots under one contract may have different closure snapshots.
- Generate document packs at print time so a pack prepared before a master amendment does not print a stale address.

## KCCI Certificate of Origin output

- COO preview, PDF and physical-letterpad printing use the same KCCI sheet geometry: 8.4 × 11.1 inches (213.36 × 281.94 mm), with Actual size / 100% printing and no browser headers/footers. Other documents retain A4.
- Keep exporter, consignee, membership, transport, marks, packages, description and weights within their printed boxes and columns. Wrap and fit text without hiding or truncating it.
- Position the owner's name, Proprietor designation and company separately above their respective bottom lines. Leave the bottom date blank; retain the commercial invoice number and its date under Other Information.
- Carry the Commercial Invoice description and HS code; do not add a separate GOODS OF PAKISTAN ORIGIN statement in the description column.

## Final shipment documents and office-folder copies

- Keep one merged final-document table, without generated-set ticks or a printing lock tied to lot closure. Completion gates apply only to Mark Lot Complete.
- Commercial Invoice and Packing List come from the system's issued final versions, including letterhead, footer and signature. Do not require re-uploading them.
- Show one Certificate of Origin entry for the uploaded issued original. The draft remains available in its own COO workspace but is excluded from Final Output and folder copies.
- Phytosanitary and other certificate uploads must populate the corresponding completion records. Allow an optional editable issued document/certificate reference. API category slugs must fit the 64-character storage limit regardless of document title length.
- Place ORIGINAL / COPY labels in normal flow above the invoice-reference box for both invoice and packing pages.
- Office copies are queued on the hosted server for the Office Agent, which writes to the configured TTI share. No browser folder picker is required. Reuse Customer / SHIPMENT #<contract sequence> / LOT #<lot sequence>.
- Copy only committed data into generated PDFs; folder saving must not persist unfinished form changes. Include all attached uploads, deduplicated by file identity. Report failures and partial copies accurately; an unavailable office share must not discard the protected online documents.

- Use the same customer / shipment / lot hierarchy for every TTI, BRM and TG route. Within the lot save balanced, committed Customs Invoice, Customs Packing List, Phytosanitary Invoice and uploaded GD in **Custom documents**. Save the reviewed Pakistan → TG settlement pack in **TG docs** for TG shipments only; omit an empty optional relationship letter. Buyer final documents and other originals stay directly in the lot folder. Reuse these subfolders on later saves.

- GD original uploads show separate GD Number and GD Date columns, automatically populated from the lot’s saved Customs/B/L Draft references (including older B/L-only records). Support multiple GD rows. Typing does not commit; Upload / Save validates each pair, uploads the file, then saves the same references into Customs and B/L with the GD fingerprint. Never require manually formatted number/date text.

- Print KCCI membership beside the membership-number label, keep package text within an inset column, and align owner name, Proprietor designation and company above their respective lines. Use the COO-specific named print page so A4 scaling cannot shift these fields.

- The saved B/L Draft goods description is the authoritative wording for Commercial Invoice, final Packing List and COO, including TG final/internal outputs and L/C shipments. Do not append old Customs or contract specifications to an amended B/L description. B/L-generated container/package controls, totals and FI/GD/L/C references stay in the documents’ dedicated fields; retain a saved HS line without duplication. Before a B/L draft is saved, use the initial Customs/L/C wording. Customs documents keep their Customs description.
- Every rendered brand label uses **"UPPERCASE NAME" Brand**, with one set of quotes and one Brand suffix. Format output only; preserve raw brand identifiers for artwork, stock and packing matching.

- Commercial Invoice Save Draft and Print / Save must explicitly server-save the entered invoice fields and refresh the Accounts source before copying or printing. Printing preserves Final status on an issued invoice. Saved drafts copy as Commercial Invoice - Draft.pdf, without replacing the issued final invoice.
- Place EDIT FOR RETENTION and PRINT RETENTION INVOICE together below the main invoice print controls. Retention versions save separately and never alter the buyer invoice. Include saved retention invoices in office-folder copies.
- Completion recognises external certificates in either uploaded-document or certificate records. A missing dispatch original quantity must name its document; a fumigation supplier bill is not an export certificate.
- The Office Agent destination is Transtrade software shipment documents on the TTI share. A hosted queue acknowledgement means queued, not verified office delivery; report agent delivery separately.

- Windows and Mac use the same office share hierarchy. Windows agents use \\tti-server\TTI DOCS\Transtrade software shipment documents; Mac agents use the mounted smb://tti-server/TTI DOCS share. Browser downloads must not substitute for Office Agent delivery.


## 1 October 2026 shipment completion and company COO settings

- Bank Covering Letter is optional for closure. Save / Print saves the letter to the server before printing; it never freezes dispatch. Preserve earlier saved letter revisions.
- Final Output has one larger LOT COMPLETE control in the existing bottom toolbar. Remove the separate office-folder-selection and save-all buttons and generic explanatory green commentary.
- LOT COMPLETE queues the committed file package and closes the lot only after the hosted server acknowledges the completed lot state. The Office Agent five-minute interval is independent of completion. A lost acknowledgement requires reload and review; never automatically overwrite the server with an old root. Completed VIEW is read/reprint only. Director and Super Admin may REOPEN LOT with a mandatory recorded reason and acknowledged save; ordinary staff remain blocked.
- One Master Shipment Documents.pdf contains all available final/supporting documents except sales contracts, Goods Declarations and bank covering letters. GD uploads are also saved separately as PDF; saved bank covering letters are native editable .docx; Sales Contract and uploaded Signed Sales Contract remain separate. Retain uploaded originals and Custom documents / TG docs subfolders.
- Company Master has a Pakistan-only Chamber / COO details section with chamber name, membership number, authorised signatory and designation. These settings control the COO, rather than ownership position or an assumed designation. Open lots use current settings; closed lots retain the settings captured at closure. Existing TTI/BRM approved identities remain during migration; new companies have no guessed person or designation.
- Screen form/table controls must fit their cells and align at row tops across Exports, Milling and Accounts. Shared screen CSS must not alter document print positioning.


## Director approvals in Super Admin

Every request requiring Director approval must also appear in the Super Admin Console approval panel and allow Super Admin to make the same decision there. Retain the Directors route. Both routes use the same underlying request and server approval rules, with the actual decision maker recorded and completed requests removed from both queues. This is a standing rule for existing and future approval workflows; it does not automatically approve requests or change staff roles, module access, entity access or Master permissions.


## Form opening and dependent fields

- Opening an icon, form, step or reused popup must bring its heading and first relevant editable input into view. Reset the actual scrollable popup/window as well as the page. A delayed load must not move the form after the user starts typing or scrolling; background refreshes must keep their position. Do not automatically summon the phone keyboard. Use the shared `TT_FORM_VIEWPORT` implementation.
- Keep the controlling dropdown/tick visible. Show dependent input boxes and sections only when the selected option makes them applicable. Reopening saved records restores the appropriate fields from their saved choices. Hide inapplicable fields without deleting historical values or changing financial calculations, permission checks or posting rules.

## Retired test-data cleanup and asset delivery

- Opening Super Admin or any module must never run a destructive test-data migration. The September Parties cleanup is retired, including its compatibility function; restoring an older store without its marker must not clear current masters. Preserve existing migration and audit history.
- Authenticated PHP pages version existing local JavaScript/CSS assets by their content hash when assembling HTML. External resources and dynamic PHP bundles retain their own delivery rules. Inline application scripts remain literal.

## Company-bank retention tick

- On a Pakistan TTI or BRM company PKR bank, keep the Retention account tick visible. Saving it creates one separate linked USD ledger named `<Bank name> Retention Account` (for example, `Meezan Bank Retention Account`). Keep the original PKR account, defaults and historical balances unchanged.
- The linked USD ledger appears in Accounts for retention receipts, payments and opening balances without requiring another account number or IBAN. Never copy the parent PKR account number into a fabricated foreign account. Validate its parent/company linkage on the server; ordinary incomplete banks remain unavailable.
- Repeated saves reuse the same linked ledger ID. Unticking removes its retention designation without deleting the account or its history; reticking reuses it. Existing separately entered physical foreign-currency retention accounts retain their identities.


## Accounts expense entry and navigation

- The Expenses area has Pay Expense, Expense Recipients, and Salaries & Staff. Pay Expense opens Other Expense, Utilities, and Credit Card using three centred green icon buttons on one screen. Pay Expense accepts any recipient, including household expenses without an individual director assignment.
- A single payment may contain multiple categorized expense rows. Debit the applicable subsidiary accounts and credit the selected bank/cash once for the total. Post is explicit; printing is a separate action in the posting confirmation. This form has no Pay Later or autosave.
- Corrections reverse and replace the original payment with its expense breakdown retained; deletions reverse postings and retain audit history. Retry keys prevent duplicate postings.
- Open the modern expense renderer directly, and show the finished form after loading; do not simulate old workspace and child-button clicks.
- Supplier/Broker Payment is available in Local Purchases and the second Home summary box. Do not add a separate top Home shortcut. Unfinished prepared payment plans remain at the bottom.
- After explicit posting, refresh summaries without replacing another active entry form. Shared transaction changes appear on intentional reopen/refresh.

- Expense recipients identify who received money; each payment row selects its purpose. Recipient defaults must not force future payments into one expense category. A single person can receive Zakat and other payments.
- Vehicle repairs and fuel select the vehicle name and registration together. Store stable asset/recipient IDs alongside journal lines for expense, recipient, vehicle and monthly reporting. Never create a second monetary posting for a reporting dimension.
- An existing vehicle can be registered for cost tracking without inventing its acquisition value or reducing bank funds. Ordinary repairs stay expenses; asset acquisition remains separate.
- Stockbroker entries initially show funding, buying shares, selling shares and bank withdrawal. Routed funds are optional. Blank commission means no commission posting.
- Reports and bank balances open their current renderer directly. Ignore stale report responses after company switches; do not rescan the complete workspace on each total or text mutation.

## 8 October 2026 expense review batch

- Visible dates throughout the application use DD-MM-YYYY. Store and API dates remain ISO for validation and sorting; a presentation change never rewrites transaction dates.
- Bank Entry offers Bank → Petty Cash: debit company petty cash, credit its company bank once; no expense. Foreign-currency conversions use their dedicated workflow.
- Ordinary expense types offer Add/Edit in place. Creating a type requires Milling/Production, Home, Office or General Export and an appropriate head/subaccount. Milling/Production types remain in their dedicated posting workflow. Changes preserve existing voucher treatment and stable type IDs.
- Add Vehicle beside the expense vehicle selector saves name/model and registration through the shared register, then selects it while preserving the expense draft. Register Another repeats the same simple registration form; full identity details remain under Assets & Investments / Assets / Review / Amend Details.
- Vehicle Tax & Licence Fees requires a vehicle, period covered and optional challan reference. It is distinct from repairs/fuel and included in vehicle costs. Period metadata alone does not generate monthly prepaid amortisation journals.
- Narration and purpose populate each other without overwriting manual edits. For several expense lines, narration fills the first purpose only; leave other purposes empty. Either field, or both fields, may be empty when posting.
- Corrections retain the original public Post ID while maintaining immutable original/reversal/replacement accounting journals. Show bracketed correction details below narration. Reporting and balances include the full journal audit trail.
- The Post ID Register has aligned columns and affected party names. Stockbroker transactions show Date separately from Post ID.
- Post is the final posting action. After successful posting, show a compact centred confirmation with Post ID, debit/credit lines, Print and Close. Print opens the browser printer dialog in the same tab using an isolated print frame; never open a new printing tab or window, or print automatically on Post.

## 8 October 2026 beneficiary and credit-card agreement

- Paid to is the actual recipient. Classification and beneficiary are stored as reporting dimensions derived from the selected expense type and Home/subsidiary ledger; do not show separate Expense Area or Expense For controls. Production posting retains its dedicated workflow. A CAS school fee can appear in Talha’s Home report without changing CAS as recipient. Reporting dimensions never add monetary debits.
- Credit cards use saved masters, one bill total, statement date, due date and reminders. No mandatory purchase-by-purchase allocation. Retain existing historical item allocations until explicitly amended. Pay Expense opens one Card Bill & Payment form. New bills and their full payment commit atomically. A saved bill is reused for payment; never expense it twice. Historical bill corrections and later personal adjustments remain explicit actions.
- Optional personal-amount ticks select each director once, with amount and remuneration deduction or cash recovery. The full card bill and full bank payment remain intact. Separate internal recovery entries reclassify personal amounts; financial reports must not treat recovered personal spending as business expense.
- Remuneration deductions use accrued payable first and carry remaining amounts into the existing salary advance/deduction workflow. Never invent an entitlement or double-deduct. Corrections restore earlier salary deductions and replace the allocation, preserving journal history and the original public Post ID. Cash receipts settle the personal receivable without touching the card. A collected cash allocation cannot be removed by silently refunding money.
- New personal adjustments are explicit Posts with retry keys, entity and icon permissions, and audit history. They may be entered with the bill, at payment or afterwards. No background saving or production test postings.


## 8 October simplified Home and expense type selection

- Pay Expense removes the visible Expense Area and Expense For controls. Classification follows the selected expense type. Home Expense shows one Home ledger selector: Salman, Talha, Tayyab or Combined Home. ARP Expense is a separate type with subsidiary selection, outside that Home list.
- Add / Edit Expense Types manages the entire list, including standard types. Standard names are editable through stable subsidiary IDs; their accounting heads and specialised vehicle, medical, rent and donation validation remain intact. Custom types retain classification and subsidiary hierarchy choices.
- MRS SRP monthly Home allocations report under Salman Home; MRS TRP under Talha Home; MRS TAYYAB under Tayyab Home. Consolidate using reporting dimensions without changing the original recipient, statutory posting head, amount or journal history. Household ledger activity does not create duplicate monetary postings.


## 8 October posting confirmation cleanup

- Successful direct expense Post/Amend closes its entry popup. Keep only the compact posting confirmation with Post ID, debit/credit details, Print and Close; do not create a second full-screen Payment Posted page or duplicate print/pay-another controls.
- Confirmation and voucher account rows show the selected bank name and account number/IBAN rather than the generic Bank Accounts control head. Resolve labels from saved line metadata or the authorised company bank master; retain accounting codes and historical journals unchanged.

## 8 October final Accounts review

- JV lists all available posting heads, subsidiaries, individual company banks and company petty cash. Bank lines require an authorised active company bank ID and retain its name/number/currency snapshot. Foreign bank lines require the actual native amount beside the book amount. Approval and reversal preserve both amounts and bank linkage. Special asset/finance registers retain their dedicated integrity checks. Narration is optional.
- General Export Expense (5550) and its custom subsidiaries are available in Pay Expense without a shipment. Production costs and shipment-specific export bills retain their existing workflows. Include general export costs in expense activity reports.
- DD-MM-YYYY separators stay fixed during typing, deletion and pasting; validate calendar dates and keep ISO storage. Constraint changes must preserve unfinished date entry.
- Accounts forms use aligned controls and readable responsive layouts. Add line follows the final row; removal uses a red circular − before the row, and remaining row actions occupy one aligned Actions cell. Utilities populate provider/type/location from their saved master and show meter readings only for mill electricity.
- Voucher company identity is centred above the voucher title, details align left/right and the receiver signature has clear separation. Print opens the same-tab printer dialog. Preserve supplier payments, export receivables, credit advice, company scopes, permissions and journal history.

## Group customer receivables

- The shared Customer / Export Receivables summary reads issued external buyer Commercial Invoices for TG, TTI and BRM, grouped by original currency. Show company, customer, invoice, receipts and outstanding in its detail. Restrict the group to companies the user may view.
- Never include TG packs, Customs settlement values or intercompany revenue candidates in this external customer total. Saved drafts are separate estimates; issued invoices awaiting recognition remain visible without creating journals on read.
- Apply only linked posted receipts and committed advance applications. Unapplied advances are not guessed across lots. Keep statutory company ledgers and posting permissions unchanged.

## Accounts controls and purpose-specific masters

- Salaries opens two aligned green tiles: ADVANCE and PREPARE SALARY. Show the month inside preparation. Staff setup is a popup, not an expanded form above the tiles. Do not refresh/rebuild salary entry on window focus or visibility return.
- Authorised Accounts users may edit recurring salary, Zakat and allowances, add staff, or remove staff in a monthly draft. Commit these master changes atomically with successful monthly posting, retain audit history and preserve previous posted periods. Advances, personal recovery deductions and payment amounts never become recurring master defaults.
- Use a green square + to add a transaction row and a red circular − before a row to remove it; use a compact pencil for editing. Keep controls beside the affected rows and preserve accessible names, confirmations, authorization and reversal rules.
- Master-backed searchable choices show a separate edit pencil beside each editable option and Add [master title] at the bottom. Open the existing authorised editor and return to the current entry; do not create duplicate editors or re-render unfinished forms. This supersedes the older M-only quick-entry restriction: M remains the full master hub. Fixed workflow choices and transaction references do not become editable masters.
- Utility providers use the active Service Provider role. Supplier, broker, customer, freight, clearing, transporter, inspection, fumigation and labour choices use their own roles. Broad recipients and JV accounts retain their legitimate broader scope. Never offer every Business Party in a specialised selector. Multi-role parties appear only in their relevant roles.

## Owner approved deployment requirements — 9 October 2026, after release c4acd9c

Status: owner authorised implementation and deployment on 9 October 2026. These requirements amend the related earlier rules when implemented; do not add a parallel renderer or duplicate posting workflow.

- Remove the redundant generic Reference field/column from payment and amendment screens and ordinary ledger presentation. Keep required cheque numbers, online-bank transaction references, supplier/customer invoice numbers, tax challan identifiers and immutable historical reference data. The owner screenshots show the optional Pay Expense Reference and the generic Post amendment Reference.
- Every actual payment-source selector must include the authorised company's active Petty Cash alongside its banks, using the real company cash ledger. Audit all payment forms and their backend sources; do not add a cosmetic option without supported posting or enable inactive cash implicitly.
- Utilities may be paid using a saved company credit card. Record the utility expense against that card's liability once; no bank or petty-cash reduction occurs until the card bill is paid. The card bill shows the included provider, utility, date and amount as a readable breakdown. The full bank payment and actual card bill total remain intact; bill recognition must account only for the remainder not already recognised by linked utility entries.
- Match card utility charges to the actual statement/bill date and prior statement boundary, not the utility bill month or due/payment date alone. Charges after the statement boundary belong to the following statement. Preserve stable charge links, retries and amendments; unbilled charges remain visible. Do not silently rewrite a previously posted/paid statement or count a charge twice.
- Add an authorised Post ID deletion action. Require company and workflow authorisation for Accounts, Directors and Super Admin, a reason and audit history. Remove the deleted posting's accounting effect from every affected ledger and ordinary book presentation while retaining immutable original and cancellation/reversal records in audit history. Update all originating registers, allocations and linked company postings consistently and atomically; do not merely hide a row or delete a journal that other records still reference.
- Align Accounts removal controls as compact red circular minus icons in a dedicated position before each row, matching Exports/Milling. Keep equally sized aligned fields and preserve handlers, confirmations and accessible names. Avoid placing a floating removal button on top of the first field or moving it into a data cell where it disrupts alignment.
- Correct opening-balance amendment routing: opening journals use the dedicated management opening-balance workflow, retaining the opening date of 01-07-2026 and stable public Post ID. The ordinary amendment form currently exposes opening entries even though its backend rejects them. After a successful amendment, refresh the authoritative ledger and summaries, and show the shared centred confirmation with Post ID, debits/credits and old-to-new values. Never report success before persistence or leave a stale balance on screen. Investigate the reported HAJI KHUSHI MUHAMMAD balance without modifying the owner's actual figures during testing.

- The unified Post ID view must include all authorised posted journals, including non-cash JVs and opening balances, rather than restricting the register to bank/cash movements. Keep drafts and pending approvals out of posted results; retain the agreed audit treatment for amendments and deletions.
- Replace the separate Registers/Ledgers navigation and nested ledger icon grids with one Ledgers entry. The Ledgers menu contains Ledger, Post ID Register, Trial Balance, Chart of Accounts & Details and Subaccounts & Details (expanded at the owner’s request on 11 October 2026). The Ledger form chooses Account or Party; searchable selectors follow that choice, with company/currency and date range controls above the results at the bottom. Account search includes main heads, subsidiaries, banks and petty cash; party view consolidates a party across its legitimate account heads without mixing currencies. Post ID opens the full voucher. Preserve linked details, authorised Edit/Delete, printing and Excel export. Owner approved this final two-entry navigation and deployment on 9 October 2026.
- Numeric Post ID search accepts the last two or three serial digits as suffix matches, including leading-zero variants, as well as full IDs and full serial numbers. Return all authorised matches when a suffix is ambiguous, showing the complete Post ID, date, company and party. Do not silently select the first match or match a year fragment as a serial suffix.
- Party names and their saved short codes/aliases, such as HKM for HAJI KHUSHI MUHAMMAD, must resolve to the same stable master ID in relevant ledger searches and transaction selectors. Show canonical name and code, and store the canonical party identity, not a new party called HKM. Respect purpose-specific roles, entity rights and ambiguous-code handling.
- Investigate the reported Fayyaz grave yard / Fayyaz graveyard dropdown duplicates by tracing their source lists and stable IDs before any merge. Existing business-name normalisation already ignores spacing/punctuation, but dropdown display uniqueness currently uses literal text. Ensure all master and expense-recipient add/edit paths share duplicate prevention; reconcile confirmed duplicates without losing posts, balances, roles or audit history, and show one canonical dropdown option. Do not auto-merge different people based only on similar names.

- Post ID cancellation uses the original workflow reversal under the same storage lock. Linked later transactions must be corrected first when the source validation requires it; never bypass stock, finance, allocation or cross-company integrity. Unsupported source types retain their source cancellation route.


## 9 October Accounts layout and posting correction

- Bank Payment starts with Entry Type and Date, then Pay From Bank. Its payment rows contain Party, Cheque / transaction reference, optional Narration, and Amount. The bank credit total is derived from the rows, never a second editable amount. Each row retains its own payment method, cheque and narration; one explicit Post commits the balanced batch atomically. Reject repeated cheque numbers within the batch and across existing postings.
- Party creation and accounting treatment open separate popups, preserving unfinished bank/expense/JV input. Checkboxes remain inline with role labels. Dropdown Arrow Up/Down visibly highlights choices; Enter selects that choice without submitting the form.
- Bank → Petty Cash only requires the company bank, date and amount. Generic reference/narration are hidden and optional; do not expense this transfer. Existing explicit cheque tracking is preserved in historical records.
- Successful bank posting closes the entry form and shows only the shared compact posting confirmation. Do not leave a second Bank Entry Posted screen underneath it.
- Salaries & Staff offers Advance and Prepare Salary. Remove separate Staff Setup and inner Back to Salaries buttons from this entry flow. The salary sheet retains contextual add/edit controls and existing authorised Master Records access. Successful advance/month posting returns to the salary landing page and presents the shared Post ID confirmation. Explain outstanding payment reviews before month completion; preserve approval, accounting treatment and historical salary integrity.
- Selecting a complete DD-MM-YYYY date and typing replaces it from the first digit with a normal caret. Hyphens remain fixed. Calendar validation, ISO storage, paste, partial edits and optional dates must work through the same shared date control throughout the software.

- Every displayed Ledger/Post ID row shows the same red minus control. Do not silently hide it for unsupported or cancelled sources. Explain already-cancelled audit records, missing permission and source-workflow requirements before asking for a deletion reason. An unapplied salary advance may be cancelled atomically with its journal and advance balance; an applied advance requires its linked salary deductions to be reversed first. Cancellation history cannot itself be erased.

## 10 October TG invoice and advance remittance

- TG Payment For orders Currency, Sender account, then Invoice / Advance. Multiple invoice selections remain as rows inside that box; selected invoices leave the dropdown and return when removed. Selecting Advance opens its amount input after the selector. Invoice amounts and an advance can share one remittance.
- The box shows TOTAL PAYMENT RECEIVED in the selected currency, calculated from the invoice and advance rows. Preserve typed advice, deductions, attached files and scroll when rows change; selection and typing never save or post.
- For this TG basket, the sender total is the full principal reserved for TG review. Actual foreign amount received is the Pakistan bank's net receipt. Their positive difference posts once to Correspondent Bank Charges (6810), valued at the entered Pakistan bank rate, while invoices and advance settle for their selected full amounts. Reject a net receipt larger than the sender total or allocations that do not equal the sender total.
- The TG bank is deducted only through the existing TG remittance review and explicit confirmation. Review shows every invoice, advance, full sender principal, net Pakistan receipt and correspondent difference. Preserve existing TG charges/VAT, permission, reversal and retry controls; never automatically post a second TG payment from the receipt browser.

- Credit advice WHT and service/bank commission percentages use the full selected invoice/advance payment at the actual bank rate, before correspondent bank charges. FED uses the resulting commission amount. Automatic percentages are recalculated by the posting endpoint using the same base; explicit bank-advice amount overrides remain supported. Actual bank credit equals the net foreign receipt PKR equivalent less these deductions and retention.

- At fractional FX rates, reconcile the receipt journal from full sender PKR equivalent minus the separately rounded correspondent fee. Carry sub-rupee bank-advice differences through the existing rounding account; never fail on a one-paisa difference between separately rounded gross/net conversions. Numeric entry formatting covers decimal text inputs as well as number inputs, showing commas and larger figures while preserving unformatted values for calculations and posting.

- Split credit advice: each partial receipt records its actual rate, date and unique payment part reference within the same bank advice. Allocate this part's received foreign amount plus any explicitly entered correspondent charge. Blank charges mean zero; unpaid invoice balances stay open. WHT/commission use this part's full PKR equivalent before correspondent charges; FED follows commission. TG principal deductions remain subject to TG review.
- Export receipt links distinguish TG Pack payments from customer commercial invoice payments and match the invoice reference within its contract. Show the dated payment history and remaining balance without changing the original invoice value or frozen bank-purpose copies.
- Bags use one owner for their shared purchase editor. Delayed commodity/Other Purchase callbacks and previous-company responses cannot render over the selected form. General/local bag bills require an active Bag Supplier but no export PO: stock debits 1340, consumed bags debit 5550, supplier payable credits 2140 and an actual tax invoice controls 1230. Keep their totals separate from export bag refund working.

## 10 October supplier balance B/F settlement

- Supplier Payment includes a separate Include balance B/F tick using the party's saved creditor opening, alongside specifically selected bills. Opening amounts are entered once through the authorised management opening workflow, never created as an expense or inferred from an oversized payment. Debit openings are not creditor payables. Preserve company, original control head, payment source, partial amounts and immutable opening/payment history. Cleared openings disappear from unpaid choices; cancellation restores them. Linked payments must be reversed before amending/deleting the opening.
- Clearing Agent Bill has one green + at the left below its charge rows and no separate Deduction shortcut. Existing line Type selections and historical deductions remain supported.

- Enabling Partial Payment Receipt for TG and changing this part's foreign receipt/correspondent charge redistributes only this part across selected invoices and updates the TG basket amounts. Partial WHT/service charge percentage calculations use received foreign principal plus explicit correspondent charge at the actual rate, independently of a stale/full invoice selection. Explicit amount overrides survive recalculation; FED follows the service charge.


## 11 October Accounts consistency and supplier concessions

- Every Accounts form uses aligned screen controls, inline labelled ticks, the same green add/red remove actions, and top-of-screen posting/validation errors. Keep document print positioning separate from screen layout. Dropdown master actions obey Create/Edit permissions; transaction choices (invoice, method, tax treatment, fixed categories) do not create master records.
- Party and account ledger screens, printed statements and Excel/CSV downloads sort by transaction date ascending (latest at the end), with Post ID as a tie-break. The Post ID Register retains its existing latest-first order. Calculate balances chronologically. This supersedes earlier descending ordinary-ledger wording.
- Supplier discounts are explicit agreed settlement concessions with a recorded reason. Clear the original creditor head for cash/tax plus discount; credit actual bank/cash/third-party payment separately and Other Operating Income (4400), subsidiary Supplier Settlement Discounts, for the concession. Do not change an original bill, create another expense, disguise the discount as a payment or supplier advance, or add it to bank charges. Source cancellation reverses all lines and restores outstanding capacity. Original purchase price corrections continue through the original bill correction workflow.
- Specific bill payment and planned supplier/broker payment support discounts. An unallocated on-account advance has no selected bill to discount; select the outstanding bills before recording a concession. TG native-currency supplier payment remains its separate currency workflow.
- Changing a TG transfer date reloads applicable rates in place, preserving the source/destination, amount, narration and chosen date. Prevent posting while rates are unavailable. Never rebuild a transaction form in response to a date or amount edit.

## Posting result and bank workspace ownership — 11 October 2026

- A posting has one result surface. A workflow that renders its own result requests inline shared journal detail and marks its fetch as workflow-owned; the automatic confirmation must not open a second popup. Completing a payment plan shows its completion controls inside that plan, not a second modal over it.
- Shared automatic and explicit confirmations for one successful operation coalesce. Dismissing, navigating away, replacing a bill result or closing its owning form invalidates pending detail responses. Later legitimate amendments remain eligible for confirmation.
- Bank balance, TG transaction, TG transfer and retention screens share a workspace owner. Clear/hide the prior screen immediately on intentional open, guard late responses against the current owner/company, and restore both hidden attributes and classes on reopen. Never replace a draft through a superseded native bank handler.
- Deferred navigation and repeated purchase/settlement loads must be cancelled when the user closes, changes company or opens another form. This does not change posting authorisation, approval routes or financial entries.

- A TG receipt/payment date edit refreshes accounting rates in place. Keep the entered date, bank, payer, amount, narration and manually entered rate; prevent posting until the new date's rates have loaded.

- Ordinary ledgers and expense activity suppress both linked journals of a complete same-company reversal. Retain immutable journals and cancellation audit history. Do not hide only one leg or a partial reversal. An as-of date before the reversal still shows the original transaction. Financial period reports retain their dated reversal movements.
- Ledgers includes Trial Balance (one editable as-of date, today in Karachi by default; account name, party or account type views), Chart of Accounts & Details and Subaccounts & Details. Reuse authorised account management. Party trial balances include unassigned company balances so the books remain complete.

- Payment icons use their service-provider roles from Business Parties, together with saved bills in that same category: Transporter, Freight Forwarder / Shipping Line, Clearing Agent, Bag Supplier, Labour Contractor, Inspection, Fumigation and Service Provider. Historical bill identities remain payable. Service-provider headings read Party Payments; commodity supplier/broker forms keep their own names. Add/Edit respects existing master permissions.
- Selected-bill payments have no manual discount amount/reason boxes. If the entered payment is smaller, show one computed “Apply Rs. [difference] as discount?” tick immediately above Post. Unticked payments settle oldest first and leave the final invoice balance open. Ticked payments credit the settlement discount account, without increasing bank/cash payment. Statutory withholding stays separate and any cash-based withholding recalculates; stale balances reject with a visible error.
- All ordinary ledger screens, print and Excel are oldest first, latest at the end. The Post ID Register remains latest first. Company-wide unassigned party lines appear as an actionable review on the Party Ledger overview, not as a warning attributed to the selected party. Generic company capital without a person reference is not an unmatched trade party. Original journals and valid manually entered openings remain intact. Trial Balance only reads posted app journals; this change never imports a historical trial balance.

Party bill payments settle only checked invoices and the separately checked Balance B/F. An explicit empty invoice selection never means all invoices. Balance B/F can be settled alone; excluded invoices keep their balances. Successful party payments use the shared posting confirmation only and close the entry form without a second receipt panel.

Across Accounts, cheque date defaults to the voucher/payment date. Both dates remain editable. Changing the voucher date updates a cheque date still following its default and preserves an explicitly edited cheque date. Save the selected cheque date in voucher metadata; post-dated supplier cheques still follow their issued-cheque workflow.

Post ID Register excludes cancelled/deleted complete journal pairs while immutable audit history remains retained. Amendment Posting date starts from the saved transaction date and stores the selected corrected date independently from audit time and cheque date. PKR-bank amendment amounts derive from debit/credit; a separately labelled bank-currency amount is shown only for foreign-currency banks. Opening payment choices resolve recorded master IDs/codes and explicitly marked historical openings without inventing balances.

Party Payments offers Review Balance B/F through the existing management opening correction endpoint. Review the original record explicitly when its saved party differs from the current master. Corrections retain the opening date, original creditor control head, immutable journal history and public Post ID. The current party target must match that control head. Linked payments or carry-forward allocations block party relinking. Refresh unpaid items in place after correction while preserving the payment draft; do not automatically post a payment or import opening balances.

Online Banking payments expose an editable payment date, defaulting from the voucher date and preserving an explicit saved date. Store it as bankPaymentDate, separately from chequeDate and journal posting date. Cheque/reference precedes payment narration. Bank Entry Post IDs never redirect to a generic finance form: date-only correction stays on the exact journal, preserves all original amounts/accounts/rates/source metadata, reverses the original date and posts on the corrected date with the same public identity. Later facility principal movements must be corrected first when required; existing company/workflow Edit permissions remain mandatory.

## USD/PKR forward bookings — 14 October 2026

- Forward Booking is for TTI/BRM only. Contract number is optional, user-entered and never generated. Internal storage IDs are not contract numbers. Save the contract bank, booking date, USD amount, rate, AB waiting days and CD additional utilisation-window days. Both periods are calendar days. Start = booking + AB; expiry = start + CD. Booking creates no fictitious bank receipt.
- Credit advice keeps one exchange rate. Manual rate or an eligible forward booking may be selected; two rates require separate advices. Utilisation is only converted USD (received USD minus retained USD), uses the posted advice date within the start/expiry window, and cannot exceed the contract capacity. Any PKR receiving bank in the same Pakistan company may be used; customer/TG/offshore remitter identity does not restrict the booking. Amount/rate/windows are protected after active utilisation; optional contract number and note remain editable.
- Capacity comes from active posted linked credit advices under the Accounts lock. Advice reversal restores it; replacement advice consumes only its corrected utilisation. Amendments account for the original amount being released. A settled contract requires expiry-settlement reversal before a utilised advice can be reversed. Distinct portions of an already-posted TG payment remain pending until all advices are recorded, without another TG bank debit.
- Expiry close-out posts unused USD times (booked rate minus bank-confirmed closing rate), less separately recorded PKR bank charges, to the contract bank. Positive difference is profit; negative difference is loss. Use Exchange Gain/Loss 7100 with Forward Exchange Profit/Loss detail and Bank & Finance Charges 6800 for fees. Zero difference/zero fees closes the register without a fabricated zero journal. Settlement reversal restores the unused balance and keeps audit history. Forward Register expands contract utilisations with advice/Post IDs, remitters, receiving banks, shipment/invoice references and running USD balances.
- Pay To accepts one unique eligible existing recipient on blur/change/Post, without interrupting typing or creating a shortened duplicate name. Ambiguous matches still need explicit selection; unmatched free-text recipients keep the existing authorised creation treatment. Preserve all handlers, permissions and draft amounts.
- Forward Booking and Pakistan tax/retention are not TG functions. Trial Balance has one shortcut in Ledgers; Expense Recipients setup is in Accounts Setup and retains Add/Edit alongside Pay To. Bank Finance remains available in either country for authorised setup or existing facilities. Retention/TG-review bank shortcuts appear when the relevant accounts or transactions exist.

## Historical bag invoices and service ledger groups — 14 October 2026

- Historical export bag invoices dated before 1 October 2026 may be recorded without shipment/PO linkage. Export invoices from 1 October require a shipment-linked bag PO. Genuine General / Local Use purchases remain exempt. Enforce original invoice dates and cutoff on server and UI, across bag routes.
- Invoice dates before 1 July 2026 retain original bill/GST history but recognise only 1 July supplier amounts unpaid, GST still recoverable and unused bag stock. Balance against Opening Balance Clearing; never repost old consumed costs. July–September invoices use the original invoice date for normal purchases.
- Link existing creditor openings rather than adding liabilities twice; remove assigned bill amounts from generic Balance B/F choices. Existing GST/stock opening allocations require sufficient unassigned balances. Record later payments once through existing bag supplier settlement on actual dates, with independently editable bank/cheque dates.
- Historical invoice GST, recoverable opening amounts and new opening GST are separate report figures. Preserve documents, bill-wise payments and original dates. Draft typing does not save; retries are idempotent; paid invoices require payment reversal before amendment/cancellation. Corrections cancel and replace atomically with audited history, while ordinary ledgers exclude cancelled pairs.
- Account 2130 remains the same monetary control, named Transport, Freight & Clearing Payables. Service ledger groups separate Customs & Clearing, Transport / Cartage and Freight & Logistics using posted bill categories and unique provider roles. Mixed-service payments and reversals split the existing control line by saved allocations for reporting only. Never create duplicate journals or hide unidentified amounts; retain Other / Unclassified Services and flag conflicting service/party roles for original-bill review.


## Accounting review, result actions and ledger return — 14 October 2026

- Bank entries, expense/utility/card postings, purchases, rent/salary, donations, supplier payments and forward settlement show a green accounting review before their final commit. Reviews run the same authorised calculation in copied Accounts state and exit before persistence, request keys or follow-up effects. Cancelling retains the draft. Final Post revalidates the current books; previews never reserve money or invoice capacity. Keep each company's book currency explicit.
- Saved posting results offer separate Edit and Print actions. Reverse remains an explicitly named, separate action with a recorded reason. Planned and on-account payment edits reverse and replace atomically through their original allocation engine; preserve the public Post ID, entered dates, invoice balances and audit history. Generic journal edits must not detach payment allocations.
- Return from posting detail, amendment and deletion retains the originating ledger/register filters, date range and scroll position. Cancel superseded detail responses; do not reopen an old party screen after navigation. Workflow-owned confirmations stay in their owning screen.
- Every bank/cash record links to its ledger. Bank & Cash includes Bank Ledgers by Party. A party filter displays each original bank movement once, with whole-bank chronological running balances; a transaction involving several parties is not multiplied or split into invented bank postings.
- Company GRNI/provisional purchase clearing (2210) never inherits a broker from a commodity bill header. Creditor 2110 follows the bill's commodity payee; brokerage 2120 follows its broker. Explicit line identities take precedence. Retain all clearing movements in the general ledger and trial balance.
- Super Admin's shared approval queue includes Pending Approval JVs and their accounting lines. Approve/Reject call the existing journal-voucher endpoint with the original company/draft identity and retain its authorisation, balance checks and actual approver. No automatic approval.


## Accounts form consistency and mill cash — 14 October 2026

- Credit Advice is the visual reference for entry sequence, relevant fields, spacing and clean sections. Ticks answer independent yes/no questions; dot choices switch mutually exclusive modes. Print is green, Delete/Reverse red, Edit amber. Popup Close is a top-right cross; Escape returns one level. Preserve the originating screen and scroll after posting or closing.
- Export bag invoice dates are visible before PO selection. Export, historical export (before 1 October) and genuine local purchases are switchable in the same form. Supplier choices retain the Bag Supplier role. Historical pre-July recognition follows the existing opening-allocation checks.
- Utilities and Credit Cards share Pay Expense with dot modes. A reminder-setup tick opens only its relevant master fields. Saved utilities populate provider, type, location, consumer reference and notes. Card selection fills saved due-date/bill details. Opening/closing master setup preserves payment drafts; reminders never themselves post expenses.
- Ordinary notices remain up to 30 seconds and dismiss on outside click; failed mutations and required posting fields remain until dismissed or their form closes. Cancelling an accounting preview is not a posting failure.
- A bank/company cash transfer to Mill Petty Cash debits the selected owned mill cash subledger and credits its source exactly once. Mill shows read-only entries derived from the same Accounts journals/Post IDs; no duplicate operational receipt is stored. Reversals/amendments update that view; old manual operational cash history remains separate. Mill cash views require Mill/Petty View and location scope. Data loads only on explicit opening, with no timed refresh or autosave.


## JV identity, decisions and amendment fields — 15 October 2026

- Future posted JVs use the same atomic yearly Post ID allocator as every other module. JVD IDs identify drafts only and must not lead the posted-voucher display. Historical JV numbers retain their numeric part with a `-JV` display suffix; keep persisted journal IDs, dates, amounts, correction links and external references intact. Do not repost historical monetary entries to populate the register.
- A Super Admin with JV and company approval authority posts their balanced submission directly through the existing JV posting validation. Preparers still submit to the existing shared approval queue; this does not grant other roles self-approval. Retried submissions reuse their request key and never create a second posting.
- Read-only JV decision notifications may check for newly committed decisions while Accounts is visible. Deliver them only to the authorized preparer/submitter. Green shows the posted Post ID; red shows the rejection reason prominently in bold. This narrow notification check must never save, refresh, replace or clear an active transaction form.
- Shared amendment forms show outgoing payment details only for a bank credit and the selected payment method. A bank debit must not acquire a guessed Cheque method. Hide inapplicable controls without erasing saved historical metadata. Ledger table sizing must not stretch the amendment account dropdowns; align row fields at the top and open the editor at its heading.


## Small PKR settlement differences and numeric entry — 15 October 2026

- When a positive payment against explicitly selected Pakistan bills or creditor openings leaves a discount strictly below PKR 100, apply that settlement discount automatically and show it before posting. This applies to historical and current bills and selected supplier payment plans. Exactly PKR 100 and larger discounts retain the explicit agreement control; larger unpaid balances remain partial unless deliberately discounted. Do not apply this rule to unallocated advances, excess payments or TG foreign-currency payments. Calculate from current authorised balances on the server; retain stale-balance checks, replay safety, original bills, discount account 4400 and actual cash/bank amount. Withholding recalculates through the existing settlement engine.
- All numeric fields use typed entry without native increment/decrement arrows. Incidental right-edge clicks, wheel movement or Arrow Up/Down must not step amounts. Preserve decimal precision, min/max/step validation, ordinary typing, grouping and form/API values.


## Accounts Home and bag period reporting — 15 October 2026

- Accounts Home has concise icons, Home-only summary cards, Recent Transactions and a collapsed Pending Work count, hidden when empty. Remove the repeated Accounts/company introduction and routine icon commentary. Keep search available inside each area and workspace. The company switcher remains in the header. Recent rows reuse the authorised Post ID Register projection and open its saved posting with separate Edit and Print actions; read-only users keep their existing restrictions.
- Show default bank first, petty cash next in balance views, then remaining banks in descending balance order. Payment selectors put the eligible default first, then descending balances. Zero-balance choices remain available through search / Show zero-balance accounts. Retain overdrafts and never replace an existing saved selection while sorting.
- New bag GST defaults to 18% when no date-effective rate exists. Explicitly saved GST master changes continue from their effective date, including on other browsers. Existing posted invoices and historical invoice amendments retain their saved actual tax. A historical invoice override is not an automatic change of the current statutory default.
- Bag Orders & Receipts reports distinguish orders issued in a period, dated receipts in that period, receipts through the period end and current received/outstanding quantities. Support order-cohort and activity bases plus company, dates/month, brand, supplier, bag type, packing size and PO/shipment filters; CSV and print use the same filters. Read actual dated Mill receipts. Never assign an undated prior snapshot to a guessed month; show undated amounts separately and retain source records without financial writes.

- Consumed General / Local bag bills debit Packaging Consumed (5300), not Other Export Supplies & Costs (5550). Export consumed bills retain 5550, stock purchases retain 1340, and creditor/GST controls remain unchanged. Preserve existing journals; any historical correction uses its authorised source workflow.

- Bag order period reports recover the original issue date from saved Exports PO versions when amendments reset issuedAt. State that ordered quantities include amendments; dated receipt totals remain separate from current balances. A report read never updates Accounts or operational snapshots.

- Report month boundaries use Asia/Karachi for UTC PO issue timestamps. Date-only original invoice/receipt dates stay as entered. In particular 30 September 20:00 UTC is 1 October in Karachi.

- Financial reports recognise both Revenue and Income chart classes in period profit and cumulative earnings. Foreign Exchange Gain/Loss (7100) and Minor Rounding Differences (7195) flow through signed profit/loss. Other or unknown nonzero account balances must be reported as unclassified rather than silently hidden. This reporting rule never changes original journals or creates a balancing transaction.


## Bag acceptance and cumulative PO billing — 16 October 2026

- A bag PO can have several invoices. Count existing pending and posted invoice quantities across both bag-bill routes under the Accounts store lock; exclude cancelled invoices and the invoice currently being assessed. Check the packing line’s ordered quantity and actual receipts each time a bill posts. An excess is held with a prominent Director approval requirement; only a recorded, current Director or Super Admin decision can release that exact bill. Never infer a purchase rate from a PO or require PO-rate approval.
- A PO with up to 20 bags outstanding across the whole PO closes administratively once receipts have begun. Do not apply 20 bags separately to each packing line. Keep actual ordered, received and invoiced quantities unchanged. Keep PO open is an explicit user choice and can be saved without a further receipt. A later genuine invoice remains possible; closing a receipt does not post a liability or waive quantity/specification checks.
- Committed Mill receipt evidence controls acceptance. Printing, material, quality and unresolved tare discrepancies hold the invoice. Accounts records its supplier contact and settlement; a corrected invoice at the actual lower received weight may post after validation. An unchanged discrepancy requires a Director or Super Admin decision through the same endpoint, with actual actor, reason and evidence recorded. Changed evidence invalidates an earlier unposted decision. Preserve posted bills and original journals.


## Actual production, recovery and costing — 16 October 2026

- Record actual raw lots independently from finished rice and by-products. Recovery is output / actual input; never copy output into input or force 100%. Show excess output with its recorded explanation.
- New production with positive output requires actual raw input; editing a historical run requires explicit entry of missing facts. Preserve legacy stock movements until such an amendment. Never fill historical input from output.
- Reports offer daily production, daily recovery, brand and contract views, exact filters, source run/lot references, matching print and CSV. Missing raw input or brand/contract allocation leaves the affected recovery/cost unavailable.
- Keep TTI and BRM production, purchase pools, processing charges and sale-based valuation sources separate. Stock consumes actual input on actual-input entries.
- Final production cost uses weighted finalized purchase costs plus recorded conversion costs less by-product values. Management estimates retain explicit source/fallback labels. No report read saves rates, prepares sheets or posts journals.
- Preserve approved cost snapshots. Approval requires the reviewed quantity/cost fingerprint; changed source facts require reopening the review. An approved historical cost without actual-input evidence remains labelled unverified.
- Shared by-products require actual brand/contract allocation for those cost reports; never invent proportional brand recovery. Use a lot default with optional row allocations; typing and changing filters never save production or Accounts data.


## Historical financial B/F and separate physical stock — 19 October 2026

- Historical customer, supplier, shipment and bag balances must reach their respective financial accounts, party ledgers and settlement workflows. Those shipments were completed and documents sent before the software; invoice quantities are reference details, never proof of physical rice or bag stock.
- Historical bag-bill entry is financial only. It must not offer stock treatment, create account 1340 stock, consume physical stock or infer opening quantities. Reject attempts through the API as well as the form. Keep original quantities, original GST, recoverable GST and unpaid supplier amounts.
- Preserve posted historical data and separately sourced actual stock movements. Do not silently rewrite a legacy invoice carrying stock as a financial-only amendment; reconcile its original allocation explicitly first.
- Salman will provide Mill rice and bag opening quantities separately. No financial B/F entry, customer invoice, completed shipment or prior bag bill supplies those quantities by inference.
- Completed or closed contracts retain their specifications as historical references and do not create a new production or outstanding-sale requirement. Never fill missing historical production or loading with fabricated physical movements.

## Annual Zakat control — confirmed scope

- The annual form is within the agreed scope. Group TTI, BRM and TG wealth workings determine the annual amount; the liability and payment expense belong to TTI.
- The TTI assessment control shows signed prior carry-forward, the current annual assessment debit, actual payment credits/refunds and the remaining payable or excess. Complete all reviewed annual sections to record the yearly control debit. A partial assessment cannot carry forward as a final obligation.
- Actual payments alone count as expense; the annual calculation must not charge it again. Retain payment Post IDs and original dates and show unresolved payment allocations explicitly.
- The control calculation is separate from monetary journals. Preserve the existing opening Dr3400 / Cr7210 and payment Dr7210 / Crbank originals. A change to canonical annual debit/credit mapping requires an evidenced balanced accounting review; never invent the counterpart or silently reclassify originals.

## Paid Zakat reports and Mill reference reconciliation

- Profit & Loss and expense activity use verified Zakat payments less refunds. Financial opening credits and unpaid assessments are not negative expenses. Ambiguous payroll, mixed settlements, invalid payment dates or unbalanced sources show a review hold; never turn an unavailable total into zero. Trial Balance and General Ledger preserve the original journals. The posted-books Balance Sheet must disclose an unresolved annual/control classification; no balancing equity or guessed annual counterpart is permitted.
- Commodity quantity reports read original purchase commitments, native receipts/loading and local/export sales. Show prior-period, selected-period and cumulative movement separately. Preserve closed history with no new requirement, original brand/product identity, source IDs and unknown-source holds. Financial B/F never creates physical rice or bag quantity.
- Mill rent/payment and salary-advance references may be linked explicitly to an existing, balanced canonical Accounts payment after exact company, person/mill, date, full amount and rent-month review. A link is non-monetary: do not create another payment, expense, accrual, stock movement or salary deduction. Retain link history and actual reviewer; reject changed source/payment/link snapshots and duplicate use of an original payment. Changed/reversed sources require reconciliation. Current advance remaining and monthly adjustments always come from canonical Accounts. Checking links must preserve unfinished Mill forms.


## 19 October JV home decisions and authorised Directors

- JV approval/rejection notices belong on the recipient's main/home screen. Keep each pending decision until that user explicitly closes it with X; do not expire it after a timeout. Store recipient-specific acknowledgement on the server so closing/reopening a browser does not silently lose a decision. A failed dismissal keeps the notice available.
- Notification-only reads may check for newly decided JVs while the home screen is visible. They must not refresh financial/stock sources into an active editor, clear entry, save drafts, or extend inactivity sign-in. Leaving home hides the notice without acknowledging it; returning shows it again.
- Director role supplies full business actions and company scope automatically, including posting own JVs and completing an existing own pending JV. Ordinary preparers retain separate approval. Director does not become Super Admin; owner-security controls and business validation remain enforced.
- Bank Receipt labels identify the receiving bank as Received into bank and the payer as Received from. Payment labels retain Pay from bank. Mode changes retain unfinished values and make no posting.
- Accounts Mill Bill Review distinguishes Rice labour from Maize / Corn / Makai labour using the saved source commodity. Both retain the same source-linked payable review and correction safeguards, with separate agreed work/rates.

- Users assigned several modules have a Home link from Accounts, Milling, Exports and Directors to a server-rendered chooser containing only their current authorised workspaces. A Director receives operational navigation without access to owner-only user/security administration. Home also uses the same private JV decision notices.

## Accounts permission action completeness — 19 October

- The owner editor represents the real Accounts View/Create/Edit/Approve/Reports/Delete actions, including company-scope rows. Existing grants remain unchanged when the editor is opened or an unrelated user field is saved. For ordinary staff, Delete is never inferred from Edit, Approve or Select all being displayed; an explicit saved choice is required. Director uses the automatic full business-access preset below.
- Removing View clears every dependent action in that row. Action checkboxes align beneath their corresponding headings on desktop and within a horizontally scrollable matrix on mobile. Master permission columns retain their own seven actions.


## Director role preset — owner clarification, 19 October 2026

- Choosing Director automatically provides all four business workspaces, TTI/BRM/TG, all locations, business View/Create/Edit/Approve/Reports/Delete actions and all operational Master Records. Existing Directors receive the same effective access without a checkbox amendment or data migration.
- Hide module, company, action, master and assigned-location choices for Director. Show a concise full-business-access summary and the owner-only boundary. Keep name, username, role and active status editable only by Super Admin.
- Derive access from the current server-authenticated role. Never set the Super Admin flag or grant owner Console, user/password administration, security/AI-provider configuration, backups/restores, module locks or permanent source/master purges. Retain controlled bank-deletion approval and opening-period lock rules.
- Preserve original custom staff scopes separately from derived role access. Changing away from Director restores the custom form choices, and the next authenticated request uses the saved lower role; do not leave synthetic all-grants behind. Ordinary staff and managed read-only QA restrictions remain unchanged.
- Full business access still requires valid source ownership, balanced accounting, CSRF, explicit reasons/decisions, retry protection and immutable history. It never auto-approves a pending bill, posts a payment or invents stock.
