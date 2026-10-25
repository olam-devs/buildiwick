<?php

namespace App\Support;

use Carbon\CarbonImmutable;

/**
 * Named review periods for Director analytics and the matching comparison period
 * (the same span one month, quarter or year earlier).
 */
class ReviewPeriod
{
    public const PRESETS = [
        'this_month' => 'This month',
        'last_month' => 'Last month',
        'last_3_months' => 'Last 3 months',
        'this_quarter' => 'This quarter',
        'last_quarter' => 'Last quarter',
        'this_year' => 'This year',
        'last_year' => 'Last year',
        'custom' => 'Custom dates',
    ];

    /** @return array{name: string, label: string, from: CarbonImmutable, to: CarbonImmutable} */
    public static function resolve(string $name, ?string $from = null, ?string $to = null, ?CarbonImmutable $today = null): array
    {
        $today ??= CarbonImmutable::today();
        $name = array_key_exists($name, self::PRESETS) ? $name : 'this_year';

        [$start, $end] = match ($name) {
            'this_month' => [$today->startOfMonth(), $today],
            'last_month' => [$today->subMonthNoOverflow()->startOfMonth(), $today->subMonthNoOverflow()->endOfMonth()->startOfDay()],
            'last_3_months' => [$today->startOfMonth()->subMonths(2), $today],
            'this_quarter' => [$today->startOfQuarter(), $today],
            'last_quarter' => [$today->subQuarterNoOverflow()->startOfQuarter(), $today->subQuarterNoOverflow()->endOfQuarter()->startOfDay()],
            'last_year' => [$today->subYear()->startOfYear(), $today->subYear()->endOfYear()->startOfDay()],
            'custom' => [CarbonImmutable::parse($from)->startOfDay(), CarbonImmutable::parse($to)->startOfDay()],
            default => [$today->startOfYear(), $today],
        };

        return ['name' => $name, 'label' => self::label($name, $start, $end), 'from' => $start, 'to' => $end];
    }

    /** The comparison period: same span shifted back by the period's natural unit. */
    public static function previous(array $range): array
    {
        [$from, $to] = [$range['from'], $range['to']];
        [$start, $end] = match ($range['name']) {
            'this_month', 'last_month' => [$from->subMonthNoOverflow(), $to->subMonthNoOverflow()],
            'last_3_months' => [$from->subMonths(3), $to->subMonthsNoOverflow(3)],
            'this_quarter', 'last_quarter' => [$from->subQuarterNoOverflow(), $to->subQuarterNoOverflow()],
            'this_year', 'last_year' => [$from->subYear(), $to->subYear()],
            default => [$from->subDays($from->diffInDays($to) + 1), $from->subDay()],
        };
        if (in_array($range['name'], ['last_month', 'last_quarter'], true)) {
            $end = $range['name'] === 'last_month' ? $start->endOfMonth()->startOfDay() : $start->endOfQuarter()->startOfDay();
        }

        return ['name' => 'previous', 'label' => $start->format('d M Y').' – '.$end->format('d M Y'), 'from' => $start, 'to' => $end];
    }

    private static function label(string $name, CarbonImmutable $from, CarbonImmutable $to): string
    {
        return ($name === 'custom' ? '' : self::PRESETS[$name].' · ').$from->format('d M Y').' – '.$to->format('d M Y');
    }
}
