<?php

namespace Database\Seeders;

use App\Models\Client;
use App\Models\ClientInvoice;
use App\Models\ClientPayment;
use App\Models\ExpensePayment;
use App\Models\ExpenseRequest;
use App\Models\InternalBudget;
use App\Models\Project;
use App\Models\ProjectEstimate;
use App\Models\ProjectPhase;
use App\Models\SupplierPayee;
use App\Models\SupplierRequestGroup;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * A training project based on the supplied Oysterbay boundary-wall quotation.
 * Supplier, client, bank and payment records remain explicitly fictional DEMO data.
 */
class OysterbayBoundaryWallDemoSeeder extends Seeder
{
    public const PROJECT_CODE = 'BWG-OYS-BW-2026-DEMO';

    public function run(): void
    {
        DB::transaction(function (): void {
            Project::where('project_code', self::PROJECT_CODE)->get()->each->delete();

            $director = User::where('email', 'director@buildiwick.test')->firstOrFail();
            $accounts = User::where('email', 'grace@buildiwick.test')->firstOrFail();
            $estimator = User::where('email', 'twalib@buildiwick.test')->firstOrFail();
            $pm = User::where('email', 'neema@buildiwick.test')->firstOrFail();
            $siteOperator = User::where('email', 'amani@buildiwick.test')->firstOrFail();

            $client = Client::updateOrCreate(
                ['email' => 'oysterbay.boundary.demo@example.test'],
                [
                    'name' => 'Oysterbay Boundary Wall Client — DEMO',
                    'contact_person' => 'Project Representative — DEMO',
                    'phone' => '+255 700 000 410',
                    'address' => 'Chisiza Close, Oysterbay, Dar es Salaam',
                    'notes' => 'Fictional client identity attached to document-derived training data.',
                    'is_active' => true,
                ]
            );

            $project = Project::create([
                'project_code' => self::PROJECT_CODE,
                'name' => 'Oysterbay Boundary Wall — DOCUMENT DEMO',
                'status' => 'active',
                'location' => 'Chisiza Close, Oysterbay, Kinondoni Municipality, Dar es Salaam',
                'client_id' => $client->id,
                'contract_reference' => 'BWK/OYS-BW/2026',
                'contract_signed' => true,
                'contract_signed_at' => today()->subWeeks(6),
                'planned_start_date' => today()->subWeeks(5),
                'expected_completion_date' => today()->addWeek(),
                'notes' => 'DOCUMENT DEMO — quotation transcribed from BWK/OYS-BW/2026; operational records are fictional training data.',
                'created_by' => $director->id,
            ]);
            $project->assignedUsers()->sync([$estimator->id, $pm->id, $siteOperator->id]);

            $phaseDefinitions = [
                ['preliminaries', 'Preliminaries', 1, 'completed', today()->subWeeks(5), today()->subWeeks(4)->subDays(4)],
                ['demolition', 'Demolition', 2, 'completed', today()->subWeeks(4)->subDays(3), today()->subWeeks(4)],
                ['substructure', 'Substructure', 3, 'completed', today()->subWeeks(4), today()->subWeeks(2)->subDays(4)],
                ['superstructure', 'Superstructure', 4, 'completed', today()->subWeeks(2)->subDays(3), today()->subWeek()->subDay()],
                ['plastering', 'Plastering', 5, 'active', today()->subWeek(), null],
                ['electrical_first_fix', 'Electrical First Fix', 6, 'not_started', null, null],
                ['main_gate', 'Main Gate', 7, 'not_started', null, null],
            ];
            $phases = collect();
            foreach ($phaseDefinitions as [$code, $name, $order, $status, $started, $completed]) {
                $phases[$code] = ProjectPhase::create([
                    'project_id' => $project->id,
                    'stage_code' => $code,
                    'phase_name' => $name,
                    'phase_order' => $order,
                    'status' => $status,
                    'started_at' => $started,
                    'completed_at' => $completed,
                    'actual_start_date' => $started,
                    'actual_completion_date' => $completed,
                    'physical_progress_percentage' => $status === 'completed' ? 100 : ($status === 'active' ? 45 : 0),
                    'created_by' => $director->id,
                ]);
            }

            $estimate = ProjectEstimate::create([
                'estimate_code' => 'BWK/OYS-BW/2026',
                'project_id' => $project->id,
                'client_id' => $client->id,
                'version' => 1,
                'status' => 'issued',
                'prepared_at' => '2026-10-10',
                'issued_at' => '2026-10-10 09:00:00',
                'valid_until' => '2026-11-10',
                'prepared_by' => $estimator->id,
                'issued_by' => $estimator->id,
                'notes' => 'Exact scope, quantities, units, published rates and quoted amounts transcribed from the supplied quotation. Base rates are reverse-calculated to retain the standard 15% markup.',
            ]);

            $quotationLines = [
                ['preliminaries', 'A', 'Site establishment and general items', 'Mobilisation, site set up and demobilisation on completion', 1, 'ls', 850000, 850000],
                ['preliminaries', 'B', 'Site establishment and general items', 'Setting out the wall line, column positions and levels', 1, 'ls', 450000, 450000],
                ['preliminaries', 'C', 'Site establishment and general items', 'Water and power for the works', 1, 'ls', 400000, 400000],
                ['preliminaries', 'D', 'Site establishment and general items', 'Tools, scaffolding and protection of adjoining property', 1, 'ls', 750000, 750000],
                ['demolition', 'A', 'Taking down the existing wall', 'Demolish existing boundary wall, including columns, beams, coping and foundations', 169.29, 'm', 10337.29, 1750000],
                ['demolition', 'B', 'Taking down the existing wall', 'Load and cart away demolition debris to an approved tip', 96.83, 'm3', 18000, 1742940],
                ['substructure', 'A', '3.1 Excavation and Earthwork', 'Excavate trench 600 mm wide, 600 mm deep', 60.94, 'm3', 15000, 914100],
                ['substructure', 'B', '3.1 Excavation and Earthwork', 'Return, fill and compact selected excavated material', 21.92, 'm3', 8000, 175360],
                ['substructure', 'C', '3.1 Excavation and Earthwork', 'Load and cart away surplus excavated material', 39.02, 'm3', 18000, 702360],
                ['substructure', 'D', '3.1 Excavation and Earthwork', 'Anti termite treatment to trench bottom', 101.57, 'm2', 4500, 457065],
                ['substructure', 'E', '3.2 Footing, Plinth Wall and Plinth Beam', 'Concrete blinding C10, 50 mm thick', 101.57, 'm2', 14000, 1421980],
                ['substructure', 'F', '3.2 Footing, Plinth Wall and Plinth Beam', 'Concrete C25 strip footing, 600 x 200 mm', 20.31, 'm3', 340000, 6905400],
                ['substructure', 'G', '3.2 Footing, Plinth Wall and Plinth Beam', '230 mm blockwork plinth wall, cores filled with concrete', 54.34, 'm2', 52000, 2825680],
                ['substructure', 'H', '3.2 Footing, Plinth Wall and Plinth Beam', 'Reinforced concrete C25 plinth beam, 230 x 200 mm', 7.14, 'm3', 340000, 2427600],
                ['substructure', 'I', '3.2 Footing, Plinth Wall and Plinth Beam', 'Y12 high yield bars BS 500 to plinth beam', 661, 'kg', 4000, 2644000],
                ['substructure', 'J', '3.2 Footing, Plinth Wall and Plinth Beam', 'R8 links to plinth beam', 233, 'kg', 4000, 932000],
                ['substructure', 'K', '3.2 Footing, Plinth Wall and Plinth Beam', 'Formwork to sides of plinth beam', 62.10, 'm2', 22000, 1366200],
                ['substructure', 'L', '3.2 Footing, Plinth Wall and Plinth Beam', 'Damp proof course on plinth beam', 169.29, 'm', 3500, 592515],
                ['superstructure', 'A', '4.1 Columns and Starters', 'Reinforced concrete C25 columns, 230 x 230 mm', 8.55, 'm3', 340000, 2907000],
                ['superstructure', 'B', '4.1 Columns and Starters', 'Y12 high yield bars BS 500 to columns', 661, 'kg', 4000, 2644000],
                ['superstructure', 'C', '4.1 Columns and Starters', 'R8 links to columns', 296, 'kg', 4000, 1184000],
                ['superstructure', 'D', '4.1 Columns and Starters', 'Formwork to exposed faces of columns', 74.36, 'm2', 22000, 1635920],
                ['superstructure', 'E', '4.2 Walling', '150 mm blockwork in cement sand mortar 1:4, between columns', 326.05, 'm2', 30933, 10085705],
                ['superstructure', 'F', '4.3 Wall Cap', 'Concrete C25 wall cap, 300 x 100 mm, projecting 75 mm each side', 5.08, 'm3', 340000, 1727200],
                ['superstructure', 'G', '4.3 Wall Cap', 'Formwork to edges and soffit of wall cap', 59.25, 'm2', 22000, 1303500],
                ['plastering', 'A', 'Both faces and groove lines', 'Cement sand plaster 1:4, 25 mm thick, to both faces of wall', 778.73, 'm2', 14950, 11642014],
                ['plastering', 'B', 'Both faces and groove lines', 'Cement sand render to top and edges of wall cap', 84.65, 'm2', 14950, 1265518],
                ['plastering', 'C', 'Both faces and groove lines', 'Groove lines formed in plaster, two per face', 677.16, 'm', 2362, 1599452],
                ['electrical_first_fix', 'A', 'Lighting point at each bay', 'Lighting point first fix, back box, conduit drop and 1.5 sq mm wiring', 60, 'no', 55000, 3300000],
                ['electrical_first_fix', 'B', 'Lighting point at each bay', '20 mm PVC conduit chased into wall, with 2.5 sq mm cable and earth', 169.29, 'm', 6900, 1168101],
                ['electrical_first_fix', 'C', 'Lighting point at each bay', 'Circuit from distribution board to the wall run', 1, 'ls', 326899, 326899],
                ['electrical_first_fix', 'D', 'Lighting point at each bay', 'Testing and commissioning', 1, 'ls', 0, 0],
                ['main_gate', 'A', 'Main gate and motor', 'Main gate fabrication and installation', 1, 'ls', 6450000, 6450000],
                ['main_gate', 'B', 'Main gate and motor', 'Gate motor, supplied and installed', 1, 'ls', 4000000, 4000000],
            ];
            foreach ($quotationLines as [$phaseCode, $reference, $section, $description, $quantity, $unit, $quotedRate, $quotedAmount]) {
                $category = $this->estimateCategory($phaseCode, $description);
                $estimate->entries()->create([
                    'project_phase_id' => $phases[$phaseCode]->id,
                    'work_area' => 'Boundary Wall',
                    'work_section' => $section,
                    'item_reference' => $reference,
                    'main_category' => $category,
                    'subcategory' => $this->estimateSubcategory($category, $description),
                    'item_description' => $description,
                    'quantity' => $quantity,
                    'unit' => $unit,
                    'base_unit_cost' => $quotedRate === 0 ? 0 : round($quotedRate / 1.15, 2),
                    'markup_percent' => 15,
                    'client_unit_rate_override' => $quotedRate,
                    'line_total_override' => $quotedAmount,
                    'entered_by' => $estimator->id,
                    'notes' => 'Source quotation rate TSh '.number_format($quotedRate, 2).' · published amount TSh '.number_format($quotedAmount, 2).'.',
                ]);
            }

            $budgetLines = [
                ['timber', 'preliminaries', 'Boundary Wall', 'Site Preparation, Setting Out & Excavation Support', 'Site establishment', 'A', 'Setting out tools', 'materials', 'timber_formwork', 'Mbao 2x4 for profiles and setting out', 40, 'pc', 6000],
                ['tools', 'preliminaries', 'Boundary Wall', 'Site Preparation, Setting Out & Excavation Support', 'Site establishment', 'A', 'Setting out tools', 'logistics_equipment', 'tools_small_equipment', 'Hand tools, line level, rope, buckets and markers', 1, 'ls', 310000],
                ['ppe', 'preliminaries', 'Boundary Wall', 'Site Preparation, Setting Out & Excavation Support', 'Site establishment', 'B', 'PPE', 'preliminaries_overheads', 'ppe_welfare', 'Reflectors and gumboots for site crew', 1, 'ls', 360000],
                ['utilities', 'preliminaries', 'Boundary Wall', 'Site Preparation, Setting Out & Excavation Support', 'Site establishment', 'C', 'Temporary utilities', 'preliminaries_overheads', 'temporary_utilities', 'Temporary water and power', 1, 'ls', 400000],
                ['mobilisation', 'preliminaries', 'Boundary Wall', 'Site Preparation, Setting Out & Excavation Support', 'Site establishment', 'D', 'Mobilisation', 'logistics_equipment', 'transport_handling', 'Mobilisation and demobilisation allowance', 1, 'ls', 780000],
                ['demo_labour', 'demolition', 'Boundary Wall', 'Demolition', 'Existing wall', 'A', 'Demolition labour', 'labour_subcontracts', 'direct_labour', 'Demolition crew and supervision', 1, 'ls', 1350000],
                ['demo_haulage', 'demolition', 'Boundary Wall', 'Demolition', 'Existing wall', 'B', 'Debris haulage', 'logistics_equipment', 'delivery_haulage', 'Load and cart debris to approved tip', 90, 'm3', 18000],
                ['demo_tools', 'demolition', 'Boundary Wall', 'Demolition', 'Existing wall', 'C', 'Tools and PPE', 'logistics_equipment', 'tools_small_equipment', 'Demolition tools and PPE replacements', 1, 'ls', 150000],
                ['cement_sub', 'substructure', 'Boundary Wall', 'Substructure', 'Footing, Plinth Wall & Beam', 'A', 'Concrete and blockwork materials', 'materials', 'concrete_cement', 'Cement for blinding, footing, plinth wall and beam', 190, 'bag', 19500],
                ['sand_sub', 'substructure', 'Boundary Wall', 'Substructure', 'Footing, Plinth Wall & Beam', 'A', 'Concrete and blockwork materials', 'materials', 'aggregates', 'Sand', 16, 'm3', 32000],
                ['aggregate_sub', 'substructure', 'Boundary Wall', 'Substructure', 'Footing, Plinth Wall & Beam', 'A', 'Concrete and blockwork materials', 'materials', 'aggregates', 'Aggregates', 31, 'm3', 110000],
                ['blocks_sub', 'substructure', 'Boundary Wall', 'Substructure', 'Plinth Wall', 'B', 'Foundation wall', 'materials', 'masonry', 'Concrete blocks', 2300, 'no', 1700],
                ['steel_y12_sub', 'substructure', 'Boundary Wall', 'Substructure', 'Plinth Beam', 'C', 'Reinforcement bars', 'materials', 'reinforcement_steel', 'Y12 high yield reinforcement steel', 0.661, 'tonne', 1991525],
                ['steel_r8_sub', 'substructure', 'Boundary Wall', 'Substructure', 'Plinth Beam', 'C', 'Reinforcement bars', 'materials', 'reinforcement_steel', 'R8 reinforcement links', 0.233, 'tonne', 1991525],
                ['formwork_sub', 'substructure', 'Boundary Wall', 'Substructure', 'Plinth Beam', 'D', 'Formwork', 'materials', 'timber_formwork', 'Marine boards and nails', 1, 'ls', 1436000],
                ['dpc_sub', 'substructure', 'Boundary Wall', 'Substructure', 'Plinth Wall', 'E', 'Damp proofing', 'materials', 'waterproofing', 'DPC rolls', 4, 'roll', 80000],
                ['termite_sub', 'substructure', 'Boundary Wall', 'Substructure', 'Earthwork', 'F', 'Soil treatment', 'materials', 'other_materials', 'Termite treatment chemical', 8, 'pc', 15000],
                ['labour_sub', 'substructure', 'Boundary Wall', 'Substructure', 'Footing, Plinth Wall & Beam', 'G', 'Foundation labour', 'labour_subcontracts', 'fundi_labour', 'Excavation, steel fixing, concrete and masonry labour', 1, 'ls', 2600000],
                ['haulage_sub', 'substructure', 'Boundary Wall', 'Substructure', 'Earthwork', 'H', 'Material handling', 'logistics_equipment', 'delivery_haulage', 'Sand, aggregate and surplus-soil haulage', 1, 'ls', 700000],
                ['premix_super', 'superstructure', 'Boundary Wall', 'Superstructure', 'Columns & Wall Cap', 'A', 'Concrete', 'materials', 'concrete_cement', 'Concrete premix C25', 13.63, 'm3', 295000],
                ['steel_y12_super', 'superstructure', 'Boundary Wall', 'Superstructure', 'Columns', 'B', 'Reinforcement bars', 'materials', 'reinforcement_steel', 'Y12 high yield reinforcement steel', 0.661, 'tonne', 1991525],
                ['steel_r8_super', 'superstructure', 'Boundary Wall', 'Superstructure', 'Columns', 'B', 'Reinforcement bars', 'materials', 'reinforcement_steel', 'R8 reinforcement links', 0.296, 'tonne', 1991525],
                ['blocks_super', 'superstructure', 'Boundary Wall', 'Superstructure', 'Walling', 'C', 'Walling', 'materials', 'masonry', '150 mm concrete blocks', 4800, 'no', 1700],
                ['cement_super', 'superstructure', 'Boundary Wall', 'Superstructure', 'Walling', 'C', 'Walling', 'materials', 'concrete_cement', 'Cement for block mortar', 85, 'bag', 19500],
                ['sand_super', 'superstructure', 'Boundary Wall', 'Superstructure', 'Walling', 'C', 'Walling', 'materials', 'aggregates', 'Sand for block mortar', 12, 'm3', 32000],
                ['formwork_super', 'superstructure', 'Boundary Wall', 'Superstructure', 'Columns & Wall Cap', 'D', 'Formwork', 'materials', 'timber_formwork', 'Marine boards', 45, 'pc', 45000],
                ['nails_super', 'superstructure', 'Boundary Wall', 'Superstructure', 'Columns & Wall Cap', 'D', 'Formwork', 'materials', 'timber_formwork', 'Assorted formwork nails', 44, 'kg', 4000],
                ['cement_plaster', 'plastering', 'Main Building', 'Plastering', 'Walls - 15 mm thick, mix 1:4', 'A', 'Ground Floor', 'materials', 'concrete_cement', 'Cement', 135, 'bag', 19500],
                ['sand_plaster', 'plastering', 'Main Building', 'Plastering', 'Walls - 15 mm thick, mix 1:4', 'A', 'Ground Floor', 'materials', 'aggregates', 'Sand', 18, 'm3', 32000],
                ['cement_plaster_first', 'plastering', 'Main Building', 'Plastering', 'Walls - 15 mm thick, mix 1:4', 'B', '1st Floor', 'materials', 'concrete_cement', 'Cement', 150, 'bag', 19500],
                ['sand_plaster_first', 'plastering', 'Main Building', 'Plastering', 'Walls - 15 mm thick, mix 1:4', 'B', '1st Floor', 'materials', 'aggregates', 'Sand', 21, 'm3', 32000],
                ['cement_plaster_terrace', 'plastering', 'Main Building', 'Plastering', 'Walls - 15 mm thick, mix 1:4', 'C', 'Terrace Floor', 'materials', 'concrete_cement', 'Cement', 52, 'bag', 19500],
                ['sand_plaster_terrace', 'plastering', 'Main Building', 'Plastering', 'Walls - 15 mm thick, mix 1:4', 'C', 'Terrace Floor', 'materials', 'aggregates', 'Sand', 7, 'm3', 32000],
                ['labour_plaster', 'plastering', 'Boundary Wall', 'Plastering', 'Both Faces & Wall Cap', 'B', 'Plastering labour', 'labour_subcontracts', 'fundi_labour', 'Plaster, render and groove-line labour', 1, 'ls', 5800000],
                ['scaffold_plaster', 'plastering', 'Boundary Wall', 'Plastering', 'Both Faces & Wall Cap', 'C', 'Access equipment', 'logistics_equipment', 'equipment_hire', 'Mobile scaffolding and protection', 1, 'ls', 450000],
            ];
            $budgetEntries = collect();
            $budgetDocuments = collect();
            foreach ($budgetLines as [$key, $phaseCode, $area, $section, $construction, $packageCode, $packageName, $category, $subcategory, $description, $quantity, $unit, $cost]) {
                $documentKey = $phaseCode.'|'.$category;
                $budget = $budgetDocuments->get($documentKey);
                if (! $budget) {
                    $categoryCode = match ($category) {
                        'labour_subcontracts' => 'LAB', 'logistics_equipment' => 'LOG', 'preliminaries_overheads' => 'PRE', default => 'MAT'
                    };
                    $budget = InternalBudget::create([
                        'budget_code' => 'OYS-BW-'.strtoupper($phaseCode).'-'.$categoryCode.'-BUD-001', 'project_id' => $project->id,
                        'project_phase_id' => $phases[$phaseCode]->id, 'main_category' => $category, 'version' => 1, 'status' => 'approved',
                        'created_by' => $pm->id, 'finalized_by' => $pm->id, 'finalized_at' => now()->subWeeks(5),
                        'approved_by' => $director->id, 'approved_at' => now()->subWeeks(5)->addDay(),
                        'notes' => 'Independent phase and cost-type budget informed by the supplied schedule of materials.',
                    ]);
                    $budgetDocuments->put($documentKey, $budget);
                }
                $budgetEntries[$key] = $budget->entries()->create([
                    'project_phase_id' => $phases[$phaseCode]->id,
                    'work_area' => $area,
                    'work_section' => $section,
                    'construction_category' => $construction,
                    'work_package_code' => $packageCode,
                    'work_package_name' => $packageName,
                    'item_reference' => strtoupper(substr($phaseCode, 0, 3)).'-'.$packageCode.'-'.str_pad((string) ($budgetEntries->count() + 1), 2, '0', STR_PAD_LEFT),
                    'main_category' => $category,
                    'subcategory' => $subcategory,
                    'item_description' => $description,
                    'quantity' => $quantity,
                    'unit' => $unit,
                    'unit_cost' => $cost,
                    'entered_by' => $pm->id,
                    'notes' => 'Document-informed demo internal-budget line.',
                ]);
            }

            $siteSupplier = $this->supplier('Oysterbay Site & Safety Supplies — DEMO', 'Neema Kweka', 'DEMO-OYS-1001');
            $haulageSupplier = $this->supplier('Safisha Demolition & Haulage — DEMO', 'John Mushi', 'DEMO-OYS-2002');
            $materialsSupplier = $this->supplier('Jengo Building Materials — DEMO', 'Rehema Said', 'DEMO-OYS-3003');
            $concreteSupplier = $this->supplier('Imara Ready Mix & Steel — DEMO', 'Peter Mallya', 'DEMO-OYS-4004');

            $groups = [
                'preliminaries' => $this->group($project, 'SG-OYS-PRE-001', 'MR-OYS-PRE-001', $siteSupplier, $pm, 'PPE and site-preparation materials.'),
                'demolition' => $this->group($project, 'SG-OYS-DEM-001', 'MR-OYS-DEM-001', $haulageSupplier, $pm, 'Demolition debris haulage.'),
                'sub_materials' => $this->group($project, 'SG-OYS-SUB-001', 'MR-OYS-SUB-001', $materialsSupplier, $pm, 'Foundation cement, aggregates and blocks.'),
                'sub_steel' => $this->group($project, 'SG-OYS-SUB-002', 'MR-OYS-SUB-001', $concreteSupplier, $pm, 'Foundation reinforcement steel.'),
                'super_materials' => $this->group($project, 'SG-OYS-SUP-001', 'MR-OYS-SUP-001', $materialsSupplier, $pm, 'Superstructure walling materials.'),
                'super_concrete' => $this->group($project, 'SG-OYS-SUP-002', 'MR-OYS-SUP-001', $concreteSupplier, $pm, 'Column and wall-cap concrete.'),
                'plastering' => $this->group($project, 'SG-OYS-PLA-001', 'MR-OYS-PLA-001', $materialsSupplier, $pm, 'Current plastering-phase materials.'),
            ];

            $requestDefinitions = [
                ['ppe_reflectors', 'REQ-OYS-PRE-01', 'preliminaries', 'preliminaries', 'ppe', 'Reflectors for site crew', 20, 'pc', 5000, $groups['preliminaries'], $siteSupplier, 1],
                ['ppe_gumboots', 'REQ-OYS-PRE-02', 'preliminaries', 'preliminaries', 'ppe', 'Gumboots for site crew', 20, 'pc', 13000, $groups['preliminaries'], $siteSupplier, 2],
                ['haulage', 'REQ-OYS-DEM-01', 'demolition', 'demolition', 'demo_haulage', 'Load and cart demolition debris', 60, 'm3', 18000, $groups['demolition'], $haulageSupplier, 1],
                ['cement_foundation', 'REQ-OYS-SUB-01', 'substructure', 'substructure', 'cement_sub', 'Cement for foundation works', 120, 'bag', 19500, $groups['sub_materials'], $materialsSupplier, 1],
                ['aggregate_foundation', 'REQ-OYS-SUB-02', 'substructure', 'substructure', 'aggregate_sub', 'Aggregate for footing and plinth concrete', 20, 'm3', 110000, $groups['sub_materials'], $materialsSupplier, 2],
                ['blocks_foundation', 'REQ-OYS-SUB-03', 'substructure', 'substructure', 'blocks_sub', 'Concrete blocks for plinth wall', 1000, 'no', 1700, $groups['sub_materials'], $materialsSupplier, 3],
                ['steel_foundation', 'REQ-OYS-SUB-04', 'substructure', 'substructure', 'steel_y12_sub', 'Y12 reinforcement steel', 0.45, 'tonne', 1991525, $groups['sub_steel'], $concreteSupplier, 4],
                ['blocks_wall', 'REQ-OYS-SUP-01', 'superstructure', 'superstructure', 'blocks_super', '150 mm blocks for boundary wall', 2000, 'no', 1700, $groups['super_materials'], $materialsSupplier, 1],
                ['premix_columns', 'REQ-OYS-SUP-02', 'superstructure', 'superstructure', 'premix_super', 'C25 ready-mix concrete for columns and wall cap', 8, 'm3', 295000, $groups['super_concrete'], $concreteSupplier, 2],
                ['cement_plaster', 'REQ-OYS-PLA-01', 'plastering', 'plastering', 'cement_plaster', 'Cement for plastering both wall faces', 100, 'bag', 19500, $groups['plastering'], $materialsSupplier, 1],
                ['sand_plaster', 'REQ-OYS-PLA-02', 'plastering', 'plastering', 'sand_plaster', 'Clean sand for plastering', 18, 'm3', 32000, $groups['plastering'], $materialsSupplier, 2],
            ];
            $requests = collect();
            foreach ($requestDefinitions as [$key, $code, $phaseCode, $batchPhase, $budgetKey, $description, $quantity, $unit, $rate, $group, $supplier, $line]) {
                $entry = $budgetEntries[$budgetKey];
                $total = round($quantity * $rate, 2);
                $requestedAt = match ($phaseCode) {
                    'preliminaries' => today()->subWeeks(5),
                    'demolition' => today()->subWeeks(4)->subDays(3),
                    'substructure' => today()->subWeeks(3)->subDays(4),
                    'superstructure' => today()->subWeeks(2),
                    default => today()->subDays(5),
                };
                $requests[$key] = ExpenseRequest::create([
                    'request_code' => $code,
                    'request_batch_code' => $group->request_batch_code,
                    'request_line_number' => $line,
                    'supplier_request_group_id' => $group->id,
                    'procurement_stage' => 'approved',
                    'project_id' => $project->id,
                    'project_phase_id' => $phases[$phaseCode]->id,
                    'work_area' => $entry->work_area,
                    'construction_category' => $entry->construction_category,
                    'work_package_code' => $entry->work_package_code,
                    'work_package_name' => $entry->work_package_name,
                    'internal_budget_entry_id' => $entry->id,
                    'request_type' => $phaseCode === 'demolition' ? 'service' : 'item',
                    'main_category' => $entry->main_category,
                    'subcategory' => $entry->subcategory,
                    'item_description' => $description,
                    'quantity' => $quantity,
                    'unit' => $unit,
                    'supplier_payee_id' => $supplier->id,
                    'commercial_unit_rate' => $rate,
                    'commercial_total' => $total,
                    'commercial_payment_method' => 'bank_transfer',
                    'commercial_payment_destination_id' => $supplier->paymentDestinations->first()->id,
                    'commercial_payment_destination' => 'Demo Commercial Bank · '.$supplier->paymentDestinations->first()->account_wallet_number,
                    'commercial_notes' => 'PM checked quantity, supplier grouping and internal-budget allocation.',
                    'commercial_prepared_by' => $pm->id,
                    'commercial_prepared_at' => $requestedAt->copy()->addDay(),
                    'approved_amount' => $total,
                    'requested_at' => $requestedAt,
                    'submitted_at' => $requestedAt,
                    'verified_by' => $pm->id,
                    'verified_at' => $requestedAt->copy()->addDay(),
                    'status' => 'approved',
                    'requested_by' => $siteOperator->id,
                    'notes' => 'Site Operator classified this item to its work package; PM verified it before supplier grouping.',
                ]);
                $requests[$key]->approvals()->create([
                    'approval_stage' => 'project_manager',
                    'decision' => 'approved',
                    'approved_amount' => $total,
                    'approved_unit_rate' => $rate,
                    'approved_total' => $total,
                    'supplier_payee_id' => $supplier->id,
                    'supplier_name_snapshot' => $supplier->name,
                    'supplier_type_snapshot' => 'supplier',
                    'payment_method_snapshot' => 'bank_transfer',
                    'payment_destination_snapshot' => 'Demo Commercial Bank · '.$supplier->paymentDestinations->first()->account_wallet_number,
                    'decided_by' => $pm->id,
                    'decided_at' => $requestedAt->copy()->addDay(),
                    'notes' => 'Approved by PM and released to Accounts.',
                ]);
            }

            $payments = [
                ['PAY-OYS-001', 'ppe_reflectors', 100000, today()->subWeeks(4)->subDays(4)],
                ['PAY-OYS-002', 'ppe_gumboots', 260000, today()->subWeeks(4)->subDays(4)],
                ['PAY-OYS-003', 'haulage', 1080000, today()->subWeeks(3)->subDays(6)],
                ['PAY-OYS-004', 'cement_foundation', 2340000, today()->subWeeks(3)],
                ['PAY-OYS-005', 'aggregate_foundation', 2200000, today()->subWeeks(2)->subDays(5)],
                ['PAY-OYS-006', 'blocks_foundation', 1700000, today()->subWeeks(2)->subDays(4)],
                ['PAY-OYS-007', 'steel_foundation', 700000, today()->subWeeks(2)->subDays(3)],
                ['PAY-OYS-008', 'blocks_wall', 3400000, today()->subWeek()->subDays(4)],
                ['PAY-OYS-009', 'premix_columns', 1500000, today()->subWeek()->subDays(2)],
            ];
            foreach ($payments as [$code, $requestKey, $amount, $date]) {
                $request = $requests[$requestKey];
                ExpensePayment::create([
                    'payment_code' => $code,
                    'expense_request_id' => $request->id,
                    'project_id' => $project->id,
                    'project_phase_id' => $request->project_phase_id,
                    'amount' => $amount,
                    'payment_date' => $date,
                    'payment_method' => 'bank_transfer',
                    'transaction_reference' => 'DEMO-'.$code,
                    'invoice_receipt_no' => 'SUP-DEMO-'.substr($code, -3),
                    'recorded_by' => $accounts->id,
                    'notes' => $amount < (float) $request->approved_amount ? 'Part payment retained to demonstrate an outstanding payable.' : 'Paid against the approved supplier item.',
                ]);
            }

            $invoice = ClientInvoice::create([
                'invoice_code' => 'INV-OYS-BW-DEMO-001',
                'reference' => 'BWK/OYS-BW/IP-01',
                'project_id' => $project->id,
                'client_id' => $client->id,
                'project_estimate_id' => $estimate->id,
                'status' => 'issued',
                'issued_at' => today()->subWeeks(3),
                'due_at' => today()->subWeek(),
                'created_by' => $accounts->id,
                'issued_by' => $accounts->id,
                'notes' => 'Demo invoice containing completed Preliminaries, Demolition, Substructure and Superstructure quotation items.',
            ]);
            foreach ($estimate->entries()->whereIn('project_phase_id', [
                $phases['preliminaries']->id, $phases['demolition']->id,
                $phases['substructure']->id, $phases['superstructure']->id,
            ])->get() as $entry) {
                $invoice->items()->create([
                    'project_phase_id' => $entry->project_phase_id,
                    'estimate_entry_id' => $entry->id,
                    'work_area' => $entry->work_area,
                    'work_section' => $entry->work_section,
                    'item_reference' => $entry->item_reference,
                    'main_category' => $entry->main_category,
                    'subcategory' => $entry->subcategory,
                    'item_description' => $entry->item_description,
                    'quantity' => $entry->quantity,
                    'unit' => $entry->unit,
                    'unit_price' => $entry->client_unit_cost,
                    'contract_amount' => $entry->line_total,
                    'claim_amount' => $entry->line_total,
                    'notes' => '100% claimed for completed phase.',
                ]);
            }
            ClientPayment::create([
                'payment_code' => 'PAY-OYS-CLIENT-DEMO-001',
                'client_invoice_id' => $invoice->id,
                'project_id' => $project->id,
                'client_id' => $client->id,
                'amount' => 20000000,
                'payment_date' => today()->subWeeks(2),
                'payment_method' => 'bank_transfer',
                'transaction_reference' => 'DEMO-OYS-CLIENT-001',
                'reference' => 'BWK/OYS-BW/IP-01-RECEIPT',
                'recorded_by' => $accounts->id,
                'notes' => 'Demo progress payment that keeps the project cash position positive while retaining an outstanding receivable.',
            ]);
        });
    }

    private function supplier(string $name, string $contact, string $account): SupplierPayee
    {
        $supplier = SupplierPayee::updateOrCreate(['name' => $name], [
            'type' => 'supplier', 'contact_person' => $contact, 'phone' => '+255 700 000 499',
            'email' => str($name)->slug('.').'@example.test', 'address' => 'Dar es Salaam',
            'notes' => 'Fictional supplier for the Oysterbay document demo.', 'is_active' => true,
        ]);
        $supplier->paymentDestinations()->updateOrCreate(
            ['payment_method' => 'bank_transfer', 'account_wallet_number' => $account],
            ['account_wallet_name' => $name, 'bank_provider_name' => 'Demo Commercial Bank', 'branch' => 'Oysterbay', 'notes' => 'Fictional demo account.', 'is_active' => true]
        );

        return $supplier->load('paymentDestinations');
    }

    private function group(Project $project, string $code, string $batch, SupplierPayee $supplier, User $pm, string $notes): SupplierRequestGroup
    {
        return SupplierRequestGroup::create([
            'group_code' => $code, 'project_id' => $project->id, 'request_batch_code' => $batch,
            'supplier_payee_id' => $supplier->id, 'payment_destination_id' => $supplier->paymentDestinations->first()->id,
            'status' => 'approved', 'payment_method' => 'bank_transfer', 'notes' => $notes,
            'created_by' => $pm->id, 'approved_by' => $pm->id, 'approved_at' => now()->subDays(2),
            'decision_notes' => 'Supplier, payment destination and all group items verified.',
        ]);
    }

    private function estimateCategory(string $phase, string $description): string
    {
        if ($phase === 'preliminaries') {
            return 'preliminaries_overheads';
        }
        if ($phase === 'demolition') {
            return str_contains(strtolower($description), 'cart away') ? 'logistics_equipment' : 'labour_subcontracts';
        }
        if (str_contains(strtolower($description), 'testing')) {
            return 'labour_subcontracts';
        }

        return 'materials';
    }

    private function estimateSubcategory(string $category, string $description): string
    {
        $text = strtolower($description);

        if ($category === 'preliminaries_overheads') {
            return str_contains($text, 'water and power') ? 'temporary_utilities' : 'site_office_accommodation';
        }
        if ($category === 'labour_subcontracts') {
            return 'direct_labour';
        }
        if ($category === 'logistics_equipment') {
            return 'delivery_haulage';
        }

        return match (true) {
            str_contains($text, 'bar'), str_contains($text, 'links'), str_contains($text, 'reinforcement') => 'reinforcement_steel',
            str_contains($text, 'blockwork') => 'masonry',
            str_contains($text, 'formwork'), str_contains($text, 'scaffolding') => 'timber_formwork',
            str_contains($text, 'concrete'), str_contains($text, 'plaster'), str_contains($text, 'render') => 'concrete_cement',
            str_contains($text, 'electr'), str_contains($text, 'lighting'), str_contains($text, 'circuit'), str_contains($text, 'conduit') => 'electrical',
            default => 'other_materials',
        };
    }
}
