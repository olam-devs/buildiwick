<?php

namespace App\Console\Commands;

use App\Http\Controllers\Web\ProjectSetupController;
use App\Models\Project;
use App\Models\User;
use App\Services\PhaseStructureService;
use Carbon\CarbonImmutable;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Shared\Date as ExcelDate;

/**
 * Sets up the pilot projects from the client's "Active Projects" list (one row per project: code,
 * name, type of work, district, start date, status, project manager, notes). With --fresh every
 * existing project and all its records are deleted first, for the clean start where all expenses
 * are entered in the system from the start date. Each project gets the standard tracker stages as
 * phases, so recording can begin at once; "Office & overheads" becomes the company overheads ledger.
 */
#[Signature('app:pilot-projects {file : Path to the Active Projects .xlsx} {--fresh : Delete ALL existing projects and their records first} {--dry-run : Read and report only; save nothing} {--as= : Email of the Director the projects are created under}')]
#[Description('Create the pilot projects from the Active Projects workbook (optionally replacing every existing project).')]
class ImportPilotProjects extends Command
{
    private const STATUSES = ['active' => 'active', 'completed' => 'completed', 'on hold' => 'on_hold', 'planning' => 'planning', 'near closure' => 'near_closure'];

    public function handle(PhaseStructureService $phases): int
    {
        $path = (string) $this->argument('file');
        if (! is_file($path)) {
            $this->error("File not found: $path");

            return self::FAILURE;
        }
        $rows = $this->rows($path);
        if ($rows === []) {
            $this->error('No projects found (expected a header row with "Project Code" and "Project Name").');

            return self::FAILURE;
        }
        $this->table(['Code', 'Name', 'Type of work', 'District', 'Start', 'Status', 'Overheads', 'PM'],
            array_map(fn ($r) => [$r['project_code'], $r['name'], $r['work_type'], $r['location'], $r['planned_start_date'] ?? '—', $r['status'], $r['is_company_overheads'] ? 'yes' : '', $r['pm'] ?? ''], $rows));
        if ($this->option('dry-run')) {
            $this->info('Dry run: nothing was saved.');

            return self::SUCCESS;
        }

        $actor = User::query()->when($this->option('as'), fn ($q, $email) => $q->where('email', $email), fn ($q) => $q->whereHas('role', fn ($r) => $r->where('name', 'director')))->orderBy('id')->first();
        if (! $actor) {
            $this->error('No Director account found to record the projects under.');

            return self::FAILURE;
        }

        DB::transaction(function () use ($rows, $actor, $phases): void {
            if ($this->option('fresh')) {
                $this->warn('Deleting '.Project::count().' existing project(s) and all their records …');
                foreach (Project::query()->get() as $project) {
                    DB::table('client_payments')->where('project_id', $project->id)->delete();
                    DB::table('client_invoice_items')->whereIn('client_invoice_id', DB::table('client_invoices')->where('project_id', $project->id)->pluck('id'))->delete();
                    DB::table('client_invoices')->where('project_id', $project->id)->delete();
                    $project->delete();
                }
            }
            foreach ($rows as $row) {
                if (Project::where('project_code', $row['project_code'])->exists()) {
                    $this->line('Skipped '.$row['project_code'].': already exists.');

                    continue;
                }
                $project = Project::create(collect($row)->except(['pm'])->all() + ['created_by' => $actor->id]);
                foreach (ProjectSetupController::startingPhases($project->is_company_overheads) as $stage) {
                    $phases->create($project, $stage, null, $actor);
                }
                if ($row['pm']) {
                    $pm = User::query()->whereHas('role', fn ($r) => $r->where('name', 'project_manager'))
                        ->where(fn ($q) => $q->where('name', $row['pm'])->orWhere('email', $row['pm']))->first();
                    $pm ? $project->assignedUsers()->syncWithoutDetaching([$pm->id]) : $this->warn('No project manager named "'.$row['pm'].'" for '.$row['project_code'].'.');
                }
            }
        });
        $this->info(count($rows).' pilot project(s) ready, each with the standard stages as phases.');

        return self::SUCCESS;
    }

    /** @return array<int, array<string, mixed>> */
    private function rows(string $path): array
    {
        $reader = IOFactory::createReaderForFile($path);
        $reader->setReadDataOnly(true);
        $sheet = $reader->load($path)->getActiveSheet();
        $data = $sheet->toArray(null, true, false, false);
        $header = null;
        $rows = [];
        foreach ($data as $cells) {
            $cells = array_map(fn ($v) => is_string($v) ? trim($v) : $v, $cells);
            if ($header === null) {
                $lower = array_map(fn ($v) => mb_strtolower((string) $v), $cells);
                if (in_array('project code', $lower, true) && in_array('project name', $lower, true)) {
                    $header = array_flip($lower);
                }

                continue;
            }
            $get = fn (string $name) => isset($header[$name]) ? ($cells[$header[$name]] ?? null) : null;
            $code = strtoupper((string) $get('project code'));
            $name = (string) $get('project name');
            if ($code === '' || $name === '' || ! preg_match('/^[A-Z0-9]+(-[A-Z0-9]+)*$/', $code)) {
                continue;
            }
            $type = (string) $get('type of work');
            $start = $get('start date');
            $overheads = $code === 'OFC-OVH' || mb_strtolower($type) === 'overheads';
            $rows[] = [
                'project_code' => str_starts_with($code, 'BWG-') ? $code : 'BWG-'.$code.'-2026-001',
                'name' => $name,
                'work_type' => $type !== '' ? $type : null,
                // Left empty when the sheet has no district (never guessed); the Director can fill it in Project Setup.
                'location' => (string) $get('location') ?: null,
                'planned_start_date' => match (true) {
                    is_numeric($start) => CarbonImmutable::instance(ExcelDate::excelToDateTimeObject((float) $start))->toDateString(),
                    is_string($start) && $start !== '' => CarbonImmutable::createFromFormat('d/m/Y', $start)->toDateString(),
                    default => null,
                },
                'status' => self::STATUSES[mb_strtolower((string) $get('status'))] ?? 'active',
                'is_company_overheads' => $overheads,
                'notes' => (string) $get('notes') ?: null,
                'pm' => (string) $get('project manager') ?: null,
            ];
        }

        return $rows;
    }
}
