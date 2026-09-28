<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Lookup extends Model
{
    protected $fillable = [
        'type', 'code', 'label', 'parent_type', 'parent_code', 'sort_order', 'is_active',
    ];

    protected function casts(): array
    {
        return ['is_active' => 'boolean'];
    }

    public function scopeOfType($query, string $type)
    {
        return $query->where('type', $type)->where('is_active', true)->orderBy('sort_order');
    }

    public function scopeChildrenOf($query, string $parentType, string $parentCode)
    {
        return $query->where('parent_type', $parentType)->where('parent_code', $parentCode);
    }
}
