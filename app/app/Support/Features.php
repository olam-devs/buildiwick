<?php

namespace App\Support;

/** Rollout feature switches from config/buildiwick.php. */
class Features
{
    public static function on(string $feature): bool
    {
        return (bool) config('buildiwick.features.'.$feature, false);
    }

    public static function recording(): bool
    {
        return config('buildiwick.mode') === 'recording';
    }
}
