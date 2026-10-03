<?php

namespace App\Support;

final class CostEntryOptions
{
    /** @return array<string, list<string>> */
    public static function unitRecommendations(): array
    {
        return [
            'materials' => ['bag', 'kg', 'tonne', 'pc', 'bundle', 'm', 'm2', 'm3', 'l', 'sheet', 'roll', 'box', 'ls'],
            'labour_subcontracts' => ['hour', 'worker_hour', 'day', 'worker_day', 'week', 'job', 'task', 'm2', 'point', 'ls'],
            'logistics_equipment' => ['hour', 'day', 'week', 'trip', 'load', 'km', 'pc', 'ls'],
            'preliminaries_overheads' => ['day', 'week', 'month', 'pc', 'service', 'person', 'ls'],
        ];
    }
}
