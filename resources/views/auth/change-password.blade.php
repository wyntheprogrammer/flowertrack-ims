<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Change Password - FlowerTrack IMS</title>

    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>

<body>
    <main class="container min-vh-100 d-flex align-items-center justify-content-center py-4">
        <div class="card border-0 shadow-sm rounded-4 w-100" style="max-width: 480px;">
            <div class="card-body p-4 p-md-5">
                <h1 class="h4 fw-bold mb-2">Change Your Password</h1>

                <p class="text-secondary small mb-4">
                    Replace your temporary password before continuing.
                    Your new password must contain at least 12 characters.
                </p>

                @if ($errors->any())
                    <div class="alert alert-danger small" role="alert">
                        {{ $errors->first() }}
                    </div>
                @endif

                <form method="POST" action="{{ route('password.update') }}">
                    @csrf
                    @method('PUT')

                    <div class="mb-3">
                        <label for="current_password" class="form-label small fw-semibold">
                            Current / Temporary Password
                        </label>

                        <input
                            type="password"
                            id="current_password"
                            name="current_password"
                            class="form-control"
                            autocomplete="current-password"
                            required
                            autofocus
                        >
                    </div>

                    <div class="mb-3">
                        <label for="password" class="form-label small fw-semibold">
                            New Password
                        </label>

                        <input
                            type="password"
                            id="password"
                            name="password"
                            class="form-control"
                            minlength="12"
                            autocomplete="new-password"
                            required
                        >
                    </div>

                    <div class="mb-4">
                        <label for="password_confirmation" class="form-label small fw-semibold">
                            Confirm New Password
                        </label>

                        <input
                            type="password"
                            id="password_confirmation"
                            name="password_confirmation"
                            class="form-control"
                            minlength="12"
                            autocomplete="new-password"
                            required
                        >
                    </div>

                    <button type="submit" class="btn btn-success w-100">
                        Update Password
                    </button>
                </form>

                <form method="POST" action="{{ route('logout') }}" class="mt-3">
                    @csrf

                    <button type="submit" class="btn btn-outline-secondary w-100">
                        Sign Out
                    </button>
                </form>
            </div>
        </div>
    </main>
</body>
</html>