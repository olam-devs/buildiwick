# Buildiwick

A real system replacing the spreadsheet a Dar es Salaam construction company has been running their entire business on.

> **New here?** AI agents must read `CLAUDE.md` before changing the project. It contains the current architecture decisions, security boundaries, and branch/deployment rules.

## What this is

Buildiwick runs 16 active construction projects out of one shared Excel workbook — materials, labour, logistics, and preliminaries costs all typed into per-project tabs by hand. It works, but it's a single point of failure: anyone with the file sees every project's numbers (including figures site staff shouldn't see), one typo can break a shared formula, two people can't safely edit it at once, and they operate every other day without getting round to updating it.

This repo is the real system replacing it. The mandate from the client is explicit: **digitize what they already have, don't redesign it.** Every table, category, and status field in this codebase traces back to their actual interim tracker (`Buildiwick_All_Projects_Interim_Tracker_4.xlsx`) and their process lifecycle document — nothing invented.

## Live sandbox and branches

The shared sandbox is **https://buildiwick.olamtec.co.tz**. It is for development and review; it is not the eventual production domain.

- **`develop` is the integration branch** and is the branch checked out by the sandbox deployment. New work should use a feature branch and return to `develop` through review.
- **`main` is the stable production-candidate branch.** Do not merge into it as part of ordinary sandbox development. Promoting `develop` to `main` is a deliberate release decision.
- Sandbox deployment is manual. A push to `develop` does not update the server until an authorized operator pulls it and runs the required dependency, migration, and cache commands documented in `CLAUDE.md`.

## Current status: Phases 1–11

Full phase breakdown (MVP → Phase 2 → Future modules) is in the **Build Phases** doc — see [Design & planning references](#design--planning-references) below. Short version:

**Built and working:**
- Schema matching the real 23-column tracker, plus every real dropdown list (categories, subcategories, units, statuses, construction stages) as one managed `lookups` table
- Auth (Sanctum API tokens + session-based web login) and role policies for Admin, Director, Accounts, Estimator/QS, Project Manager, and Site Operator
- A real entry form (web UI, not just an API) for Materials / Labour & Subcontracts / Logistics & Equipment / Preliminaries & Overheads, including the Labour "new agreement" vs "record payment" split
- All Projects dashboard (company-wide rollup) and per-project drill-down, computed live from the database — not a maintained spreadsheet
- Kinondoni Residence's 33 historical rows imported and verified **byte-for-byte** against the client's real Consolidated tab totals

**Implemented through Phase 11:**
- Project setup, Estimator/QS estimates, Site Operator need reports, PM internal budgets and approvals, Accountant payments/invoicing/reporting, Director read-only executive analytics, and security/authorization/audit/financial-integrity hardening.
- Verified regression suite: 126 tests / 1,190 assertions, all passing in testing with in-memory SQLite.

**Not built yet:**
- The full multi-stage procurement workflow and sales/CRM pipeline (Future — separate modules, not scheduled)

## Getting started

Prerequisites: PHP 8.4+, Composer. ([Herd](https://herd.laravel.com) recommended on Windows/Mac — zero-config PHP + Composer.)

```bash
cd app
composer install
cp .env.example .env
php artisan key:generate
php artisan migrate:fresh --seed
php artisan app:import-kinondoni-historical-data
php artisan serve
```

Visit `http://127.0.0.1:8000/login`. Local/demo accounts use password `password` for all; these seeded credentials are development-only and production deployments must replace them before use. The sign-in page displays demo hints only in local/testing environments:

| Email | Role | Scope |
|---|---|---|
| director@buildiwick.test | Director | Read-only organization and project financial analytics |
| grace@buildiwick.test | Accounts | Everything (not project-scoped) |
| twalib@buildiwick.test | Estimator/QS | Assigned projects, including the complete demo project; no cash |
| neema@buildiwick.test | Project Manager | Kinondoni, KICD, and the complete demo project |
| amani@buildiwick.test | Site Operator | Same three projects; site requirements only |

The seeded project **Bahari View Family Villa — DEMO** is wholly fictional training data. It deliberately contains mixed workflow states across all roles: an issued estimate, finalized internal budget, pending and approved requests, full and partial supplier payments, an overdue part-paid client invoice, a current invoice, and a draft invoice that Accounts can continue testing.

## Design & planning references

These were built as Claude artifacts during planning. They're private by default — if a link doesn't open for you, ask whoever owns it to share it from the artifact's Share menu.

- **Build Phases** — the actual source of truth for scope (MVP vs Phase 2 vs Future): https://claude.ai/artifact/Aj3baTbHdXgCmHQVxhZyKD
- Schema Blueprint (ERD + table reference): https://claude.ai/artifact/1WkwmF611e6D5w9bYtbu75
- Build Plan (migrations/policy patterns, milestone sequence): https://claude.ai/artifact/1VVZvGup3vwv3CJ7rSrBBc
- Entry Form sketch (the original mockup this UI is built from): https://claude.ai/artifact/UpdAx2xuCRodfzcmv3dRob
- Review Desk sketch (PM/Accounts views, pre-real-backend): https://claude.ai/artifact/BXneSXA5LMJxBdzuhGK2tX
- `Developer Brief.pdf` in this repo — the client's original written brief

## Decisions worth knowing before you touch the schema

- **Labour instalments are not a separate table.** They're rows in `transactions` with `agreement_id` set. Only the agreement's own terms (fundi, agreed value, dates, status) live in `labour_agreements`.
- **A labour instalment IS the payment event.** `amount_paid_received` is set to the full amount and `payment_status` to `paid` at creation — Finance only logs an instalment once the money has actually moved. Materials/Logistics/Preliminaries are the opposite: a cost incurred now, paid later via Accounts' separate record-payment action.
- **`payment_status` and `data_status` are different questions.** One asks "has money moved," the other "can this row be trusted." A row can be `paid` and still `needs_confirmation`.
- **Site Operator has no read route at all**, not just a denied one. The entry-form endpoint echoes back only the fields that role just submitted — never the saved row, never anyone else's data.
- **Every lookup list is one table** (`type` + `code` + `label` + `parent_code`), not nine separate tables and not hardcoded enums — matches how the client's own "Lists & Settings" tab works, and means Admin can edit a list later without a code change.

## Contribution protocol

This is a two-developer team (plus AI agents on both sides), so nobody should have to reverse-engineer a diff to know what changed. Before you push:

1. Work on a feature branch and target `develop` unless the owners explicitly approve a production promotion.
2. Write a specific commit message — what changed and why, not "update files."
3. Add a dated entry to `CHANGELOG.md` in plain language: what you built or fixed, and what's now possible that wasn't before.
4. If you're an AI agent, read `CLAUDE.md` first — it has the fuller technical context and the same protocol in more detail.
