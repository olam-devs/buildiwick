# Changelog

Every session or PR adds an entry here — dated, plain language, written for a teammate (human or AI) who wasn't in the room. See `CLAUDE.md` for the full contribution protocol.

## 2026-10-13 — Cross-Role Demo Project and MVP Workflow Simplification

**Added a complete fictional training project that can be followed through every MVP role without changing the 16 tracker-derived projects.**

- Added `Bahari View Family Villa — DEMO` (`BWG-DEMO-2026-001`) with a fictional client, complete role assignments, seven construction phases, an issued Estimator estimate, and a finalized PM internal budget.
- Standardized every demo estimate line to Buildiwick’s usual **15% client markup**.
- Seeded realistic mixed workflow states: a Site request waiting for PM preparation, three PM-approved supplier items, one fully paid item, one part-paid item, one unpaid payable, a labour agreement, two issued client invoices, one client receipt, and a draft invoice left for continued Accounts testing.
- Added fictional suppliers and payment destinations with `.test` contacts and visibly labelled demo account values. The demo seeder is idempotent and is included in the normal database seed sequence.
- Added a feature test that runs the demo seeder twice, verifies the financial states, and confirms that the project is visible to Director, Estimator, PM, Site Operator, and Accounts.
- Simplified the PM and Accounts dashboards, collapsed inactive PM budget phases, added short working-order guides, highlighted incomplete Director project setup, and corrected obsolete Director-approval wording in the labour view.
- Verified locally in the browser across all five roles. The full suite passes **139 tests / 1,300 assertions**.
- Pushed the work to `develop`. The shared sandbox still requires its documented manual pull/cache/seed deployment; pushing alone does not change the server database.

### Demo-data decision

- The new demo project is the only intentionally invented project dataset. It is isolated in `DemoProjectSeeder`, explicitly labelled DEMO in the UI, and must remain distinguishable from the 16 projects sourced from the client tracker.

## 2026-10-03 — Branch Reconciliation and Independent Verification

**Prepared the Phase 11 `main` snapshot to return to the shared `develop` workflow without promoting or changing production.**

- Restored the documented branch model that had remained only on the diverged `develop` branch: feature work targets `develop`, the shared sandbox checks out `develop`, and `main` remains the stable production-candidate branch.
- Updated the restored guidance for the current Phase 11 role model: the bundled Director account is read-only and is not the Admin bypass.
- Kept the newer Phase 2–11 implementation, security hardening, and financial workflows from commit `2986a9e`; no application or database behavior was changed by this reconciliation.
- Independently installed the locked dependencies and ran the full test suite using portable PHP 8.4.25 and in-memory SQLite: **126 tests / 1,190 assertions passed**.
- The shared sandbox remains a manual deployment. Merging or deploying the reconciliation still requires explicit review; no remote branch, production checkout, or database was changed during preparation.

## 2026-09-30 — Phase 11 Security, Authorization, Audit and Financial Integrity

**Hardened existing workflows without starting Phase 12 or adding a new business module.**

- Added active-account middleware to authenticated web and Sanctum API routes; inactive users are logged out/revoked. Login resolves exact role codes to role dashboards, unknown roles are denied, web/API login is IP-throttled, and demo credentials are hidden outside local/testing.
- Closed a sensitive evidence leak: Site Operators could use the PM commercial-quotation evidence route because the general request-view policy also permits assigned Site users. Quotation downloads are now limited to Admin, Accounts, and an assigned Project Manager. Site-facing request pages show workflow/payment status without historical monetary totals or payment amounts/methods.
- New Phase 1 transaction evidence is stored on private local storage. Local-disk serving is disabled. Added the opt-in `app:secure-legacy-transaction-evidence --force` command to copy old public-disk transaction evidence to the same private paths and remove public copies without changing transaction rows or path values; run it deliberately after backup in the target environment.
- Locked PM commercial preparation to a transactional service that rechecks project/decision state, validates supplier destination ownership, computes totals server-side, attributes the preparer, and cleans replaced evidence. Invoice project/client/estimate scope and payment route IDs are rechecked server-side.
- Kept invoice issuance and issued-content locking intact; separated the authorized void ability so Accounts can void an unpaid issued invoice with actor/reason/time audit, while paid invoices and Director attempts remain blocked.
- Added estimate issue actor/timestamp columns additively. Added nullable unique payment idempotency keys; payment forms generate request tokens and retries return the existing event, while different-parent/token replays are rejected. Existing payment and approval row locks remain in place.
- Upload rules validate detected content and allowed filename extensions. Construction evidence allows PDF, JPEG/PNG/WebP images, Word, and Excel documents up to 20 MB; legacy transaction photos remain images up to 8 MB.
- Added 23 focused Phase 11 tests (268 assertions) covering login/role routing, active accounts, URL/IDOR denial, forged fields, payment retry/overpayment, private evidence, privacy, migration upgrade preservation, and role boundaries.
- Verified using Herd Lite PHP 8.5.0 and isolated SQLite: focused tests pass 23/268; full suite passes 126 tests / 1,190 assertions. Fresh migrate/seed and additive migration upgrade checks pass in memory. The normal development DB was not touched.
- Test-driven corrections included middleware handling for inactive Sanctum tokens and tightening commercial evidence authorization after a Site Operator test demonstrated access.

### Phase 11 decisions

- New legacy transaction evidence is private. Existing public transaction evidence requires the explicit copy-and-delete command; the app does not silently rewrite historical database rows or run the command automatically.
- ClientPayment and ExpensePayment forms submit unique idempotency UUIDs; nullable unique columns preserve old payment rows and internal recorder callers without keys. Row locks still guard current outstanding balances.
- Site Operators retain approval/payment status visibility but not historical priced request totals, approved monetary totals, actual paid amounts, or payment method/event details in Site views.
- Production must replace local demo credentials; the login page only displays demo credentials in local/testing environments.

## 2026-09-30 — Phase 10 Director Executive Analytics

**Added a dedicated, read-only Director analytics workspace over existing financial records.**

- Added a distinct `director` role for the seeded Director login. It can view executive analytics without inheriting the Admin bypass or Accountant write permissions; Admin retains prior access.
- Added organization summary cards, project portfolio and detail analysis, budget utilization, phase/category comparisons, monthly cash movement, and factual receivable/budget indicators. The highest issued estimate version per project is authoritative; historical versions are not double-counted.
- Reused invoice/request computed totals and outstanding balances plus the Phase 9 transaction-event reporter; payment relations now use eager-loaded rows when present to keep register summaries query-efficient.
- Added current-position versus selected-period activity labels, preset/custom activity ranges, receivable/payable/event drill-downs, and portfolio/financial-summary/transaction CSV exports.
- Lightweight server-prepared HTML charts reuse the existing frontend; no financial schema, chart dependency, profit calculation, or arbitrary risk score was added. An additive role-data migration provisions the `director` role and moves the bundled demo Director account off the Admin bypass.
- Added focused feature coverage for financial-source boundaries, estimate versioning, project isolation, utilization/over-budget/zero-budget handling, overdue receivables, monthly cash movement, exports, and role boundaries.
- Verified with Herd Lite PHP 8.5.0 and explicitly in-memory SQLite: all 9 Director tests pass (115 assertions); full isolated suite passes (103 tests, 922 assertions), versus the previous 94/807 baseline.
- Fresh `migrate:fresh --seed` succeeded in memory. The migration idempotence/upgrade test confirms only the bundled Director account is reassigned, unrelated Admin/Accounts users retain roles, and invoice/payment records remain unchanged. Route listing shows eight Director GET-only endpoints; focused tests exercise allowed reads/exports and deny write routes.
- Verification fixed a missing `actual_expenses` summary alias and an unresolved `ClientInvoice` controller type import exposed by the authorized-route tests.

### Phase 10 decision

- Director is a separate read-only role authorized by a dedicated analytics Gate. Date ranges affect invoice/payment activity only; balances, receivables, payables, budgets, and estimates remain current position.

## 2026-09-30 — Phase 9 Accountant Financial Control and Reporting

**Added read-only Accountant financial control and reporting projections.**

- Added deterministic project/global transaction registers built only from `ClientPayment` and `ExpensePayment` events. Same-date ordering is payment date, recorded timestamp, then event ID; running balances use actual cash movement only.
- Added receivables and approved-payables registers with CSV exports. Receivables exclude draft/void/fully paid invoices; payable aging is explicitly age since PM approval rather than invented supplier due-date aging.
- Added printable project financial statements with period opening balance, in-period client receipts, in-period expense payments, closing balance, and transaction detail.
- Added phase/category outgoing breakdowns and dashboard control cards for actual expenses, client receipts, cash balance, receivables, and approved payables. Recorded cash balance remains cash position, not profit.
- Added no generic transaction creation path; reporting links trace back to invoice/payment or approved request/payment records.
- Full isolated suite passes: 94 tests, 807 assertions. Director analytics, profit/margin dashboards, general ledger, tax, credit notes, bank integrations, and Phase 10 remain unimplemented.

## 2026-09-30 — Phase 8 Client Billing and Unified Accountant Workspace

**Added Accountant client invoicing, client payments, receivables, and unified project financials.**

- Added Accountant all-project overview and project-centered financial workspaces combining PM budget, approved commitments, actual ExpensePayments, client invoices, ClientPayments, payables, receivables, and recorded cash balance.
- Added draft invoice creation, server-generated invoice numbers, fixed-point line totals, issued lifecycle, estimate-to-invoice client-price prefill, printable invoice detail, and immutable issued contents.
- Added transactional ClientPayment recording with partial payments, multiple receipt events, issued-invoice-only validation, server-side overpayment protection, private evidence, references, and payment history.
- Added additive invoice/payment audit fields for references, issue/void audit, transaction references, and evidence filenames. Historical invoice/payment records remain readable.
- Recorded Cash Balance is explicitly ClientPayments minus ExpensePayments; it is not labelled profit. Draft invoices are excluded from receivables and invoices are not cash received.
- Full isolated suite passes: 91 tests, 795 assertions. Director analytics, profit/margin dashboards, tax, credit notes, bank integrations, and Phase 9 remain unimplemented.

## 2026-09-30 — Phase 7 Accountant Expense Payment Workflow

**Implemented Accountant handling of approved project expense payments.**

- Added Accountant dashboard, approved-only payment queue, request/payment detail, project outgoing financial views, filters, partial-payment progress, and private evidence downloads.
- Extended ExpensePayment audit data with actual transaction reference, method-variance reason, and private evidence original filename. Payment events remain individual immutable records; paid/outstanding/status are derived from those rows.
- Reused ExpensePaymentRecorder for transactional locking, approved-state checks, fixed-point amounts, and overpayment protection. Actual payment method differences require an explanation, and payment evidence is stored on the private disk.
- Accountant reads the immutable Phase 6 approval snapshot for supplier, contact, approved rate/total, method, destination, evidence, approver, timestamp, and decision notes. Accountant cannot re-commercialize or change the approved instruction.
- PMs receive read-only payment progress; Site Operators and Estimators cannot access payment destinations or payment operations. Approval alone still creates no ExpensePayment.
- Full isolated suite passes: 87 tests, 775 assertions. Client invoicing, deposits, Director analytics, and Phase 8 remain unimplemented.

## 2026-09-30 — Phase 6 PM Request Review, Commercialization and Approval

**Implemented the Project Manager Site Request decision workflow.**

- Added assigned-project PM queue filters for awaiting pricing, ready for review, approved, and rejected requests, plus actionable dashboard metrics for commercialized requests, approved commitments, budget remaining, and Accounts handoff.
- Added PM review sections for read-only Site need details, commercial/procurement preparation, phase/category budget impact, and approve/reject decisions. Approved commitments use approved commercial totals only; rejected/cancelled requests and ExpensePayments are excluded from commitment calculations.
- Added reusable SupplierPaymentDestination records under SupplierPayee. PM/Admin can select destinations or add multiple destinations per payee; sensitive destination data is restricted from Site Operators and Estimators.
- Approval is transactional and locked: it rechecks assignment/state, commercial completeness, payment-method destination rules, fixed-point quantity × rate totals, and over-budget justification. It records an immutable supplier/payment/commercial snapshot in ExpenseRequestApproval and never creates ExpensePayment.
- Rejection requires a reason, preserves request history, and cannot be followed by approval. Decided commercial instructions are read-only.
- Focused PM Phase 6 tests and full isolated suite pass: 82 tests, 744 assertions. Accounts payment execution, client invoicing, and Director analytics remain unimplemented.

## 2026-09-30 — Pre-Phase-6 Workflow Correction

**Separated Site needs, Estimator costing, and PM commercial preparation.**

- Estimator no longer collects or presents supplier/provider information; the historical nullable field remains untouched and new service writes ignore it.
- Site Operator requests are now need-only: item, quantity, unit, notes, evidence, and server-controlled project/phase/request metadata. New HTTP submissions cannot set supplier, unit cost, or monetary totals.
- Site summaries and tables use request counts and pricing-review counts, never fake a combined quantity or unpriced amount. Historical priced requests remain readable and their monetary summary compatibility fields remain available.
- Added reusable PM/Admin SupplierPayee records and PM-only commercial preparation fields on ExpenseRequest: selected payee, rate, calculated commercial total, payment method/destination, notes, and preparation audit. Payment destination is not exposed to Site Operators or Estimators.
- PM internal budgets remain separate and unchanged. No approval/rejection, ExpensePayment creation, Accounts workflow, or Phase 6 transition was added.
- Full isolated suite passes: 79 tests, 729 assertions.

## 2026-09-30 — Entry System Hardening

**Hardened Estimator, Project Manager, and Site Operator cost entry.**

- Added construction-relevant unit lookups additively, including tonne, m², bundle, worker-day, trip, load, km, job, task, service, point, and person; existing unit codes remain valid and unique.
- Added shared category-aware unit recommendations to all three forms while retaining every active lookup unit as a fallback. Subcategory validation remains server-side and category-scoped.
- Improved supplier/fundi/service-provider wording, lump-sum guidance, explicit-zero behavior, and the Estimator table’s derived Client Unit Price column.
- Preserved separate financial meanings: Estimator base/markup/client totals, PM expected internal budget totals, and Site requested totals with approval/payment state unchanged.
- Added focused hardening coverage. The full isolated suite passes: 77 tests, 713 assertions. No Phase 6 approval, payment, invoicing, or analytics workflow was added.

## 2026-09-30
**Implemented Phase 4 Site Operator dashboard and ExpenseRequest workflow.**

- Added role-specific Site Operator dashboard, assigned-project request pages, details, safe owner-only pending edits/cancellation, and private evidence downloads.
- Requests bind to the project’s single active standard phase, calculate amount server-side with fixed-point math, use collision-safe REQ-<ULID> codes, preserve submission/cancellation audit timestamps, and never create ExpensePayment.
- Approval state, approved amount, actual payments, payment status, and approved outstanding remain separate; summaries are derived from request and payment records.
- Removed Site Operator legacy transaction creation from policy, web entry UI, and API response path; historical transaction access/data remain otherwise intact.
- Added additive submission/approved amount/cancellation and evidence original-filename columns. Focused Site tests pass (14 tests, 128 assertions); full suite passes (59 tests, 569 assertions) using in-memory SQLite migrations/seeders.
- PM decision controls and Accounts payment controls were not added in this phase.

## 2026-09-30 — Phase 5
**Implemented Phase 5 Project Manager internal operational budgets.**

- Added assigned-project PM dashboard and project/phase budget workspace with read-only Site Request comparisons; requested/remaining/utilisation are derived and preserve negative over-budget values. Zero budgets display “No budget set”.
- Added draft budget entries for all seven phases, with fixed-point quantity × expected unit cost, actor audit, edit/delete while draft, and no client markup fields.
- Added a minimal immutable-baseline lifecycle: one working `draft`; PM can finalize a non-empty budget; finalized history is retained, later finalized revisions supersede older baselines. Added `finalized_by`/`finalized_at` and entry `entered_by` fields additively.
- PM approval/rejection and Accounts payment workflows remain disabled/not implemented; legacy PM transaction creation is denied to prevent request workflow bypass.
- Focused PM tests pass (15 tests, 115 assertions); final full suite passes (74 tests, 684 assertions). Migrations/seeders ran only against in-memory SQLite.

**Implemented Phase 3 Estimator/QS dashboard and client-facing estimate workflow.**

- Added an assignment-scoped Estimator workspace, Admin global estimate viewing, a role-specific Estimator landing page/navigation, project and seven-phase estimate summaries, detailed entries, history and useful empty states.
- Added one on-demand working draft per project. Issuing requires entries, locks the version, retains prior versions and marks the previous issued version superseded when a later version is issued.
- Added draft-only entry create/edit/delete with phase/category/subcategory validation and actor audit. Estimate totals and markup summaries use the existing fixed-point `Money` calculations; browser preview is integer-cent only and never authoritative.
- Added an additive `estimate_entries.entered_by` FK; no existing estimate/financial rows were rewritten. Added 16 Estimator workflow feature tests.
- Verified all migrations and seeders using explicitly in-memory SQLite. Full suite: 45 tests, 441 assertions, all passing. No PM, Site Operator, Accounts, invoice, or Director analytics workflows were added.

## 2026-09-30

**Hardened the next-phase financial workflow foundation without replacing the Phase 1 transaction system.**

- Added clients, seven project phases per seeded project, versioned estimates and internal budgets with separate entries, site expense requests with approval history and individual expense payments, client invoices/items and individual client payments, and polymorphic project documents.
- Added Eloquent models and relationships, computed line totals/payment balances, role-specific policy foundations, and Form Requests with project/phase consistency checks.
- Estimate entries keep the base unit rate separate from markup percentage and derive client unit price, base total, markup amount, and client line total using fixed-point arithmetic. Internal budgets remain a separate, unmarked-up dataset.
- Added transactional `ExpensePaymentRecorder` and `ClientPaymentRecorder` services. Each locks and rereads the payable record, checks approval/issued state and exact outstanding amount, creates an individually coded payment with evidence and actor audit data, and removes uploaded evidence if persistence rolls back.
- Added `brick/math` as a direct dependency. New workflow monetary columns remain DECIMAL; new financial totals/statuses and overpayment guards use fixed-point arithmetic.
- Seeded workflow/document status lookups while retaining existing values. `other_general` remains a fallback lookup, not a seeded project phase.
- Kept legacy `transactions` and `TransactionRecorder` in place; added only a nullable phase link, leaving historical rows unclassified rather than guessing stages.
- Added Phase 1 domain tests and corrected the root-route test to assert its intentional redirect. The isolated Phase 1 verification passed: 14 tests, 130 assertions.

**Implemented Phase 2 Admin/Director project registration and setup.**

- Added project client, contract metadata, overall schedule, notes, phase planned/actual dates, and nullable physical-progress percentage through an additive migration. Existing projects/transactions remain; old untouched phase sets are initialized to Phase 1 Active and Phases 2–7 Not Started.
- Added Admin-only project list, New Project, detail, setup-edit, and private contract-download routes/pages. Registration can select an existing client or create one inline, assigns active Estimator/QS, Project Manager, and Site Operator users by role, and atomically creates one master project with seven phase rows and optional signed-contract document metadata.
- Project and phase schedule dates are validated; phase ranges may overlap. Actual dates and physical progress remain nullable. `other_general` is not a project phase.
- Contract PDFs/JPGs/PNGs are stored on Laravel’s private local disk and downloadable only after Admin policy authorization. Project code is immutable during setup edits; client changes are blocked after invoice history; destructive project/phase deletion is denied to preserve history.
- Final verification used explicitly in-memory SQLite for migrations and seeders. The full suite passed: 29 tests, 296 assertions. No dashboards or Phase 3 workflows were added.
- Final verification used explicitly in-memory SQLite for migrations and seeders. At Phase 2 completion: 29 tests, 310 assertions passed. No dashboards were added in that phase.
- A pre-Phase-2 standalone migration invocation previously targeted the default local SQLite file; it was reseeded and the documented Kinondoni import was rerun. Ad hoc local-only data absent from the repository seeders/import may not have been recoverable.

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

**Deployed to a live sandbox: https://buildiwick.olamtec.co.tz**

- Server: `vda6000.is.cc` (DirectAdmin, same host as the other olamtec.co.tz sites), subdomain created with PHP 8.4.
- Deploy pattern matches the sibling projects (e.g. sokokuku.olamtec.co.tz): the repo is cloned to `~/domains/buildiwick.olamtec.co.tz/repo`, and `public_html` is a symlink to `repo/app` — so the whole Laravel app (source included) sits at the web root. This looked like a risk at first glance, but it's protected by a server-level rule blocking dotfile access (`.env` returns 403) — confirmed by testing sokokuku's live `.env` before repeating the pattern here, not assumed.
- To update the live site: `cd ~/domains/buildiwick.olamtec.co.tz/repo && git pull`, then re-run `composer install --no-dev --optimize-autoloader` if dependencies changed, and any new migrations.
- **Found and fixed a real deploy-time bug**: `DatabaseSeeder` had a leftover `User::factory()->create(...)` call from before `UserSeeder` existed, which crashed `db:seed` in production because `composer install --no-dev` correctly excludes `fakerphp/faker`. Removed it — `UserSeeder`'s real named accounts already cover the admin demo login, the factory call was dead weight.
- Server DB is SQLite for now, matching local dev — same reasoning as local (start simple, no separate DB service to provision). Revisit if/when this needs to survive a server migration or handle real concurrent writes.

**Branching model set up**: `develop` (tracks the live sandbox) vs `main` (frozen until a real production domain exists) — server's checkout switched from `main` to `develop` to match. Documented in `CLAUDE.md` ("Branching & deployment") and at the top of `README.md`, including a callout pointing any AI agent to read `CLAUDE.md` first. Demo logins are now listed in both files, not just README, so an agent doesn't have to go hunting for them.
