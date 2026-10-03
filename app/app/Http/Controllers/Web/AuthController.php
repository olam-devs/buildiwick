<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class AuthController extends Controller
{
    public function show(Request $request)
    {
        if (Auth::check()) {
            return $this->roleLandingRedirect($request);
        }

        return view('auth.login');
    }

    public function login(Request $request)
    {
        $credentials = $request->validate([
            'email' => ['required', 'email'],
            'password' => ['required', 'string'],
        ]);

        if (! Auth::attempt($credentials)) {
            return back()->withErrors(['email' => 'Those credentials don\'t match our records.'])->onlyInput('email');
        }

        if (! Auth::user()->is_active) {
            Auth::logout();

            return back()->withErrors(['email' => 'Those credentials don\'t match our records.']);
        }

        $request->session()->regenerate();

        return $this->roleLandingRedirect($request);
    }

    public function logout(Request $request)
    {
        Auth::logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect('/login');
    }

    private function roleLandingRedirect(Request $request)
    {
        $route = match (Auth::user()->role?->name) {
            'admin' => 'dashboard.index',
            'director' => 'director.index',
            'accounts', 'accountant' => 'accountant.index',
            'project_manager' => 'pm.projects.index',
            'estimator_qs' => 'estimator.projects.index',
            'site_operator' => 'site.index',
            default => null,
        };

        if ($route === null) {
            Auth::logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();

            return redirect()->route('login')->withErrors(['email' => 'This account has no assigned application role.']);
        }

        return redirect()->route($route);
    }
}
