<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A line typed in the Google entry sheet. State: `draft` (in the Data Entry draft), `saved` (saved to the
 * ledger from the draft) or `removed` (taken out of the draft in Buildiwick without saving).
 */
class SheetLine extends Model
{
    protected $fillable = ['project_id', 'row_key', 'sheet_hash', 'state', 'expense_request_id'];

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function expenseRequest(): BelongsTo
    {
        return $this->belongsTo(ExpenseRequest::class);
    }

    /**
     * Marks sheet lines as saved once their draft rows became ledger lines.
     *
     * @param  array<string, int>  $savedByKey  row key => expense request id
     */
    public static function markSaved(array $savedByKey): void
    {
        foreach ($savedByKey as $key => $requestId) {
            self::query()->where('row_key', $key)->update(['state' => 'saved', 'expense_request_id' => $requestId]);
        }
    }
}
