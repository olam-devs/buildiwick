<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** An unsubmitted Record Expense form (header + rows), one per person and project. */
class ExpenseDraft extends Model
{
    protected $fillable = ['user_id', 'project_id', 'data', 'item_count'];

    protected function casts(): array
    {
        return ['data' => 'array'];
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public static function for(User $user, Project $project): ?self
    {
        return self::query()->where('user_id', $user->id)->where('project_id', $project->id)->first();
    }

    public static function clear(User $user, Project $project): void
    {
        self::query()->where('user_id', $user->id)->where('project_id', $project->id)->delete();
    }
}
