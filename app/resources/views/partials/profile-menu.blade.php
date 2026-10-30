{{-- Signed-in user's menu (top right): who is signed in, the theme switch and Sign out. --}}
@php
  $me = auth()->user();
  $roleNames = ['director' => 'Director', 'admin' => 'Director', 'project_manager' => 'Project Manager', 'accounts' => 'Accounts', 'accountant' => 'Accounts'];
  $initials = collect(preg_split('/\s+/', trim(preg_replace('/\(.*?\)/', '', $me->name))))->filter()->take(2)->map(fn ($part) => mb_strtoupper(mb_substr($part, 0, 1)))->join('');
@endphp
<details class="profile">
  <summary aria-label="Account menu"><span class="avatar">{{ $initials ?: 'U' }}</span><span class="profile-name">{{ $me->name }}</span><span class="caret" aria-hidden="true">▾</span></summary>
  <div class="profile-menu">
    <div class="profile-who"><span class="avatar lg">{{ $initials ?: 'U' }}</span><div><b>{{ $me->name }}</b><small>{{ $roleNames[$me->role?->name] ?? ucfirst(str_replace('_', ' ', (string) $me->role?->name)) }}</small><small>{{ $me->email }}</small></div></div>
    <div class="profile-label">Theme</div>
    <div class="theme-switch" role="group" aria-label="Theme"><button type="button" data-theme="light">☀ Light</button><button type="button" data-theme="dark">☾ Dark</button><button type="button" data-theme="auto">Auto</button></div>
    <form method="POST" action="{{ url('/logout') }}">@csrf<button type="submit" class="btn btn-ghost profile-signout">Sign out</button></form>
  </div>
</details>
