<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Support\Features;
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

    /** Where a role starts; recording mode sends PM and Accounts to the project ledger and has no estimator/site screens. */
    public static function landingRoute(?string $role): ?string
    {
        if (Features::recording()) {
            return match ($role) {
                'admin' => 'ledger.index',
                'director' => 'director.index',
                'accounts', 'accountant', 'project_manager' => 'ledger.index',
                default => null,
            };
        }

        return match ($role) {
            'admin' => 'dashboard.index',
            'director' => 'director.index',
            'accounts', 'accountant' => 'accountant.index',
            'project_manager' => 'pm.projects.index',
            'estimator_qs' => 'estimator.projects.index',
            'site_operator' => 'site.index',
            default => null,
        };
    }

    private function roleLandingRedirect(Request $request)
    {
        $route = self::landingRoute(Auth::user()->role?->name);

        if ($route === null) {
            Auth::logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();

            return redirect()->route('login')->withErrors(['email' => Features::recording()
                ? 'This role is not used in the current phase of the system. Ask the Director for access.'
                : 'This account has no assigned application role.']);
        }

        return redirect()->route($route);
    }
}
