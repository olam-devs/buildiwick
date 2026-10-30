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
use App\Models\User;
use App\Services\PhaseStructureService;
use App\Services\ProjectRegistrationService;
use App\Support\Features;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
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
        'Roofing', 'Finishing', 'External Works', 'Other / General',
    ];

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

    public function create()
    {
        $this->authorize('create', Project::class);
        if (Features::recording()) {
            return view('projects.recording-form', $this->recordingFormData(null));
        }

        return view('projects.form', $this->formData(null));
    }

    public function store(Request $request, ProjectRegistrationService $registration, PhaseStructureService $phases)
    {
        if (Features::recording()) {
            $this->authorize('create', Project::class);
            $data = $this->recordingData($request, null);
            $project = DB::transaction(function () use ($data, $request, $phases): Project {
                $project = Project::create(collect($data)->only(['project_code', 'name', 'status', 'location', 'planned_start_date', 'expected_completion_date', 'notes'])->all() + ['created_by' => $request->user()->id]);
                $project->assignedUsers()->sync(array_filter($data['project_manager_ids'] ?? []));
                if ($request->boolean('standard_stages')) {
                    foreach (self::TRACKER_STAGES as $stage) {
                        $phases->create($project, $stage, null, $request->user());
                    }
                }

                return $project;
            });

            return redirect()->route('ledger.structure', $project)->with('success', 'Project '.$project->project_code.' created. Set up its phases and budget below.');
        }
        $project = $registration->create(app(StoreProjectSetupRequest::class)->validated(), $request->user());

        return redirect()->route('admin.projects.show', $project)->with('success', 'Project '.$project->project_code.' registered.');
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
            $project->update(collect($data)->only(['project_code', 'name', 'status', 'location', 'planned_start_date', 'expected_completion_date', 'notes'])->all());
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
            'planned_start_date' => ['nullable', 'date'],
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
