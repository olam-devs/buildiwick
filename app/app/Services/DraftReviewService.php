<?php

namespace App\Services;

use App\Models\ExpenseRequest;
use App\Models\Lookup;
use App\Models\Project;
use App\Support\Money;
use Carbon\CarbonImmutable;

/**
 * Checks the lines of a Record Expense form (usually a Data Entry draft filled from the Google sheet) the way
 * they were checked by hand before: wrong or flipped dates, missing prices, unknown units, sums that do not add
 * up and possible doubles. Problems come back grouped, each with the rows it concerns and a note per row.
 *
 * Errors stop saving; warnings are for checking only. A missing price is an error for Data Entry only.
 */
class DraftReviewService
{
    /** @var array<string, array{label: string, severity: string}> in the order shown */
    public const CHECKS = [
        'future_date' => ['label' => 'Date in the future', 'severity' => 'error'],
        'no_price' => ['label' => 'No price', 'severity' => 'error'],
        'unknown_unit' => ['label' => 'Unit not in the list', 'severity' => 'error'],
        'unknown_subcategory' => ['label' => 'Subcategory not in the list', 'severity' => 'error'],
        'error_value' => ['label' => 'Spreadsheet error value (#REF! and similar)', 'severity' => 'error'],
        'no_item' => ['label' => 'No item name', 'severity' => 'error'],
        'no_phase' => ['label' => 'No phase', 'severity' => 'error'],
        'flipped_date' => ['label' => 'Day and month may be swapped', 'severity' => 'warning'],
        'date_order' => ['label' => 'Date out of order with the rows around it', 'severity' => 'warning'],
        'sum_mismatch' => ['label' => 'Quantity × unit cost ≠ total', 'severity' => 'warning'],
        'cross_category' => ['label' => 'Subcategory from another main category', 'severity' => 'warning'],
        'duplicate_in_draft' => ['label' => 'Same date, item and amount twice in this form', 'severity' => 'warning'],
        'possible_double' => ['label' => 'Possible double of a saved line', 'severity' => 'warning'],
    ];

    private const ERROR_VALUE = '/#(REF!|VALUE!|N\/A|DIV\/0!|NAME\?|NUM!|NULL!|ERROR!|SPILL!|CALC!)/i';

    /**
     * @param  array<int, array<string, mixed>>  $rows  form rows, keyed by their position in the form
     * @return array{groups: list<array{code: string, label: string, severity: string, rows: list<array{index: int, note: string}>}>, errors: int, warnings: int}
     */
    public function review(Project $project, array $rows, ?string $headerDate, bool $priceRequired): array
    {
        $today = now()->toDateString();
        $headerDate = $this->validDate($headerDate) ?? $today;
        $units = Lookup::ofType('unit')->pluck('label', 'code');
        $subcategories = Lookup::ofType('subcategory')->get()->keyBy('code');
        $categories = Lookup::ofType('main_category')->pluck('label', 'code');
        $found = [];
        $add = function (string $code, int $index, string $note) use (&$found): void {
            $found[$code][$index] = $note;
        };

        $lines = [];
        foreach ($rows as $index => $row) {
            if (! is_array($row)) {
                continue;
            }
            $value = fn (string $key) => trim((string) ($row[$key] ?? ''));
            if ($value('item_description') === '' && $value('unit_cost') === '' && $value('total_amount') === '') {
                continue;
            }
            $date = $value('expense_date') !== '' ? $this->validDate($value('expense_date')) : $headerDate;
            $lines[$index] = $row + ['_date' => $date];

            foreach ($row as $field => $text) {
                if (is_string($text) && preg_match(self::ERROR_VALUE, $text, $match)) {
                    $add('error_value', $index, "{$match[0]} in ".str_replace('_', ' ', (string) $field));
                    break;
                }
            }
            if ($date === null) {
                $add('future_date', $index, 'The date "'.$value('expense_date').'" is not a date');
            } elseif ($date > $today) {
                $add('future_date', $index, $this->show($date).' is after today');
            }
            $cost = $this->number($value('unit_cost'));
            $total = $this->number($value('total_amount'));
            if (($cost === null || $cost <= 0) && ($total === null || $total <= 0)) {
                $add('no_price', $index, 'No unit cost or total');
            }
            if ($value('unit') !== '' && ! $units->has($value('unit'))) {
                $add('unknown_unit', $index, '"'.$value('unit').'" is not a unit in the system');
            }
            $subcategory = $value('subcategory') !== '' ? $subcategories->get($value('subcategory')) : null;
            if ($value('subcategory') !== '' && ! $subcategory) {
                $add('unknown_subcategory', $index, '"'.$value('subcategory').'" is not a subcategory in the system');
            } elseif ($subcategory && $value('main_category') !== '' && $subcategory->parent_code !== $value('main_category')) {
                $add('cross_category', $index, $subcategory->label.' belongs to '.($categories[$subcategory->parent_code] ?? $subcategory->parent_code).', not '.($categories[$value('main_category')] ?? $value('main_category')));
            }
            if ($value('item_description') === '') {
                $add('no_item', $index, 'The item name is empty');
            }
            if ($value('project_phase_id') === '') {
                $add('no_phase', $index, 'No phase chosen');
            }
            $quantity = $this->number($value('quantity')) ?? 1.0;
            if ($cost !== null && $total !== null && abs($quantity * $cost - $total) > 1) {
                $add('sum_mismatch', $index, rtrim(rtrim(number_format($quantity, 3, '.', ','), '0'), '.').' × '.Money::format((string) $cost).' = '.Money::format((string) round($quantity * $cost, 2)).', but the total is '.Money::format((string) $total));
            }
        }

        $this->checkDates($lines, $today, $add);
        $this->checkDoubles($project, $lines, $add);

        $groups = [];
        $errors = 0;
        $warnings = 0;
        foreach (self::CHECKS as $code => $check) {
            if (empty($found[$code])) {
                continue;
            }
            $severity = $code === 'no_price' && ! $priceRequired ? 'warning' : $check['severity'];
            ksort($found[$code]);
            $groups[] = ['code' => $code, 'label' => $check['label'], 'severity' => $severity,
                'rows' => array_map(fn ($index, $note) => ['index' => (int) $index, 'note' => $note], array_keys($found[$code]), $found[$code])];
            $severity === 'error' ? $errors += count($found[$code]) : $warnings += count($found[$code]);
        }

        return ['groups' => $groups, 'errors' => $errors, 'warnings' => $warnings];
    }

    /**
     * Error messages for the rows that cannot be saved, numbered as the user sees them.
     *
     * @param  array{groups: list<array{severity: string, label: string, rows: list<array{index: int, note: string}>}>}  $review
     * @return list<string>
     */
    public static function blockingMessages(array $review): array
    {
        $messages = [];
        foreach ($review['groups'] as $group) {
            if ($group['severity'] !== 'error') {
                continue;
            }
            foreach ($group['rows'] as $row) {
                $messages[] = 'Item '.($row['index'] + 1).': '.$group['label'].' ('.$row['note'].').';
            }
        }

        return $messages;
    }

    /**
     * Dates Excel turned round (8 Apr for 4 Aug) and dates out of step with the rows around them. Rows are
     * expected roughly in date order, as in the tracker; a date that breaks the order but comes nearer the
     * rows around it once day and month are swapped is shown with the swapped date.
     *
     * @param  array<int, array<string, mixed>>  $lines
     */
    private function checkDates(array $lines, string $today, callable $add): void
    {
        $dated = array_filter(array_map(fn (array $line) => $line['_date'], $lines));
        $indexes = array_keys($dated);
        foreach ($indexes as $position => $index) {
            $date = $dated[$index];
            $previous = $position > 0 ? $dated[$indexes[$position - 1]] : null;
            $next = $position < count($indexes) - 1 ? $dated[$indexes[$position + 1]] : null;
            $swapped = $this->swapped($date);
            $low = $previous !== null && $next !== null ? min($previous, $next) : ($previous ?? $next);
            $high = $previous !== null && $next !== null ? max($previous, $next) : ($previous ?? $next);
            $outOfOrder = $previous !== null && $next !== null && $previous <= $next && ($date < $previous || $date > $next);

            // Swapped, the date lands nearer the rows around it (or is no longer in the future).
            $closer = $outOfOrder && $swapped && $this->distance($swapped, $low, $high) < $this->distance($date, $low, $high);
            if ($swapped && $swapped <= $today && ($date > $today || $closer)) {
                $add('flipped_date', $index, $this->show($date).' may be '.$this->show($swapped));
            } elseif ($outOfOrder) {
                $add('date_order', $index, $this->show($date).' between rows dated '.$this->show($previous).' and '.$this->show($next));
            }
        }
    }

    /**
     * The same line twice in the form, and lines that look like ones already saved on the project: same item
     * and amount, or same amount and subcategory within three days (catches different wording).
     *
     * @param  array<int, array<string, mixed>>  $lines
     */
    private function checkDoubles(Project $project, array $lines, callable $add): void
    {
        $seen = [];
        foreach ($lines as $index => $line) {
            $cents = $this->cents($line);
            if ($cents > 0 && $line['_date']) {
                $seen[$line['_date'].'|'.$this->words((string) ($line['item_description'] ?? '')).'|'.$cents][] = $index;
            }
        }
        foreach ($seen as $indexes) {
            if (count($indexes) > 1) {
                foreach ($indexes as $index) {
                    $add('duplicate_in_draft', $index, 'Same as item '.implode(' and ', array_map(fn ($other) => $other + 1, array_diff($indexes, [$index]))));
                }
            }
        }

        $saved = ExpenseRequest::query()->where('project_id', $project->id)->where('status', '!=', 'rejected')
            ->get(['request_code', 'item_description', 'subcategory', 'total_amount', 'requested_at']);
        if ($saved->isEmpty()) {
            return;
        }
        $byAmount = $saved->groupBy(fn (ExpenseRequest $line) => (int) round((float) $line->total_amount * 100));
        foreach ($lines as $index => $line) {
            $cents = $this->cents($line);
            if ($cents <= 0) {
                continue;
            }
            $words = $this->words((string) ($line['item_description'] ?? ''));
            $match = ($byAmount[$cents] ?? collect())->first(function (ExpenseRequest $saved) use ($line, $words) {
                $days = $line['_date'] && $saved->requested_at ? abs(CarbonImmutable::parse($saved->requested_at)->diffInDays(CarbonImmutable::parse($line['_date']))) : 999;

                return $this->words((string) $saved->item_description) === $words
                    || (filled($line['subcategory'] ?? null) && $saved->subcategory === $line['subcategory'] && $days <= 3);
            });
            if ($match) {
                $add('possible_double', $index, 'Like saved '.$match->request_code.' ('.$match->item_description.', '.$this->show(CarbonImmutable::parse($match->requested_at)->toDateString()).', '.Money::format((string) $match->total_amount).')');
            }
        }
    }

    /** Days from a date to the range [low, high]; 0 inside it. */
    private function distance(string $date, string $low, string $high): int
    {
        $day = CarbonImmutable::parse($date);

        return match (true) {
            $date < $low => (int) $day->diffInDays(CarbonImmutable::parse($low)),
            $date > $high => (int) CarbonImmutable::parse($high)->diffInDays($day),
            default => 0,
        };
    }

    /** @param  array<string, mixed>  $line */
    private function cents(array $line): int
    {
        $total = $this->number(trim((string) ($line['total_amount'] ?? '')));
        if ($total === null) {
            $cost = $this->number(trim((string) ($line['unit_cost'] ?? '')));
            $total = $cost === null ? null : $cost * ($this->number(trim((string) ($line['quantity'] ?? ''))) ?? 1.0);
        }

        return (int) round(($total ?? 0) * 100);
    }

    private function words(string $text): string
    {
        return trim(preg_replace('/[^a-z0-9]+/', ' ', mb_strtolower($text)));
    }

    private function number(string $value): ?float
    {
        $value = str_replace([',', ' '], '', $value);

        return $value !== '' && is_numeric($value) ? (float) $value : null;
    }

    private function validDate(?string $value): ?string
    {
        if (! is_string($value) || ! preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) {
            return null;
        }
        [$year, $month, $day] = array_map('intval', explode('-', $value));

        return checkdate($month, $day, $year) ? $value : null;
    }

    private function swapped(string $date): ?string
    {
        [$year, $month, $day] = array_map('intval', explode('-', $date));
        if ($day > 12 || $day === $month) {
            return null;
        }

        return sprintf('%04d-%02d-%02d', $year, $day, $month);
    }

    private function show(string $date): string
    {
        return CarbonImmutable::parse($date)->format('d M Y');
    }
}
