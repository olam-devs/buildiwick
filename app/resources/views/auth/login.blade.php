<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Sign in &middot; Buildiwick</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap">
<style>
  :root{ --bg:#F4F5F6; --surface:#FFFFFF; --ink:#1F272E; --ink-dim:#8D99A6; --primary:#2490EF; --primary-dark:#1B75C4; --line:#E1E5E8; --line-strong:#D1D8DD; --danger:#E6533C; --danger-soft:#FBE9E6; }
  *{box-sizing:border-box;}
  body{margin:0;min-height:100vh;display:flex;align-items:center;justify-content:center;background:var(--bg);color:var(--ink);font-family:'Inter',-apple-system,BlinkMacSystemFont,'Segoe UI',sans-serif;padding:20px;}
  .card{width:100%;max-width:360px;background:var(--surface);border:1px solid var(--line);border-radius:12px;padding:32px 28px;box-shadow:0 1px 3px rgba(16,24,32,.06);}
  .brand{display:flex;align-items:center;gap:9px;margin-bottom:22px;}
  .brand .mark{width:28px;height:28px;border-radius:7px;background:var(--primary);color:#fff;display:flex;align-items:center;justify-content:center;font-weight:800;font-size:14px;}
  .brand span{font-weight:700;font-size:15px;}
  h1{font-size:21px;margin:0 0 20px;font-weight:700;letter-spacing:-0.01em;}
  label{display:block;font-size:12.5px;font-weight:600;color:var(--ink-dim);margin-bottom:6px;}
  input{width:100%;font-family:inherit;font-size:14px;padding:10px 12px;border:1px solid var(--line-strong);border-radius:8px;background:var(--surface);color:var(--ink);margin-bottom:16px;}
  input:focus{outline:none;border-color:var(--primary);box-shadow:0 0 0 3px rgba(36,144,239,.15);}
  button{width:100%;font-family:inherit;font-size:14.5px;font-weight:700;background:var(--primary);color:#fff;border:none;border-radius:8px;padding:11px;cursor:pointer;}
  button:hover{background:var(--primary-dark);}
  .err{background:var(--danger-soft);color:#a3392a;border-radius:8px;padding:9px 12px;font-size:12.5px;margin-bottom:16px;}
  .hint{font-size:11.5px;color:var(--ink-dim);margin-top:18px;line-height:1.7;}
</style>
</head>
<body>
  <div class="card">
    <div class="brand"><span class="mark">B</span><span>Buildiwick</span></div>
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
    <div class="hint">
      director@buildiwick.test<br>
      neema@buildiwick.test<br>
      grace@buildiwick.test<br>
      &middot; password for all: password
    </div>
  </div>
</body>
</html>
