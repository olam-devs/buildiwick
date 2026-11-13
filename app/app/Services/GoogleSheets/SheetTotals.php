<?php

namespace App\Services\GoogleSheets;

use App\Models\SheetTabRequest;

/**
 * Keeps the Total column of every Buildiwick tab a "quantity × unit cost" formula, so changing Quantity or Unit Cost
 * updates the Total at once, in every row: project tabs, the "+ New project" template (copies inherit it) and new
 * tabs still waiting for approval.
 *
 * Per row from row 3: an empty Total gets the formula; a Total that already equals quantity × unit cost (typed or
 * pasted) becomes the formula; a Total typed on its own (no unit cost, or a different amount) and any formula already
 * there are left alone. Only tabs whose row 2 has the Buildiwick headers are touched; rejected tabs never.
 */
class SheetTotals
{
    public function __construct(private SheetsClient $client) {}

    /** Formula for one sheet row (quantity 1 when empty, blank while there is no unit cost). */
    public static function formula(int $row): string
    {
        $letter = fn (string $key) => chr(ord('A') + ProjectSheetLayout::column($key));
        [$q, $c] = [$letter('quantity').$row, $letter('unit_cost').$row];

        return "=IF({$c}=\"\",\"\",ROUND(IF({$q}=\"\",1,{$q})*{$c},2))";
    }

    /** @return int Total cells turned into the formula */
    public function fill(): int
    {
        $rejected = SheetTabRequest::query()->where('status', 'rejected')->pluck('sheet_tab_id')->map(fn ($id) => (int) $id)->all();
        $tabs = collect($this->client->tabs())->reject(fn (array $tab) => $tab['title'] === 'How to use' || in_array($tab['id'], $rejected, true))->values();
        if ($tabs->isEmpty()) {
            return 0;
        }
        [$qty, $cost, $total] = [ProjectSheetLayout::column('quantity'), ProjectSheetLayout::column('unit_cost'), ProjectSheetLayout::column('total_amount')];
        $first = chr(ord('A') + $qty);
        $lastLetter = chr(ord('A') + $total);
        $headers = array_column(array_slice(ProjectSheetLayout::COLUMNS, $qty, $total - $qty + 1), 'header');
        // Row 2 (headers) is read too, to make sure the tab has the Buildiwick layout.
        $grids = $this->client->readValues($tabs->map(fn (array $tab) => self::quote($tab['title'])."!{$first}2:{$lastLetter}".(int) ($tab['rows'] ?? ProjectSheetLayout::ROWS))->all(), 'FORMULA');

        $writes = [];
        $count = 0;
        foreach ($tabs as $index => $tab) {
            $grid = $grids[$index] ?? [];
            if (array_map(fn ($value) => trim((string) $value), array_pad(array_slice($grid[0] ?? [], 0, count($headers)), count($headers), '')) !== $headers) {
                continue;
            }
            $run = [];
            $lastRow = (int) ($tab['rows'] ?? ProjectSheetLayout::ROWS);
            for ($row = ProjectSheetLayout::FIRST_LINE + 1; $row <= $lastRow; $row++) {
                $cells = $grid[$row - 2] ?? [];
                if ($this->wantsFormula($cells, $total - $qty, 0, $cost - $qty)) {
                    $run[$row] = [self::formula($row)];
                    $count++;
                } elseif ($run !== []) {
                    $this->flush($writes, $tab['title'], $run, $lastLetter);
                }
            }
            $this->flush($writes, $tab['title'], $run, $lastLetter);
        }
        $this->client->writeValues($writes, 'USER_ENTERED');

        return $count;
    }

    /** @param  list<mixed>  $cells  quantity … total of one row, as entered (formulas as text) */
    private function wantsFormula(array $cells, int $totalAt, int $qtyAt, int $costAt): bool
    {
        $total = $cells[$totalAt] ?? '';
        if (is_string($total) && str_starts_with(trim($total), '=')) {
            return false;
        }
        if (trim((string) $total) === '') {
            return true;
        }
        $number = fn ($value) => is_numeric($value) ? (float) $value : (is_numeric($clean = str_replace(',', '', trim((string) $value))) ? (float) $clean : null);
        $quantity = trim((string) ($cells[$qtyAt] ?? '')) === '' ? 1.0 : $number($cells[$qtyAt]);
        $cost = $number($cells[$costAt] ?? '');
        $typed = $number($total);

        return $quantity !== null && $cost !== null && $typed !== null && round($quantity * $cost, 2) === round($typed, 2);
    }

    /**
     * @param  array<string, list<list<string>>>  $writes
     * @param  array<int, list<string>>  $run  consecutive sheet rows => [formula]
     */
    private function flush(array &$writes, string $title, array &$run, string $column): void
    {
        if ($run === []) {
            return;
        }
        $rows = array_keys($run);
        $writes[self::quote($title)."!{$column}".min($rows).":{$column}".max($rows)] = array_values($run);
        $run = [];
    }

    private static function quote(string $title): string
    {
        return "'".str_replace("'", "''", $title)."'";
    }
}
