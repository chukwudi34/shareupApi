<?php

namespace App\Http\Middleware;

use App\Models\ApiToken;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class AuthenticateApiToken
{
    public function handle(Request $request, Closure $next, ?string $mode = null): Response
    {
        $optional = $mode === 'optional';
        $plain = $request->bearerToken();

        if (! $plain) {
            return $optional
                ? $next($request)
                : response()->json(['message' => 'Authentication required.'], 401);
        }

        $token = ApiToken::with('user')->where('token_hash', hash('sha256', $plain))->first();

        if (! $token || ($token->expires_at && $token->expires_at->isPast())) {
            return $optional
                ? $next($request)
                : response()->json(['message' => 'Your session has expired. Please sign in again.'], 401);
        }

        if (! $token->user || $token->user->status !== 'ACTIVE') {
            return $optional
                ? $next($request)
                : response()->json(['message' => 'This account is not active.'], 403);
        }

        $token->forceFill(['last_used_at' => now()])->save();

        $request->attributes->set('api_token', $token);
        $request->setUserResolver(fn () => $token->user);

        return $next($request);
    }
}
