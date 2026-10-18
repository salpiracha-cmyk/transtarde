# Protected module script delivery — 18 October 2026

Production startup evidence for main2028774 shows zero decoded script bytes for
the Maize labour and lifting review URLs. Both request files inside milling/,
whose .htaccess deliberately says Require all denied. The Export office-folder
hook uses a relative URL that resolves at the root while its file is in exports/.
The previous20-test read-only smoke passed without checking these runtimes;
that pass does not prove these controls were delivered. Earlier native fixtures
loaded source files directly and therefore missed the delivery problem.

The authenticated module renderer now embeds these three existing scripts at
their original execution positions, using the established Rice Ledger delivery
pattern. Direct source folders remain denied. Missing required files return503;
unauthorized users receive403 before source assembly. Script-closing text is
escaped and callbacks/literal concatenation preserve JavaScript replacement
characters. No labour rates, source data, permissions or financial rules change.

The real module renderer fixture fails on the preceding code, then passes after
the repair. It verifies inline delivery, no protected/unresolved script URLs,
permission denial, missing-file failure and parseable assembled Milling scripts.
The existing native Maize and Corn review/form tests also pass. Live smoke now
checks installed Export office hooks and the lifting review function, opens the
Maize form, verifies all three routes and an initially blank rate, and performs
no Save or financial action. Actual office-agent delivery and positive financial
cases remain separate acceptance boundaries.
