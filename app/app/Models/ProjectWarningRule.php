<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** A warning threshold: company default when project_id is null, otherwise a project-specific override. */
class ProjectWarningRule extends Model
{
    protected $fillable = ['project_id', 'rule', 'threshold', 'is_active', 'updated_by'];

    protected function casts(): array
    {
        return ['threshold' => 'decimal:2', 'is_active' => 'boolean'];
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }
}
