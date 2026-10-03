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
use App\Services\ProjectRegistrationService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
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

    public function index(Request $request)
    {
        $this->authorize('manageSetup', Project::class);

        $projects = Project::with(['client', 'activePhase', 'assignedUsers.role'])
            ->orderBy('name')
            ->get();

        return view('projects.index', compact('projects'));
    }

    public function create()
    {
        $this->authorize('create', Project::class);

        return view('projects.form', $this->formData(null));
    }

    public function store(StoreProjectSetupRequest $request, ProjectRegistrationService $registration)
    {
        $project = $registration->create($request->validated(), $request->user());

        return redirect()->route('admin.projects.show', $project)->with('success', 'Project '.$project->project_code.' registered.');
    }

    public function show(Project $project)
    {
        $this->authorize('manageSetup', Project::class);
        $this->authorize('view', $project);

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
        $project->load(['client', 'phases', 'assignedUsers.role', 'contractDocuments']);

        return view('projects.form', $this->formData($project));
    }

    public function update(UpdateProjectSetupRequest $request, Project $project, ProjectRegistrationService $registration)
    {
        $project = $registration->update($project, $request->validated(), $request->user());

        return redirect()->route('admin.projects.show', $project)->with('success', 'Project setup updated.');
    }

    public function downloadContract(Project $project, ProjectDocument $document): StreamedResponse
    {
        $this->authorize('viewContract', $document);
        abort_unless($document->project_id === $project->id && $document->document_type === 'contract', 404);
        abort_unless(Storage::disk('local')->exists($document->file_path), 404);

        return Storage::disk('local')->download($document->file_path, $document->file_name);
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