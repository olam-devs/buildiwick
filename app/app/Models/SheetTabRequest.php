<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A tab Data Entry added to the entry sheet for a project Buildiwick does not have yet. It waits (`pending`) until
 * the Director creates the project from it (`approved`, the tab becomes that project's tab and its lines go into the
 * draft) or marks it as not a project (`rejected`, its lines are never read).
 */
#[Fillable(['sheet_tab_id', 'title', 'project_code', 'location', 'line_count', 'layout_ok', 'status', 'project_id', 'decided_by', 'decided_at', 'tab_ready_at'])]
class SheetTabRequest extends Model
{
    protected function casts(): array
    {
        return [
            'layout_ok' => 'boolean',
            'decided_at' => 'datetime',
            'tab_ready_at' => 'datetime',
        ];
    }

    /** Pattern of a project code (same rule as the New Project form). */
    public const CODE_PATTERN = '/^[A-Z0-9][A-Z0-9-]*$/';

    /** What stops a one-click approval with the code and location she typed in row 1, or null when both are fine. */
    public function detailsProblem(): ?string
    {
        if (! $this->project_code) {
            return 'Type the project code in the yellow cell C1 (e.g. BWG-MSV-2026-001).';
        }
        if (! preg_match(self::CODE_PATTERN, $this->project_code)) {
            return 'Project code "'.$this->project_code.'": use capital letters, numbers and dashes only.';
        }
        if (Project::query()->where('project_code', $this->project_code)->exists()) {
            return 'Project code "'.$this->project_code.'" is already used by another project.';
        }
        if (! $this->location) {
            return 'Type the location in the yellow cell E1.';
        }

        return null;
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function decider(): BelongsTo
    {
        return $this->belongsTo(User::class, 'decided_by');
    }
}
