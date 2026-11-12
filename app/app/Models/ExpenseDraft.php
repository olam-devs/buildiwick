<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** An unsubmitted Record Expense form (header + rows), one per person and project. */
class ExpenseDraft extends Model
{
    protected $fillable = ['user_id', 'project_id', 'data', 'item_count'];

    protected function casts(): array
    {
        return ['data' => 'array'];
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public static function for(User $user, Project $project): ?self
    {
        return self::query()->where('user_id', $user->id)->where('project_id', $project->id)->first();
    }

    /**
     * Merges rows sent by an open form with lines the sheet sync put in the draft meanwhile, so an open page
     * never wipes them: a sheet line the page did not know about is kept, and a newer sheet version of a line
     * (higher sheet_rev) wins over the page's older copy. Lines the page knew and removed stay removed.
     *
     * @param  list<array<string, mixed>>  $incoming
     * @param  list<string>|null  $knownKeys  sheet keys the page loaded; null when the page did not say
     * @param  list<string>  $savedKeys  sheet keys just saved to the ledger
     * @return array{0: list<array<string, mixed>>, 1: int} rows, and how many sheet lines the page was missing
     */
    public static function mergeFromPage(?self $draft, array $incoming, ?array $knownKeys, array $savedKeys = []): array
    {
        $server = collect($draft?->data['rows'] ?? [])->filter(fn ($row) => is_array($row) && filled($row['sheet_key'] ?? null))->keyBy('sheet_key');
        if ($server->isEmpty()) {
            return [$incoming, 0];
        }
        $rows = array_map(function (array $row) use ($server) {
            $newer = $server->get($row['sheet_key'] ?? '');

            return $newer && (int) ($newer['sheet_rev'] ?? 0) > (int) ($row['sheet_rev'] ?? 0) ? $newer : $row;
        }, $incoming);
        $present = array_filter(array_column($incoming, 'sheet_key'));
        $missing = $server->reject(fn ($row, $key) => in_array($key, $present, true) || in_array($key, $savedKeys, true)
            || ($knownKeys !== null && in_array($key, $knownKeys, true)))->values()->all();

        return [[...$rows, ...$missing], count($missing)];
    }

    /**
     * Gives page rows that have no sheet key the key of the identical draft line the sheet sync labelled after the page
     * was opened (same date, item, quantity, unit cost and total), so an open page neither duplicates nor loses it.
     *
     * @param  list<array<string, mixed>>  $rows
     * @param  list<string>  $skipKeys  keys already used elsewhere in the same request
     * @return list<array<string, mixed>>
     */
    public static function adoptSheetKeys(?self $draft, array $rows, array $skipKeys = []): array
    {
        $used = [...$skipKeys, ...array_filter(array_map(fn ($row) => is_array($row) ? ($row['sheet_key'] ?? null) : null, $rows))];
        $server = collect($draft?->data['rows'] ?? [])->filter(fn ($row) => is_array($row) && filled($row['sheet_key'] ?? null));
        if ($server->isEmpty()) {
            return $rows;
        }
        foreach ($rows as $index => $row) {
            if (! is_array($row) || filled($row['sheet_key'] ?? null)) {
                continue;
            }
            $match = $server->first(fn (array $line) => ! in_array($line['sheet_key'], $used, true) && self::signature($line) === self::signature($row));
            if ($match) {
                $rows[$index]['sheet_key'] = $match['sheet_key'];
                $rows[$index]['sheet_rev'] = $match['sheet_rev'] ?? '';
                $used[] = $match['sheet_key'];
            }
        }

        return $rows;
    }

    /** @param  array<string, mixed>  $row */
    private static function signature(array $row): string
    {
        $number = fn (string $key) => is_numeric($value = str_replace(',', '', trim((string) ($row[$key] ?? '')))) ? (string) (float) $value : '';

        return implode('|', [mb_strtolower(trim((string) ($row['item_description'] ?? ''))), trim((string) ($row['expense_date'] ?? '')),
            $number('quantity'), $number('unit_cost'), $number('total_amount')]);
    }

    public static function clear(User $user, Project $project): void
    {
        self::query()->where('user_id', $user->id)->where('project_id', $project->id)->delete();
    }
}
