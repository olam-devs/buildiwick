<?php

namespace App\Console\Commands;

use App\Services\GoogleSheets\ProjectSheetTabs;
use App\Services\GoogleSheets\SheetsClient;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Throwable;

#[Signature('app:sheets-tabs {--project=* : Only these project ids}')]
#[Description('Create a tab per open project in the shared Google Sheet and refresh its dropdown lists.')]
class SyncProjectSheetTabs extends Command
{
    public function handle(SheetsClient $client, ProjectSheetTabs $tabs): int
    {
        if (! $client->isConfigured()) {
            $this->error('Google Sheets is not set up. Set GOOGLE_SHEETS_KEY_PATH and GOOGLE_SHEETS_SPREADSHEET_ID in .env.');

            return self::FAILURE;
        }

        $only = $this->option('project') ? array_map('intval', $this->option('project')) : null;
        try {
            $report = $tabs->sync($only);
        } catch (Throwable $e) {
            $this->error('Sheet tabs could not be updated: '.$e->getMessage());

            return self::FAILURE;
        }

        if ($report['guide']) {
            $this->line('Turned Sheet1 into the "How to use" tab.');
        }
        foreach (['created' => 'Created', 'renamed' => 'Renamed', 'refreshed' => 'Lists refreshed'] as $key => $label) {
            $this->line($label.' ('.count($report[$key]).')'.($report[$key] ? ': '.implode(', ', $report[$key]) : ''));
        }

        return self::SUCCESS;
    }
}
