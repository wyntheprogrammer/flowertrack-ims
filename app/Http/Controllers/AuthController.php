<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Rules\SafeAccountPassword;
use App\Support\AccountSession;
use Illuminate\Support\Facades\DB;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class AuthController extends Controller
{
    public function showLogin(): View|RedirectResponse
    {
        if (Auth::check()) {
            return $this->redirectAfterLogin(Auth::user());
        }

        return view('auth.login');
    }

    public function login(Request $request): RedirectResponse
    {
        $request->merge([
            'email' => Str::lower(trim((string) $request->input('email'))),
        ]);

        $credentials = $request->validate([
            'email' => ['required', 'email', 'max:254'],
            'password' => ['required', 'string', new SafeAccountPassword()],
        ]);

        $key = 'login:'.hash(
            'sha256',
            $credentials['email'].'|'.$request->ip()
        );

        if (RateLimiter::tooManyAttempts($key, 5)) {
            $seconds = RateLimiter::availableIn($key);

            throw ValidationException::withMessages([
                'email' => "Too many login attempts. Try again in {$seconds} seconds.",
            ]);
        }

        $authenticated = Auth::attempt([
            'email' => $credentials['email'],
            'password' => $credentials['password'],
            'is_active' => true,
        ]);

        if (! $authenticated) {
            RateLimiter::hit($key, 60);

            throw ValidationException::withMessages([
                'email' => 'Unable to sign in with these credentials.',
            ]);
        }

        $user = Auth::user();

        // Client storefront authentication will be connected separately.
        if (! in_array($user->role, [
            User::ROLE_ADMIN,
            User::ROLE_SUPPLIER,
            User::ROLE_DELIVERY,
        ], true)) {
            Auth::logout();

            $request->session()->invalidate();
            $request->session()->regenerateToken();

            RateLimiter::hit($key, 60);

            throw ValidationException::withMessages([
                'email' => 'Unable to sign in with these credentials.',
            ]);
        }

        RateLimiter::clear($key);

        // Prevent reuse of the session ID from before login.
        $request->session()->regenerate();
        AccountSession::remember($request, $user);

        return $this->redirectAfterLogin($user);
    }

    public function showChangePassword(Request $request): View|RedirectResponse
    {
        return view('auth.change-password');
    }

    public function updatePassword(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'current_password' => [
                'required',
                'string',
                'current_password:web',
            ],
            'password' => [
                'required',
                'string',
                'confirmed',
                Password::min(12),
                new SafeAccountPassword(),
            ],
        ]);

        $user = DB::transaction(function () use ($request, $validated) {
            $locked = User::query()->lockForUpdate()->findOrFail($request->user()->id);
            if (!$locked->is_active || !Hash::check($validated['current_password'], $locked->password)) {
                throw ValidationException::withMessages([
                    'current_password' => 'Account credentials changed. Sign in again.',
                ]);
            }
            if (Hash::check($validated['password'], $locked->password)) {
                throw ValidationException::withMessages([
                    'password' => 'Choose a different password from your current password.',
                ]);
            }
            $locked->password = $validated['password'];
            $locked->must_change_password = false;
            $locked->remember_token = Str::random(60);
            $locked->save();
            return $locked;
        });

        Auth::setUser($user);
        $request->session()->regenerate();
        $request->session()->regenerateToken();
        AccountSession::remember($request, $user);

        return $this->redirectAfterLogin($user)
            ->with('status', 'Your password has been changed.');
    }

    public function logout(Request $request): RedirectResponse
    {
        Auth::logout();

        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('login');
    }

    private function redirectAfterLogin(User $user): RedirectResponse
    {
        if ($user->must_change_password) {
            return redirect()->route('password.change');
        }

        if ($user->isDeliveryPersonnel()) {
            return redirect()->route('delivery.deliveries');
        }

        return redirect()->route('supplier.dashboard');
    }
}
