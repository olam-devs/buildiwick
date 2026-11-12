<?php

namespace Tests\Feature;

use Tests\TestCase;

class GoogleSheetsCheckTest extends TestCase
{
    public function test_sheets_check_fails_clearly_when_google_is_not_set_up(): void
    {
        config(['services.google_sheets.key_path' => null, 'services.google_sheets.spreadsheet_id' => null]);

        $this->artisan('app:sheets-check')
            ->expectsOutputToContain('Google Sheets is not set up')
            ->assertFailed();
    }

    public function test_sheets_check_fails_clearly_when_the_key_file_is_missing(): void
    {
        config(['services.google_sheets.key_path' => storage_path('framework/testing/missing-key.json'), 'services.google_sheets.spreadsheet_id' => 'sheet-id']);

        $this->artisan('app:sheets-check')
            ->expectsOutputToContain('key file cannot be read')
            ->assertFailed();
    }
}
