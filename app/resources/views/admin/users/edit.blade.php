@extends('layouts.app')
@section('title', 'Edit User · Buildiwick')
@section('content')
<a class="back-link" href="{{ route('admin.users.index') }}">← User Management</a>
<div class="topline"><div><h2>Edit {{ $managedUser->name }}</h2><p>Update role, access, contact details, or issue a new temporary password.</p></div></div>
<form method="POST" action="{{ route('admin.users.update', $managedUser) }}" class="panel form-section">@csrf @method('PUT')
@include('admin.users._form')
</form>
@endsection
