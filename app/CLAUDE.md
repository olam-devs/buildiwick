# Buildiwick — Laravel App

This is the Laravel 13 codebase. **Read `../README.md` and `../CLAUDE.md` first** — they have the full project context (client, scope, decisions already made). This file is just a quick technical reference for working inside `app/`.

Laravel Boost was never installed for this project — that's a deliberate omission, not an oversight. If you want it, treat that as a decision to make and document in `../CLAUDE.md`, not a default setup step.

## Quick reference

- PHP 8.4, SQLite for local dev (see `.env` / `database/database.sqlite`, gitignored — rebuild with the command below).
- Two route files, two auth guards: `routes/web.php` (session guard — the real product UI) and `routes/api.php` (Sanctum tokens — JSON API, built first, still used).
- Rebuild the database from scratch at any time:
  ```bash
  php artisan migrate:fresh --seed
  php artisan app:import-kinondoni-historical-data
  ```
- Demo logins: see `../README.md`.

## Structure

- `app/Models` — `Role`, `User`, `Project`, `ProjectAssignment`, `Lookup`, `LabourAgreement`, `Transaction`.
- `app/Policies` — one per gated model. Global rules (deactivated-account lockout, Admin bypass, the `view-dashboard` Gate) live in `app/Providers/AppServiceProvider::boot()`.
- `app/Http/Controllers/Web` — the real Blade-rendered product: `AuthController`, `DashboardController`, `EntryController`.
- `app/Http/Controllers/Api` — JSON API, same requests/policies reused.
- `app/Http/Requests` — `StoreTransactionRequest`, `StoreLabourAgreementRequest`, shared by both Web and Api controllers.
- `app/Services/TransactionRecorder.php` — the one place a `Transaction` row gets created. Both entry points call this; don't duplicate its logic in a new controller.
- `resources/views` — Blade templates. `layouts/app.blade.php` is the shared shell (sidebar, styles, the New Entry drawer include); `partials/new-entry-drawer.blade.php` is the entry form itself, fed lookups/projects/agreements via a View Composer in `AppServiceProvider`.
