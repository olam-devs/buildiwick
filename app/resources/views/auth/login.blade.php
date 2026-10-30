<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<link rel="icon" href="{{ asset('favicon.ico') }}" sizes="any">
<link rel="icon" type="image/png" sizes="32x32" href="{{ asset('images/favicon-32.png') }}">
<link rel="apple-touch-icon" href="{{ asset('images/apple-touch-icon.png') }}">
<meta name="theme-color" content="#0081C3">
<title>Sign in &middot; Buildiwick</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap">
@include('partials.theme')
@include('partials.password-toggle')
<style>
  *{box-sizing:border-box;}
  body{margin:0;min-height:100vh;display:flex;align-items:center;justify-content:center;background:var(--bg);color:var(--ink);font-family:'Inter',-apple-system,BlinkMacSystemFont,'Segoe UI',sans-serif;padding:20px;}
  .card{width:100%;max-width:360px;background:var(--surface);border:1px solid var(--line);border-radius:12px;padding:32px 28px;box-shadow:0 1px 3px rgba(16,24,32,.06);}
  .brand{display:flex;align-items:center;gap:9px;margin-bottom:22px;}
  .brand .mark{width:28px;height:28px;border-radius:7px;background:var(--primary);color:#fff;display:flex;align-items:center;justify-content:center;font-weight:800;font-size:14px;}
  .brand span{font-weight:700;font-size:17px;}
  .login-theme{position:fixed;top:14px;right:14px;width:210px}
  .brand .logo{height:34px;width:auto;display:block;}
  h1{font-size:21px;margin:0 0 20px;font-weight:700;letter-spacing:-0.01em;}
  label{display:block;font-size:12.5px;font-weight:600;color:var(--ink-dim);margin-bottom:6px;}
  input{width:100%;font-family:inherit;font-size:14px;padding:10px 12px;border:1px solid var(--line-strong);border-radius:8px;background:var(--surface);color:var(--ink);margin-bottom:16px;}
  input:focus{outline:none;border-color:var(--primary);box-shadow:0 0 0 3px rgba(36,144,239,.15);}
  button[type=submit]{width:100%;font-family:inherit;font-size:14.5px;font-weight:700;background:var(--primary);color:#fff;border:none;border-radius:8px;padding:11px;cursor:pointer;}
  button[type=submit]:hover{background:var(--primary-dark);}
  .err{background:var(--danger-soft);color:var(--danger-ink);border-radius:8px;padding:9px 12px;font-size:12.5px;margin-bottom:16px;}
  .hint{font-size:11.5px;color:var(--ink-dim);margin-top:18px;line-height:1.7;}
</style>
</head>
<body>
  <div class="theme-switch login-theme" role="group" aria-label="Theme"><button type="button" data-theme="light">☀ Light</button><button type="button" data-theme="dark">☾ Dark</button><button type="button" data-theme="auto">Auto</button></div>
  <div class="card">
    <div class="brand"><img src="{{ asset('images/buildiwick-mark.png') }}" alt="Buildiwick logo" class="logo"><span>Buildiwick</span></div>
    <h1>Sign in</h1>
    @if ($errors->any())
      <div class="err">{{ $errors->first() }}</div>
    @endif
    <form method="POST" action="{{ url('/login') }}">
      @csrf
      <label for="email">Email</label>
      <input id="email" name="email" type="email" value="{{ old('email') }}" autofocus>
      <label for="password">Password</label>
      <input id="password" name="password" type="password">
      <button type="submit">Sign in</button>
    </form>
    @if (app()->environment(['local', 'testing']))
    <div class="hint">
      director@buildiwick.test<br>
      neema@buildiwick.test<br>
      grace@buildiwick.test<br>
      &middot; password for all: password
    </div>
    @endif
  </div>
@include('partials.page-loader')
</body>
</html>
