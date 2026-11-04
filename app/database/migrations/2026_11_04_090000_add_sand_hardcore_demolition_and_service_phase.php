<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /** Subcategories the client asked for: Sand and Hardcore Stones (Materials), Demolition (next to "Other" under Labour & Subcontracts). */
    private const SUBCATEGORIES = [
        ['sand', 'Sand', 'materials', 117],
        ['hardcore_stones', 'Hardcore Stones', 'materials', 118],
        ['demolition', 'Demolition', 'labour_subcontracts', 109],
    ];

    public function up(): void
    {
        foreach (self::SUBCATEGORIES as [$code, $label, $parent, $order]) {
            if (! DB::table('lookups')->where('type', 'subcategory')->where('code', $code)->exists()) {
                DB::table('lookups')->insert([
                    'type' => 'subcategory', 'code' => $code, 'label' => $label, 'parent_type' => 'main_category', 'parent_code' => $parent,
                    'sort_order' => $order, 'is_active' => true, 'created_at' => now(), 'updated_at' => now(),
                ]);
            }
        }

        if (! DB::table('lookups')->where('type', 'construction_stage')->where('code', 'service')->exists()) {
            DB::table('lookups')->insert([
                'type' => 'construction_stage', 'code' => 'service', 'label' => 'Service',
                'sort_order' => 8, 'is_active' => true, 'created_at' => now(), 'updated_at' => now(),
            ]);
        }

        // Every construction project gets a "Service" phase at the end (the company overheads ledger has cost groups, not stages).
        $projects = DB::table('projects')->where(fn ($q) => $q->where('is_company_overheads', false)->orWhereNull('is_company_overheads'))->pluck('id');
        foreach ($projects as $projectId) {
            $phases = DB::table('project_phases')->where('project_id', $projectId);
            if ((clone $phases)->where(fn ($q) => $q->where('stage_code', 'service')->orWhere('phase_name', 'Service'))->exists()) {
                continue;
            }
            DB::table('project_phases')->insert([
                'project_id' => $projectId, 'parent_id' => null, 'stage_code' => 'service', 'phase_name' => 'Service',
                'phase_order' => (int) ((clone $phases)->whereNull('parent_id')->max('phase_order') ?? 0) + 1,
                'status' => (clone $phases)->exists() ? 'not_started' : 'active',
                'created_at' => now(), 'updated_at' => now(),
            ]);
        }
    }

    public function down(): void
    {
        $service = DB::table('project_phases')->where('stage_code', 'service')->where('phase_name', 'Service')->pluck('id');
        $used = DB::table('expense_requests')->whereIn('project_phase_id', $service)->pluck('project_phase_id')->unique();
        DB::table('project_phases')->whereIn('id', $service->diff($used))->delete();
        DB::table('lookups')->where('type', 'construction_stage')->where('code', 'service')->delete();
        foreach (self::SUBCATEGORIES as [$code]) {
            if (! DB::table('expense_requests')->where('subcategory', $code)->exists()) {
                DB::table('lookups')->where('type', 'subcategory')->where('code', $code)->delete();
            }
        }
    }
};
