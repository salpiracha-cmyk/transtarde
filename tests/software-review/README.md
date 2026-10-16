# Whole-software review probes

The 16 October review covers the current production source at
`1beeac3f880fda95ab2dd722e653bdab0ee067ab`. These diagnostic probes use disposable
DOM fixtures and do not contact production or change real accounts/users.

```bash
NODE_PATH=/path/to/jsdom/node_modules node scripts/probe_console_review.cjs /tmp/console-review.json
NODE_PATH=/path/to/jsdom/node_modules node scripts/probe_milling_review.cjs /tmp/milling-review.json
NODE_PATH=/path/to/dependencies/node_modules python3 scripts/run_software_review_checks.py --output /tmp/software-review-checks
```

Use the dependency versions and fixture preparation in the two existing QA
workflows. Accounts DOM tests use jsdom 26, Exports DOM tests use jsdom 27.
Playwright checks require a working Chromium runtime; MySQL/browser variants
require their separately configured workflow environment. The runner executes
commands independently and retains failures. It does not provision these services
or run destructive live QA workflows.

The diagnostic probes retain observed defects rather than asserting that the
application is already correct. Read the findings and limitations in
`docs/reviews/software-audit-2026-10-16/REVIEW.md` before interpreting their output.
