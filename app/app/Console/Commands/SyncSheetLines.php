<?php

namespace App\Console\Commands;

use App\Models\Project;
use App\Services\GoogleSheets\ProjectSheetLines;
use App\Services\GoogleSheets\ProjectSheetTabs;
use App\Services\GoogleSheets\SheetsClient;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Throwable;

#[Signature('app:sheets-sync {--project=* : Only these project ids}')]
#[Description('Bring lines typed in the Google entry sheet into the Data Entry drafts (runs every 5 minutes); also gives new projects their tab.')]
class SyncSheetLines extends Command
{
    public function handle(SheetsClient $client, ProjectSheetTabs $tabs, ProjectSheetLines $lines): int
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
            $report = $lines->sync($only);
        } catch (Throwable $e) {
            $this->error('Sheet lines could not be synced: '.$e->getMessage());

            return self::FAILURE;
        }

        $this->line("Added {$report['added']}, updated {$report['updated']}, removed {$report['removed']} draft line(s); {$report['needs_check']} need checking.");
        if ($report['projects']) {
            $this->line('Drafts changed: '.implode(', ', $report['projects']));
        }
        if ($report['missing_tabs']) {
            $this->warn('No tab for: '.implode(', ', $report['missing_tabs']));
        }

        return self::SUCCESS;
    }
}
