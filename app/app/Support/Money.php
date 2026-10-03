<?php

namespace App\Support;

use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;

final class Money
{
    public static function decimal(mixed $value): BigDecimal
    {
        return BigDecimal::of((string) $value)->toScale(2, RoundingMode::HalfUp);
    }

    /** @param iterable<mixed> $values */
    public static function sum(iterable $values): string
    {
        $total = BigDecimal::zero();
        foreach ($values as $value) {
            $total = $total->plus(self::decimal($value));
        }

        return $total->toScale(2, RoundingMode::HalfUp)->__toString();
    }

    public static function product(mixed $left, mixed $right): string
    {
        return self::decimal($left)
            ->multipliedBy(BigDecimal::of((string) $right))
            ->toScale(2, RoundingMode::HalfUp)
            ->__toString();
    }

    public static function difference(mixed $left, mixed $right): string
    {
        return self::decimal($left)->minus(self::decimal($right))
            ->toScale(2, RoundingMode::HalfUp)->__toString();
    }

    public static function compare(mixed $left, mixed $right): int
    {
        return self::decimal($left)->compareTo(self::decimal($right));
    }

    public static function format(mixed $amount, int $scale = 2): string
    {
        $value = BigDecimal::of((string) $amount)->toScale($scale, RoundingMode::HalfUp)->__toString();
        $negative = str_starts_with($value, '-');
        $unsigned = $negative ? substr($value, 1) : $value;
        [$whole, $fraction] = array_pad(explode('.', $unsigned, 2), 2, '');
        $whole = preg_replace('/\B(?=(\d{3})+(?!\d))/', ',', $whole) ?? $whole;

        return ($negative ? '-' : '').$whole.($scale > 0 ? '.'.str_pad($fraction, $scale, '0') : '');
    }

    public static function percentage(mixed $numerator, mixed $denominator): string
    {
        $denominator = self::decimal($denominator);
        if ($denominator->isZero()) {
            return '0.00';
        }

        return self::decimal($numerator)
            ->multipliedBy('100')
            ->dividedBy($denominator, 2, RoundingMode::HalfUp)
            ->toScale(2, RoundingMode::HalfUp)
            ->__toString();
    }

    public static function estimateClientUnitCost(mixed $baseUnitCost, mixed $markupPercent): string
    {
        $markupMultiplier = BigDecimal::of('100')->plus((string) $markupPercent);

        return self::decimal($baseUnitCost)
            ->multipliedBy($markupMultiplier)
            ->dividedBy('100', 2, RoundingMode::HalfUp)
            ->toScale(2, RoundingMode::HalfUp)
            ->__toString();
    }
}