# Login HTTP 500 investigation — 19 October 2026

Owner screenshot: `/login.php` returned HTTP 500 at approximately 20:49 PKT.
Fresh unauthenticated production GET and two requests reusing their anonymous
cookie subsequently returned HTTP 200 with the sign-in form. These requests do
not verify the owner's existing cookie, password submission, or incident cause.
The read-only host diagnostic was rerun as run 39258849858, attempt 2; its result
must be checked before attributing the incident to storage or a damaged session.

## Reproduced source defect and bounded repair

A malformed native JSON session record throws during `session_start()` and makes
sign-in return HTTP 500. Classify invalid JSON/non-array payloads separately from
I/O failures, discard only that client's in-memory authentication, and start a
fresh anonymous session. Preserve the original damaged bytes for diagnosis;
retain valid users, private books, cookie policy and CSRF protection. Private
storage and damaged authentication-store errors remain errors.

`tests/security/corrupt_session_recovery_test.py` fails against unmodified main
36ede86 with HTTP 500, and passes with the repair: six damaged payload variants,
new cookie, protected requests denied, stable replacement CSRF, another logged-in
user unaffected, original session/auth/accounts/operations bytes unchanged.
A deliberately damaged auth store remains HTTP 500 and is not replaced.
Authentication/session hardening and existing atomic session tests also pass.

This local repair is not evidence that the screenshot's root cause is confirmed,
or that the repair is deployed. Feature CI, main CI, deployment source identity,
and live acceptance remain required. Existing host capacity/backup retention
limits are still tracked separately; do not prune historical backups to make
room without the retained-baseline decision.
