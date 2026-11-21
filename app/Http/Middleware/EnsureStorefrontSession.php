<?php

namespace App\Http\Middleware;

use App\Support\AccountSession;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

class EnsureStorefrontSession
{
    public function __construct(private EnsurePasswordIsChanged $passwordChanged) {}

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (! $user?->isClient()) {
            return $next($request);
        }

        if (! $user->is_active || ! AccountSession::isCurrent($request, $user) || ! $user->clientProfile()->exists()) {
            Auth::logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();

            if ($request->expectsJson()) {
                return response()->json(['message' => 'Please sign in again to continue.'], 401);
            }

            return to_route('client.login')->withErrors(['email' => 'Please sign in again to continue.']);
        }

        if ($request->routeIs('password.change', 'password.update')) {
            return $next($request);
        }

        return $this->passwordChanged->handle($request, $next);
    }
}
