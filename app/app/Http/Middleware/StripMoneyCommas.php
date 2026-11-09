<?php

namespace App\Http\Middleware;

use Illuminate\Foundation\Http\Middleware\TransformsRequest;

/**
 * Money fields show thousands commas in the browser (4,500,000). The page removes them before sending,
 * but an old cached page could still send "4,500,000"; this turns that back into 4500000 for money fields only.
 */
class StripMoneyCommas extends TransformsRequest
{
    /** @var list<string> */
    private const MONEY_FIELDS = [
        'amount', 'total_amount', 'unit_cost', 'unit_price', 'base_unit_cost', 'agreed_value', 'claim_amount', 'commercial_unit_rate',
    ];

    protected function transform($key, $value): mixed
    {
        if (! is_string($value) || ! preg_match('/^\s*\d{1,3}(,\d{3})+(\.\d+)?\s*$/', $value)) {
            return $value;
        }

        $field = str_contains($key, '.') ? substr($key, strrpos($key, '.') + 1) : $key;

        return in_array($field, self::MONEY_FIELDS, true) || str_starts_with($key, 'budget.') ? str_replace(',', '', trim($value)) : $value;
    }
}
