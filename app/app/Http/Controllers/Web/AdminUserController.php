<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreAdminUserRequest;
use App\Http\Requests\UpdateAdminUserRequest;
use App\Models\Role;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Arr;
use Illuminate\View\View;

class AdminUserController extends Controller
{
    public function index(): View
    {
        $this->authorize('manage-users');

        return view('admin.users.index', [
            'users' => User::with('role')->orderBy('name')->get(),
        ]);
    }

    public function create(): View
    {
        $this->authorize('manage-users');

        return view('admin.users.create', ['roles' => $this->roles()]);
    }

    public function store(StoreAdminUserRequest $request): RedirectResponse
    {
        User::create($request->validated());

        return redirect()->route('admin.users.index')->with('success', 'User account created. Share the temporary password securely.');
    }

    public function edit(User $user): View
    {
        $this->authorize('manage-users');

        return view('admin.users.edit', ['managedUser' => $user, 'roles' => $this->roles()]);
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
        $user->update($data);

        return redirect()->route('admin.users.index')->with('success', 'User account updated.');
    }

    private function roles()
    {
        return Role::orderBy('name')->get();
    }
}
