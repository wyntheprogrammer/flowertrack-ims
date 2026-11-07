<?php

namespace App\Http\Middleware;

use App\Support\AccountSession;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

class EnsureAccountSessionIsCurrent
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        $saved = $request->session()->get(AccountSession::KEY);

        if (!$user || !is_string($saved) || !hash_equals(AccountSession::signature($user), $saved)) {
            Auth::logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();

            if ($request->expectsJson()) {
                return response()->json(['message' => 'Account access changed. Please sign in again.'], 401);
            }

            return redirect()->route('login')->withErrors([
                'email' => 'Please sign in again to continue.',
            ]);
        }

        return $next($request);
    }
}
