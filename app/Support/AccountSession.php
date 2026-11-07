<?php

namespace App\Support;

use App\Models\User;
use Illuminate\Http\Request;

class AccountSession
{
    public const KEY = 'account_security_signature';

    /** Changes to credentials, role, email or access flags revoke older sessions. */
    public static function signature(User $user): string
    {
        return hash_hmac('sha256', json_encode([
            (int) $user->id, $user->email, $user->password,
            $user->role, (bool) $user->is_active,
            (bool) $user->must_change_password, $user->remember_token,
        ], JSON_THROW_ON_ERROR), (string) config('app.key'));
    }

    public static function remember(Request $request, User $user): void
    {
        $request->session()->put(self::KEY, self::signature($user));
    }
}
