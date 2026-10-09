@extends('layouts.app')
@section('title', 'Create User · Buildiwick')
@section('content')
<a class="back-link" href="{{ route('admin.users.index') }}">← User Management</a>
<div class="topline"><div><h2>Create User</h2><p>Set the role and a temporary password for the new account.</p></div></div>
<form method="POST" action="{{ route('admin.users.store') }}" class="panel form-section">@csrf
@include('admin.users._form', ['managedUser' => null])
</form>
@endsection
