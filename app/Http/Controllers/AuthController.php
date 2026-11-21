<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Rules\SafeAccountPassword;
use App\Support\AccountSession;
use App\Support\CheckoutSession;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class AuthController extends Controller
{
    private const STOREFRONT_RETURN = 'shop.sign_in_return';

    public function showLogin(Request $request): View|RedirectResponse
    {
        if (Auth::check()) {
            return $this->redirectAfterLogin($request, Auth::user());
        }

        return view('auth.login');
    }

    public function showClientLogin(Request $request): View|RedirectResponse
    {
        $destination = $this->clientDestination($request->query('continue'));

        if ($request->user()?->isClient()) {
            return $this->redirectToClientDestination($destination);
        }

        return view('client.sign-in', compact('destination'));
    }

    public function login(Request $request): RedirectResponse
    {
        $email = $request->input('email');
        if (is_string($email)) {
            $request->merge(['email' => Str::lower(trim($email))]);
        }

        $credentials = $request->validate([
            'email' => ['required', 'email', 'max:254'],
            'password' => ['required', 'string', new SafeAccountPassword],
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

        $storefront = $request->routeIs('client.login.store');
        $authenticated = Auth::attemptWhen([
            'email' => $credentials['email'],
            'password' => $credentials['password'],
            'is_active' => true,
        ], fn (User $user): bool => $storefront
            ? $user->isClient() && $user->clientProfile()->exists()
            : in_array($user->role, [User::ROLE_ADMIN, User::ROLE_SUPPLIER, User::ROLE_DELIVERY], true));

        if (! $authenticated) {
            RateLimiter::hit($key, 60);

            throw ValidationException::withMessages([
                'email' => 'Unable to sign in with these credentials.',
            ]);
        }

        $user = Auth::user();

        if ($storefront) {
            $request->session()->put(self::STOREFRONT_RETURN, $this->clientDestination($request->input('continue')));
        }

        RateLimiter::clear($key);

        // Prevent reuse of the session ID from before login.
        $request->session()->regenerate();
        AccountSession::remember($request, $user);

        return $this->redirectAfterLogin($request, $user);
    }

    public function showChangePassword(Request $request): View|RedirectResponse
    {
        return view($request->user()->isClient() ? 'client.change-password' : 'auth.change-password');
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
                new SafeAccountPassword,
            ],
        ]);

        $user = DB::transaction(function () use ($request, $validated) {
            $locked = User::query()->lockForUpdate()->findOrFail($request->user()->id);
            if (! $locked->is_active || ! Hash::check($validated['current_password'], $locked->password)) {
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

        return $this->redirectAfterLogin($request, $user)
            ->with('status', 'Your password has been changed.');
    }

    public function logout(Request $request): RedirectResponse
    {
        $storefront = $request->user()->isClient();
        $switching = $request->routeIs('client.login.switch');
        $session = $request->session();
        $shoppingState = $switching ? array_filter([
            CheckoutSession::CART => $session->get(CheckoutSession::CART),
            CheckoutSession::SELECTION => $session->get(CheckoutSession::SELECTION),
        ], fn (mixed $value): bool => $value !== null) : [];
        Auth::logout();

        $session->invalidate();
        $session->regenerateToken();

        if ($switching) {
            $session->put($shoppingState);
            $destination = $this->clientDestination($request->input('continue'));

            return to_route('client.login', $destination === 'client.home' ? [] : ['continue' => $destination]);
        }

        return to_route($storefront ? 'client.home' : 'login');
    }

    private function redirectAfterLogin(Request $request, User $user): RedirectResponse
    {
        if ($user->must_change_password) {
            return redirect()->route('password.change');
        }

        if ($user->isClient()) {
            return $this->redirectToClientDestination($request->session()->pull(self::STOREFRONT_RETURN));
        }

        if ($user->isDeliveryPersonnel()) {
            return redirect()->route('delivery.deliveries');
        }

        return redirect()->route('supplier.dashboard');
    }

    private function clientDestination(mixed $destination): string
    {
        return in_array($destination, ['client.checkout', 'client.checkout.delivery'], true)
            ? $destination
            : 'client.home';
    }

    private function redirectToClientDestination(mixed $destination): RedirectResponse
    {
        return match ($this->clientDestination($destination)) {
            'client.checkout.delivery' => to_route('client.checkout', ['method' => 'delivery']),
            'client.checkout' => to_route('client.checkout'),
            default => to_route('client.home'),
        };
    }
}
