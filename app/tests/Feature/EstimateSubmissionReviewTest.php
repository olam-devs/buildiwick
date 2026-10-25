<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\Project;
use App\Models\ProjectEstimate;
use App\Models\ProjectPhase;
use App\Models\Role;
use App\Models\User;
use App\Services\EstimatorEstimateService;
use Database\Seeders\LookupSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Tests\TestCase;

class EstimateSubmissionReviewTest extends TestCase
{
    use RefreshDatabase;

    private EstimatorEstimateService $estimates;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(LookupSeeder::class);
        foreach (['director', 'accounts', 'estimator_qs'] as $role) {
            Role::firstOrCreate(['name' => $role], ['description' => $role]);
        }
        $this->estimates = app(EstimatorEstimateService::class);
    }

    public function test_submitted_estimate_is_read_only_for_the_estimator(): void
    {
        [$project, $estimator, $phase] = $this->projectWithEstimator();
        $estimate = $this->draftWithLine($project, $estimator, $phase);
        $entry = $estimate->entries()->firstOrFail();

        $this->actingAs($estimator)->post(route('estimator.estimates.submit', [$project, $estimate]))->assertRedirect();
        $estimate->refresh();
        $this->assertSame('submitted', $estimate->status);
        $this->assertSame($estimator->id, $estimate->submitted_by);
        $this->assertNotNull($estimate->submitted_at);

        $this->actingAs($estimator)->put(route('estimator.entries.update', [$project, $phase, $entry]), $this->payload(['item_description' => 'Changed after submit']))->assertForbidden();
        $this->actingAs($estimator)->delete(route('estimator.entries.delete', [$project, $phase, $entry]))->assertForbidden();
        $this->actingAs($estimator)->post(route('estimator.entries.store', [$project, $phase]), $this->payload())->assertForbidden();
        $this->actingAs($estimator)->post(route('estimator.estimates.start', $project))->assertSessionHasErrors('estimate');
        $this->actingAs($estimator)->post(route('estimator.estimates.upload', $project), ['estimate_file' => $this->workbook([['Preliminaries', 'Materials', 'Sand', 1, 'BAG', 10, 0]])])
            ->assertSessionHasErrors('estimate');
        $this->actingAs($estimator)->post(route('estimator.phases.store', $project), ['phase_name' => 'Late phase'])->assertSessionHasErrors('phases');
        $this->actingAs($estimator)->post(route('estimator.estimates.finalize', [$project, $estimate]))->assertForbidden();
        $this->actingAs($estimator)->post(route('estimator.estimates.submit', [$project, $estimate]))->assertForbidden();
        $this->assertSame(1, $estimate->entries()->count());
        $this->assertSame('Cement estimate', $entry->fresh()->item_description);

        $this->actingAs($estimator)->get(route('estimator.projects.show', $project))->assertOk()
            ->assertSee('Submitted to Director')->assertSee('read-only for you')
            ->assertDontSee('Submit to Director</button>', false)->assertDontSee('Upload Estimate')->assertDontSee('Return to Estimator');
    }

    public function test_director_edits_submitted_estimate_and_only_approval_makes_it_invoiceable(): void
    {
        [$project, $estimator, $phase] = $this->projectWithEstimator();
        $estimate = $this->draftWithLine($project, $estimator, $phase);
        $this->estimates->submit($estimate, $estimator);
        $director = $this->user('director');

        $this->actingAs($director)->get(route('director.index'))->assertOk()->assertSee('Estimates Awaiting Approval');
        $this->actingAs($director)->get(route('director.projects.show', $project))->assertOk()
            ->assertSee('Review Estimate')->assertSee('awaiting your approval')->assertSee('Project Estimate');
        $this->actingAs($director)->get(route('estimator.projects.index', ['status' => 'submitted']))->assertOk()->assertSee($project->name);
        $this->actingAs($director)->get(route('estimator.projects.show', $project))->assertOk()
            ->assertSee('Director Review')->assertSee('Approve &amp; Issue', false)->assertSee('Return to Estimator')->assertSee('Upload Estimate');
        $this->actingAs($director)->post(route('estimator.entries.store', [$project, $phase]), $this->payload(['item_description' => 'Director added line']))->assertRedirect();
        $this->assertSame(2, $estimate->entries()->count());
        $this->assertSame('submitted', $estimate->fresh()->status);
        $this->assertSame(0, $project->estimates()->where('status', 'issued')->count());

        $this->actingAs($director)->post(route('estimator.estimates.finalize', [$project, $estimate]))->assertRedirect();
        $estimate->refresh();
        $this->assertSame('issued', $estimate->status);
        $this->assertSame($director->id, $estimate->issued_by);

        $this->actingAs($estimator)->post(route('estimator.estimates.edit', [$project, $estimate]), ['edit_reason' => 'Want to change'])->assertForbidden();
        $this->actingAs($estimator)->get(route('estimator.projects.show', $project))->assertOk()->assertDontSee('Edit Estimate</button>', false);
    }

    public function test_director_can_return_a_submitted_estimate_and_the_estimator_can_resubmit(): void
    {
        [$project, $estimator, $phase] = $this->projectWithEstimator();
        $estimate = $this->draftWithLine($project, $estimator, $phase);
        $this->estimates->submit($estimate, $estimator);
        $director = $this->user('director');

        $this->actingAs($director)->post(route('estimator.estimates.return', [$project, $estimate]), [])->assertSessionHasErrors('review_notes');
        $this->actingAs($director)->post(route('estimator.estimates.return', [$project, $estimate]), ['review_notes' => 'Use the revised cement rate.'])->assertRedirect();
        $estimate->refresh();
        $this->assertSame('draft', $estimate->status);
        $this->assertSame('estimator', $estimate->editor_role);
        $this->assertSame($director->id, $estimate->returned_by);
        $this->assertDatabaseHas('project_edit_logs', ['document_id' => $estimate->id, 'action' => 'returned_to_estimator', 'reason' => 'Use the revised cement rate.', 'edited_by' => $director->id]);

        $this->actingAs($estimator)->get(route('estimator.projects.show', $project))->assertOk()->assertSee('Use the revised cement rate.')->assertSee('Submit to Director');
        $entry = $estimate->entries()->firstOrFail();
        $this->actingAs($estimator)->put(route('estimator.entries.update', [$project, $phase, $entry]), $this->payload(['base_unit_cost' => '60.00']))->assertRedirect();
        $this->assertSame('60.00', $entry->fresh()->base_unit_cost);
        $this->actingAs($estimator)->post(route('estimator.estimates.submit', [$project, $estimate]))->assertRedirect();
        $this->assertSame('submitted', $estimate->fresh()->status);
    }

    public function test_director_revision_of_an_issued_estimate_stays_with_admin_unless_returned(): void
    {
        [$project, $estimator, $phase] = $this->projectWithEstimator();
        $director = $this->user('director');
        $estimate = $this->draftWithLine($project, $estimator, $phase);
        $this->estimates->submit($estimate, $estimator);
        $issued = $this->estimates->finalize($estimate->fresh(), $director);

        $revision = $this->estimates->startEditing($issued, 'Client added a store room.', $director);
        $this->assertSame('admin', $revision->editor_role);
        $this->assertFalse($estimator->can('update', $revision));
        $this->assertFalse($estimator->can('submit', $revision));
        $this->actingAs($estimator)->get(route('estimator.projects.show', $project))->assertOk()
            ->assertSee('Issued · v1')->assertDontSee('Working draft');
        $this->actingAs($estimator)->get(route('estimator.projects.index'))->assertOk()->assertSee('Issued');
        $this->estimates->finalize($revision, $director);
        $this->assertSame('superseded', $issued->fresh()->status);

        $returned = $this->estimates->returnToEstimator($revision->fresh(), 'Re-measure the external works.', $director);
        $this->assertSame(3, $returned->version);
        $this->assertSame('estimator', $returned->editor_role);
        $this->assertSame('draft', $returned->status);
        $this->assertTrue($estimator->can('update', $returned));
        $this->assertSame('issued', $revision->fresh()->status);
    }

    public function test_director_can_create_and_issue_an_estimate_without_the_estimator(): void
    {
        [$project, $estimator, $phase] = $this->projectWithEstimator();
        $director = $this->user('director');

        $this->actingAs($director)->post(route('estimator.estimates.start', $project))->assertRedirect();
        $estimate = $project->estimates()->firstOrFail();
        $this->assertSame('admin', $estimate->editor_role);
        $this->actingAs($director)->post(route('estimator.entries.store', [$project, $phase]), $this->payload())->assertRedirect();

        $this->actingAs($estimator)->post(route('estimator.entries.store', [$project, $phase]), $this->payload())->assertForbidden();
        $this->expectException(AuthorizationException::class);
        try {
            $this->estimates->submit($estimate, $estimator);
        } finally {
            $this->actingAs($director)->post(route('estimator.estimates.finalize', [$project, $estimate]))->assertRedirect();
            $this->assertSame('issued', $estimate->fresh()->status);
        }
    }

    public function test_template_download_and_upload_replace_the_working_estimate_lines(): void
    {
        [$project, $estimator, $phase] = $this->projectWithEstimator();
        $estimate = $this->draftWithLine($project, $estimator, $phase);

        $download = $this->actingAs($estimator)->get(route('estimator.estimates.template', $project));
        $download->assertOk()->assertDownload($project->project_code.'-estimate-template.xlsx');
        $sheet = IOFactory::load($download->baseResponse->getFile()->getPathname())->getSheetByName('Estimate Lines');
        $this->assertSame('Description', $sheet->getCell('D4')->getValue());
        $this->assertSame('Cement estimate', $sheet->getCell('D5')->getValue());
        $this->assertSame('Preliminaries', $sheet->getCell('A5')->getValue());

        $file = $this->workbook([
            ['Preliminaries', 'Materials', 'Hardcore filling', '12', 'tonne', '45000', '15'],
            ['2', 'Labour & Subcontracts', 'Blockwork labour', '30', 'M²', '8000', '10'],
        ]);
        $this->actingAs($estimator)->post(route('estimator.estimates.upload', $project), ['estimate_file' => $file])->assertRedirect()->assertSessionHasNoErrors();

        $entries = $estimate->entries()->orderBy('id')->get();
        $this->assertCount(2, $entries);
        $this->assertSame(['Hardcore filling', 'Blockwork labour'], $entries->pluck('item_description')->all());
        $this->assertSame('tonne', $entries[0]->unit);
        $this->assertSame('m2', $entries[1]->unit);
        $this->assertSame('labour_subcontracts', $entries[1]->main_category);
        $this->assertSame($project->phases()->where('phase_order', 2)->value('id'), $entries[1]->project_phase_id);
        $this->assertSame('15.0000', $entries[0]->markup_percent);
    }

    public function test_upload_with_bad_rows_lists_every_problem_and_saves_nothing(): void
    {
        [$project, $estimator, $phase] = $this->projectWithEstimator();
        $estimate = $this->draftWithLine($project, $estimator, $phase);

        $response = $this->actingAs($estimator)->post(route('estimator.estimates.upload', $project), ['estimate_file' => $this->workbook([
            ['Basement', 'Materials', 'Sand', '1', 'bag', '100', '0'],
            ['Preliminaries', 'Snacks', '', '1', 'bag', 'abc', '0'],
        ])]);
        $response->assertSessionHasErrors('estimate_file');
        $messages = session('errors')->get('estimate_file');
        $this->assertContains('Row 5: Phase "Basement" is not one of this project\'s phases.', $messages);
        $this->assertContains('Row 6: Category "Snacks" is not recognised.', $messages);
        $this->assertTrue(collect($messages)->contains(fn ($message) => str_starts_with($message, 'Row 6: The Description field is required')));
        $this->assertSame(['Cement estimate'], $estimate->entries()->pluck('item_description')->all());
    }

    public function test_director_can_create_an_estimate_by_uploading_the_template(): void
    {
        [$project, , $phase] = $this->projectWithEstimator();
        $director = $this->user('director');

        $this->actingAs($director)->post(route('estimator.estimates.upload', $project), ['estimate_file' => $this->workbook([
            ['Preliminaries', 'Materials', 'Site hoarding', '1', 'LS', '250000', '12.5'],
        ])])->assertRedirect()->assertSessionHasNoErrors();

        $estimate = $project->estimates()->firstOrFail();
        $this->assertSame('draft', $estimate->status);
        $this->assertSame('admin', $estimate->editor_role);
        $this->assertSame('Site hoarding', $estimate->entries()->value('item_description'));
    }

    /** @return array{0: Project, 1: User, 2: ProjectPhase} */
    private function projectWithEstimator(): array
    {
        $project = Project::factory()->create([
            'project_code' => 'BWG-REV-'.fake()->unique()->numerify('#####'),
            'client_id' => Client::factory()->create()->id,
        ]);
        foreach (['preliminaries', 'substructure', 'superstructure'] as $index => $stage) {
            $project->phases()->create(['stage_code' => $stage, 'phase_order' => $index + 1, 'status' => $index === 0 ? 'active' : 'not_started']);
        }
        $estimator = $this->user('estimator_qs');
        $project->assignedUsers()->attach($estimator->id);

        return [$project, $estimator, $project->phases()->orderBy('phase_order')->firstOrFail()];
    }

    private function user(string $roleName): User
    {
        return User::factory()->create(['role_id' => Role::where('name', $roleName)->firstOrFail()->id, 'is_active' => true]);
    }

    private function draftWithLine(Project $project, User $estimator, ProjectPhase $phase): ProjectEstimate
    {
        $estimate = $this->estimates->ensureWorkingEstimate($project, $estimator);
        $this->estimates->createEntry($estimate, $phase, $this->payload(), $estimator);

        return $estimate->fresh();
    }

    private function payload(array $override = []): array
    {
        return array_merge([
            'main_category' => 'materials',
            'subcategory' => 'concrete_cement',
            'item_description' => 'Cement estimate',
            'quantity' => '1.00',
            'unit' => 'bag',
            'base_unit_cost' => '50.00',
            'markup_percent' => '0.0000',
        ], $override);
    }

    /** @param  array<int, array{0: string, 1: string, 2: string, 3: string, 4: string, 5: string, 6: string}>  $rows  Phase, Category, Description, Qty, Unit, Rate, Markup */
    private function workbook(array $rows): UploadedFile
    {
        $spreadsheet = new Spreadsheet;
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle('Estimate Lines');
        $sheet->setCellValue('A1', 'Estimate Template');
        foreach (['Phase', 'Section', 'Item Ref.', 'Description', 'Category', 'Subcategory', 'Quantity', 'Unit', 'Base Rate (TSh)', 'Markup %', 'Notes'] as $index => $header) {
            $sheet->setCellValue([$index + 1, 4], $header);
        }
        foreach ($rows as $offset => [$phase, $category, $description, $quantity, $unit, $rate, $markup]) {
            $row = 5 + $offset;
            foreach ([1 => $phase, 4 => $description, 5 => $category, 7 => $quantity, 8 => $unit, 9 => $rate, 10 => $markup] as $column => $value) {
                $sheet->setCellValue([$column, $row], $value);
            }
        }
        $path = tempnam(sys_get_temp_dir(), 'estimate-upload-').'.xlsx';
        (new Xlsx($spreadsheet))->save($path);

        return new UploadedFile($path, 'estimate.xlsx', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', null, true);
    }
}
