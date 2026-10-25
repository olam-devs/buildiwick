<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\ClientInvoice;
use App\Models\ExpenseRequest;
use App\Models\InternalBudget;
use App\Models\InternalBudgetEntry;
use App\Models\OnboardingImport;
use App\Models\Project;
use App\Models\ProjectPhase;
use App\Models\Role;
use App\Models\User;
use App\Services\AccountantReportingService;
use App\Services\DirectorAnalyticsService;
use App\Services\ExpensePaymentRecorder;
use Database\Seeders\LookupSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Storage;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Tests\TestCase;

class OnboardingRecordsImportTest extends TestCase
{
    use RefreshDatabase;

    private const EXPENSE_HEADERS = ['Expense Date', 'Phase', 'Category', 'Subcategory', 'Description', 'Supplier / Payee', 'Quantity', 'Unit', 'Total Cost (TSh)', 'Amount Paid (TSh)', 'Payment Date', 'Payment Method', 'Supplier Invoice / Receipt No.', 'Payment Reference', 'Budget Item Ref.', 'Notes'];

    private const INVOICE_HEADERS = ['Invoice No.', 'Issue Date', 'Due Date', 'Phase', 'Category', 'Description', 'Amount (TSh)', 'Notes'];

    private const RECEIPT_HEADERS = ['Payment Date', 'Invoice No.', 'Amount (TSh)', 'Payment Method', 'Reference', 'Notes'];

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        $this->seed(LookupSeeder::class);
        foreach (['director', 'accounts', 'project_manager', 'estimator_qs'] as $role) {
            Role::firstOrCreate(['name' => $role], ['description' => $role]);
        }
    }

    public function test_template_is_built_for_the_project(): void
    {
        [$project] = $this->project();
        $response = $this->actingAs($this->user('accounts'))->get(route('accountant.onboarding.template', $project));

        $response->assertOk()->assertDownload($project->project_code.'-opening-records.xlsx');
        $book = IOFactory::load($response->baseResponse->getFile()->getPathname());
        $this->assertSame(['Instructions', 'Expenses', 'Client Invoices', 'Client Payments', 'Lists'], $book->getSheetNames());
        $this->assertSame('Total Cost (TSh)', $book->getSheetByName('Expenses')->getCell('I1')->getValue());
        $lists = $book->getSheetByName('Lists');
        $this->assertSame('Preliminaries', $lists->getCell('A2')->getValue());
        $this->assertSame('B-01', $lists->getCell('F2')->getValue());
    }

    public function test_import_creates_real_historical_records_that_reports_count(): void
    {
        [$project, $phases, $budgetEntry] = $this->project();
        $accounts = $this->user('accounts');

        $this->actingAs($accounts)->post(route('accountant.onboarding.store', $project), [
            'records_file' => $this->workbook(
                expenses: [
                    ['15/03/2026', 'Preliminaries', 'Materials', 'Concrete & Cement', 'Cement 50kg', 'Simba Cement Ltd', '100', 'BAG', '1,850,000', '1850000', '', 'Bank Transfer', 'INV-778', 'TRF-001', 'B-01', ''],
                    ['02/04/2026', '2', 'Labour & Subcontracts', '', 'Foundation labour', 'Juma Fundi', '', '', '900000', '400000', '10/04/2026', 'cash', '', '', '', 'Second instalment pending'],
                ],
                invoices: [
                    ['IPC-01', '20/03/2026', '20/04/2026', 'Preliminaries', 'Preliminaries & Site Overheads', 'Site establishment', '3000000', ''],
                    ['IPC-01', '20/03/2026', '', 'Substructure', 'Materials', 'Substructure works to date', '2000000', ''],
                ],
                receipts: [
                    ['05/04/2026', 'IPC-01', '4000000', 'Bank Transfer', 'CRDB-123', ''],
                ],
            ),
            'notes' => 'From interim tracker',
        ])->assertRedirect()->assertSessionHasNoErrors();

        $import = OnboardingImport::query()->sole();
        $this->assertSame([2, 1, 1], [$import->expense_count, $import->invoice_count, $import->client_payment_count]);
        $this->assertSame(['2750000.00', '2250000.00', '5000000.00', '4000000.00'], [$import->expense_total, $import->expense_paid_total, $import->invoice_total, $import->client_payment_total]);
        Storage::disk('local')->assertExists($import->stored_path);

        $requests = ExpenseRequest::query()->orderBy('id')->with(['payments', 'approvals', 'supplierPayee'])->get();
        $this->assertSame(['approved', 'approved'], $requests->pluck('status')->all());
        $this->assertSame($budgetEntry->id, $requests[0]->internal_budget_entry_id);
        $this->assertSame('bag', $requests[0]->unit);
        $this->assertSame('18500.00', $requests[0]->unit_cost);
        $this->assertSame('Simba Cement Ltd', $requests[0]->supplierPayee->name);
        $this->assertSame('paid', $requests[0]->payment_status);
        $this->assertSame('part_paid', $requests[1]->payment_status);
        $this->assertSame('500000.00', $requests[1]->approved_outstanding_amount);
        $this->assertSame($phases[1]->id, $requests[1]->project_phase_id);
        $this->assertSame('2026-04-02', $requests[1]->approvals->first()->decided_at->toDateString());
        $this->assertSame('onboarding_import', $requests[1]->approvals->first()->approval_stage);
        $this->assertSame('2026-04-10', $requests[1]->payments->first()->payment_date->toDateString());
        $this->assertTrue($requests->every(fn (ExpenseRequest $request) => $request->onboarding_import_id === $import->id));
        $this->assertTrue($requests->flatMap->payments->every(fn ($payment) => $payment->onboarding_import_id === $import->id && $payment->recorded_by === $accounts->id));

        $invoice = ClientInvoice::query()->with(['items', 'payments'])->sole();
        $this->assertSame(['issued', 'IPC-01', '2026-03-20', '2026-04-20'], [$invoice->status, $invoice->reference, $invoice->issued_at->toDateString(), $invoice->due_at->toDateString()]);
        $this->assertSame('5000000.00', $invoice->total_amount);
        $this->assertSame('1000000.00', $invoice->outstanding_amount);
        $this->assertSame($import->id, $invoice->payments->first()->onboarding_import_id);

        $register = app(AccountantReportingService::class)->transactions($project);
        $this->assertCount(3, $register);
        $payables = app(DirectorAnalyticsService::class)->payables($project->id);
        $this->assertCount(1, $payables);
        $receivables = app(DirectorAnalyticsService::class)->receivables($project->id);
        $this->assertCount(1, $receivables);
        $this->assertSame('1000000.00', $receivables->first()['outstanding']);

        $this->actingAs($accounts)->get(route('accountant.onboarding.index', $project))->assertOk()->assertSee($import->import_code)->assertSee('From interim tracker');
        $this->actingAs($accounts)->get(route('accountant.projects.show', $project))->assertOk()->assertSee('Opening Records');
        $this->actingAs($accounts)->get(route('accountant.requests.show', $requests[1]))->assertOk()->assertSee('Foundation labour');
        $this->actingAs($accounts)->get(route('accountant.payables.index'))->assertOk();
        foreach (['accountant.invoices.index', 'accountant.receivables.index'] as $route) {
            $this->actingAs($accounts)->get(route($route))->assertOk()->assertSee('Client ref: IPC-01');
        }
        $this->actingAs($accounts)->get(route('accountant.projects.show', $project))->assertSee('Client ref: IPC-01');
        $this->actingAs($this->user('director'))->get(route('director.receivables.index'))->assertOk()->assertSee('Client ref: IPC-01');
        $this->actingAs($this->user('director'))->get(route('director.projects.show', $project))->assertOk();
    }

    public function test_bad_rows_are_all_reported_and_nothing_is_saved(): void
    {
        [$project] = $this->project();

        $response = $this->actingAs($this->user('accounts'))->post(route('accountant.onboarding.store', $project), ['records_file' => $this->workbook(
            expenses: [
                ['31/02/2026', 'Basement', 'Materials', '', 'Sand', '', '', '', '500', '600', '', '', '', '', 'X-99', ''],
                ['01/03/2026', 'Preliminaries', 'Materials', '', 'Gravel', '', '', '', '500', '500', '', '', '', '', '', ''],
            ],
            receipts: [['05/04/2026', 'IPC-404', '100', 'cash', '', '']],
        )]);

        $response->assertSessionHasErrors('records_file');
        $messages = session('errors')->get('records_file');
        $this->assertContains('Expenses row 2: Expense Date "31/02/2026" is not a date. Use day/month/year, for example 15/03/2026.', $messages);
        $this->assertContains('Expenses row 2: Phase "Basement" is not one of this project\'s phases.', $messages);
        $this->assertContains('Expenses row 2: Amount Paid cannot be more than Total Cost.', $messages);
        $this->assertContains('Expenses row 2: Budget Item Ref. "X-99" is not a line in this project\'s approved budget.', $messages);
        $this->assertContains('Expenses row 3: Payment Method is required.', $messages);
        $this->assertContains('Client Payments row 2: Invoice No. "IPC-404" is not on the Client Invoices sheet or an issued invoice of this project.', $messages);
        $this->assertSame(0, OnboardingImport::count());
        $this->assertSame(0, ExpenseRequest::count());
        $this->assertSame([], Storage::disk('local')->allFiles());
    }

    public function test_import_can_be_undone_until_something_newer_depends_on_it(): void
    {
        [$project] = $this->project();
        $accounts = $this->user('accounts');
        $file = fn () => $this->workbook(expenses: [['01/03/2026', 'Preliminaries', 'Materials', '', 'Gravel', 'Quarry Co', '', '', '500', '200', '', 'cash', '', '', '', '']]);

        $this->actingAs($accounts)->post(route('accountant.onboarding.store', $project), ['records_file' => $file()])->assertSessionHasNoErrors();
        $first = OnboardingImport::query()->sole();
        $this->actingAs($accounts)->post(route('accountant.onboarding.undo', [$project, $first]), [])->assertSessionHasErrors('undo_reason');
        $this->actingAs($accounts)->post(route('accountant.onboarding.undo', [$project, $first]), ['undo_reason' => 'Wrong project'])->assertRedirect();
        $this->assertNotNull($first->fresh()->undone_at);
        $this->assertSame(0, ExpenseRequest::count());

        $this->actingAs($accounts)->post(route('accountant.onboarding.store', $project), ['records_file' => $file()])->assertSessionHasNoErrors();
        $second = OnboardingImport::query()->whereNull('undone_at')->sole();
        app(ExpensePaymentRecorder::class)->record(ExpenseRequest::query()->sole(), ['amount' => '100.00', 'payment_date' => '2026-10-01', 'payment_method' => 'cash'], $accounts);
        $this->actingAs($accounts)->post(route('accountant.onboarding.undo', [$project, $second]), ['undo_reason' => 'Mistake'])->assertSessionHasErrors('import');
        $this->assertNull($second->fresh()->undone_at);
        $this->assertSame(1, ExpenseRequest::count());
    }

    public function test_only_accounts_and_the_director_can_use_opening_records(): void
    {
        [$project] = $this->project();
        foreach (['project_manager', 'estimator_qs'] as $role) {
            $this->actingAs($this->user($role))->get(route('accountant.onboarding.index', $project))->assertForbidden();
            $this->actingAs($this->user($role))->post(route('accountant.onboarding.store', $project), ['records_file' => $this->workbook()])->assertForbidden();
        }
        $this->actingAs($this->user('director'))->get(route('accountant.onboarding.index', $project))->assertOk();
    }

    /** @return array{0: Project, 1: Collection<int, ProjectPhase>, 2: InternalBudgetEntry} */
    private function project(): array
    {
        $project = Project::factory()->create(['project_code' => 'BWG-ONB-'.fake()->unique()->numerify('####'), 'client_id' => Client::factory()->create()->id]);
        foreach (['preliminaries', 'substructure', 'superstructure'] as $index => $stage) {
            $project->phases()->create(['stage_code' => $stage, 'phase_order' => $index + 1, 'status' => $index === 0 ? 'active' : 'not_started']);
        }
        $phases = $project->phases()->orderBy('phase_order')->get();
        $budget = InternalBudget::create(['budget_code' => 'BUD-'.$project->project_code, 'project_id' => $project->id, 'version' => 1, 'status' => 'approved']);
        $entry = $budget->entries()->create(['project_phase_id' => $phases[0]->id, 'main_category' => 'materials', 'item_reference' => 'B-01', 'item_description' => 'Cement', 'quantity' => '100', 'unit_cost' => '19000.00']);

        return [$project, $phases, $entry];
    }

    private function user(string $role): User
    {
        return User::factory()->create(['role_id' => Role::where('name', $role)->firstOrFail()->id, 'is_active' => true]);
    }

    private function workbook(array $expenses = [], array $invoices = [], array $receipts = []): UploadedFile
    {
        $spreadsheet = new Spreadsheet;
        $spreadsheet->removeSheetByIndex(0);
        foreach (['Expenses' => [self::EXPENSE_HEADERS, $expenses], 'Client Invoices' => [self::INVOICE_HEADERS, $invoices], 'Client Payments' => [self::RECEIPT_HEADERS, $receipts]] as $title => [$headers, $rows]) {
            $sheet = $spreadsheet->createSheet();
            $sheet->setTitle($title);
            $sheet->fromArray($headers, null, 'A1');
            foreach ($rows as $index => $row) {
                foreach ($row as $column => $value) {
                    $sheet->setCellValueExplicit([$column + 1, $index + 2], (string) $value, DataType::TYPE_STRING);
                }
            }
        }
        $path = tempnam(sys_get_temp_dir(), 'onboarding-').'.xlsx';
        (new Xlsx($spreadsheet))->save($path);

        return new UploadedFile($path, 'opening-records.xlsx', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', null, true);
    }
}
