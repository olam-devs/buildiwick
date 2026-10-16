<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('internal_budgets', function (Blueprint $table) {
            $table->foreignId('project_phase_id')->nullable()->after('project_id')->constrained()->cascadeOnDelete();
            $table->string('main_category')->nullable()->after('project_phase_id');
            $table->dropUnique(['project_id', 'version']);
        });

        DB::table('internal_budgets')->orderBy('id')->get()->each(function ($budget): void {
            $groups = DB::table('internal_budget_entries')->where('internal_budget_id', $budget->id)->get()
                ->groupBy(fn ($entry) => $entry->project_phase_id.'|'.$entry->main_category);
            if ($groups->isEmpty()) {
                $phaseId = DB::table('project_phases')->where('project_id', $budget->project_id)
                    ->orderByRaw("CASE WHEN status = 'active' THEN 0 ELSE 1 END")->orderBy('phase_order')->value('id');
                DB::table('internal_budgets')->where('id', $budget->id)->update(['project_phase_id' => $phaseId, 'main_category' => 'materials']);

                return;
            }

            foreach ($groups->values() as $index => $entries) {
                $phaseId = $entries->first()->project_phase_id;
                $category = $entries->first()->main_category;
                $phase = DB::table('project_phases')->where('id', $phaseId)->first();
                $projectCode = DB::table('projects')->where('id', $budget->project_id)->value('project_code');
                $categoryCode = match ($category) {
                    'labour_subcontracts' => 'LAB',
                    'logistics_equipment' => 'LOG',
                    'preliminaries_overheads' => 'PRE',
                    default => 'MAT',
                };
                $code = $projectCode.'-'.strtoupper($phase?->stage_code ?? 'PH'.$phaseId).'-'.$categoryCode.'-BUD-'.str_pad((string) $budget->version, 3, '0', STR_PAD_LEFT);
                if ($index === 0) {
                    DB::table('internal_budgets')->where('id', $budget->id)->update(['budget_code' => $code, 'project_phase_id' => $phaseId, 'main_category' => $category]);

                    continue;
                }
                $newId = DB::table('internal_budgets')->insertGetId([
                    'budget_code' => $code, 'project_id' => $budget->project_id, 'project_phase_id' => $phaseId, 'main_category' => $category,
                    'version' => $budget->version, 'status' => $budget->status, 'created_by' => $budget->created_by,
                    'approved_by' => $budget->approved_by, 'approved_at' => $budget->approved_at, 'finalized_by' => $budget->finalized_by,
                    'finalized_at' => $budget->finalized_at, 'notes' => $budget->notes, 'created_at' => $budget->created_at, 'updated_at' => $budget->updated_at,
                ]);
                DB::table('internal_budget_entries')->whereIn('id', $entries->pluck('id'))->update(['internal_budget_id' => $newId]);
            }
        });

        Schema::table('internal_budgets', function (Blueprint $table) {
            $table->unique(['project_id', 'project_phase_id', 'main_category', 'version'], 'internal_budget_scope_version_unique');
            $table->index(['project_phase_id', 'main_category', 'status'], 'internal_budget_scope_status_index');
        });
    }

    public function down(): void
    {
        Schema::table('internal_budgets', function (Blueprint $table) {
            $table->dropUnique('internal_budget_scope_version_unique');
            $table->dropIndex('internal_budget_scope_status_index');
            $table->dropConstrainedForeignId('project_phase_id');
            $table->dropColumn('main_category');
        });
    }
};
