<?php

namespace App\Http\Middleware;

use App\Support\Features;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/** Routes of a switched-off rollout feature behave as if they do not exist. */
class RequireFeature
{
    public function handle(Request $request, Closure $next, string $feature): Response
    {
        abort_unless(Features::on($feature), 404);

        return $next($request);
    }
}
