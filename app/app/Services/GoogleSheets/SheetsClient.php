<?php

namespace App\Services\GoogleSheets;

use Google\Client;
use Google\Service\Drive;
use Google\Service\Sheets;
use Google\Service\Sheets\BatchUpdateSpreadsheetRequest;
use Google\Service\Sheets\BatchUpdateValuesRequest;
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

    /**
     * The spreadsheet's tabs in order.
     *
     * @return list<array{id: int, title: string, rows: int}>
     */
    public function tabs(): array
    {
        $spreadsheet = $this->sheets()->spreadsheets->get($this->spreadsheetId(), ['fields' => 'sheets.properties(sheetId,title,gridProperties.rowCount)']);

        return array_map(fn ($sheet) => ['id' => (int) $sheet->getProperties()->getSheetId(), 'title' => (string) $sheet->getProperties()->getTitle(),
            'rows' => (int) ($sheet->getProperties()->getGridProperties()?->getRowCount() ?? 1000)], $spreadsheet->getSheets());
    }

    /**
     * Applies Sheets API batchUpdate requests (plain arrays in the API's JSON shape) in one call.
     *
     * @param  list<array<string, mixed>>  $requests
     */
    public function batchUpdate(array $requests): void
    {
        if ($requests === []) {
            return;
        }
        $this->sheets()->spreadsheets->batchUpdate($this->spreadsheetId(), new BatchUpdateSpreadsheetRequest(['requests' => $requests]));
    }

    /**
     * Protected ranges with a given description, per tab id: their id, first row, row after the last (0-based) and editors.
     *
     * @return array<int, list<array{id: int, start: int, end: int, editors: list<string>}>>
     */
    public function protectedRanges(string $description): array
    {
        $spreadsheet = $this->sheets()->spreadsheets->get($this->spreadsheetId(), ['fields' => 'sheets(properties.sheetId,protectedRanges(protectedRangeId,description,range,editors.users))']);
        $found = [];
        foreach ($spreadsheet->getSheets() as $sheet) {
            foreach ($sheet->getProtectedRanges() ?? [] as $range) {
                if ($range->getDescription() === $description) {
                    $found[(int) $sheet->getProperties()->getSheetId()][] = ['id' => (int) $range->getProtectedRangeId(),
                        'start' => (int) $range->getRange()->getStartRowIndex(), 'end' => (int) $range->getRange()->getEndRowIndex(),
                        'editors' => array_values($range->getEditors()?->getUsers() ?? [])];
                }
            }
        }

        return $found;
    }

    /**
     * Cell values of several A1 ranges in one call, unformatted (numbers as numbers, dates as day serials).
     *
     * @param  list<string>  $ranges
     * @return list<list<list<mixed>>> one grid per range, in the order asked
     */
    public function readValues(array $ranges): array
    {
        if ($ranges === []) {
            return [];
        }
        $response = $this->sheets()->spreadsheets_values->batchGet($this->spreadsheetId(), [
            'ranges' => $ranges, 'valueRenderOption' => 'UNFORMATTED_VALUE', 'dateTimeRenderOption' => 'SERIAL_NUMBER',
        ]);

        return array_map(fn ($range) => $range->getValues() ?? [], $response->getValueRanges());
    }

    /**
     * Writes plain values into cells, several ranges in one call.
     *
     * @param  array<string, list<list<mixed>>>  $data  A1 range => rows of values
     */
    public function writeValues(array $data): void
    {
        if ($data === []) {
            return;
        }
        $this->sheets()->spreadsheets_values->batchUpdate($this->spreadsheetId(), new BatchUpdateValuesRequest([
            'valueInputOption' => 'RAW',
            'data' => array_map(fn (string $range, array $values) => ['range' => $range, 'values' => $values], array_keys($data), $data),
        ]));
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
