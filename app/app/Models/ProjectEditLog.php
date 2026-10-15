<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ProjectEditLog extends Model
{
    protected $fillable = [
        'project_id', 'document_type', 'document_id', 'document_code', 'from_version', 'to_version',
        'action', 'item_description', 'reason', 'before_snapshot', 'after_snapshot', 'edited_by',
    ];

    protected function casts(): array
    {
        return [
            'from_version' => 'integer',
            'to_version' => 'integer',
            'before_snapshot' => 'array',
            'after_snapshot' => 'array',
        ];
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function editor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'edited_by');
    }

    public function changeSummary(): string
    {
        if ($this->before_snapshot && $this->after_snapshot) {
            $keys = array_unique(array_merge(array_keys($this->before_snapshot), array_keys($this->after_snapshot)));
            $changes = [];
            foreach ($keys as $key) {
                $before = $this->before_snapshot[$key] ?? null;
                $after = $this->after_snapshot[$key] ?? null;
                if ((string) $before !== (string) $after) {
                    $changes[] = ucwords(str_replace('_', ' ', $key)).': '.($before === null || $before === '' ? '—' : $before).' → '.($after === null || $after === '' ? '—' : $after);
                }
            }

            return implode('; ', $changes) ?: 'Saved without a field-value change';
        }

        return match ($this->action) {
            'edit_started' => 'Version '.$this->from_version.' copied to editable version '.$this->to_version,
            'entry_added' => 'Line added',
            'entry_deleted' => 'Line removed',
            'returned_for_editing' => 'Director unlocked version '.$this->to_version.' for correction',
            default => '—',
        };
    }
}
