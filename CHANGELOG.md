# Changelog

Every session or PR adds an entry here — dated, plain language, written for a teammate (human or AI) who wasn't in the room. See `CLAUDE.md` for the full contribution protocol.

## 2026-10-30 — Bulk Confirm / Deny / Delete

- **Expenditure rows can be ticked**, one by one or with "select all on page", and handled together from a bar at the bottom of the screen (`ledger.expenses.bulk`):
  - **✓ Confirm** pays what is still open on each line, with one date, method and reference;
  - **Deny** uses one reason for every selected line;
  - **Delete** removes the selected lines.
- Each line goes through the same service call and permission check as its own button. Lines that can't take the action (already paid, already denied, someone else's line for a PM) are skipped and named in the message. Lines from other projects are never touched.
- Full suite: 186 tests.

## 2026-10-30 — Staff Roles Without Totals, Director Auto-Confirm, Team KPIs, Excel Workbooks, Project Dates

- **Totals and analytics are now a permission**, "See totals & analytics" (`view_analytics`). It is off for staff by default; the Director always has it. Without it:
  - a project opens on Expenditure (no Overview);
  - there are no total cards, footer totals or spend-by-phase tables;
  - the projects list shows only what is waiting to be confirmed on each project;
  - Phases & Budget shows the budget amounts but not the spend.
- **The Accountant role is a staff accountant.**
  - Defaults are now only Confirm payments and Deny expenses: no recording, no workers, no budget page and no Excel.
  - They land on To Confirm after signing in.
  - The Director does everything the old Accounts role did.
- **PM defaults** are Record expenses and Manage workers. Excel is off by default, because the register adds up to totals. The Director can tick any of these back on per person.
- **The Director's entries are confirmed as they are recorded.** A line with an amount is saved as paid; the method defaults to cash and the date to the expense date. The Director can still edit or delete such a line: the payment is re-recorded for the corrected amount (`ledger_paid_line_corrected` in the edit log) or removed with the line.
- **Clear row buttons.** Expenditure rows show separate ✓ Confirm, Edit, Deny and Delete buttons. On phones they are full-width tap targets.
- **Team KPIs.** When adding a project KPI target, the Director can tick "Show to this project's PMs and Accountants". Shared targets appear on that project's Phases & Budget and Expenditure pages. The Warnings & KPIs list has a show/hide toggle per target (`director_kpi_targets.visible_to_team`).
- **Project start and end dates** (`planned_start_date`, `expected_completion_date`) on the project form, Project Setup list, project headers and Excel.
- **Excel workbooks** (`LedgerWorkbookService`):
  - **Project export:** a Summary sheet (cards, by category, by phase), only for users who may see totals, plus the Expenditure register. The register has a project header block, filters, frozen headers, colour-coded category and status, and a SUBTOTAL row that follows the Excel filter.
  - **"Download Excel" for all projects** on the projects list and the Director dashboard (current period): Projects, By Category, Monthly and All Expenditure sheets.
  - The all-projects export raises PHP memory to 512 MB for that request only, because the host default is 128 MB.
- **Lighter pages for staff.** The staff projects list uses two aggregate queries. The Expenditure page skips the per-phase calculation when it isn't shown.
- **Note — "Office running costs, staff, tools & overheads" (BWG-OFC-OVH-2026-001)** is the tracker's company office sheet: salaries, PAYE/SDL, stationery, subscriptions and municipal fees, about TSh 182M over 320 lines. It was imported as a project, so it counts in company "project" spend and in the projects lists. Treating it separately is open for the owners to decide.
- Full suite: 185 tests.

## 2026-10-30 — User Access Control, Multi-Item Recording, Deny, Interactive Analytics

- **Per-user access (User Management).** When creating or editing a user, the Director ticks what they can do and which projects they open:
  - what they can do: record expenses, confirm payments, deny expenses, edit anyone's lines, manage workers, edit phases & budget, download Excel;
  - which projects: "All projects", which includes projects created later, or a searchable project list.

  Each role starts from defaults in `App\Support\Access`. Accounts and Director see all projects; a PM sees only assigned ones. The Director can add more Directors, PMs and Accountants. Storage is `users.permissions` (null means role defaults) and `users.all_projects`; the ledger gates in `AppServiceProvider` check both. Confirming a payment now also checks project access.
- **Record Expense takes many items at once.** It is a table with one row per item: category, phase, item, worker (labour rows), subcategory, qty, unit, unit cost, total, receipt no. (optional) and notes.
  - The phase copies from the row above unless changed.
  - Changing a row's category switches its item suggestions, usual units and subcategories to that category.
  - Item suggestions are names only (no amounts), seeded per category and learned from what people type (`material_item` lookups).
- **Deny an expense.** Accounts and the Director can deny an unpaid line with a reason. It leaves every total, shows under the Status filter as "Denied", and can be restored.
- **To Confirm by project.**
  - The company To Confirm page lists projects (count, amount, oldest).
  - Opening a project shows its Expenditure filtered to "to confirm", oldest first.
  - Inside a project, Accounts and the Director get a "To Confirm" sidebar link with a count.
- **Lists and filters.** Projects are numbered with live search. Expenditure filters apply as soon as they change (search after typing pauses); there is no Apply button. The project form's PM picker is a compact searchable list.
- **Interactive analytics** on the project Overview and the Director dashboard (`partials/ledger/charts`):
  - hover or tap any column, slice or bar to see the amounts;
  - category chips filter the cards and every chart to one category;
  - "Custom" takes From–To dates from a calendar.
- **Phones and tablets.** Below 860 px the sidebar becomes a ☰ slide-in menu. Ledger tables turn into cards, and wide tables scroll inside their own box.
- **Sandbox note:** Neema had no project assignments, probably lost with the demo projects removed by the tracker import's `--fresh`. She now has access to all projects.
- Full suite: 181 tests.

## 2026-10-30 — Expenses and Category Budgets at the Lowest Phase Level

- The Director can create phases and subphases at any time. Budgets are optional, so recording starts as soon as a phase exists.
- Categories belong to the lowest level. A phase without subphases takes expenses and category budgets directly. Once it has subphases:
  - Record Expense lists it as a heading (▸) that can't be picked, so the expense goes on a subphase;
  - Phases & Budget shows its category totals from the subphases instead of input boxes.
- Lines already on a phase before it got subphases stay where they are. A budget set on a phase before it got subphases is flagged so it can be cleared and moved to the subphases.
- The rule is enforced in `ExpenseLedgerService` and `PhaseBudgetService` as well as the UI. Full suite: 178 tests.

## 2026-10-30 — Recording Mode (Phase 1 rollout) and Tracker Import

**The client starts by only recording what happens, with no approvals. Director, PM and Accounts use one clean, per-project workspace. The live tracker workbook (36 projects, 5,805 lines) can be imported unchanged.**

- **Rollout switch.** `config/buildiwick.php` reads `BUILDIWICK_MODE`, which defaults to `recording`; tests run with `full` via `phpunit.xml`. Recording mode switches off four areas:
  - client billing (invoices, receipts, receivables, cash balance, estimated client value);
  - estimates and the Estimator role;
  - the Site Operator role;
  - the request/approval workflow.

  Their routes carry `feature:*` middleware and return 404. Estimator and Site Operator accounts can't sign in, and User Management doesn't offer those roles. The code stays in place, so later phases can switch areas back on.
- **Per-project workspace** (`LedgerController`, `/ledger/...`), for Director, PM and Accounts:
  - **Projects** list.
  - **Overview** with modern charts: spend cards, category donut, monthly stacked columns, phase-vs-budget bars, top items, latest entries.
  - **Expenditure** register: the tracker's columns, category chips, phase (including subphases), status, subcategory, worker, date and search filters, totals, a Spend-by-phase matrix and Excel export.
  - **Record Expense**: category first, then phase / subphase, item name (suggested from past entries), optional subcategory (any, option A), qty, unit, unit cost or total, receipt no., and worker for labour.
  - **Phases & Budget** and **Workers**.
  - **To Confirm** queue for Accounts and the Director.

  Charts are inline SVG partials, with no JS chart dependency.
- **Recording without approvals.** `ExpenseLedgerService` stores each line as an approved `ExpenseRequest` (approval stage `recorded`), coded like the tracker (`OYS-RES-TXN-0956`), so existing payables, KPIs and warnings read it.
  - The PM logs lines as **To confirm** and can't mark them paid.
  - Accounts (or the Director) **Confirm**: optionally correct the amount (logged), then record the amount paid, date and method through `ExpensePaymentRecorder`. Accounts and the Director can also record a line directly as paid.
  - A PM sees only assigned projects and edits only their own unpaid lines. Accounts and the Director can edit any unpaid line.
- **Phase tree.** `project_phases.parent_id` gives phase › subphase › sub-subphase (max 3 levels, `PhaseTree`), freely named by the Director (`PhaseStructureService`). The unique (project, phase_order) index is replaced by a plain index, because order is now per parent. Phases holding expenses or budgets can't be deleted.
- **Budget.** The Director sets one amount per phase node × main category (`PhaseBudgetService`). It's stored as an approved `InternalBudget` with one "Budget allowance" line, and changes go to `project_edit_logs`. Figures roll up the tree.
- **Workers.** The PM registers fundis and subcontractors (`labour_agreements`) with an optional contract amount. Labour lines link a worker, and the page shows recorded, paid, balance and % of contract.
- **KPIs and warnings for recording mode.**
  - KPI measures: recorded spend, money paid out, amount still to confirm, budget used %. A target can be narrowed to a project, phase (including subphases) and/or category (`director_kpi_targets.project_phase_id` / `main_category`).
  - Warning rules: spend above a share of budget (whole project and every phase × category with its own budget), lines unconfirmed for more than N days, an active project with no entries for N days, and late phases.
  - Client-billing measures and rules are not offered.
- **Director dashboard (recording):** period chips with comparison to the previous period, monthly chart, category donut, top projects, **Needs attention** (warnings) and the all-projects table. `director.projects.show` redirects to the ledger overview.
- **Project Setup (recording)** needs only the tracker's fields: Project ID, name, status (adds **Near closure** and **Overheads**) and location, plus optional PMs. A new project can start with the tracker's 8 standard stages. No client, contract or estimator is required.
- **Tracker import.** `php artisan app:import-tracker <xlsx> [--fresh] [--dry-run] [--as=director-email]` imports every project sheet unchanged:
  - Project ID, name, status and location from row 2;
  - the 8 stages become phases;
  - each line keeps its ID, date, category, subcategory, description, qty, unit, unit cost and stage;
  - "Expense" lines are paid (amount paid, or the line total when blank); "Material log – to confirm" lines stay unpaid;
  - negative "difference/discount" lines keep their negative paid amount;
  - labour lines naming a fundi create and link a worker;
  - each project's import is an `OnboardingImport`.

  `--fresh` deletes all existing projects first. On v514 the dry run and the import both give 5,805 lines, TSh 3,437,181,114.51 recorded and TSh 2,556,820,007.17 paid, matching the workbook. Two lines where paid exceeded the total by 1 cent had their total raised by 0.01. A remainder under TSh 1 counts as settled (tracker rounding).
- **Speed.** `LedgerAnalyticsService` converts each line to integer cents once (exact fixed-point) and totals in cents. On the full import the company dashboard went from 10–14 s to about 3 s, and project pages load in under 0.6 s (local dev server).
- Tests: new `RecordingModeTest` (8 tests / 134 assertions). Full suite: 177 tests / 1,948 assertions.

### Decisions

- Phase 1 is recording only: no client billing anywhere, and only the Director, PM and Accounts roles. Switched-off areas are disabled at the route level (`feature:*`), not deleted.
- A recorded line is an approved `ExpenseRequest`. "Paid" is always an `ExpensePayment`, so the ledger and every report share one source.
- Imported tracker data is kept exactly as recorded, including odd units such as "75" typed in the Unit column and negative adjustment lines.

## 2026-10-27 — Director Sidebar: Company vs Project Scope

- Company-level pages now sit only in the Director's main sidebar, under **Company Targets**: **Company KPI Targets** (company-wide targets only, with no project picker) and **Default Warning Rules**. The duplicate dashboard header buttons are gone.
- Each project workspace sidebar gains **Warnings & KPIs** (`/director/projects/{project}/warnings`). The page shows that project's current warnings, its KPI targets with an add form scoped to the project (`director.projects.kpis.store`), and its warning limits with **Use company defaults**. The project overview keeps a short **Warnings** summary with **Open Warnings & KPIs**.
- The dashboard's KPI panel is now **Company KPI Targets** and lists company targets only.

## 2026-10-25 — Director Strategic Review: Periods, KPI Targets and Warning Rules

**Directors can now review any period against the one before it, set their own KPI targets, and define warning limits per project.**

- **Review periods:** Director pages offer last month, this quarter, last quarter and last year, alongside the existing this month, last 3 months, this year and custom (`App\Support\ReviewPeriod`). A **Period Review** panel on the dashboard and on each project's *Dates & Activity* tab compares invoiced, client receipts, money paid out and net cash flow with the previous period, with the change in TSh and %. The previous period is the same span one month, quarter or year earlier; custom ranges use the equal-length span just before.
- **KPI Targets:** a new page (Director dashboard → **KPI Targets**). Directors set a target for this or next month, quarter or year, or custom dates, for the whole company or one project. The default measures:
  - client money received, invoiced, and net cash flow (floors);
  - money paid out, overdue client invoices, and % of budget paid out (ceilings).

  Actuals come from `DirectorAnalyticsService` (payment events, issued invoices, budgets). Status is Met / On track / Behind / Missed for floors (on track means ahead of a straight-line pace through the period) and Within / Over limit for ceilings; future periods show "Not started". Current targets appear on the dashboard, and project targets on the project page.
- **Warning Rules:** company defaults (Director dashboard → **Warning Rules**) with per-project overrides on each project page (*Warning limits for this project*, plus **Use company defaults** to reset). The built-in defaults:
  - budget paid out above 85%;
  - approved commitments above 100% of budget;
  - a client invoice overdue more than 30 days;
  - an approved payment unpaid for more than 30 days (counted from approval);
  - a phase past its planned finish by more than 14 days;
  - unpaid client invoices above TSh 50m (off by default).

  Triggered warnings show on the project page and as a "N warning(s)" badge in the dashboard project list, and a **Projects with Warnings** tile shows the count.
- Writing targets and rules needs the `manage-director-strategy` gate (Director role; Admin through the bypass). Other roles get 403.
- Additive migration `2026_10_25_183345_create_director_kpi_targets_and_warning_rules`: `director_kpi_targets` and `project_warning_rules`. No seeding is needed: built-in defaults apply until a Director saves their own.
- Tests: new `DirectorStrategicAnalyticsTest` (4 tests) covering period resolution and comparison, the dashboard comparison, KPI evaluation and statuses with permissions, and warning defaults, overrides and reset. Full suite: 169 tests / 1,798 assertions.

### Decisions

- KPI actuals and warning checks reuse the Director analytics row and summary. There are no KPI-owned figures or alternative money formulas. Warnings are the Directors' own thresholds applied to recorded facts, not a computed risk score.

## 2026-10-25 — Director Estimate Access

- The Director's project page now has an estimate button that changes with the estimate's status: **Review Estimate** when one is submitted, together with an "awaiting your approval" notice; **Open Working Estimate** for a draft; **Edit Estimate** for an issued one; **Create Estimate** when none exists. Previously the Director's page showed only the last issued estimate, with no way to reach editing.
- The Director's project sidebar gains **Project Estimate**. The Director dashboard gains an **Estimates Awaiting Approval** tile that opens the estimate list filtered to submitted estimates (`?status=submitted`).

## 2026-10-25 — Client Invoice Number on Lists

- Invoice lists now show the client's own invoice number (`reference`, e.g. an imported `IPC-01`) as "Client ref:" under the system code. This covers Accountant Invoices, Accountant Receivables, the Accountant project billing table and Director Receivables, so people can find invoices by the number they already know. The receivables Excel export gains a **Client Ref.** column at the end.

## 2026-10-23 — Opening Records Import for Ongoing Projects

**Ongoing projects can be brought into the system with their history, project by project, without a Site Operator.**

- Accounts gets an **Opening Records** page for each project: Accountant project page → **Opening Records**. The Director can also use it through the Admin bypass. **Download Template** builds a workbook for that project with three sheets: **Expenses** (costs already incurred, with the amount paid so far), **Client Invoices** (already issued; rows sharing an Invoice No. become one invoice) and **Client Payments** (already received). Dropdowns list the project's phases, approved budget item refs, categories, subcategories, units and payment methods.
- **Import Records** checks every row on every sheet first. If anything is wrong, nothing is saved and each problem is listed as "sheet row N: …". Dates are day/month/year or real Excel dates; labels or codes are both accepted.
- Imported rows become ordinary records, so every Accountant and Director report counts them:
  - an approved `ExpenseRequest`, with an `ExpenseRequestApproval` at stage `onboarding_import` dated on the expense date (payable aging stays truthful);
  - its paid amount recorded through `ExpensePaymentRecorder`, with any unpaid balance becoming an approved payable;
  - issued `ClientInvoice`s with their real issue and due dates, the invoice no. kept as `reference`;
  - receipts recorded through `ClientPaymentRecorder`.
  Suppliers are matched by name or created. A Budget Item Ref. links a cost to an approved budget line.
- Every created record carries `onboarding_import_id`. The uploaded file is kept privately and can be downloaded from the import history. **Undo** removes everything an import created, with a required reason, until a newer payment has been recorded against those records.
- The normal workflow is unchanged: teams can still enter past items through the usual request and approval steps if they'd rather learn the system that way.
- Additive migration `2026_10_23_171013_create_onboarding_imports_table`: an `onboarding_imports` audit table, plus a nullable `onboarding_import_id` on `expense_requests`, `expense_payments`, `client_invoices` and `client_payments`.
- Tests: new `OnboardingRecordsImportTest` (5 tests) covering the template, a full import feeding the register, payables and receivables, row-level errors with nothing saved, undo and its lock, and role access. Full suite: 157 tests passing on `develop` plus this change.

### Decisions

- Historical onboarding records are real payment and invoice events tagged with `onboarding_import_id`, not legacy `transactions` rows. Legacy rows don't feed Accountant or Director reporting.
## 2026-10-21 — Estimate Submission, Director Review and Excel Upload

**The Estimator's job now ends at submission; the Director (Admin) owns the estimate afterwards.**

- Estimators click **Submit to Director** instead of **Issue Estimate**. A submitted estimate is read-only for the Estimator: no line edits, uploads, phase changes, re-submission or **Edit Estimate**. Their page shows it as submitted and nothing more.
- The Director (the Admin authority; `hasRole('admin')` is true for Director) gets a **Director Review** panel on the estimate page. From there they can edit a submitted estimate's lines in place, **Approve & Issue** it, or **Return to Estimator** with required notes. Only an issued estimate counts for invoicing and Director analytics, so a submitted estimate cannot be invoiced until it is approved.
- The Director can also start an estimate on their own, by the form or by upload, and issue it directly. **Edit Estimate** on an issued estimate is now Director-only. It opens a Director-held revision that the Estimator neither sees nor edits. **Return to Estimator** on an issued estimate opens a revision the Estimator can edit and resubmit.
- New **Excel Template** panel: **Download Template** gives an `.xlsx` with a Lists sheet and dropdowns for Phase, Category, Subcategory and Unit, pre-filled with the current lines. **Upload Estimate** replaces every line in the working estimate. Labels or codes are accepted, phases can be given by name or number, and percent-formatted markup cells are understood. If any row is invalid, nothing is saved and every problem is listed by row number. On revised versions (v2+) an upload requires a justification and is recorded in the edit log.
- Additive migration `2026_10_21_190403_add_estimate_submission_review_fields`: `editor_role`, `submitted_by/at`, `returned_by/at`, `review_notes` on `project_estimates`, plus the `submitted` estimate status lookup. Existing drafts keep their current estimator-editable behaviour.
- Tests: new `EstimateSubmissionReviewTest` (8 tests) using the real Director role. Existing estimator and security tests were updated to the submit, then approve, flow. Full suite: 160 tests / 1,650 assertions passing.

### Decisions

- Estimate lifecycle is `draft → submitted → issued`. The Estimator controls only drafts whose `editor_role` is not `admin`. Everything after submission belongs to the Director/Admin.

## 2026-10-15 — Accountant Supplier-Group Payment Workspace

**Corrected the approved-request workspace so Accounts operates entirely from PM-approved supplier groups.**

- Removed the Site Operator’s original-request section, Site submitter, Site notes, and requested-value context from the Accountant request page.
- Moved supplier name, type, contact person, phone, email, address, approved payment method, destination, and quotation links to the top of each supplier group.
- Placed each group’s approved items, internal-budget allocations, group totals, payment history, and payment controls together. Payments remain recorded against the exact item so budget usage and audit history do not become ambiguous when one supplier group contains several items.
- Replaced the visible PM grouping checkbox column with whole-row interaction: hover highlights the item card, clicking anywhere outside its form controls selects it, and keyboard Enter/Space selection is supported.
- Full suite passes: **145 tests / 1,406 assertions**.

### Workflow decision

- Accounts receives the PM-approved commercial instruction only. The supplier group is the payment workspace, but each payment continues to post to a specific approved item.

## 2026-10-15 — Clickable Request Summaries, Supplier Groups, and Document Polish

**Made request navigation and Accounts processing match the agreed complete-request workflow.**

- Made Accountant dashboard and project request summaries clickable. Project summaries open the matching approved, awaiting-payment, part-paid, or paid queue already filtered to that project; its status tabs preserve that project scope, while global queues show the project name and link back into its workspace.
- Restricted Accountant request surfaces and counts to PM-approved requests. Pending PM review, rejected requests, and cancelled requests remain outside Accounts.
- Changed PM and Accountant request queues to one row per complete request batch rather than one row per item. Opening an approved request shows its complete total, supplier-group totals, the items inside every group, each item's internal-budget allocation, and item-level paid/outstanding values.
- Kept item-level accounting underneath the grouped display so one request can contain several suppliers without losing precise budget allocation, payment, evidence, or audit history.
- Updated the fictional demo request to contain three items in one request across two supplier groups, removed obsolete Site-entered price/supplier values, and populated its seven custom phase names plus BOQ work-area, work-section, and item-reference fields.
- Reorganized printable internal invoices into a professional header, client/project metadata, phase-aware lines, totals, notes, and print-only layout. Improved all generated Excel workbooks with Buildiwick document properties, consistent typography, print headers/footers, repeatable headings, and print areas; invoice workbooks now include reference, status, received, and outstanding context.
- Added regression coverage for approved-only Accounts views, clickable project filters, complete-request supplier grouping/totals, and the updated demo format.
- Full suite passes: **145 tests / 1,396 assertions**.

### Workflow decision

- Request batch → supplier groups → items is the presentation hierarchy. Supplier grouping is derived from the PM's supplier assignment; items remain the financial posting unit.

## 2026-10-15 — Project-Specific Phases and BOQ Hierarchy

**Aligned estimate and internal-budget setup with the supplied material schedule and quotation/BOQ samples.**

- Estimators can add, rename, date, delete, and reset phases for each project while the estimate is still a draft. The default seven phases remain a starter template, not a fixed project structure.
- Draft estimate and internal-budget lines can be edited or deleted without a justification. Once an estimate is issued or an internal budget is approved, later edits still require a reason and preserve the prior version and audit history.
- Phase structure locks after an issued estimate, an approved budget/request, a recorded expense payment, or an issued invoice so approved records cannot be removed by cascading phase deletion.
- Added optional **Work Area / Building**, **Work Section**, and **Item Reference** fields to estimate and internal-budget lines. Their screens and Excel exports now follow the sample hierarchy: work area → phase → section → line item.
- All operational and Director analytics now include the project’s custom phases instead of filtering back to the original hard-coded seven.
- Identified the supplied six-page commercial document as a quotation/BOQ, so it informs the estimate format rather than being misclassified as an invoice.
- Full suite passes: **144 tests / 1,371 assertions**.

### Workflow decision

- Draft means freely editable and restartable. Approval/issuance is the boundary that introduces immutability, mandatory reasons, and audit retention.

## 2026-10-15 — Audited Budget/Estimate Editing and Labour Request Review

**Replaced misleading new-version/new-request actions with the review and edit workflows requested by the team.**

- Labour/subcontract agreement rows no longer open a fresh PM request. Their last column now lists each existing linked request and opens pending items for PM review or completed items as a reference.
- Added a clearly labelled **Download All Labour Requests Excel** action. The workbook contains every non-cancelled labour/subcontract request in the selected project scope, not the agreements table and not unrelated material requests.
- Replaced **Create Revised Budget Version** and **Start New Version** with **Edit Internal Budget** and **Edit Estimate**. Editing copies the approved/issued version into an editable draft so the prior version remains immutable, but users work through a normal edit action rather than constructing an empty replacement.
- Made a justification mandatory when an approved budget or issued estimate is opened for editing and whenever a line is added to an edited version, changed, or deleted. Director-return reasons are logged too.
- Added project edit logs with actor, date, document/version, item, changed field values, and justification. Budget logs appear in the PM project workspace, estimate logs in the Estimator workspace, and the combined log at the bottom of the Director project view.
- Verified the generated labour-request workbook with PhpSpreadsheet and ran the full suite: **142 tests / 1,361 assertions**.

### Workflow decision

- Approved internal budgets and issued estimates stay immutable. The UI calls the operation **Edit**, while the implementation preserves the prior document and opens a copied draft version with a compulsory audit reason.

## 2026-10-15 — Excel Exports, Verified Internal Budgets, and Project Workspaces

**Turned multi-project role screens into project workspaces and completed the requested document/export and internal-budget controls.**

- Replaced all existing CSV downloads with formatted Excel `.xlsx` workbooks and added downloads for all project requests (including labour-only filtering), labour/subcontract agreements, Estimator estimates, PM internal budgets, and client invoices. Workbooks include titles, context, typed currency/date cells, filters, frozen headers, print setup, and totals for estimate/budget/invoice formats.
- Changed the internal-budget lifecycle so PM submission is locked while awaiting Director verification. The Director can approve it as the active baseline or return the same version to draft with review notes. Older finalized baselines are grandfathered to approved by an additive migration.
- Restricted new request allocation and approval to entries in a Director-approved internal-budget version. An awaiting or draft budget cannot be used to send a request to Accounts.
- Removed Site Operator pricing and estimate fields from request creation, request detail, and PM review context. Site now supplies description, type, quantity/unit, and specifications; PM supplies supplier and prices.
- Added persistent project-specific sidebars for Director, PM, Accountant, Estimator, and Site Operator pages. Project-filtered links remain scoped until the user chooses **Close Project**.
- Split Director, PM, and Accountant project detail into smaller overview/phase/activity/billing/cost views so a project no longer presents every detailed table at once. Existing optional Director graphs remain optional.
- Added PhpSpreadsheet 5.10 and workbook-content regression coverage. Full suite passes **141 tests / 1,333 assertions**; Composer reports no known security advisories.

### Workflow decisions

- Director remains separate from the Admin bypass but is no longer purely read-only: internal-budget verification is its one explicit workflow decision.
- Site-entered amounts are never accepted for new request batches. Commercial value starts with PM preparation.
- New request batches cannot be approved against a draft or merely submitted internal budget; the budget version must be Director-approved.

## 2026-10-13 — Cross-Role Demo Project and MVP Workflow Simplification

**Added a complete fictional training project that can be followed through every MVP role without changing the 16 tracker-derived projects.**

- Added `Bahari View Family Villa — DEMO` (`BWG-DEMO-2026-001`) with a fictional client, complete role assignments, seven construction phases, an issued Estimator estimate, and a finalized PM internal budget.
- Standardized every demo estimate line to Buildiwick’s usual **15% client markup**.
- Clarified that Director “financial activity” dates are reporting dates, not the current construction phase. Added a calendar-based end-of-day historical reconstruction, a persistent phase-history table, optional cost graphs, and an expandable/minimizable sidebar across roles.
- Removed Estimator Client Value from operational phase/category comparison tables; the Director still sees the value once in the separate current client position.
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
