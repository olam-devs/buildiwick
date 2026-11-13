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
#[Fillable(['sheet_tab_id', 'title', 'project_name', 'project_code', 'location', 'line_count', 'layout_ok', 'status', 'project_id', 'decided_by', 'decided_at', 'tab_ready_at'])]
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

    /** The project's name: what she typed in row 1, else the tab's name. */
    public function name(): string
    {
        return $this->project_name ?: $this->title;
    }

    /** What stops a one-click approval with the name and location she typed in row 1, or null when both are fine. */
    public function detailsProblem(): ?string
    {
        if (! $this->project_name) {
            return 'Type the project name in the yellow cell C1.';
        }
        if (Project::query()->whereRaw('lower(name) = ?', [mb_strtolower($this->project_name)])->exists()) {
            return 'A project named "'.$this->project_name.'" already exists in Buildiwick.';
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
