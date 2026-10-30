@extends('layouts.app')
@section('title', 'User Management · Buildiwick')
@section('content')
<div class="topline">
  <div><h2>User Management</h2><p>Create accounts, assign roles, reset temporary passwords, and control access.</p></div>
  <a class="btn btn-primary" href="{{ route('admin.users.create') }}">Create User</a>
</div>
<div class="tablewrap"><table><thead><tr><th>Name</th><th>Email</th><th>Phone</th><th>Role</th><th>Projects</th><th>Access</th><th>Action</th></tr></thead><tbody>
@forelse($users as $user)
<tr><td class="name">{{ $user->name }}</td><td>{{ $user->email }}</td><td>{{ $user->phone ?: '—' }}</td><td>{{ ucwords(str_replace('_', ' ', $user->role?->name ?? 'Unassigned')) }}</td><td>{{ $user->seesAllProjects() ? 'All projects' : ($user->assigned_projects_count ? $user->assigned_projects_count.' project(s)' : 'None') }}</td><td><span class="dot-status {{ $user->is_active ? 'active' : 'zero' }}">{{ $user->is_active ? 'Active' : 'Inactive' }}</span></td><td><a href="{{ route('admin.users.edit', $user) }}">Edit</a></td></tr>
@empty<tr><td colspan="7">No users found.</td></tr>@endforelse
</tbody></table></div>
@endsection
