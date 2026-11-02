<?php

use App\Http\Controllers\Web\ProjectSetupController;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

return new class extends Migration
{
    /**
     * A company overheads ledger groups its costs by office cost group (salaries, statutory, rent …), not
     * by construction stage. Ledgers that still have only the untouched construction stages and no
     * expenses or budgets on them get the office cost groups instead. Anything already used is left alone.
     */
    public function up(): void
    {
        foreach (DB::table('projects')->where('is_company_overheads', true)->get(['id', 'created_by']) as $ledger) {
            $phases = DB::table('project_phases')->where('project_id', $ledger->id)->get(['id', 'phase_name', 'parent_id']);
            $names = $phases->pluck('phase_name')->sort()->values()->all();
            $stages = collect(ProjectSetupController::TRACKER_STAGES)->sort()->values()->all();
            $ids = $phases->pluck('id');
            $used = DB::table('expense_requests')->whereIn('project_phase_id', $ids)->exists()
                || DB::table('internal_budgets')->whereIn('project_phase_id', $ids)->exists();
            if ($names !== $stages || $phases->whereNotNull('parent_id')->isNotEmpty() || $used) {
                continue;
            }
            DB::table('project_phases')->whereIn('id', $ids)->delete();
            foreach (ProjectSetupController::OFFICE_COST_GROUPS as $order => $name) {
                DB::table('project_phases')->insert([
                    'project_id' => $ledger->id, 'parent_id' => null, 'stage_code' => Str::slug($name, '_'), 'phase_name' => $name,
                    'phase_order' => $order + 1, 'status' => $order === 0 ? 'active' : 'not_started', 'created_by' => $ledger->created_by,
                    'created_at' => now(), 'updated_at' => now(),
                ]);
            }
        }
    }

    public function down(): void
    {
        // The construction stages are not restored: an overheads ledger should not carry them.
    }
};
