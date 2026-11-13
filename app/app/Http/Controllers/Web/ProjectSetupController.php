<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreProjectSetupRequest;
use App\Http\Requests\UpdateProjectSetupRequest;
use App\Models\Client;
use App\Models\Lookup;
use App\Models\Project;
use App\Models\ProjectDocument;
use App\Models\Role;
use App\Models\SheetTabRequest;
use App\Models\User;
use App\Services\GoogleSheets\ProjectSheetTabs;
use App\Services\GoogleSheets\SheetsClient;
use App\Services\PhaseStructureService;
use App\Services\ProjectRegistrationService;
use App\Support\Features;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ProjectSetupController extends Controller
{
    private const PHASES = [
        'preliminaries' => 'Preliminaries',
        'substructure' => 'Substructure (Foundation)',
        'superstructure' => 'Superstructure',
        'walling' => 'Walling',
        'roofing' => 'Roofing',
        'finishing' => 'Finishing',
        'external_works' => 'External Works',
    ];

    /** The tracker's standard stages, offered as starting phases for a new project in recording mode. */
    public const TRACKER_STAGES = [
        'Preliminaries', 'Substructure (Foundation)', 'Superstructure', 'Walling',
        'Roofing', 'Finishing', 'External Works', 'Other / General', 'Service',
    ];

    /** Starting cost groups of a company overheads ledger (the Director renames, adds or removes them). */
    public const OFFICE_COST_GROUPS = [
        'Staff Salaries & Allowances', 'Statutory & Taxes', 'Office Rent & Utilities', 'Transport & Vehicles',
        'Tools & Equipment', 'Communication & Subscriptions', 'Stationery & Printing', 'Other Office Costs',
    ];

    /** @return array<int, string> the starting phases (or cost groups) for a new ledger */
    public static function startingPhases(bool $overheads): array
    {
        return $overheads ? self::OFFICE_COST_GROUPS : self::TRACKER_STAGES;
    }

    public function index(Request $request)
    {
        $this->authorize('manageSetup', Project::class);
        if (Features::recording()) {
            $projects = Project::with('assignedUsers.role')->withCount('phases')->orderBy('name')->get();

            return view('projects.recording-index', compact('projects'));
        }

        $projects = Project::with(['client', 'activePhase', 'assignedUsers.role'])
            ->orderBy('name')
            ->get();

        return view('projects.index', compact('projects'));
    }

    public function create(Request $request)
    {
        $this->authorize('create', Project::class);
        if (Features::recording()) {
            $sheetTab = $request->filled('sheet_tab') ? SheetTabRequest::query()->where('status', 'pending')->find($request->integer('sheet_tab')) : null;
            if ($sheetTab && ! $request->session()->hasOldInput()) {
                $request->session()->flashInput(['name' => $sheetTab->title, 'project_code' => $sheetTab->project_code, 'location' => $sheetTab->location]);
            }

            return view('projects.recording-form', $this->recordingFormData(null) + ['sheetTab' => $sheetTab]);
        }

        return view('projects.form', $this->formData(null));
    }

    public function store(Request $request, ProjectRegistrationService $registration, PhaseStructureService $phases)
    {
        if (Features::recording()) {
            $this->authorize('create', Project::class);
            $data = $this->recordingData($request, null);
            $sheetTabId = $request->integer('sheet_tab_request_id') ?: null;
            $project = DB::transaction(function () use ($data, $request, $phases, $sheetTabId): Project {
                $project = Project::create(collect($data)->only(['project_code', 'name', 'work_type', 'status', 'location', 'planned_start_date', 'expected_completion_date', 'notes'])->all() + ['is_company_overheads' => $request->boolean('is_company_overheads')] + ['created_by' => $request->user()->id]);
                $project->assignedUsers()->sync(array_filter($data['project_manager_ids'] ?? []));
                if ($request->boolean('standard_stages')) {
                    foreach (self::startingPhases($project->is_company_overheads) as $stage) {
                        $phases->create($project, $stage, null, $request->user());
                    }
                }
                if ($sheetTabId) {
                    $this->adoptSheetTab($project, $sheetTabId, $request->user());
                }

                return $project;
            });
            if ($sheetTabId) {
                $this->refreshSheetTabs();

                return redirect()->route('ledger.structure', $project)->with('success', 'Project '.$project->project_code.' created from the Google Sheet tab. Her lines go into her Record Expense draft within 5 minutes; set up the phases below so they match.');
            }

            return redirect()->route('ledger.structure', $project)->with('success', 'Project '.$project->project_code.' created. Set up its phases and budget below.');
        }
        $project = $registration->create(app(StoreProjectSetupRequest::class)->validated(), $request->user());

        return redirect()->route('admin.projects.show', $project)->with('success', 'Project '.$project->project_code.' registered.');
    }

    /** Tabs Data Entry added to the entry sheet for projects Buildiwick does not have yet. */
    public function sheetTabs()
    {
        $this->authorize('create', Project::class);
        $waiting = SheetTabRequest::query()->where('status', 'pending')->orderBy('created_at')->get();
        $decided = SheetTabRequest::query()->where('status', '!=', 'pending')->with(['project', 'decider'])->latest('decided_at')->limit(20)->get();

        return view('projects.sheet-tabs', compact('waiting', 'decided'));
    }

    /**
     * One-click approval with the code and location she typed in the tab: the project is created with the tab's name
     * and the standard phases, and the tab becomes its tab.
     */
    public function approveSheetTab(Request $request, SheetTabRequest $sheetTab, PhaseStructureService $phases)
    {
        $this->authorize('create', Project::class);
        abort_unless($sheetTab->status === 'pending', 409, 'This tab has already been decided.');
        if ($problem = $sheetTab->detailsProblem()) {
            return redirect()->route('admin.projects.create', ['sheet_tab' => $sheetTab->id])->withErrors(['project_code' => $problem]);
        }
        $project = DB::transaction(function () use ($sheetTab, $request, $phases): Project {
            $project = Project::create(['project_code' => $sheetTab->project_code, 'name' => mb_substr($sheetTab->title, 0, 150), 'status' => 'active',
                'location' => $sheetTab->location, 'is_company_overheads' => false, 'created_by' => $request->user()->id]);
            foreach (self::startingPhases(false) as $stage) {
                $phases->create($project, $stage, null, $request->user());
            }
            $this->adoptSheetTab($project, $sheetTab->id, $request->user());

            return $project;
        });
        $this->refreshSheetTabs();

        return back()->with('success', 'Project '.$project->project_code.' · '.$project->name.' is approved. Her lines in the tab go into her Record Expense draft within 5 minutes.');
    }

    public function rejectSheetTab(Request $request, SheetTabRequest $sheetTab)
    {
        $this->authorize('create', Project::class);
        abort_unless($sheetTab->status === 'pending', 409, 'This tab has already been decided.');
        $sheetTab->update(['status' => 'rejected', 'decided_by' => $request->user()->id, 'decided_at' => now()]);
        $this->refreshSheetTabs();

        return back()->with('success', 'The tab "'.$sheetTab->title.'" is marked as not a project. Its lines are not read.');
    }

    public function reopenSheetTab(SheetTabRequest $sheetTab)
    {
        $this->authorize('create', Project::class);
        abort_unless($sheetTab->status === 'rejected', 409, 'Only a tab marked as not a project can be reopened.');
        $sheetTab->update(['status' => 'pending', 'decided_by' => null, 'decided_at' => null]);
        $this->refreshSheetTabs();

        return back()->with('success', 'The tab "'.$sheetTab->title.'" is waiting for approval again.');
    }

    /** Makes a waiting sheet tab the new project's tab; Data Entry's lines in it then flow into her draft. */
    private function adoptSheetTab(Project $project, int $requestId, User $director): void
    {
        $sheetTab = SheetTabRequest::query()->lockForUpdate()->find($requestId);
        if (! $sheetTab || $sheetTab->status !== 'pending') {
            throw ValidationException::withMessages(['name' => 'That Google Sheet tab has already been decided or was deleted from the sheet.']);
        }
        if (! $sheetTab->layout_ok) {
            throw ValidationException::withMessages(['name' => 'The tab "'.$sheetTab->title.'" is not in the Buildiwick layout, so its lines cannot be read. Ask her to copy them into a duplicate of the "+ New project" tab.']);
        }
        $project->forceFill(['sheet_tab_id' => $sheetTab->sheet_tab_id])->save();
        $sheetTab->update(['status' => 'approved', 'project_id' => $project->id, 'decided_by' => $director->id, 'decided_at' => now()]);
        $clerk = User::query()->where('username', (string) config('services.google_sheets.draft_username'))->first();
        if ($clerk && ! $clerk->all_projects) {
            $project->assignedUsers()->syncWithoutDetaching([$clerk->id]);
        }
    }

    /** Updates the sheet now (rename, heading, dropdowns); if Google is unreachable the 5-minute sync does it. */
    private function refreshSheetTabs(): void
    {
        if (! app(SheetsClient::class)->isConfigured()) {
            return;
        }
        try {
            app(ProjectSheetTabs::class)->newProjectTabs();
        } catch (\Throwable $e) {
            Log::warning('Sheet tab update after a decision failed; the next sync retries', ['error' => $e->getMessage()]);
        }
    }

    public function show(Project $project)
    {
        $this->authorize('manageSetup', Project::class);
        $this->authorize('view', $project);
        if (Features::recording()) {
            return view('projects.recording-form', $this->recordingFormData($project));
        }

        $project->load([
            'client',
            'phases' => fn ($query) => $query->orderBy('phase_order'),
            'assignedUsers.role',
            'contractDocuments.uploadedBy',
        ]);
        $team = $this->teamMembers($project);

        return view('projects.show', compact('project', 'team'));
    }

    public function edit(Project $project)
    {
        $this->authorize('update', $project);
        if (Features::recording()) {
            return view('projects.recording-form', $this->recordingFormData($project));
        }
        $project->load(['client', 'phases', 'assignedUsers.role', 'contractDocuments']);

        return view('projects.form', $this->formData($project));
    }

    public function update(Request $request, Project $project, ProjectRegistrationService $registration)
    {
        if (Features::recording()) {
            $this->authorize('update', $project);
            $data = $this->recordingData($request, $project);
            $project->update(collect($data)->only(['project_code', 'name', 'work_type', 'status', 'location', 'planned_start_date', 'expected_completion_date', 'notes'])->all() + ['is_company_overheads' => $request->boolean('is_company_overheads')]);
            $managers = User::query()->whereHas('role', fn ($query) => $query->where('name', 'project_manager'))->pluck('id');
            $project->assignedUsers()->detach($managers->diff($data['project_manager_ids'] ?? [])->all());
            $project->assignedUsers()->syncWithoutDetaching(array_filter($data['project_manager_ids'] ?? []));

            return redirect()->route('admin.projects.show', $project)->with('success', 'Project settings saved.');
        }
        $project = $registration->update($project, app(UpdateProjectSetupRequest::class)->validated(), $request->user());

        return redirect()->route('admin.projects.show', $project)->with('success', 'Project setup updated.');
    }

    public function downloadContract(Project $project, ProjectDocument $document): StreamedResponse
    {
        $this->authorize('viewContract', $document);
        abort_unless($document->project_id === $project->id && $document->document_type === 'contract', 404);
        abort_unless(Storage::disk('local')->exists($document->file_path), 404);

        return Storage::disk('local')->download($document->file_path, $document->file_name);
    }

    private function recordingFormData(?Project $project): array
    {
        return [
            'project' => $project,
            'statuses' => Lookup::ofType('project_status')->get(),
            'managers' => User::query()->where('is_active', true)->whereHas('role', fn ($query) => $query->where('name', 'project_manager'))->orderBy('name')->get(),
            'assigned' => $project ? $project->assignedUsers()->pluck('users.id')->all() : [],
            'stages' => self::TRACKER_STAGES,
        ];
    }

    /** Recording mode needs what the tracker records for a project (ID, name, status, location) plus its planned start and end dates. */
    private function recordingData(Request $request, ?Project $project): array
    {
        return $request->validate([
            'project_code' => ['required', 'string', 'max:50', 'regex:/^[A-Z0-9][A-Z0-9-]*$/', Rule::unique('projects', 'project_code')->ignore($project?->id)],
            'name' => ['required', 'string', 'max:150'],
            'status' => ['required', Rule::exists('lookups', 'code')->where('type', 'project_status')],
            'location' => ['required', 'string', 'max:150'],
            'work_type' => ['nullable', 'string', 'max:80'],
            'planned_start_date' => ['nullable', 'date'],
            'is_company_overheads' => ['nullable', 'boolean'],
            'expected_completion_date' => ['nullable', 'date', 'after_or_equal:planned_start_date'],
            'notes' => ['nullable', 'string', 'max:2000'],
            'project_manager_ids' => ['nullable', 'array'],
            'project_manager_ids.*' => ['integer', Rule::exists('users', 'id')->where('is_active', true)],
        ], ['project_code.regex' => 'Use capital letters, numbers and dashes, e.g. BWG-OYS-RES-2026-001.',
            'expected_completion_date.after_or_equal' => 'The end date cannot be before the start date.']);
    }

    private function formData(?Project $project): array
    {
        $clientQuery = Client::where('is_active', true)->orderBy('name');
        if ($project?->client_id) {
            $clientQuery->orWhereKey($project->client_id);
        }

        $teamUsers = [];
        foreach (['estimator_qs', 'project_manager', 'site_operator'] as $roleName) {
            $roleId = Role::where('name', $roleName)->value('id');
            $teamUsers[$roleName] = User::where('role_id', $roleId)->where('is_active', true)->orderBy('name')->get();
        }

        return [
            'project' => $project,
            'clients' => $clientQuery->get(),
            'teamUsers' => $teamUsers,
            'statuses' => Lookup::ofType('project_status')->whereIn('code', ['active', 'planning', 'on_hold', 'completed'])->get(),
            'phaseLabels' => self::PHASES,
            'team' => $project ? $this->teamMembers($project) : [],
        ];
    }

    private function teamMembers(Project $project): array
    {
        $users = $project->assignedUsers;

        return [
            'estimator_qs' => $users->first(fn (User $user) => $user->hasRole('estimator_qs')),
            'project_manager' => $users->first(fn (User $user) => $user->hasRole('project_manager')),
            'site_operator' => $users->first(fn (User $user) => $user->hasRole('site_operator')),
        ];
    }
}
