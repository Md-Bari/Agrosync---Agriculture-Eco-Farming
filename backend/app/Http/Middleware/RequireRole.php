<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class RequireRole
{
    public function handle(Request $request, Closure $next, string ...$roles): Response
    {
        $user = $request->attributes->get('authUser');

        if (! $user || ! in_array($user->role, $roles, true)) {
            return response()->json(['status' => 'error', 'message' => 'You do not have permission to do this.'], 403);
        }

        return $next($request);
    }
}
