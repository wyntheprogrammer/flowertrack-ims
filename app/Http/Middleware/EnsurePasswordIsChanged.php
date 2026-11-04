<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsurePasswordIsChanged
{
    public function handle(Request $request, Closure $next): Response
    {
        if ($request->user()?->must_change_password) {
            if ($request->expectsJson()) {
                return response()->json([
                    'message' => 'Change your temporary password before continuing.',
                ], 403);
            }

            return redirect()->route('password.change');
        }

        return $next($request);
    }
}