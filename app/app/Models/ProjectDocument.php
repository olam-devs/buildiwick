<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class ProjectDocument extends Model
{
    protected $fillable = ['project_id', 'project_phase_id', 'documentable_type', 'documentable_id', 'document_type', 'file_name', 'file_path', 'mime_type', 'file_size', 'uploaded_by', 'notes', 'uploaded_at'];

    protected function casts(): array
    {
        return ['file_size' => 'integer', 'uploaded_at' => 'datetime'];
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function phase(): BelongsTo
    {
        return $this->belongsTo(ProjectPhase::class, 'project_phase_id');
    }

    public function uploadedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }

    public function documentable(): MorphTo
    {
        return $this->morphTo();
    }
}