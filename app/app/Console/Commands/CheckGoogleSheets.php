<?php

namespace App\Console\Commands;

use App\Services\GoogleSheets\SheetsClient;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Throwable;

#[Signature('app:sheets-check')]
#[Description('Check that the app can open the shared Google Sheet as its service account.')]
class CheckGoogleSheets extends Command
{
    public function handle(SheetsClient $sheets): int
    {
        if (! $sheets->isConfigured()) {
            $this->error('Google Sheets is not set up. Set GOOGLE_SHEETS_KEY_PATH and GOOGLE_SHEETS_SPREADSHEET_ID in .env.');

            return self::FAILURE;
        }

        try {
            $info = $sheets->describe();
            $account = $sheets->serviceAccountEmail();
        } catch (Throwable $e) {
            $this->error('Could not open the Google Sheet: '.$e->getMessage());

            return self::FAILURE;
        }

        $this->info("Connected to \"{$info['title']}\" as {$account}.");
        $this->line('Owner: '.(implode(', ', $info['owners']) ?: 'unknown'));
        $this->line('Tabs: '.implode(', ', $info['tabs']));
        $this->line('Service account can edit: '.($info['can_edit'] ? 'yes' : 'NO'));
        $this->line('Editors can re-share: '.($info['can_share'] ? 'yes (turn off "Editors can change permissions and share")' : 'no'));

        return $info['can_edit'] ? self::SUCCESS : self::FAILURE;
    }
}
