# Hosting credential protection — owner step required

Prepared for Security Cloud finding 3. Do not mark the finding closed yet.

The existing `live-qa` environment is now restricted in GitHub settings to the exact `main` branch (no tags). This patch assigns every hosting-credential job to that environment. Existing live-qa jobs retain their environment. Main-branch conditions are retained. No credentials, hosting files, or business entries are changed by this patch itself.

## Complete the transition

1. The owner enters the existing authorized hosting private SSH key as an environment secret named `HOSTINGER_SSH_KEY` in Settings → Environments → live-qa. GitHub does not reveal the existing repository secret. If no authorized copy exists, use an owner-managed SSH key replacement on the hosting account, keeping the current key working until the replacement has been tested. Do not put private keys in chat, source code, logs, or artifacts.
2. Merge this prepared environment-binding patch after its validation succeeds. Existing credential references keep their names. This changes which credential scope GitHub selects.
3. Verify the normal production deployment succeeds on main with the environment key. Also verify GitHub refuses a branch outside main access to live-qa, using a harmless job that does not print or export credentials.
4. After successful verification, the owner removes the repository-level `HOSTINGER_SSH_KEY` copy. Keep the environment copy. Existing repository-wide QA password copies should likewise be removed after checking their protected environment equivalents; they already exist in live-qa.
5. Recheck that no production private key is stored at repository scope, all hosting jobs use the protected environment, main deployment works, and non-main access is denied. Only then close finding 3.

The environment restriction alone does not protect a repository-scoped key. Workflow conditions alone are also insufficient because a repository writer can change a branch workflow. Removing the broadly available key is the necessary final boundary.

## Validation performed

Parsed every workflow as YAML and confirmed that every job referencing a `HOSTINGER_*` secret selects `live-qa`. Existing job actions, main guards, deployment target checks, and credential names are preserved. GitHub's environment screen confirmed one allowed branch (`main`) and zero tags. A live credential migration test remains blocked until the owner enters the environment key.
