<?php

namespace App\Services\GoogleSheets;

use Google\Client;
use Google\Service\Drive;
use Google\Service\Sheets;
use RuntimeException;

/**
 * The one place the app talks to Google: signs in as the service account and opens the shared
 * "Buildiwick Project Entries" spreadsheet. Settings live in config('services.google_sheets').
 */
class SheetsClient
{
    private ?Client $client = null;

    public function isConfigured(): bool
    {
        return filled(config('services.google_sheets.key_path')) && filled(config('services.google_sheets.spreadsheet_id'));
    }

    public function spreadsheetId(): string
    {
        $this->assertConfigured();

        return (string) config('services.google_sheets.spreadsheet_id');
    }

    public function sheets(): Sheets
    {
        return new Sheets($this->client());
    }

    public function drive(): Drive
    {
        return new Drive($this->client());
    }

    /**
     * Title, tabs and access of the shared spreadsheet, used to check the connection.
     *
     * @return array{title: string, tabs: list<string>, owners: list<string>, can_edit: bool, can_share: bool}
     */
    public function describe(): array
    {
        $id = $this->spreadsheetId();
        $spreadsheet = $this->sheets()->spreadsheets->get($id, ['fields' => 'properties.title,sheets.properties.title']);
        $file = $this->drive()->files->get($id, ['fields' => 'owners(emailAddress),capabilities(canEdit,canShare)']);

        return [
            'title' => (string) $spreadsheet->getProperties()->getTitle(),
            'tabs' => array_map(fn ($sheet) => (string) $sheet->getProperties()->getTitle(), $spreadsheet->getSheets()),
            'owners' => array_map(fn ($owner) => (string) $owner->getEmailAddress(), $file->getOwners() ?? []),
            'can_edit' => (bool) $file->getCapabilities()?->getCanEdit(),
            'can_share' => (bool) $file->getCapabilities()?->getCanShare(),
        ];
    }

    public function serviceAccountEmail(): string
    {
        $key = json_decode((string) file_get_contents($this->keyPath()), true);

        return (string) ($key['client_email'] ?? '');
    }

    private function client(): Client
    {
        if ($this->client) {
            return $this->client;
        }
        $client = new Client;
        $client->setApplicationName('Buildiwick');
        $client->setAuthConfig($this->keyPath());
        $client->setScopes([Sheets::SPREADSHEETS, Drive::DRIVE]);

        return $this->client = $client;
    }

    private function keyPath(): string
    {
        $this->assertConfigured();
        $path = (string) config('services.google_sheets.key_path');
        if (! is_readable($path)) {
            throw new RuntimeException('The Google service-account key file cannot be read. Check GOOGLE_SHEETS_KEY_PATH.');
        }

        return $path;
    }

    private function assertConfigured(): void
    {
        if (! $this->isConfigured()) {
            throw new RuntimeException('Google Sheets is not set up. Set GOOGLE_SHEETS_KEY_PATH and GOOGLE_SHEETS_SPREADSHEET_ID in .env.');
        }
    }
}
