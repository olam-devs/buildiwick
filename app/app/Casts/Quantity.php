<?php

namespace App\Casts;

use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Illuminate\Database\Eloquent\Model;

/** A quantity with up to 4 decimals (0.652 tonnes of steel), kept and shown without trailing zeros: "30", "0.652". */
class Quantity implements CastsAttributes
{
    public const DECIMALS = 4;

    /** @param  array<string, mixed>  $attributes */
    public function get(Model $model, string $key, mixed $value, array $attributes): ?string
    {
        return self::normal($value);
    }

    /** @param  array<string, mixed>  $attributes */
    public function set(Model $model, string $key, mixed $value, array $attributes): ?string
    {
        return self::normal($value);
    }

    public static function normal(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        return rtrim(rtrim(number_format((float) $value, self::DECIMALS, '.', ''), '0'), '.');
    }
}
