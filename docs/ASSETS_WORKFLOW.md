# Assets and property instalments

Accounts and Directors share one managed asset record in the locked Accounts store. Accounts requires the Assets icon permission plus the selected company rights. Directors requires the Private Assets / Properties permission. Super Admin has access to both. Unrelated Directors permissions do not grant access.

Accounts enters properties, vehicles, motorcycles and other fixed assets through the main Assets window. Country and city lists support add/delete; deletion deactivates choices and never changes saved historical snapshots. Ownership country is independent of paying-company books. Legal owner is explicitly Company or Personal.

The fixed historical cutoff is **1 July 2026**. Dates before it save instalment number, original date, amount and reference without any journal. From that date, payments either post new cash/bank movements or link an existing exact-date/amount payment journal; they cannot silently bypass the ledger. Request keys prevent duplicate registration and payment submissions, and version checks prevent stale asset changes.

Personal asset registration never creates a company asset. Company-funded personal payments debit Family / Personal Allocations. A new company purchase from the cutoff posts asset cost and payable once; instalments debit the payable and credit the selected bank/petty cash. An existing company asset requires confirmation that its asset and remaining liability already exist in the opening books. Opening balances are not silently created. Financial payments use company book currency: PKR for TTI/BRM, AED for TG. Historical register currency can be PKR/AED/USD.

Cash and Bank can both be selected. Their amounts must equal the total. Bank defaults use saved company payment/receipt defaults, with cheque selected initially. Cheque number is mandatory; online reference is optional. Bank balance does not block posting.

Fully paid assets are omitted from Accounts API responses and direct record requests. The only registration receipt retained by Accounts is generic message, Post ID, date and entered-by user. Original payment vouchers remain available for reconciliation; searchable journal descriptions do not include property name, owner, address or private notes. Directors may reopen a completed record for an identifying-detail amendment with a reason. Cost/payment corrections continue to require controlled accounting review rather than overwriting posted amounts.

Monthly schedules cover only the balance after registered payment history. They preserve month-end due days and cap the final instalment. No payment is posted automatically. Purpose (personal use, business use, rental, intended resale, other) is retained for later Director Zakat working; this feature does not calculate Zakat.
