<?php

namespace Database\Seeders;

use App\Models\Client;
use App\Models\ClientInvoice;
use App\Models\ClientPayment;
use App\Models\ExpensePayment;
use App\Models\ExpenseRequest;
use App\Models\InternalBudget;
use App\Models\LabourAgreement;
use App\Models\Project;
use App\Models\ProjectEstimate;
use App\Models\ProjectPhase;
use App\Models\SupplierPayee;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * Illustrative training data only. Every name, amount, contact and account in
 * this seeder is fictional and explicitly labelled DEMO in user-facing data.
 */
class DemoProjectSeeder extends Seeder
{
    public const PROJECT_CODE = 'BWG-DEMO-2026-001';

    public function run(): void
    {
        DB::transaction(function (): void {
            $director = User::where('email', 'director@buildiwick.test')->firstOrFail();
            $accounts = User::where('email', 'grace@buildiwick.test')->firstOrFail();
            $estimator = User::where('email', 'twalib@buildiwick.test')->firstOrFail();
            $pm = User::where('email', 'neema@buildiwick.test')->firstOrFail();
            $siteOperator = User::where('email', 'amani@buildiwick.test')->firstOrFail();

            $client = Client::updateOrCreate(
                ['email' => 'asha.msuya@example.test'],
                [
                    'name' => 'Asha & Karim Msuya',
                    'contact_person' => 'Asha Msuya',
                    'phone' => '+255 700 000 101',
                    'address' => 'Mbezi Beach, Dar es Salaam',
                    'notes' => 'Demo client for the complete Buildiwick MVP walkthrough.',
                    'is_active' => true,
                ]
            );

            $project = Project::updateOrCreate(
                ['project_code' => self::PROJECT_CODE],
                [
                    'name' => 'Bahari View Family Villa — DEMO',
                    'status' => 'active',
                    'location' => 'Mbezi Beach, Dar es Salaam',
                    'client_id' => $client->id,
                    'contract_reference' => 'BVC/RES/2026/014',
                    'contract_signed' => true,
                    'contract_signed_at' => today()->subMonths(2),
                    'planned_start_date' => today()->subWeeks(6),
                    'expected_completion_date' => today()->addMonths(8),
                    'notes' => 'DEMO PROJECT — safe for training and continued role-by-role testing.',
                    'created_by' => $director->id,
                ]
            );
            $project->assignedUsers()->syncWithoutDetaching([$estimator->id, $pm->id, $siteOperator->id]);

            $phaseDefinitions = [
                ['preliminaries', 'Site Preparation & Preliminaries', 1, 'completed', today()->subWeeks(6), today()->subWeeks(4)],
                ['substructure', 'Substructure', 2, 'active', today()->subWeeks(4), null],
                ['superstructure', 'Ground Floor & Upper Structure', 3, 'not_started', null, null],
                ['walling', 'Walling & Openings', 4, 'not_started', null, null],
                ['roofing', 'Roof Structure & Covering', 5, 'not_started', null, null],
                ['finishing', 'Finishes & Building Services', 6, 'not_started', null, null],
                ['external_works', 'External Works', 7, 'not_started', null, null],
            ];
            $phases = collect();
            foreach ($phaseDefinitions as [$stage, $phaseName, $order, $status, $startedAt, $completedAt]) {
                $phases[$stage] = ProjectPhase::updateOrCreate(
                    ['project_id' => $project->id, 'stage_code' => $stage],
                    [
                        'phase_order' => $order,
                        'phase_name' => $phaseName,
                        'status' => $status,
                        'started_at' => $startedAt,
                        'completed_at' => $completedAt,
                        'created_by' => $director->id,
                    ]
                );
            }

            $estimate = ProjectEstimate::updateOrCreate(
                ['estimate_code' => 'EST-DEMO-2026-001'],
                [
                    'project_id' => $project->id,
                    'client_id' => $client->id,
                    'version' => 1,
                    'status' => 'issued',
                    'prepared_at' => today()->subMonths(2),
                    'issued_at' => now()->subMonths(2)->addDays(2),
                    'valid_until' => today()->addMonths(2),
                    'prepared_by' => $estimator->id,
                    'issued_by' => $estimator->id,
                    'notes' => 'Issued demo client estimate with Buildiwick’s usual 15% markup.',
                ]
            );
            $estimateEntries = [
                ['preliminaries', 'preliminaries_overheads', 'site_office_accommodation', 'Site mobilisation, temporary office and storage', 1, 'ls', 4700000, 15],
                ['substructure', 'materials', 'concrete_cement', 'Cement 42.5R for foundations', 650, 'bag', 18500, 15],
                ['substructure', 'materials', 'reinforcement_steel', 'High-yield reinforcement steel', 4.5, 'tonne', 2600000, 15],
                ['substructure', 'materials', 'aggregates', 'Sand and aggregate deliveries', 18, 'trip', 420000, 15],
                ['substructure', 'labour_subcontracts', 'fundi_labour', 'Foundation excavation, steel fixing and concrete labour', 1, 'ls', 11000000, 15],
                ['substructure', 'logistics_equipment', 'plant_machinery_hire', 'Excavator hire', 5, 'day', 750000, 15],
                ['superstructure', 'materials', 'reinforcement_steel', 'Structural frame concrete and reinforcement package', 1, 'ls', 42000000, 15],
                ['walling', 'materials', 'masonry', 'Blocks, mortar and lintels', 1, 'ls', 28000000, 15],
                ['roofing', 'materials', 'timber_formwork', 'Roof structure and covering', 1, 'ls', 32000000, 15],
                ['finishing', 'materials', 'finishes', 'Floor, wall, ceiling and paint finishes', 1, 'ls', 54000000, 15],
                ['finishing', 'materials', 'plumbing_drainage', 'Plumbing and sanitary fixtures', 1, 'ls', 18000000, 15],
                ['finishing', 'materials', 'electrical', 'Electrical installation and fittings', 1, 'ls', 16000000, 15],
                ['external_works', 'labour_subcontracts', 'subcontractor', 'Boundary wall, drainage and landscaping', 1, 'ls', 22000000, 15],
            ];
            $sectionNames = ['materials' => 'Materials Schedule', 'labour_subcontracts' => 'Labour & Subcontracts', 'logistics_equipment' => 'Plant & Equipment', 'preliminaries_overheads' => 'Preliminaries & Site Overheads'];
            $estimateLine = 0;
            foreach ($estimateEntries as [$phase, $category, $subcategory, $description, $quantity, $unit, $cost, $markup]) {
                $estimateLine++;
                $estimate->entries()->updateOrCreate(
                    ['project_phase_id' => $phases[$phase]->id, 'item_description' => $description],
                    [
                        'work_area' => 'Main Building',
                        'work_section' => $sectionNames[$category],
                        'item_reference' => 'E-'.str_pad((string) $estimateLine, 3, '0', STR_PAD_LEFT),
                        'main_category' => $category,
                        'subcategory' => $subcategory,
                        'quantity' => $quantity,
                        'unit' => $unit,
                        'base_unit_cost' => $cost,
                        'markup_percent' => $markup,
                        'entered_by' => $estimator->id,
                        'notes' => 'Demo estimate line.',
                    ]
                );
            }

            $budget = InternalBudget::updateOrCreate(
                ['budget_code' => 'IB-DEMO-2026-001-V1'],
                [
                    'project_id' => $project->id,
                    'version' => 1,
                    'status' => 'approved',
                    'created_by' => $pm->id,
                    'finalized_by' => $pm->id,
                    'finalized_at' => now()->subWeeks(5),
                    'approved_by' => $director->id,
                    'approved_at' => now()->subWeeks(5)->addDay(),
                    'notes' => 'PM operational baseline verified by the Director for the demo project.',
                ]
            );
            $budgetDefinitions = [
                'mobilisation' => ['preliminaries', 'preliminaries_overheads', 'site_office_accommodation', 'Site mobilisation and temporary facilities', 1, 'ls', 4500000],
                'cement' => ['substructure', 'materials', 'concrete_cement', 'Cement 42.5R — foundation allocation', 650, 'bag', 18000],
                'steel' => ['substructure', 'materials', 'reinforcement_steel', 'Reinforcement steel — foundation allocation', 4.5, 'tonne', 2500000],
                'aggregate' => ['substructure', 'materials', 'aggregates', 'Sand and aggregate — foundation allocation', 18, 'trip', 400000],
                'foundation_labour' => ['substructure', 'labour_subcontracts', 'fundi_labour', 'Foundation labour package', 1, 'ls', 10500000],
                'excavator' => ['substructure', 'logistics_equipment', 'plant_machinery_hire', 'Excavator and compactor hire', 5, 'day', 700000],
                'temporary_utilities' => ['substructure', 'preliminaries_overheads', 'temporary_utilities', 'Temporary water and electricity', 1, 'ls', 2000000],
                'superstructure' => ['superstructure', 'materials', 'reinforcement_steel', 'Structural frame materials', 1, 'ls', 40000000],
                'walling' => ['walling', 'materials', 'masonry', 'Walling materials', 1, 'ls', 26500000],
                'roofing' => ['roofing', 'materials', 'timber_formwork', 'Roofing materials and installation', 1, 'ls', 30000000],
                'finishes' => ['finishing', 'materials', 'finishes', 'Architectural finishes', 1, 'ls', 50000000],
                'services' => ['finishing', 'labour_subcontracts', 'subcontractor', 'Plumbing and electrical subcontract works', 1, 'ls', 31000000],
                'external' => ['external_works', 'labour_subcontracts', 'subcontractor', 'External works package', 1, 'ls', 20000000],
            ];
            $budgetEntries = collect();
            $budgetLine = 0;
            foreach ($budgetDefinitions as $key => [$phase, $category, $subcategory, $description, $quantity, $unit, $cost]) {
                $budgetLine++;
                $budgetEntries[$key] = $budget->entries()->updateOrCreate(
                    ['project_phase_id' => $phases[$phase]->id, 'item_description' => $description],
                    [
                        'work_area' => 'Main Building',
                        'work_section' => $sectionNames[$category],
                        'item_reference' => 'B-'.str_pad((string) $budgetLine, 3, '0', STR_PAD_LEFT),
                        'main_category' => $category,
                        'subcategory' => $subcategory,
                        'quantity' => $quantity,
                        'unit' => $unit,
                        'unit_cost' => $cost,
                        'entered_by' => $pm->id,
                        'notes' => 'Demo internal-budget line.',
                    ]
                );
            }

            $materialsSupplier = SupplierPayee::updateOrCreate(
                ['name' => 'Jenga Bora Building Supplies — DEMO'],
                [
                    'type' => 'supplier',
                    'contact_person' => 'Rehema Hassan',
                    'phone' => '+255 700 000 201',
                    'email' => 'sales@jengabora.example.test',
                    'address' => 'Tegeta, Dar es Salaam',
                    'notes' => 'Demo supplier; all contact and banking details are fictional.',
                    'is_active' => true,
                ]
            );
            $materialsDestination = $materialsSupplier->paymentDestinations()->updateOrCreate(
                ['payment_method' => 'bank_transfer', 'account_wallet_number' => 'DEMO-001-445566'],
                [
                    'account_wallet_name' => 'Jenga Bora Building Supplies',
                    'bank_provider_name' => 'Demo Commercial Bank',
                    'branch' => 'Mbezi',
                    'notes' => 'Fictional demo account.',
                    'is_active' => true,
                ]
            );
            $plantSupplier = SupplierPayee::updateOrCreate(
                ['name' => 'Kisima Plant Hire — DEMO'],
                [
                    'type' => 'supplier',
                    'contact_person' => 'Daudi Mrema',
                    'phone' => '+255 700 000 202',
                    'email' => 'hire@kisimaplant.example.test',
                    'address' => 'Bunju, Dar es Salaam',
                    'notes' => 'Demo equipment supplier.',
                    'is_active' => true,
                ]
            );
            $plantDestination = $plantSupplier->paymentDestinations()->updateOrCreate(
                ['payment_method' => 'bank_transfer', 'account_wallet_number' => 'DEMO-002-778899'],
                [
                    'account_wallet_name' => 'Kisima Plant Hire',
                    'bank_provider_name' => 'Demo Commercial Bank',
                    'branch' => 'Bunju',
                    'notes' => 'Fictional demo account.',
                    'is_active' => true,
                ]
            );

            $agreement = LabourAgreement::updateOrCreate(
                ['agreement_code' => 'AGR-DEMO-FOUND-001'],
                [
                    'project_id' => $project->id,
                    'fundi_subcontractor' => 'Mussa Juma Foundation Crew — DEMO',
                    'scope_of_work' => 'Excavation support, blinding, reinforcement fixing and foundation concrete labour.',
                    'labour_type' => 'fundi_labour',
                    'agreement_date' => today()->subWeeks(5),
                    'agreed_value' => 10500000,
                    'start_date' => today()->subWeeks(4),
                    'expected_completion' => today()->addWeeks(2),
                    'contact' => '+255 700 000 301',
                    'status' => 'active',
                    'created_by' => $pm->id,
                ]
            );

            $cement = $this->request([
                'request_code' => 'REQ-DEMO-001-01', 'request_batch_code' => 'SRQ-DEMO-001', 'request_line_number' => 1,
                'project_id' => $project->id, 'project_phase_id' => $phases['substructure']->id,
                'internal_budget_entry_id' => $budgetEntries['cement']->id, 'request_type' => 'item',
                'main_category' => 'materials', 'subcategory' => 'concrete_cement',
                'item_description' => 'Cement 42.5R — first foundation delivery', 'quantity' => 100, 'unit' => 'bag',
                'requested_amount' => null, 'supplier_fundi_client' => null,
                'supplier_payee_id' => $materialsSupplier->id, 'commercial_unit_rate' => 18000,
                'commercial_total' => 1800000, 'commercial_payment_method' => 'bank_transfer',
                'commercial_payment_destination' => 'Demo Commercial Bank · DEMO-001-445566',
                'commercial_payment_destination_id' => $materialsDestination->id,
                'commercial_notes' => 'Deliver in two covered loads; verify manufacture date.',
                'commercial_prepared_by' => $pm->id, 'commercial_prepared_at' => now()->subDays(18),
                'approved_amount' => 1800000, 'requested_at' => today()->subDays(20),
                'submitted_at' => now()->subDays(20), 'status' => 'approved', 'requested_by' => $siteOperator->id,
                'notes' => 'Site requested Simba or equivalent 42.5R cement.',
            ]);
            $steel = $this->request([
                'request_code' => 'REQ-DEMO-001-02', 'request_batch_code' => 'SRQ-DEMO-001', 'request_line_number' => 2,
                'project_id' => $project->id, 'project_phase_id' => $phases['substructure']->id,
                'internal_budget_entry_id' => $budgetEntries['steel']->id, 'request_type' => 'item',
                'main_category' => 'materials', 'subcategory' => 'reinforcement_steel',
                'item_description' => 'Y12 and Y16 reinforcement steel', 'quantity' => 1, 'unit' => 'tonne',
                'requested_amount' => null, 'supplier_fundi_client' => null,
                'supplier_payee_id' => $materialsSupplier->id, 'commercial_unit_rate' => 2500000,
                'commercial_total' => 2500000, 'commercial_payment_method' => 'bank_transfer',
                'commercial_payment_destination' => 'Demo Commercial Bank · DEMO-001-445566',
                'commercial_payment_destination_id' => $materialsDestination->id,
                'commercial_notes' => 'Mill certificate required with delivery.',
                'commercial_prepared_by' => $pm->id, 'commercial_prepared_at' => now()->subDays(18),
                'approved_amount' => 2500000, 'requested_at' => today()->subDays(20),
                'submitted_at' => now()->subDays(20), 'status' => 'approved', 'requested_by' => $siteOperator->id,
                'notes' => 'Mixed Y12 and Y16 bars according to foundation schedule.',
            ]);
            $excavator = $this->request([
                'request_code' => 'REQ-DEMO-002-01', 'request_batch_code' => 'SRQ-DEMO-001', 'request_line_number' => 3,
                'project_id' => $project->id, 'project_phase_id' => $phases['substructure']->id,
                'internal_budget_entry_id' => $budgetEntries['excavator']->id, 'request_type' => 'service',
                'main_category' => 'logistics_equipment', 'subcategory' => 'plant_machinery_hire',
                'item_description' => 'Excavator hire for foundation trenches', 'quantity' => 3, 'unit' => 'day',
                'requested_amount' => null, 'supplier_fundi_client' => null,
                'supplier_payee_id' => $plantSupplier->id, 'commercial_unit_rate' => 700000,
                'commercial_total' => 2100000, 'commercial_payment_method' => 'bank_transfer',
                'commercial_payment_destination' => 'Demo Commercial Bank · DEMO-002-778899',
                'commercial_payment_destination_id' => $plantDestination->id,
                'commercial_notes' => 'Rate includes operator; fuel supplied by the project.',
                'commercial_prepared_by' => $pm->id, 'commercial_prepared_at' => now()->subDays(7),
                'approved_amount' => 2100000, 'requested_at' => today()->subDays(9),
                'submitted_at' => now()->subDays(9), 'status' => 'approved', 'requested_by' => $siteOperator->id,
                'notes' => 'Needed before forecast rain.',
            ]);
            $labour = $this->request([
                'request_code' => 'REQ-DEMO-003-01', 'request_batch_code' => 'SRQ-DEMO-003', 'request_line_number' => 1,
                'project_id' => $project->id, 'project_phase_id' => $phases['substructure']->id,
                'labour_agreement_id' => $agreement->id, 'request_type' => 'salary_wage',
                'main_category' => 'labour_subcontracts', 'subcategory' => 'fundi_labour',
                'item_description' => 'Foundation crew first progress instalment', 'quantity' => 1, 'unit' => 'ls',
                'requested_amount' => null, 'supplier_fundi_client' => null, 'requested_at' => today()->subDay(),
                'submitted_at' => now()->subDay(), 'status' => 'pending_approval', 'requested_by' => $siteOperator->id,
                'notes' => 'Work measured at approximately one-third of the agreed foundation scope.',
            ]);

            foreach ([$cement, $steel, $excavator] as $request) {
                $request->approvals()->updateOrCreate(
                    ['approval_stage' => 'project_manager'],
                    [
                        'decision' => 'approved',
                        'approved_amount' => $request->commercial_total,
                        'approved_unit_rate' => $request->commercial_unit_rate,
                        'approved_total' => $request->commercial_total,
                        'supplier_payee_id' => $request->supplier_payee_id,
                        'supplier_name_snapshot' => $request->supplierPayee?->name,
                        'supplier_type_snapshot' => 'supplier',
                        'payment_method_snapshot' => $request->commercial_payment_method,
                        'payment_destination_snapshot' => $request->commercial_payment_destination,
                        'decided_by' => $pm->id,
                        'decided_at' => $request->commercial_prepared_at,
                        'notes' => 'Approved by PM for Accounts — demo workflow.',
                    ]
                );
            }

            ExpensePayment::updateOrCreate(
                ['payment_code' => 'PAY-DEMO-EXP-001'],
                [
                    'expense_request_id' => $cement->id,
                    'project_id' => $project->id,
                    'project_phase_id' => $phases['substructure']->id,
                    'amount' => 1800000,
                    'payment_date' => today()->subDays(14),
                    'payment_method' => 'bank_transfer',
                    'transaction_reference' => 'DEMO-TXN-EXP-001',
                    'invoice_receipt_no' => 'DEMO-SUP-INV-101',
                    'recorded_by' => $accounts->id,
                    'notes' => 'Full payment for cement delivery.',
                ]
            );
            ExpensePayment::updateOrCreate(
                ['payment_code' => 'PAY-DEMO-EXP-002'],
                [
                    'expense_request_id' => $steel->id,
                    'project_id' => $project->id,
                    'project_phase_id' => $phases['substructure']->id,
                    'amount' => 1500000,
                    'payment_date' => today()->subDays(12),
                    'payment_method' => 'bank_transfer',
                    'transaction_reference' => 'DEMO-TXN-EXP-002',
                    'invoice_receipt_no' => 'DEMO-SUP-INV-102',
                    'recorded_by' => $accounts->id,
                    'notes' => 'Part payment; TSh 1,000,000 remains.',
                ]
            );

            $invoiceOne = ClientInvoice::updateOrCreate(
                ['invoice_code' => 'INV-DEMO-2026-001'],
                [
                    'reference' => 'BVC-IP-01',
                    'project_id' => $project->id,
                    'client_id' => $client->id,
                    'project_estimate_id' => $estimate->id,
                    'status' => 'issued',
                    'issued_at' => today()->subDays(35),
                    'due_at' => today()->subDays(5),
                    'created_by' => $accounts->id,
                    'issued_by' => $accounts->id,
                    'notes' => 'Mobilisation and foundation progress invoice. Overdue balance is intentional for reminder testing.',
                ]
            );
            $invoiceOne->items()->updateOrCreate(
                ['item_description' => 'Mobilisation and substructure progress certificate No. 1'],
                [
                    'project_phase_id' => $phases['substructure']->id,
                    'main_category' => 'preliminaries_overheads',
                    'subcategory' => 'site_office_accommodation',
                    'quantity' => 1,
                    'unit' => 'ls',
                    'unit_price' => 25000000,
                    'notes' => 'Demo progress billing.',
                ]
            );
            ClientPayment::updateOrCreate(
                ['payment_code' => 'PAY-DEMO-CLIENT-001'],
                [
                    'client_invoice_id' => $invoiceOne->id,
                    'project_id' => $project->id,
                    'client_id' => $client->id,
                    'amount' => 15000000,
                    'payment_date' => today()->subDays(20),
                    'payment_method' => 'bank_transfer',
                    'transaction_reference' => 'DEMO-CLIENT-TXN-001',
                    'reference' => 'BVC-IP-01-PART',
                    'recorded_by' => $accounts->id,
                    'notes' => 'Client part payment; TSh 10,000,000 remains overdue.',
                ]
            );

            $invoiceTwo = ClientInvoice::updateOrCreate(
                ['invoice_code' => 'INV-DEMO-2026-002'],
                [
                    'reference' => 'BVC-IP-02',
                    'project_id' => $project->id,
                    'client_id' => $client->id,
                    'project_estimate_id' => $estimate->id,
                    'status' => 'issued',
                    'issued_at' => today()->subDays(3),
                    'due_at' => today()->addDays(11),
                    'created_by' => $accounts->id,
                    'issued_by' => $accounts->id,
                    'notes' => 'Current foundation materials progress invoice.',
                ]
            );
            $invoiceTwo->items()->updateOrCreate(
                ['item_description' => 'Foundation materials progress certificate No. 2'],
                [
                    'project_phase_id' => $phases['substructure']->id,
                    'main_category' => 'materials',
                    'subcategory' => 'concrete_cement',
                    'quantity' => 1,
                    'unit' => 'ls',
                    'unit_price' => 18000000,
                    'notes' => 'Due in 11 days for due-date tracking.',
                ]
            );

            $draftInvoice = ClientInvoice::updateOrCreate(
                ['invoice_code' => 'INV-DEMO-2026-DRAFT'],
                [
                    'reference' => 'BVC-DRAFT-03',
                    'project_id' => $project->id,
                    'client_id' => $client->id,
                    'project_estimate_id' => $estimate->id,
                    'status' => 'draft',
                    'due_at' => today()->addDays(30),
                    'created_by' => $accounts->id,
                    'notes' => 'Draft invoice left intentionally for Accounts testing.',
                ]
            );
            $draftInvoice->items()->updateOrCreate(
                ['item_description' => 'Draft next-stage advance'],
                [
                    'project_phase_id' => $phases['superstructure']->id,
                    'main_category' => 'materials',
                    'subcategory' => 'reinforcement_steel',
                    'quantity' => 1,
                    'unit' => 'ls',
                    'unit_price' => 12000000,
                    'notes' => 'Safe draft for the tester to edit or issue.',
                ]
            );

            $labour->touch();
        });
    }

    private function request(array $attributes): ExpenseRequest
    {
        return ExpenseRequest::updateOrCreate(
            ['request_code' => $attributes['request_code']],
            $attributes
        );
    }
}
