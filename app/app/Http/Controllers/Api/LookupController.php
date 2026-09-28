<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Lookup;
use Illuminate\Http\Request;

class LookupController extends Controller
{
    /**
     * GET /lookups              -> every list, grouped by type
     * GET /lookups?type=unit    -> just one list
     * GET /lookups?type=subcategory&parent_code=materials -> one branch of subcategory
     */
    public function index(Request $request)
    {
        $this->authorize('viewAny', Lookup::class);

        $query = Lookup::query()->where('is_active', true)->orderBy('sort_order');

        if ($request->filled('type')) {
            $query->where('type', $request->string('type'));
        }
        if ($request->filled('parent_code')) {
            $query->where('parent_code', $request->string('parent_code'));
        }

        $rows = $query->get(['type', 'code', 'label', 'parent_code']);

        return $request->filled('type')
            ? $rows->values()
            : $rows->groupBy('type')->map(fn ($group) => $group->values());
    }
}
