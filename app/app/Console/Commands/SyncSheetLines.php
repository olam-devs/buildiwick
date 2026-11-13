<?php

namespace App\Console\Commands;

use App\Models\Project;
use App\Services\GoogleSheets\ProjectSheetLines;
use App\Services\GoogleSheets\ProjectSheetTabs;
use App\Services\GoogleSheets\SheetsClient;
use App\Services\GoogleSheets\SheetTotals;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Throwable;

#[Signature('app:sheets-sync {--project=* : Only these project ids} {--with-drafts : Also put draft lines typed in Buildiwick into the sheet (one-time fill)} {--total-formulas : Turn every Total that equals quantity x unit cost into a formula (one-time)}')]
#[Description('Bring lines typed in the Google entry sheet into the Data Entry drafts (runs every 5 minutes); also gives new projects their tab.')]
class SyncSheetLines extends Command
{
    public function handle(SheetsClient $client, ProjectSheetTabs $tabs, ProjectSheetLines $lines, SheetTotals $totals): int
    {
        if (! $client->isConfigured()) {
            $this->error('Google Sheets is not set up. Set GOOGLE_SHEETS_KEY_PATH and GOOGLE_SHEETS_SPREADSHEET_ID in .env.');

            return self::FAILURE;
        }
        $only = $this->option('project') ? array_map('intval', $this->option('project')) : null;

        try {
            // New projects (no tab yet) get their tab first; dropdown lists are refreshed hourly by app:sheets-tabs.
            $tabIds = collect($client->tabs())->pluck('id')->all();
            $missing = $tabs->projects()->filter(fn (Project $project) => ! in_array($project->sheet_tab_id, $tabIds, true))
                ->filter(fn (Project $project) => $only === null || in_array($project->id, $only, true))->pluck('id')->all();
            if ($missing !== []) {
                $created = $tabs->sync($missing)['created'];
                $this->line('New tabs ('.count($created).'): '.implode(', ', $created));
            }
            // Tabs Data Entry added for a project Buildiwick does not have yet wait for the Director.
            if ($only === null) {
                $added = $tabs->newProjectTabs();
                if ($added['new'] !== []) {
                    $this->line('New project tabs waiting for approval: '.implode(', ', $added['new']));
                }
                if ($added['ready'] !== []) {
                    $this->line('Approved project tabs set up: '.implode(', ', $added['ready']));
                }
            }
            $report = $lines->sync($only, (bool) $this->option('with-drafts'), (bool) $this->option('total-formulas'));
            // Every Total cell of every tab follows quantity × unit cost, before anything is typed (new tabs too).
            $report['total_formulas'] = $only === null ? $totals->fill() : 0;
        } catch (Throwable $e) {
            $this->error('Sheet lines could not be synced: '.$e->getMessage());
            Log::warning('Google sheet sync failed', ['error' => $e->getMessage()]);

            return self::FAILURE;
        }

        $this->line("Added {$report['added']}, updated {$report['updated']}, removed {$report['removed']} draft line(s); {$report['needs_check']} need checking.");
        if ($report['written_back'] + $report['waiting'] > 0) {
            $this->line("Changes from Buildiwick written to the sheet: {$report['written_back']}; sheet changes to saved lines waiting for approval: {$report['waiting']}.");
        }
        $this->line("Locked lines: {$report['locked']}.");
        if ($report['total_formulas']) {
            $this->line("Total formulas filled in: {$report['total_formulas']}.");
        }
        if ($report['appended']) {
            $this->line("Written into the sheet: {$report['appended']} line(s).");
        }
        if ($report['projects']) {
            $this->line('Drafts changed: '.implode(', ', $report['projects']));
        }
        if ($report['missing_tabs']) {
            $this->warn('No tab for: '.implode(', ', $report['missing_tabs']));
        }

        return self::SUCCESS;
    }
}
