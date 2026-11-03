<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Hides Fortify's registration routes unless REGISTRATION_ENABLED is true.
 */
final class EnsureRegistrationEnabled
{
    public function handle(Request $request, Closure $next): Response
    {
        if ($request->routeIs('register', 'register.store') && ! config('leadradar.registration_enabled')) {
            abort(404);
        }

        return $next($request);
    }
}
