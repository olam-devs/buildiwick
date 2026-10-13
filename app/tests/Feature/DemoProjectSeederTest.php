<?php

namespace Tests\Feature;

use App\Models\ClientInvoice;
use App\Models\ExpenseRequest;
use App\Models\Project;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Database\Seeders\DemoProjectSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DemoProjectSeederTest extends TestCase
{
    use RefreshDatabase;

    public function test_demo_project_is_complete_and_visible_across_roles(): void
    {
        $this->seed(DatabaseSeeder::class);
        $this->seed(DemoProjectSeeder::class);

        $project = Project::where('project_code', DemoProjectSeeder::PROJECT_CODE)->firstOrFail();

        $this->assertSame(7, $project->phases()->count());
        $this->assertSame(3, $project->assignedUsers()->count());
        $this->assertNotNull($project->client_id);
        $this->assertSame('issued', $project->estimates()->firstOrFail()->status);
        $this->assertSame('finalized', $project->internalBudgets()->firstOrFail()->status);
        $this->assertSame(4, $project->expenseRequests()->count());
        $this->assertSame(3, $project->invoices()->count());

        $this->assertSame('paid', ExpenseRequest::where('request_code', 'REQ-DEMO-001-01')->firstOrFail()->payment_status);
        $this->assertSame('part_paid', ExpenseRequest::where('request_code', 'REQ-DEMO-001-02')->firstOrFail()->payment_status);
        $this->assertSame('unpaid', ExpenseRequest::where('request_code', 'REQ-DEMO-002-01')->firstOrFail()->payment_status);
        $this->assertSame('10000000.00', ClientInvoice::where('invoice_code', 'INV-DEMO-2026-001')->firstOrFail()->outstanding_amount);

        foreach ([
            'director@buildiwick.test' => '/director',
            'twalib@buildiwick.test' => '/estimator/projects',
            'neema@buildiwick.test' => '/pm/projects',
            'amani@buildiwick.test' => '/site',
            'grace@buildiwick.test' => '/accountant',
        ] as $email => $path) {
            $this->actingAs(User::where('email', $email)->firstOrFail())
                ->get($path)
                ->assertOk()
                ->assertSee('Bahari View Family Villa');
        }
    }
}
