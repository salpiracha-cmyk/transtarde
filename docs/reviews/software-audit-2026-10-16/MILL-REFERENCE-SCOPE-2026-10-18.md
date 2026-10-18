# Rent and salary reference display scope — 18 October 2026

The actual Milling render functions summed rent references and same-person,
same-month salary advances across all companies and mills. A disposable JSDOM
fixture reproduced a TTI rent estimate of Rs350 instead of Rs850: Rs500 from
another TTI mill and BRM had incorrectly reduced the current estimate. The
same source functions independently showed cross-company salary advances.

Both reference projections now use the existing operational stock scope helper.
The salary month filter remains. Same-company, other-mill and other-company
references are excluded from the displayed estimates and rent history. Existing
legacy unscoped rows retain the established default TTI Rice Mills policy;
this change does not assign or rewrite their missing source identities.

Actual render/save functions are covered in a mixed-company/mill/month fixture.
The new test fails against the preceding code and passes after the repair.
Opening/filtering performs no write. Explicit reference saves retain their
existing company/mill identity and preserve all other reference history. The
production/recovery report entry-scope test also passes. CI runs the new fixture
in the existing browser job after its JSDOM installation.

This repairs operational estimates only. Legacy salary/cost masters remain
separate from canonical Accounts salary/rent workflows. Reported references
remain unverified; they establish neither an Accounts liability nor payment.
No journal, payment, master, role, backend permission or stored reference is
changed by this display repair. Canonical handoff and historical reconciliation
remain open R08 acceptance gates.
