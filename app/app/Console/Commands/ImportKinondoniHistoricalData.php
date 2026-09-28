<?php

namespace App\Console\Commands;

use App\Models\Project;
use App\Models\Transaction;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

/**
 * One-time import of Kinondoni Residence's 33 historical rows, transcribed
 * directly from Buildiwick_All_Projects_Interim_Tracker_4.xlsx (sheet
 * "Kinondoni Residence", rows 7-39). Every row keeps its original
 * Transaction ID and its real Payment/Data Status — every one of these 33
 * rows is still "To Confirm" / "Needs Confirmation" in the source file,
 * and that stays true here rather than being quietly cleaned up on import.
 *
 * None of these rows carry a Work Area / Stage tag in the source file either
 * (confirmed by scanning the workbook — Consolidated's own "Spend by Stage"
 * section shows the full amount sitting under "Not yet tagged"), so
 * work_area_stage is left null here too, not guessed.
 */
#[Signature('app:import-kinondoni-historical-data')]
#[Description('One-time import of Kinondoni Residence\'s 33 historical rows from the interim Excel tracker.')]
class ImportKinondoniHistoricalData extends Command
{
    public function handle(): int
    {
        $project = Project::where('project_code', 'BWG-KIN-2026-001')->first();

        if (! $project) {
            $this->error('Project BWG-KIN-2026-001 (Kinondoni Residence) not found — run ProjectSeeder first.');

            return self::FAILURE;
        }

        if ($project->transactions()->exists()) {
            $this->warn('Kinondoni Residence already has transactions — skipping to avoid duplicate import.');

            return self::SUCCESS;
        }

        // [code, date, main_category, subcategory, description, qty, unit, unit_cost, total]
        $rows = [
            ['MWN-TXN-0001', '2026-07-23', 'materials', 'plumbing_drainage', 'CHOO', 1, 'pc', 23000, 23000],
            ['MWN-TXN-0002', '2026-07-23', 'materials', 'plumbing_drainage', 'PVC PIPE 4"', 1, 'pc', 11000, 11000],
            ['MWN-TXN-0003', '2026-07-23', 'materials', 'plumbing_drainage', 'TANGIT', 1, 'pc', 4000, 4000],
            ['MWN-TXN-0004', '2026-07-23', 'materials', 'plumbing_drainage', 'PVC ELBOW', 1, 'pc', 3000, 3000],
            ['MWN-TXN-0005', '2026-07-23', 'materials', 'concrete_cement', 'CEMENT', 2, 'bag', 19500, 39000],
            ['MWN-TXN-0006', '2026-07-23', 'materials', 'timber_formwork', 'MBAO 2*4', 36, 'pc', 6000, 216000],
            ['MWN-TXN-0007', '2026-07-23', 'materials', 'timber_formwork', 'NAILS', 3, 'bag', 120000, 360000],
            ['MWN-TXN-0008', '2026-07-23', 'logistics_equipment', 'tools_small_equipment', 'PIPE LEVEL', 1, 'pc', 6000, 6000],
            ['MWN-TXN-0009', '2026-07-23', 'logistics_equipment', 'tools_small_equipment', 'MARK PEN', 3, 'pc', 1000, 3000],
            ['MWN-TXN-0010', '2026-07-23', 'materials', 'other_materials', 'MANILLA ROPE', 14, 'pc', 1000, 14000],
            ['MWN-TXN-0011', '2026-07-23', 'logistics_equipment', 'tools_small_equipment', 'HANDSAW', 2, 'pc', 12000, 24000],
            ['MWN-TXN-0012', '2026-07-23', 'preliminaries_overheads', 'ppe_welfare', 'REFLECTOR', 20, 'pc', 5000, 100000],
            ['MWN-TXN-0013', '2026-07-23', 'preliminaries_overheads', 'ppe_welfare', 'GUMBOOT', 12, 'pc', 13000, 156000],
            ['MWN-TXN-0014', '2026-07-23', 'materials', 'other_materials', 'NDOO', 12, 'pc', 8000, 96000],
            ['MWN-TXN-0015', '2026-07-23', 'logistics_equipment', 'tools_small_equipment', 'PANGA', 1, 'pc', 5000, 5000],
            ['MWN-TXN-0016', '2026-07-23', 'logistics_equipment', 'transport_handling', 'TRANSPORT', 1, 'trip', 100000, 100000],
            ['MWN-TXN-0017', '2026-07-27', 'logistics_equipment', 'tools_small_equipment', 'SURURU', 5, 'pc', 13000, 65000],
            ['MWN-TXN-0018', '2026-07-27', 'logistics_equipment', 'tools_small_equipment', 'MPINI', 5, 'pc', 2000, 10000],
            ['MWN-TXN-0019', '2026-07-28', 'materials', 'concrete_cement', 'CEMENT', 20, 'bag', 18500, 370000],
            ['MWN-TXN-0020', '2026-07-28', 'logistics_equipment', 'tools_small_equipment', 'BAWABA', 2, 'pc', 2000, 4000],
            ['MWN-TXN-0021', '2026-07-28', 'logistics_equipment', 'transport_handling', 'TRANSPORT', 1, 'trip', 20000, 20000],
            ['MWN-TXN-0022', '2026-07-28', 'logistics_equipment', 'tools_small_equipment', 'KOMEO', 1, 'pc', 2500, 2500],
            ['MWN-TXN-0023', '2026-07-28', 'materials', 'aggregates', 'SAND', 4, 'm3', 32500, 130000],
            ['MWN-TXN-0024', '2026-07-28', 'materials', 'aggregates', 'AGGREGATE', 4, 'm3', 115000, 460000],
            ['MWN-TXN-0025', '2026-07-28', 'materials', 'reinforcement_steel', '8mm', 105, 'pc', 9482.14, 995624.70],
            ['MWN-TXN-0026', '2026-07-28', 'materials', 'reinforcement_steel', '12mm', 94, 'pc', 21188.52, 1991720.88],
            ['MWN-TXN-0027', '2026-07-28', 'materials', 'reinforcement_steel', '16mm', 106, 'pc', 37581.26, 3983613.56],
            ['MWN-TXN-0028', '2026-07-28', 'materials', 'tax_on_materials', 'VAT', 1, 'ls', 1252772.68, 1252772.68],
            ['MWN-TXN-0029', '2026-07-28', 'materials', 'reinforcement_steel', 'BINDING WIRE', 3, 'pc', 50000, 150000],
            ['MWN-TXN-0030', '2026-07-29', 'logistics_equipment', 'tools_small_equipment', 'CUTTING DISC', 5, 'pc', 6000, 30000],
            ['MWN-TXN-0031', '2026-07-29', 'materials', 'aggregates', 'AGGREGATE', 20, 'm3', 110000, 2200000],
            ['MWN-TXN-0032', '2026-07-29', 'logistics_equipment', 'transport_handling', 'TRANSPORT', 1, 'trip', 2000, 2000],
            ['MWN-TXN-0033', '2026-07-30', 'logistics_equipment', 'tools_small_equipment', 'CHAIN 8MM', 3, 'pc', 8000, 24000],
        ];

        $note = 'Imported from the interim Excel tracker\'s historical record; supplier, receipt and payment information still needs confirming.';

        foreach ($rows as [$code, $date, $category, $subcategory, $desc, $qty, $unit, $unitCost, $total]) {
            Transaction::create([
                'client_uuid' => (string) Str::uuid(),
                'transaction_code' => $code,
                'project_id' => $project->id,
                'transaction_type' => 'expense',
                'main_category' => $category,
                'subcategory' => $subcategory,
                'item_description' => $desc,
                'quantity' => $qty,
                'unit' => $unit,
                'unit_cost' => $unitCost,
                'total_amount' => $total,
                'amount_paid_received' => 0,
                'payment_status' => 'to_confirm',
                'data_status' => 'needs_confirmation',
                'notes' => $note,
                'transaction_date' => $date,
                'synced_at' => now(),
            ]);
        }

        $this->info('Imported '.count($rows).' historical transactions into Kinondoni Residence.');

        return self::SUCCESS;
    }
}
