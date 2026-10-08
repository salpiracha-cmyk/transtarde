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
- LOT COMPLETE queues the committed file package and closes the lot only after the hosted server acknowledges the completed lot state. The Office Agent five-minute interval is independent of completion. A lost acknowledgement requires reload and review; never automatically overwrite the server with an old root. Completed VIEW is read/reprint only. Only Super Admin may REOPEN LOT with a mandatory recorded reason and acknowledged save.
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

- The Expenses area has Pay Expense, Expense Recipients, and Salaries & Staff. Pay Expense opens Other Expense, Utilities, and Credit Card using radio selections on one screen. Pay Expense accepts any recipient, including household expenses without an individual director assignment.
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
- Ordinary expense types offer Add/Edit in place. Creating a type requires Milling/Production, Home or Office and an appropriate head/subaccount. Milling/Production types remain in their dedicated posting workflow. Changes preserve existing voucher treatment and stable type IDs.
- Add Vehicle beside the expense vehicle selector saves name/model and registration through the shared register, then selects it while preserving the expense draft. Register Another repeats the same simple registration form; full identity details remain under Assets & Investments / Assets / Review / Amend Details.
- Vehicle Tax & Licence Fees requires a vehicle, period covered and optional challan reference. It is distinct from repairs/fuel and included in vehicle costs. Period metadata alone does not generate monthly prepaid amortisation journals.
- Expense purpose fills payment narration until the user edits it; user edits remain intact.
- Corrections retain the original public Post ID while maintaining immutable original/reversal/replacement accounting journals. Show bracketed correction details below narration. Reporting and balances include the full journal audit trail.
- The Post ID Register has aligned columns and affected party names. Stockbroker transactions show Date separately from Post ID.
- Post is the final posting action. After successful posting, show a compact centred confirmation with Post ID, debit/credit lines, Print and Close. Print opens the browser printer dialog in the same tab using an isolated print frame; never open a new printing tab or window, or print automatically on Post.

## 8 October 2026 beneficiary and credit-card agreement

- Paid to is the actual recipient. Expense for is a separate row dimension: Salman, Talha, Tayyab, ARP or Shared–Common. Expense area is Office, Home or Milling–Production; production posting retains its dedicated workflow. A CAS school fee can appear in Talha’s Home report without changing CAS as recipient. Reporting dimensions never add monetary debits.
- Credit cards use saved masters, one bill total, statement date, due date and reminders. No mandatory purchase-by-purchase allocation. Retain existing historical item allocations until explicitly amended. Enter Bill and Pay Bill share Pay Expense; payment clears the payable and never expenses the bill twice.
- Optional personal-amount ticks select each director once, with amount and remuneration deduction or cash recovery. The full card bill and full bank payment remain intact. Separate internal recovery entries reclassify personal amounts; financial reports must not treat recovered personal spending as business expense.
- Remuneration deductions use accrued payable first and carry remaining amounts into the existing salary advance/deduction workflow. Never invent an entitlement or double-deduct. Corrections restore earlier salary deductions and replace the allocation, preserving journal history and the original public Post ID. Cash receipts settle the personal receivable without touching the card. A collected cash allocation cannot be removed by silently refunding money.
- New personal adjustments are explicit Posts with retry keys, entity and icon permissions, and audit history. They may be entered with the bill, at payment or afterwards. No background saving or production test postings.


## 8 October simplified Home and expense type selection

- Pay Expense removes the visible Expense Area and Expense For controls. Classification follows the selected expense type. Home Expense shows one Home ledger selector: Salman, Talha, Tayyab or Combined Home. ARP Expense is a separate type with subsidiary selection, outside that Home list.
- Add / Edit Expense Types manages the entire list, including standard types. Standard names are editable through stable subsidiary IDs; their accounting heads and specialised vehicle, medical, rent and donation validation remain intact. Custom types retain classification and subsidiary hierarchy choices.
- MRS SRP monthly Home allocations report under Salman Home; MRS TRP under Talha Home; MRS TAYYAB under Tayyab Home. Consolidate using reporting dimensions without changing the original recipient, statutory posting head, amount or journal history. Household ledger activity does not create duplicate monetary postings.
