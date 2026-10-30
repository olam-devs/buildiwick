<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<link rel="icon" href="{{ asset('favicon.ico') }}" sizes="any">
<link rel="icon" type="image/png" sizes="32x32" href="{{ asset('images/favicon-32.png') }}">
<link rel="apple-touch-icon" href="{{ asset('images/apple-touch-icon.png') }}">
<meta name="theme-color" content="#0081C3">
<title>@yield('title', 'Buildiwick')</title>
@include('partials.theme')
@include('partials.password-toggle')
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap">
<style>
  *{box-sizing:border-box;}
  html,body{height:100%;}
  [hidden]{display:none!important;}
  body{margin:0;background:var(--bg);color:var(--ink);font-family:'Inter',-apple-system,BlinkMacSystemFont,'Segoe UI',sans-serif;font-size:13.5px;-webkit-font-smoothing:antialiased;}
  a{color:inherit;}
  button, input, select{font-family:inherit;}

  .shell{display:grid;grid-template-columns:232px 1fr;min-height:100%;transition:grid-template-columns .18s ease;}
  .shell.sidebar-collapsed{grid-template-columns:0 1fr;}
  .shell.sidebar-collapsed .sidebar{visibility:hidden;opacity:0;pointer-events:none;overflow:hidden;padding-left:0;padding-right:0;}
  .shell.sidebar-collapsed .main{max-width:none;}
  .sidebar-toggle{position:fixed;z-index:35;top:18px;left:214px;width:34px;height:34px;border:1px solid var(--line-strong);border-radius:8px;background:var(--surface);color:var(--ink-soft);box-shadow:0 2px 8px rgba(16,24,32,.10);cursor:pointer;transition:left .18s ease;}
  .sidebar-toggle:hover{color:var(--primary-dark);border-color:var(--primary);}
  .sidebar-collapsed .sidebar-toggle{left:12px;}
  .mobile-bar,.nav-scrim{display:none}
  @media (max-width:860px){
    .shell{grid-template-columns:1fr;} .sidebar-toggle{display:none;}
    .sidebar{position:fixed;inset:0 auto 0 0;width:270px;z-index:60;transform:translateX(-105%);transition:transform .2s ease;box-shadow:4px 0 24px rgba(16,24,32,.18);overflow-y:auto;}
    body.nav-open .sidebar{transform:none;}
    .nav-scrim{display:none;position:fixed;inset:0;background:var(--scrim);z-index:55;}
    body.nav-open .nav-scrim{display:block;}
    .mobile-bar{display:flex;align-items:center;gap:10px;position:sticky;top:0;z-index:40;margin:0 0 14px;border-radius:10px;background:var(--surface);border-bottom:1px solid var(--line);padding:10px 14px;}
    .mobile-bar > button{border:1px solid var(--line-strong);background:var(--surface);border-radius:8px;padding:6px 10px;font-size:16px;cursor:pointer;}
    .mobile-bar .title{font-weight:700;font-size:14px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;}
  }

  .sidebar{background:var(--surface);border-right:1px solid var(--line);padding:16px 12px;display:flex;flex-direction:column;gap:18px;transition:opacity .14s ease;}
  .brand{display:flex;align-items:center;gap:9px;padding:6px 8px 14px;border-bottom:1px solid var(--line);margin-bottom:2px;}
  .brand .mark{width:26px;height:26px;border-radius:6px;background:var(--primary);color:#fff;display:flex;align-items:center;justify-content:center;font-weight:800;font-size:13px;flex:none;}
  .brand span{font-weight:700;font-size:14.5px;letter-spacing:-0.01em;}
  .brand .mark-img{height:22px;width:auto;display:block;flex:none;}
  .mobile-bar .logo{height:20px;width:auto;flex:none;}

  .topbar{display:flex;justify-content:flex-end;margin:-10px 0 8px;min-height:36px}
  .profile{position:relative}
  .profile summary{list-style:none;display:flex;align-items:center;gap:8px;padding:4px 10px 4px 4px;border:1px solid var(--line);border-radius:99px;background:var(--surface);cursor:pointer;user-select:none}
  .profile summary::-webkit-details-marker{display:none}
  .profile summary:hover{border-color:var(--primary)}
  .profile .avatar{width:28px;height:28px;border-radius:50%;background:#0081C3;color:#fff;display:flex;align-items:center;justify-content:center;font-weight:700;font-size:11.5px;flex:none}
  .profile .avatar.lg{width:38px;height:38px;font-size:14px}
  .profile-name{font-size:12.5px;font-weight:600;max-width:180px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
  .profile .caret{font-size:10px;color:var(--ink-dim)}
  .profile-menu{position:absolute;right:0;top:calc(100% + 6px);z-index:70;width:270px;background:var(--surface);border:1px solid var(--line);border-radius:12px;box-shadow:0 12px 32px rgba(0,0,0,.18);padding:12px;display:grid;gap:10px}
  .profile-who{display:flex;gap:10px;align-items:center}.profile-who b{display:block;font-size:13px}.profile-who small{display:block;color:var(--ink-dim);font-size:11.5px;word-break:break-all}
  .profile-label{font-size:10.5px;font-weight:700;letter-spacing:.06em;text-transform:uppercase;color:var(--ink-dim)}
  .profile-signout{width:100%;justify-content:center}
  .mobile-bar .profile{margin-left:auto}.mobile-bar .profile-name,.mobile-bar .caret{display:none}.mobile-bar .profile summary{padding:2px;border:0;background:transparent}
  @media (max-width:860px){.topbar{display:none}}
  .whoami{display:flex;align-items:center;gap:9px;padding:8px;border-radius:8px;background:var(--surface-2);margin-bottom:4px;}
  .whoami .avatar{width:28px;height:28px;border-radius:50%;background:var(--primary-soft);color:var(--primary-dark);display:flex;align-items:center;justify-content:center;font-weight:700;font-size:11.5px;flex:none;}
  .whoami .who{min-width:0;}
  .whoami .name{font-size:12.5px;font-weight:600;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;}
  .whoami .role{font-size:10.5px;color:var(--ink-dim);text-transform:capitalize;}

  .navgroup{display:flex;flex-direction:column;gap:1px;}
  .navgroup .label{font-size:10.5px;font-weight:700;letter-spacing:.05em;text-transform:uppercase;color:var(--ink-dim);padding:10px 10px 4px;}
  .navitem{display:flex;align-items:center;gap:10px;padding:7px 10px;border-radius:6px;font-size:13px;font-weight:500;color:var(--ink-soft);text-decoration:none;}
  .navitem svg{flex:none;opacity:.75;}
  .navitem.active{background:var(--primary-soft);color:var(--primary-dark);font-weight:600;}
  .navitem.active svg{opacity:1;}
  .navitem:not(.active):hover{background:var(--surface-2);}
  .project-workspace-card{padding:10px;margin-bottom:7px;border:1px solid var(--line);border-radius:8px;background:var(--primary-soft)}
  .project-workspace-card .label{padding:0 0 4px;color:var(--primary-dark)}.project-workspace-card strong,.project-workspace-card small{display:block}.project-workspace-card strong{font-size:12.5px}.project-workspace-card small{font-size:10.5px;color:var(--ink-dim);margin-top:2px}.navitem.close-workspace{margin-top:8px;border:1px solid var(--line);justify-content:center}
  .form-section{margin-bottom:14px;}
  .form-section h3{margin-bottom:16px;}
  .phase-schedule{border-top:1px solid var(--line);padding-top:14px;margin-top:8px;}
  .phase-schedule h4{font-size:13px;margin:0 0 10px;font-weight:600;}
  .actions{white-space:nowrap;}
  .actions a{display:block;color:var(--primary-dark);text-decoration:none;margin:3px 0;font-size:12px;font-weight:600;}
  .back-link{display:inline-block;margin-bottom:10px;color:var(--ink-dim);text-decoration:none;font-size:12.5px;}
  .back-link:hover{color:var(--primary);}
  .project-list-main{max-width:none;}
  .responsive-project-table{width:100%;min-width:0;table-layout:fixed;}
  .responsive-project-table th,.responsive-project-table td{padding-left:10px;padding-right:10px;overflow-wrap:anywhere;}
  .responsive-project-table .name{width:22%;}
  @media(max-width:1100px){.responsive-project-table{min-width:900px;table-layout:auto;}}
  .estimate-entry-table{min-width:1320px;}
  .phase-heading{display:flex;align-items:flex-start;justify-content:space-between;gap:14px;margin-bottom:14px;}
  .phase-heading h3{margin:0 0 4px;}
  .phase-heading p,.form-context{color:var(--ink-dim);font-size:12px;margin:4px 0 12px;}
  .phase-metrics{grid-template-columns:repeat(auto-fit,minmax(155px,1fr));}
  .phase-metrics .tile .value{font-size:17px;}
  .phase-empty,.empty-state{padding:20px;color:var(--ink-dim);}
  .phase-empty a{color:var(--primary-dark);font-weight:600;margin-left:4px;}
  .estimate-actions{display:flex;align-items:center;gap:12px;flex-wrap:wrap;}
  .estimate-actions form{margin:0;}
  .entry-actions a,.entry-actions button{display:block;border:0;background:none;padding:2px 0;color:var(--primary-dark);font:inherit;font-size:12px;font-weight:600;cursor:pointer;text-decoration:none;}
  .entry-actions form{margin:0;}
  .status-pill{display:inline-flex;align-items:center;border-radius:999px;padding:4px 9px;font-size:11px;font-weight:600;text-transform:capitalize;white-space:nowrap;}
  .approval-pending_approval{background:var(--warn-soft);color:var(--warn-ink);}
  .approval-pending_director_approval{background:var(--violet-soft);color:var(--violet-ink);}
  .approval-approved{background:var(--good-soft);color:var(--good-ink);}
  .approval-rejected,.approval-cancelled{background:var(--danger-soft);color:var(--danger-ink);}
  .payment-unpaid{background:var(--surface-2);color:var(--ink-soft);}
  .payment-part_paid{background:var(--warn-soft);color:var(--warn-ink);}
  .payment-paid{background:var(--good-soft);color:var(--good-ink);}
  .request-actions{display:flex;gap:8px;margin:16px 0 32px;}
  .request-actions form{margin:0;}
  .request-submit-note{line-height:1.8;color:var(--ink-soft);}
  .site-project-table{font-size:12px;}
  .site-project-table thead th{font-size:10px;line-height:1.25;}
  .site-request-table{min-width:760px;}
  .request-detail-grid{grid-template-columns:repeat(auto-fit,minmax(210px,1fr));}
  tfoot th{padding:11px 16px;border-top:2px solid var(--line-strong);text-align:left;background:var(--surface-2);}
  @media(max-width:700px){.main{padding:18px 14px 40px}.phase-heading{display:block}.phase-heading .btn{margin-top:8px}.estimate-actions{align-items:flex-start;flex-direction:column}}

  .main{padding:22px 30px 60px;max-width:none;min-width:0;width:100%;}
  .crumb{font-size:12px;color:var(--ink-dim);margin-bottom:6px;}
  .crumb a{color:var(--ink-dim);text-decoration:none;} .crumb a:hover{color:var(--primary);}
  .topline{display:flex;align-items:flex-start;justify-content:space-between;gap:20px;margin-bottom:22px;flex-wrap:wrap;}
  .topline h2{font-size:22px;margin:0 0 4px;font-weight:700;letter-spacing:-0.02em;}
  .topline p{margin:0;color:var(--ink-dim);font-size:13px;}

  .btn{display:inline-flex;align-items:center;gap:7px;font-size:13px;font-weight:600;border-radius:7px;padding:9px 15px;cursor:pointer;border:1px solid transparent;text-decoration:none;}
  .btn-primary{background:var(--primary);color:#fff;box-shadow:0 1px 2px rgba(20,80,150,.25);}
  .btn-primary:hover{background:var(--primary-dark);}
  .btn-ghost{background:var(--surface);border-color:var(--line-strong);color:var(--ink-soft);}
  .btn-ghost:hover{background:var(--surface-2);}

  .tiles{display:grid;grid-template-columns:repeat(auto-fit,minmax(180px,1fr));gap:12px;margin-bottom:24px;}
  .tile{background:var(--surface);border:1px solid var(--line);border-radius:10px;padding:16px 17px;box-shadow:0 1px 2px rgba(16,24,32,.04);}
  .tile .label{font-size:11.5px;color:var(--ink-dim);font-weight:600;margin-bottom:7px;}
  .tile .value{font-size:22px;font-weight:700;font-variant-numeric:tabular-nums;letter-spacing:-0.01em;}
  .tile .sub{font-size:11px;color:var(--ink-dim);margin-top:3px;}

  .panelrow{display:grid;grid-template-columns:1.5fr 1fr;gap:14px;margin-bottom:24px;}
  @media (max-width:900px){ .panelrow{grid-template-columns:1fr;} }
  .panel{background:var(--surface);border:1px solid var(--line);border-radius:10px;padding:18px 19px;box-shadow:0 1px 2px rgba(16,24,32,.04);}
  .panel h3{font-size:12.5px;font-weight:700;margin:0 0 14px;color:var(--ink);}

  .bar-row{display:grid;grid-template-columns:150px 1fr 96px;align-items:center;gap:10px;margin-bottom:12px;font-size:12.5px;font-weight:500;}
  .bar-row:last-child{margin-bottom:0;}
  .bar-track{background:var(--surface-2);border-radius:5px;height:8px;overflow:hidden;}
  .bar-fill{height:100%;background:var(--primary);border-radius:5px;}
  .bar-amt{text-align:right;color:var(--ink-dim);font-variant-numeric:tabular-nums;}

  .attn-row{display:flex;justify-content:space-between;align-items:center;padding:9px 0;border-top:1px solid var(--line);font-size:12.5px;gap:10px;}
  .attn-row:first-of-type{border-top:none;}
  .attn-row .meta{color:var(--ink-dim);font-size:11.5px;flex:none;font-variant-numeric:tabular-nums;}

  .tablewrap{overflow-x:auto;border:1px solid var(--line);border-radius:10px;background:var(--surface);box-shadow:0 1px 2px rgba(16,24,32,.04);}
  table{width:100%;border-collapse:collapse;font-size:13px;min-width:760px;}
  thead th{text-align:left;padding:10px 16px;background:var(--surface-2);border-bottom:1px solid var(--line);font-size:11px;letter-spacing:.03em;text-transform:uppercase;color:var(--ink-dim);font-weight:600;}
  tbody td{padding:11px 16px;border-top:1px solid var(--line);}
  tbody tr.rowlink{cursor:pointer;}
  tbody tr.rowlink:hover{background:var(--surface-2);}
  td.amt{text-align:right;font-variant-numeric:tabular-nums;white-space:nowrap;}
  td.name{font-weight:600;}
  td.code{font-size:11.5px;color:var(--ink-dim);}

  .dot-status{display:inline-flex;align-items:center;gap:7px;font-size:12.5px;font-weight:500;}
  .dot-status::before{content:"";width:7px;height:7px;border-radius:50%;background:currentColor;flex:none;}
  .dot-status.active{color:var(--good);}
  .dot-status.zero{color:var(--ink-dim);}
  .dot-status.attn{color:var(--warn);}
  .dot-status.danger{color:var(--danger);}

  .badge{display:inline-flex;align-items:center;font-size:11px;font-weight:600;padding:3px 9px;border-radius:99px;}
  .badge.warn{background:var(--warn-soft);color:var(--warn-ink);}
  .badge.good{background:var(--good-soft);color:var(--good-ink);}

  footer{margin-top:26px;padding-top:14px;border-top:1px solid var(--line);font-size:11.5px;color:var(--ink-dim);}

  .flash{background:var(--good-soft);color:var(--good-ink);border:1px solid #b9e6cf;border-radius:8px;padding:11px 15px;font-size:13px;font-weight:500;margin-bottom:18px;}
  .flash.error{background:var(--danger-soft);color:var(--danger-ink);border-color:var(--danger-line);}

  .drawer-overlay{position:fixed;inset:0;background:rgba(20,28,36,.42);opacity:0;pointer-events:none;transition:opacity .2s ease;z-index:40;}
  .drawer-overlay.open{opacity:1;pointer-events:auto;}
  .drawer{position:fixed;top:0;right:0;bottom:0;width:min(460px,100%);background:var(--surface);box-shadow:-8px 0 30px rgba(16,24,32,.18);transform:translateX(100%);transition:transform .24s cubic-bezier(.32,.72,0,1);z-index:41;display:flex;flex-direction:column;}
  .drawer.open{transform:translateX(0);}
  @media (prefers-reduced-motion: reduce){ .drawer, .drawer-overlay{transition:none;} }
  .drawer-head{display:flex;align-items:center;justify-content:space-between;padding:18px 20px;border-bottom:1px solid var(--line);}
  .drawer-head h3{margin:0;font-size:16px;font-weight:700;}
  .drawer-close{background:none;border:none;font-size:20px;line-height:1;color:var(--ink-dim);cursor:pointer;padding:4px;}
  .drawer-close:hover{color:var(--ink);}
  .drawer-body{padding:18px 20px;overflow-y:auto;flex:1;}
  .drawer-foot{padding:14px 20px;border-top:1px solid var(--line);display:flex;gap:8px;}

  .cattabs{display:grid;grid-template-columns:repeat(2,1fr);gap:6px;margin-bottom:16px;}
  .cattab{font-size:12px;font-weight:600;padding:8px 6px;text-align:center;border-radius:7px;background:var(--surface-2);border:1px solid var(--line);color:var(--ink-soft);cursor:pointer;}
  .cattab.active{background:var(--primary-soft);border-color:var(--primary);color:var(--primary-dark);}

  .modetabs{display:flex;gap:6px;margin-bottom:14px;background:var(--surface-2);border-radius:7px;padding:3px;}
  .modetab{flex:1;font-size:12px;font-weight:600;padding:7px;text-align:center;border-radius:5px;border:none;background:none;color:var(--ink-dim);cursor:pointer;}
  .modetab.active{background:var(--surface);color:var(--primary-dark);box-shadow:0 1px 2px rgba(16,24,32,.08);}

  .field{margin-bottom:13px;}
  .field label{display:block;font-size:12px;font-weight:600;color:var(--ink-soft);margin-bottom:5px;}
  .field .req{color:var(--danger);}
  .field input, .field select, .field textarea{width:100%;font-size:13px;padding:8px 10px;border:1px solid var(--line-strong);border-radius:7px;background:var(--surface);color:var(--ink);}
  .field input:focus, .field select:focus, .field textarea:focus{outline:none;border-color:var(--primary);box-shadow:0 0 0 3px var(--primary-soft);}
  .row2{display:grid;grid-template-columns:1fr 1fr;gap:10px;}
  .row3{display:grid;grid-template-columns:1fr 1fr 1fr;gap:10px;}
  .field-err{color:var(--danger);font-size:11.5px;margin-top:4px;}
</style>
</head>
<body>
<div class="shell">
  <aside class="sidebar">
    <div class="brand"><img class="mark-img" src="{{ asset('images/buildiwick-mark-160.png') }}" alt="Buildiwick logo"><span>Buildiwick</span></div>
    <nav class="navgroup">
    @if(\App\Support\Features::recording() && auth()->check() && (auth()->user()->hasRole('admin') || in_array(auth()->user()->role?->name, ['project_manager', 'accounts', 'accountant'], true)))
      @include('partials.recording-nav')
    @else
      @if($workspaceProject ?? null)
      <div class="project-workspace-card"><div class="label">Project Workspace</div><strong>{{ $workspaceProject->name }}</strong><small>{{ $workspaceProject->project_code }}</small></div>
      @if(auth()->user()->hasRole('director'))
        <a class="navitem {{ request()->routeIs('director.projects.show') ? 'active' : '' }}" href="{{ route('director.projects.show', $workspaceProject) }}">Project Overview</a>
        <a class="navitem {{ request()->routeIs('estimator.projects.*') ? 'active' : '' }}" href="{{ route('estimator.projects.show', $workspaceProject) }}">Project Estimate</a>
        <a class="navitem {{ request()->routeIs('director.projects.warnings') ? 'active' : '' }}" href="{{ route('director.projects.warnings', $workspaceProject) }}">Warnings &amp; KPIs</a>
        <a class="navitem {{ request()->routeIs('director.requests.*') ? 'active' : '' }}" href="{{ route('director.requests.index', ['project_id'=>$workspaceProject->id]) }}">Request Oversight</a>
        <a class="navitem {{ request()->routeIs('director.receivables.*') ? 'active' : '' }}" href="{{ route('director.receivables.index', ['project_id'=>$workspaceProject->id]) }}">Receivables</a>
        <a class="navitem {{ request()->routeIs('director.payables.*') ? 'active' : '' }}" href="{{ route('director.payables.index', ['project_id'=>$workspaceProject->id]) }}">Approved Payables</a>
        <a class="navitem" href="{{ route('admin.projects.show', $workspaceProject) }}">Project Setup</a>
        <a class="navitem close-workspace" href="{{ route('director.index') }}">Close Project</a>
      @elseif(auth()->user()->hasRole('project_manager'))
        <a class="navitem {{ request()->routeIs('pm.projects.show') || request()->routeIs('pm.budget*') ? 'active' : '' }}" href="{{ route('pm.projects.show', $workspaceProject) }}">Overview &amp; Internal Budget</a>
        <a class="navitem {{ request()->routeIs('pm.requests.*') ? 'active' : '' }}" href="{{ route('pm.requests.index', ['project_id'=>$workspaceProject->id]) }}">Project Requests</a>
        <a class="navitem {{ request()->routeIs('pm.labour.*') ? 'active' : '' }}" href="{{ route('pm.labour.index', ['project_id'=>$workspaceProject->id]) }}">Labour &amp; Subcontracts</a>
        <a class="navitem" href="{{ route('pm.requests.create', $workspaceProject) }}">Create PM Request</a>
        <a class="navitem close-workspace" href="{{ route('pm.projects.index') }}">Close Project</a>
      @elseif(auth()->user()->hasRole('accountant') || auth()->user()->hasRole('accounts'))
        <a class="navitem {{ request()->routeIs('accountant.projects.show') ? 'active' : '' }}" href="{{ route('accountant.projects.show', $workspaceProject) }}">Project Overview</a>
        <a class="navitem {{ request()->routeIs('accountant.requests.*') ? 'active' : '' }}" href="{{ route('accountant.requests.index', ['project_id'=>$workspaceProject->id]) }}">Approved Payments</a>
        <a class="navitem {{ request()->routeIs('accountant.invoices.*') ? 'active' : '' }}" href="{{ route('accountant.invoices.index', ['project_id'=>$workspaceProject->id]) }}">Client Invoices</a>
        <a class="navitem {{ request()->routeIs('accountant.projects.transactions') ? 'active' : '' }}" href="{{ route('accountant.projects.transactions', $workspaceProject) }}">Transactions</a>
        <a class="navitem {{ request()->routeIs('accountant.projects.statement') ? 'active' : '' }}" href="{{ route('accountant.projects.statement', $workspaceProject) }}">Project Statement</a>
        <a class="navitem" href="{{ route('accountant.invoices.create', $workspaceProject) }}">Create Invoice</a>
        <a class="navitem close-workspace" href="{{ route('accountant.projects.index') }}">Close Project</a>
      @elseif(auth()->user()->hasRole('estimator_qs'))
        <a class="navitem active" href="{{ route('estimator.projects.show', $workspaceProject) }}">Project Estimate</a>
        <a class="navitem close-workspace" href="{{ route('estimator.projects.index') }}">Close Project</a>
      @elseif(auth()->user()->hasRole('site_operator'))
        <a class="navitem {{ request()->routeIs('site.projects.show') ? 'active' : '' }}" href="{{ route('site.projects.show', $workspaceProject) }}">Project Overview</a>
        <a class="navitem {{ request()->routeIs('site.requests.create') ? 'active' : '' }}" href="{{ route('site.requests.create', $workspaceProject) }}">New Site Request</a>
        <a class="navitem close-workspace" href="{{ route('site.index') }}">Close Project</a>
      @endif
      @else
      <div class="label">{{ auth()->user()->hasRole('director') ? 'Analytics' : 'Overview' }}</div>
      @if (auth()->user()->hasRole('estimator_qs'))
      <a class="navitem {{ request()->routeIs('estimator.projects.*') ? 'active' : '' }}" href="{{ route('estimator.projects.index') }}">Estimator Dashboard</a>
      @elseif (auth()->user()->hasRole('site_operator'))
      <a class="navitem {{ request()->routeIs('site.index') || request()->routeIs('site.projects.*') ? 'active' : '' }}" href="{{ route('site.index') }}">Projects</a>
      <a class="navitem {{ request()->routeIs('site.requests.*') ? 'active' : '' }}" href="{{ route('site.requests.index') }}">Expense Requests</a>
      @elseif (auth()->user()->hasRole('project_manager'))
      <a class="navitem {{ request()->routeIs('pm.projects.*') ? 'active' : '' }}" href="{{ route('pm.projects.index') }}">Projects</a>
      <a class="navitem {{ request()->routeIs('pm.budget-entries.*') || request()->routeIs('pm.budgets.*') ? 'active' : '' }}" href="{{ route('pm.projects.index') }}">Internal Budgets</a>
      <a class="navitem {{ request()->routeIs('pm.requests.*') ? 'active' : '' }}" href="{{ route('pm.requests.index') }}">Project Requests</a>
      <a class="navitem {{ request()->routeIs('pm.labour.*') ? 'active' : '' }}" href="{{ route('pm.labour.index') }}">Labour &amp; Subcontracts</a>
      @elseif (auth()->user()->hasRole('accountant') || auth()->user()->hasRole('accounts'))
      <a class="navitem {{ request()->routeIs('accountant.index') ? 'active' : '' }}" href="{{ route('accountant.index') }}">Dashboard</a>
      <a class="navitem {{ request()->routeIs('accountant.requests.*') ? 'active' : '' }}" href="{{ route('accountant.requests.index') }}">Approved Payments</a>
      <a class="navitem {{ request()->routeIs('accountant.invoices.*') ? 'active' : '' }}" href="{{ route('accountant.invoices.index') }}">Client Invoices</a>
      <a class="navitem {{ request()->routeIs('accountant.transactions.*') ? 'active' : '' }}" href="{{ route('accountant.transactions.index') }}">Transactions</a>
      <a class="navitem {{ request()->routeIs('accountant.receivables.*') ? 'active' : '' }}" href="{{ route('accountant.receivables.index') }}">Receivables</a>
      <a class="navitem {{ request()->routeIs('accountant.payables.*') ? 'active' : '' }}" href="{{ route('accountant.payables.index') }}">Approved Payables</a>
      <a class="navitem {{ request()->routeIs('accountant.projects.*') ? 'active' : '' }}" href="{{ route('accountant.projects.index') }}">Projects</a>
      @elseif (auth()->user()->hasRole('director'))
      <a class="navitem {{ request()->routeIs('director.index') || request()->routeIs('director.projects.*') ? 'active' : '' }}" href="{{ route('director.index') }}">Executive Dashboard</a>
      <a class="navitem {{ request()->routeIs('director.requests.*') ? 'active' : '' }}" href="{{ route('director.requests.index') }}">Request Oversight</a>
      <a class="navitem {{ request()->routeIs('director.receivables.*') ? 'active' : '' }}" href="{{ route('director.receivables.index') }}">Receivables</a>
      <a class="navitem {{ request()->routeIs('director.payables.*') ? 'active' : '' }}" href="{{ route('director.payables.index') }}">Approved Payables</a>
      <a class="navitem {{ request()->routeIs('director.transactions.*') ? 'active' : '' }}" href="{{ route('director.transactions.index') }}">Transactions</a>
      <div class="label">Company Targets</div>
      <a class="navitem {{ request()->routeIs('director.kpis.*') ? 'active' : '' }}" href="{{ route('director.kpis.index') }}">Company KPI Targets</a>
      <a class="navitem {{ request()->routeIs('director.warning-rules.*') ? 'active' : '' }}" href="{{ route('director.warning-rules.index') }}">Default Warning Rules</a>
      @else
      <a class="navitem {{ request()->routeIs('dashboard.index') ? 'active' : '' }}" href="{{ url('/projects') }}">
        <svg width="15" height="15" viewBox="0 0 20 20" fill="none"><rect x="2.5" y="2.5" width="6" height="6" rx="1.4" stroke="currentColor" stroke-width="1.6"/><rect x="11.5" y="2.5" width="6" height="6" rx="1.4" stroke="currentColor" stroke-width="1.6"/><rect x="2.5" y="11.5" width="6" height="6" rx="1.4" stroke="currentColor" stroke-width="1.6"/><rect x="11.5" y="11.5" width="6" height="6" rx="1.4" stroke="currentColor" stroke-width="1.6"/></svg>
        All Projects
      </a>
      @endif
      @if (auth()->user()->hasRole('director'))
        <div class="label">Administration</div>
      @endif
      @can('manageSetup', App\Models\Project::class)
        <a class="navitem {{ request()->routeIs('admin.projects.*') ? 'active' : '' }}" href="{{ route('admin.projects.index') }}">Project Setup</a>
        <a class="navitem {{ request()->routeIs('estimator.projects.*') ? 'active' : '' }}" href="{{ route('estimator.projects.index') }}">Estimator Estimates</a>
      @endcan
      @if (auth()->user()->hasRole('director'))
        <a class="navitem {{ request()->routeIs('admin.users.*') ? 'active' : '' }}" href="{{ route('admin.users.index') }}">User Management</a>
      @endif
      @endif
    @endif
    </nav>
  </aside>
  <button class="sidebar-toggle" id="sidebar-toggle" type="button" aria-label="Minimize sidebar" aria-expanded="true" title="Minimize sidebar">&#x2039;</button>
  <div class="nav-scrim" onclick="document.body.classList.remove('nav-open')"></div>
  <main class="main @yield('mainClass')">
    <div class="mobile-bar"><button type="button" aria-label="Open menu" onclick="document.body.classList.add('nav-open')">&#9776;</button><img class="logo" src="{{ asset('images/buildiwick-mark-160.png') }}" alt="Buildiwick"><span class="title">@yield('title', 'Buildiwick')</span>@auth @include('partials.profile-menu') @endauth</div>
    @auth<div class="topbar">@include('partials.profile-menu')</div>@endauth
    @if (session('success'))<div class="flash">{{ session('success') }}</div>@endif
    @yield('content')
  </main>
</div>

@stack('drawers')
<script>
(() => {
  const shell = document.querySelector('.shell');
  const toggle = document.getElementById('sidebar-toggle');
  if (!shell || !toggle) return;
  const apply = collapsed => {
    shell.classList.toggle('sidebar-collapsed', collapsed);
    toggle.innerHTML = collapsed ? '&#x203A;' : '&#x2039;';
    toggle.setAttribute('aria-expanded', String(!collapsed));
    toggle.setAttribute('aria-label', collapsed ? 'Expand sidebar' : 'Minimize sidebar');
    toggle.title = collapsed ? 'Expand sidebar' : 'Minimize sidebar';
  };
  apply(localStorage.getItem('buildiwick-sidebar-collapsed') === '1');
  toggle.addEventListener('click', () => {
    const collapsed = !shell.classList.contains('sidebar-collapsed');
    apply(collapsed);
    localStorage.setItem('buildiwick-sidebar-collapsed', collapsed ? '1' : '0');
  });
})();
</script>
@include('partials.page-loader')
<script>
  // Close the account menu when clicking elsewhere.
  document.addEventListener('click', function (e) { document.querySelectorAll('details.profile[open]').forEach(function (d) { if (!d.contains(e.target)) { d.removeAttribute('open'); } }); });
</script>
</body>
</html>
