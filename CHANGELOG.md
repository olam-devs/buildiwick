# Changelog

Every session or PR adds an entry here — dated, plain language, written for a teammate (human or AI) who wasn't in the room. See `CLAUDE.md` for the full contribution protocol.

## 2026-09-28

**Laravel app scaffolded and Phase 1 backend built end to end.**

- Laravel 13 installed (`app/`), SQLite for local dev — zero services needed to run it.
- Full schema: `roles`, `users` (+role/phone/active), one shared `lookups` table covering all 9 real dropdown lists (main category, subcategory, unit, payment status, data status, labour status, construction stage, transaction type, project status), `projects`, `project_assignments`, `labour_agreements`, `transactions`.
- Seeded: 5 roles, every real lookup value (transcribed from the client's Lists & Settings tab), all 16 real projects (from the Consolidated tab, with locations left `null` where the client's own sheet still says "Enter Location"), 5 demo users matching the earlier design mockups, with Neema (PM) and Amani (Site Operator) scoped to Kinondoni + KICD.
- One-time import command (`app:import-kinondoni-historical-data`) for Kinondoni Residence's 33 historical rows — verified byte-for-byte against the client's real Consolidated totals (Materials TSh 12,299,731.82, Logistics TSh 295,500, Preliminaries TSh 256,000).
- Sanctum API auth + 4 policies (`Project`, `Transaction`, `LabourAgreement`, `Lookup`) plus a `view-dashboard` Gate, covering all 5 roles. Tested live against a running server: Site Operator is write-only and response-scoped, Project Manager is project-scoped, Estimator/QS is field-scoped (no cash columns in the response), Accounts can record payments, Admin bypasses everything, a deactivated account is locked out entirely.
- Dashboard/reporting: All Projects rollup, per-project drill-down, and an Accounts bills queue — grouped SQL queries, not N+1 per project.
- **Real web UI** (session auth, Blade) replacing the earlier curl-only testing: login page, All Projects list, per-project drill-down, and a live "New Entry" slide-over drawer wired to the real backend for Materials / Logistics / Preliminaries / Labour (both "new agreement" and "record payment" modes).
- Two real bugs found and fixed during live browser testing (not just happy-path checks): Blade's `{{ }}` was HTML-escaping JSON before it hit an inline `<script>` tag, corrupting it — switched to `{!! !!}`; and several CSS classes (`.modetabs{display:flex}` etc.) tied in specificity with the browser's native `[hidden]` rule, silently defeating it — added the standard `[hidden]{display:none!important}` override.
- Visual direction settled: Inter font, Frappe's real blue (`#2490EF`), dot-status indicators, slide-over "New Entry" drawer — matches Frappe's actual Desk UI after two earlier directions (a blueprint/drafting theme, a construction-safety theme) were rejected as still reading AI-generated.
- Verified end-to-end in a live browser session: filed a real Materials entry for Kinondoni (Cement, 50 bags → auto-computed TSh 925,000), confirmed the category total and needs-confirmation list updated; created a real labour agreement for a fundi (TSh 1,900,000) and recorded a TSh 205,000 advance against it — running balance showed 205,000 / 1,900,000, matching the brief's own worked example.

**Not yet built:** approve/reject as a real page for Project Managers (policy + logic exist, no Blade page); record-payment as a real page for Accounts (same). Client invoicing and the full procurement/sales workflow are Phase 2 / Future — deliberately out of scope for this phase.
