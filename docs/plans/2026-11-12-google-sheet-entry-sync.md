# Plan: Google Sheet entry, synced to Data Entry drafts

Status: **agreed 2026-11-12; steps 1–3 built (sync every 5 minutes).** Read with `CLAUDE.md` (recording mode, Data Entry, drafts).

## Why

Data Entry keeps one Excel tracker (a tab per project) and edits/extends the same file. Every few days the user passes it to Claude, which imports the new lines into her drafts and writes a review of problems for her to check. This plan replaces that hand-off: the tracker lives in Google Sheets, the system reads it on its own, puts new lines into her drafts, and shows the same review inside the system.

## What stays the same

- **Nothing from the sheet is ever saved to the ledger directly.** Sheet lines only ever land in the Data Entry draft (`expense_drafts`) for that project. She checks and saves them in the system, as today.
- Ledger rules are unchanged: `edit-ledger-line`, confirmation by Accounts, denied lines, totals.
- She can still type in the system form; the sheet is an extra way in, not a replacement.

## The sheet

- **One Google Sheet, one tab per project**, laid out like her tracker (Transaction ID, Date, Main Category, Subcategory, Item, Supplier, Invoice No., Qty, Unit, Unit Cost, Total, Work Area / Stage, Notes) plus a system-managed **Status** column.
- **The user owns the file** (Google no longer gives service accounts Drive storage). The **service account** (robot user) is an editor and does all the work; Data Entry is an editor; "Editors can change permissions and share" is off, so Data Entry cannot re-share, and she cannot remove protections she is not listed on. Set up 2026-11-12; `app:sheets-check` confirms it.
- **New project in the system → new tab appears** (projects are created in the system only; a tab added by hand is ignored and reported).
- Dropdowns for main category, subcategory (filtered by main category where Sheets allows), unit and phase come from the system's `lookups` / `project_phases`; dates use a date picker. Pasting can bypass these, so the review still checks everything.
- Works offline in Chrome or the Sheets app (offline mode on); lines reach the system once uploaded.

## Three states, visible in the sheet

| State | Meaning | Sheet look | Who can edit in the sheet |
|---|---|---|---|
| **Draft** | In her system draft, not saved | white / pale yellow, Status `Draft` (+ `Needs check` in red when the review flags it) | anyone with edit access, any time |
| **Saved – editable** | Saved in the ledger and still editable under `edit-ledger-line` (same day it was entered, not confirmed by Accounts, not changed by anyone else) | light blue, Status `Saved KND-RES-TXN-0009 · editable until 13 Nov 00:00` | anyone with edit access |
| **Saved – locked** | Saved and a day has passed since it was entered (midnight Dar time after the entry day), or confirmed/edited by someone else earlier | grey, Status `Locked KND-RES-TXN-0009` | **only the user and people given the new permission** |

- Draft lines never lock, however old, until they are saved.
- Lock = Google **protected range** in hard mode (not "warning only"). An edit, paste, delete or clear on a locked row is refused by Google with "You're trying to edit a protected cell"; nothing is kept and nothing fails silently.
- The protected range's editor list is kept equal to: the user + system users with the new permission **`edit_locked_sheet_lines`** ("Edit locked sheet lines", off by default, set in User Management) who have a Google email on file.

## Sync (every 5 minutes, cron on the host)

1. Read each tab. Rows are matched by a hidden row key the system writes (not by row number, so inserted/sorted rows are safe).
2. **New row** → added to her draft for that project.
3. **Changed Draft row** → the draft line is updated. **Deleted Draft row** → removed from the draft.
4. **Changed Saved – editable row** → not applied automatically; appears in the review as "Changed in sheet after saving" with old/new values and an **Apply** button that goes through the normal edit path (`ExpenseLedgerService`, edit log).
5. **Changed Saved – locked row** (only possible for permitted editors, or in the few minutes before the lock is applied) → never imported quietly; appears in the review as "Changed after locking by <name>" with old/new values for the user to accept or reject.
6. Lines saved in the system get their code written back to the Status column; states and protections are refreshed.
7. Edits made in the system form to a draft line are written back to the sheet. If the same line changed in both places between syncs, the review shows "Changed in both" and nothing is overwritten.

## Review panel on each project draft

A **Review** button on the Record Expense form shows the current problems in the draft, grouped with counts; clicking a group shows only those lines in the form (extending the existing "Show date" filter). **Agreed 2026-11-12:** only hard errors block saving (missing price/total, unknown unit or subcategory, error values such as `#REF!`, future date); everything else is a warning she can save past.

Checks (the same ones run by hand so far):

- Dates Excel flipped (day ≤ 12): corrected from row order and "no future date"; shows Excel date → corrected date.
- Future dates; dates out of order with the rows around them.
- Missing unit cost or total; quantity × unit cost ≠ total (more than TSh 1).
- Unknown unit or subcategory; `#REF!` / error values; old labels such as "Concrete & Masonry" (suggested replacement shown).
- Subcategory from a different main category (allowed, but skews totals).
- Same date + item + amount twice in the sheet.
- Possible double with a saved line: same item and amount, or same amount + subcategory within a few days (catches different wording such as "500pcs of blocks" vs "Blocks procurement"; may flag some genuine repeats).
- Sheet changes to saved/locked lines (sync steps 4, 5, 7).

## What the user needs to provide

- A Google Cloud project with a service account and its JSON key (stored on the server outside Git, path in `.env`). Steps to be given.
- OK to add `google/apiclient` (Sheets + Drive) to `composer.json` (new dependency, needs approval per `app/CLAUDE.md`).
- Google emails for the user and anyone given `edit_locked_sheet_lines`.
- First-time move: her current tracker is imported once to build the sheet; existing saved lines are marked Saved/Locked, not re-imported.

## Build order

1. Google client, settings, service account check command.
2. Sheet creation and tab per project; Status column, colours, dropdowns.
3. Sync command (new/changed/deleted draft rows) + cron.
4. Review service (checks above) + Review panel and group filter on the form.
5. Saved-line write-back, states and hard locks; `edit_locked_sheet_lines` permission.
6. Changes to saved/locked lines in the review (Apply / accept / reject); system → sheet draft write-back.
7. Tests for each step; one-time import of the current tracker on the sandbox after a backup.

## Open points

- Lock time: midnight Dar time after the day the line was **entered in the sheet** (agreed). Draft lines never lock.
- Review flags: **agreed** — block only hard errors, warn on the rest.
- Google emails: **received** (the user as lock editor; Data Entry as sheet editor). Kept out of Git; they go into the system's user records when built.
- How often to sync: **every 5 minutes** (agreed).
