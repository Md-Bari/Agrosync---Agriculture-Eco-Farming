<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;

class AuthenticateApiToken
{
    public function handle(Request $request, Closure $next): Response
    {
        $plainToken = (string) $request->bearerToken();
        if ($plainToken === '') {
            return $this->deny('Authentication required.', 401);
        }

        $token = DB::table('api_tokens')
            ->where('token_hash', hash('sha256', $plainToken))
            ->where('expires_at', '>', now())
            ->first();
        $user = $token ? DB::table('users')->where('public_id', $token->user_public_id)->first() : null;

        if (! $user) {
            return $this->deny('Session expired. Please log in again.', 401);
        }

        if ($user->role === 'farmer' && DB::table('farmers')->where('user_id', $user->public_id)->value('blocked')) {
            return $this->deny('This farmer account is blocked.', 403);
        }

        if ($user->role === 'officer' && ! DB::table('officers')->where('user_id', $user->public_id)->value('active')) {
            return $this->deny('This officer account is not active.', 403);
        }

        if (! $token->last_used_at || now()->diffInMinutes($token->last_used_at, true) >= 5) {
            DB::table('api_tokens')->where('id', $token->id)->update(['last_used_at' => now()]);
        }

        $request->attributes->set('authUser', $user);
        $request->attributes->set('authTokenId', $token->id);

        return $next($request);
    }

    private function deny(string $message, int $status): Response
    {
        return response()->json(['status' => 'error', 'message' => $message], $status);
    }
}
