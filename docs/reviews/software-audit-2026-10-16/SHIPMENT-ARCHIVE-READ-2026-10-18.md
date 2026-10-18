# Sales Tax access to protected shipment packs

Sales Tax previously listed only uploaded Export files. Native documents prepared
by the existing committed-shipment workflow were stored inside Office Agent job
packs but inaccessible from Accounts: the job file route requires the Office
Agent credential. Do not expose or reuse that credential in the browser.

This change lists dated, immutable Master Shipment Documents.pdf archive copies
beside uploaded originals. It retains separate archive job revisions and labels
each as an archived pack with its preparation timestamp in Pakistan time. A pack
contains the native documents available when it was prepared; it is not certified
to match the current invoice. No missing CI, Packing List or other original is
invented, rendered again or extracted from an old merged PDF. Standalone native
CI/PL indexing and original-document reconciliation remain open.

The new GET-only download route reuses the existing Export document target
policy: Accounts company View or Exports Print View, resolved from the stored
contract/lot. Its global entity-policy classification follows export_documents:
the caller cannot choose a company to bypass source-based authority. Only the
named root shipment master pack is available; arbitrary Office Agent files,
credentials, paths and bank packs are not exposed. The reader verifies the saved
size and SHA-256 against the same open file handle before sending bytes and
rejects symlink/traversal paths. An agent delivery failure does not make its
protected hosted bytes unavailable. Missing or altered bytes produce explicit
errors, and corrupt metadata does not look like an empty successful report.

No generation, posting, office delivery, completion, folder structure, original
upload or job acknowledgement is changed. Screen and print keep the archived
label; existing selected-download deduplication uses stable archive identities.
Reports and downloads never change financial, operational or archive sources.

Local checks passed: real authenticated archive/download and report fixtures,
Sales Tax source/endpoint/output selection, PHP syntax and existing authentication
boundaries. The new regression is included in full Accounts QA. Positive cases
use disposable sources, with exact financial/operational/archive byte comparison.
The wider audit and five live shipment source holds remain incomplete.

Chrome connection is now available and verified as Chrome. The Transtrade tab
currently requires sign-in, so live original-source inspection waits for that
session. No production credential or alternative browser session was used.
