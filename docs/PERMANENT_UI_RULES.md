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
