<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreAdminUserRequest;
use App\Http\Requests\UpdateAdminUserRequest;
use App\Models\Project;
use App\Models\Role;
use App\Models\User;
use App\Support\Access;
use App\Support\Features;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Arr;
use Illuminate\View\View;

class AdminUserController extends Controller
{
    public function index(): View
    {
        $this->authorize('manage-users');

        return view('admin.users.index', [
            'users' => User::with('role')->withCount('assignedProjects')->orderBy('name')->get(),
        ]);
    }

    public function create(): View
    {
        $this->authorize('manage-users');

        return view('admin.users.create', ['roles' => $this->roles()] + $this->accessData(null));
    }

    public function store(StoreAdminUserRequest $request): RedirectResponse
    {
        $data = $request->validated();
        $user = User::create(Arr::except($data, ['permissions', 'all_projects', 'project_ids']) + $this->accessAttributes($request, $data));
        $user->assignedProjects()->sync($user->all_projects ? [] : ($data['project_ids'] ?? []));

        return redirect()->route('admin.users.index')->with('success', 'User account created. Share the temporary password securely.');
    }

    public function edit(User $user): View
    {
        $this->authorize('manage-users');

        return view('admin.users.edit', ['managedUser' => $user, 'roles' => $this->roles()] + $this->accessData($user));
    }

    public function update(UpdateAdminUserRequest $request, User $user): RedirectResponse
    {
        $data = $request->validated();
        if ($request->user()->is($user) && (! (bool) $data['is_active'] || (int) $data['role_id'] !== (int) $user->role_id)) {
            return back()->withErrors(['is_active' => 'You cannot deactivate your own account or change your own role.'])->withInput();
        }

        if (blank($data['password'] ?? null)) {
            $data = Arr::except($data, ['password']);
        }
        $user->update(Arr::except($data, ['permissions', 'all_projects', 'project_ids']) + $this->accessAttributes($request, $data));
        $user->assignedProjects()->sync($user->all_projects ? [] : ($data['project_ids'] ?? []));

        return redirect()->route('admin.users.index')->with('success', 'User account updated.');
    }

    /** Directors always have everything; other roles keep exactly the boxes ticked. */
    private function accessAttributes($request, array $data): array
    {
        $role = Role::whereKey($data['role_id'])->value('name');
        if (in_array($role, ['director', 'admin'], true)) {
            return ['permissions' => null, 'all_projects' => true];
        }

        return ['permissions' => array_values($data['permissions'] ?? []), 'all_projects' => $request->boolean('all_projects')];
    }

    private function accessData(?User $user): array
    {
        return [
            'projects' => Project::query()->orderBy('name')->get(['id', 'name', 'project_code']),
            'assignedIds' => $user ? $user->assignedProjects()->pluck('projects.id')->all() : [],
            'roleDefaults' => Role::query()->get()->mapWithKeys(fn (Role $role) => [$role->id => [
                'permissions' => Access::defaultsFor($role->name),
                'all_projects' => in_array($role->name, Access::ALL_PROJECTS_BY_DEFAULT, true),
                'director' => in_array($role->name, ['director', 'admin'], true),
            ]])->all(),
        ];
    }

    private function roles()
    {
        return Role::where('name', '!=', 'admin')
            ->when(Features::recording(), fn ($query) => $query->whereNotIn('name', ['estimator_qs', 'site_operator']))
            ->orderBy('name')->get();
    }
}
