<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** A Director's target for one measure over one period, for the whole company (no project) or a single project. */
class DirectorKpiTarget extends Model
{
    protected $fillable = ['metric', 'project_id', 'project_phase_id', 'main_category', 'period_start', 'period_end', 'target_value', 'notes', 'visible_to_team', 'created_by'];

    protected function casts(): array
    {
        return ['period_start' => 'date', 'period_end' => 'date', 'target_value' => 'decimal:2', 'visible_to_team' => 'boolean'];
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function phase(): BelongsTo
    {
        return $this->belongsTo(ProjectPhase::class, 'project_phase_id');
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
