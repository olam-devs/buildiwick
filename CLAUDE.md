# Buildiwick — Agent Context

Read this before making changes. It's written for whichever AI agent picks this codebase up next — you weren't in the room for the decisions below, so this is the room.

## What Buildiwick is

A Dar es Salaam construction company running 16 active projects out of one shared Excel workbook. The client's mandate is explicit: **digitize what they already have, don't redesign it.** Every table, category, status field, project name, and dollar figure in this codebase's seed data traces back to their real interim tracker (`Buildiwick_All_Projects_Interim_Tracker_4.xlsx`, in their original brief materials) and their process lifecycle document. Don't invent data or restructure the schema on aesthetic grounds — if something looks odd, it's probably a faithful digitization of something odd in their real process, not a bug.

Full scope reasoning lives in the **Build Phases** artifact (https://claude.ai/artifact/Aj3baTbHdXgCmHQVxhZyKD) — read it before assuming a feature is missing by accident. Short version: this is Phase 1 (MVP) — data capture, entry form, and the dashboard rollup. Approval workflows, client invoicing, and the sales/procurement pipeline are Phase 2 / Future, deliberately not built yet.

## Current state of the code

- **Schema**: `app/database/migrations` — `roles`, `users` (+role/phone/active), one shared `lookups` table for every real dropdown list, `projects`, `project_assignments`, `labour_agreements`, `transactions`.
- **Models**: `app/app/Models` — straightforward Eloquent, see `Project::categoryTotals()` and `LabourAgreement::paidToDate()/outstanding()` for the computed-not-stored pattern.
- **Auth & authorization**: Sanctum tokens for `routes/api.php`, session guard for `routes/web.php`. Policies in `app/app/Policies`, one per gated model. `AppServiceProvider::boot()` has a `Gate::before` that blocks deactivated accounts outright and bypasses everything for Admin, plus a `view-dashboard` Gate that isn't tied to any one model.
- **Web UI (the real product)**: `app/app/Http/Controllers/Web` + `app/resources/views`. Session-authenticated, server-rendered Blade. This is what a human actually opens — the API exists for a future PWA/offline client, not as the primary interface.
- **Shared creation logic**: `app/app/Services/TransactionRecorder.php`. Both `Api\TransactionController` and `Web\EntryController` call this — it's the one place a transaction gets created, so the labour-instalment-is-already-paid rule and the offline `client_uuid` idempotency check can't drift between the two entry points. Don't duplicate this logic in a new controller; call the service.
- **Visual identity**: Inter font, Frappe's actual blue (`#2490EF`), status shown as a colored dot + text. This was a deliberate pivot after two earlier visual directions were rejected as "still looks AI-generated" — the brief was to match Frappe's real Desk UI, not an original interpretation of it. Don't reintroduce a different accent color or font without checking with the human first; this was already litigated once.

## Decisions already made — don't relitigate these

- **Labour instalments are not a separate table.** They're `transactions` rows with `agreement_id` set, `main_category = labour_subcontracts`. Only the agreement's own terms live in `labour_agreements`. This was tested against the real Excel (zero labour rows existed anywhere in the source file at the time), so there's no real example to contradict it — but if the client ever shows you a real labour row from their own system, check it still matches this shape before extending it.
- **A labour instalment IS the payment event.** `amount_paid_received = total_amount` and `payment_status = 'paid'` are set at creation time in `TransactionRecorder`. This is intentionally different from Materials/Logistics/Preliminaries, where a cost is incurred now and paid later via Accounts' `recordPayment` action.
- **`payment_status` and `data_status` are separate axes**, both real columns on `transactions`. Don't collapse them — a row can be `paid` and still `needs_confirmation` (this is literally true of all 33 imported Kinondoni rows).
- **One `lookups` table, not nine.** Columns: `type`, `code`, `label`, `parent_type`/`parent_code` (for subcategory→main_category), `sort_order`, `is_active`. Query with `Lookup::ofType('unit')` or filter by `parent_code`. Don't add a new physical table for a new dropdown list — add rows here.
- **Site Operator has no index/show route for transactions at all**, checked in `TransactionPolicy::viewAny()` — not a route that returns 403, an ability that's simply never granted. The entry-form response for this role (`TransactionController::respond()` and the equivalent in `Web\EntryController`) echoes back only the fields just submitted. If you add a new endpoint this role can hit, apply the same rule: never return more than what they just typed.
- **Project codes and transaction codes are human-meaningful strings** (`BWG-KIN-2026-001`, `BWG-KIN-2026-001-TXN-0034`), not just internal IDs — they match the client's own reference convention. Don't replace them with UUIDs-only; the `client_uuid` column is separate and exists purely for offline idempotency.
- **The database is regenerable, not sacred.** `php artisan migrate:fresh --seed && php artisan app:import-kinondoni-historical-data` rebuilds it from scratch, deterministically, from the migrations/seeders/import command — not from a committed `.sqlite` file (which is gitignored). If you're testing something destructive, this is your reset button.

## Branching & deployment

- **`develop` is where all work happens.** It auto-tracks to the live sandbox at **https://buildiwick.olamtec.co.tz** — that subdomain exists purely for the two of us (and our agents) to see real changes on a real site while building. Branch off `develop`, PR back into `develop`, whatever workflow you like day to day — just don't touch `main` yet.
- **Demo logins** (same on local and the live sandbox, password `password` for all): `director@buildiwick.test` (Admin), `grace@buildiwick.test` (Accounts), `twalib@buildiwick.test` (Estimator/QS), `neema@buildiwick.test` (Project Manager, scoped to Kinondoni + KICD), `amani@buildiwick.test` (Site Operator, same two projects, write-only). Use these to test — don't invent a new test user when one of these already covers the role you need.
- **`main` is frozen on purpose.** It's a stable snapshot reserved for whenever a real production domain is bought and connected — not this sandbox subdomain. Don't merge `develop` → `main` casually; that merge is a deliberate "we're ready to go live for real" decision, not a routine one.
- **Deploy to the sandbox is currently manual**, not automatic — pushing to `develop` on GitHub does *not* by itself update https://buildiwick.olamtec.co.tz. Someone has to SSH in and pull:
  ```bash
  ssh olamtecc@vda6000.is.cc   # DirectAdmin host, same as the other olamtec.co.tz sites
  cd ~/domains/buildiwick.olamtec.co.tz/repo
  git pull                      # tracks origin/develop
  cd app
  composer install --no-dev --optimize-autoloader   # only if dependencies changed
  php artisan migrate --force                        # only if new migrations
  php artisan config:cache && php artisan route:cache && php artisan view:cache
  ```
  An auto-deploy webhook (push → pull automatically) was considered and deferred — ask the human before building it, it's a real infra decision (needs a secret-verified public endpoint on the server), not a default to add unprompted.
- The server's `app/.env` is production config (`APP_ENV=production`, `APP_DEBUG=false`, SQLite for now) — it's not in git (gitignored, same as local), so it won't come back if the server directory is ever rebuilt from a fresh clone. If that happens, recreate it from `.env.example` plus the production values in this section.

## Contribution protocol

This is a two-developer team, each working with their own AI agent, sharing one repo. **Work from `develop`, not `main`** — see Branching & deployment above. Read `CHANGELOG.md`'s most recent entries before you start, so you're not rediscovering context the other side already has. Before you finish and push:

1. **Write a specific commit message.** What changed and why — "update files" or "fixes" tells the next agent nothing. Reference the real thing that changed: "add record-payment page for Accounts" not "update EntryController."
2. **Add a dated entry to `CHANGELOG.md`.** Plain language, written for a teammate (human or AI) who wasn't there: what you built or fixed, what's now possible that wasn't before, and any new decision that belongs in this file's "Decisions already made" section.
3. **If you made a real decision** (not just an implementation detail) that the other developer's agent would need to know to avoid redoing your reasoning or contradicting it, add it to this file, not just the changelog — the changelog is a log, this file is the current state of truth.
4. **Don't invent data.** Every project name, category, and figure in seed data should trace to the client's real materials unless a comment explicitly marks it illustrative.

## Who's working on this

Two developers, each with their own Claude session, building this together. Treat this file and `CHANGELOG.md` as the shared source of truth — the goal is that either side can read a `git log` plus these two files and understand exactly where things stand, without a sync call.
