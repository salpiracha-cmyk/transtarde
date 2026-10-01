# Standing application rules

- Every workflow requiring Director approval must also offer the same decision to Super Admin in the Super Admin Console's approval panel.
- Use the same underlying request and approval endpoint for both routes. Record the actual approver and reflect completion in both places; never create duplicate approvals or bypass business validation.
- Preserve all existing staff roles, module permissions and entity restrictions. This rule grants an alternative decision route to Super Admin only.
- Follow the permanent product rules in `docs/PERMANENT_UI_RULES.md`.
