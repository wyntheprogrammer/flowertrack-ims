<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Sign In - FlowerTrack IMS</title>

    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>

<body>
    <main class="container min-vh-100 d-flex align-items-center justify-content-center py-4">
        <div class="card border-0 shadow-sm rounded-4 w-100" style="max-width: 430px;">
            <div class="card-body p-4 p-md-5">
                <div class="text-center mb-4">
                    <img
                        src="{{ asset('images/gemmas-logo.png') }}"
                        alt="Gemma's Flower Shop"
                        class="img-fluid mb-3"
                        style="max-height: 100px;"
                    >

                    <h1 class="h4 fw-bold">Sign In</h1>
                    <p class="text-secondary small mb-0">
                        Access your FlowerTrack IMS account.
                    </p>
                </div>

                @if (session('status'))
                    <div class="alert alert-success small" role="status">
                        {{ session('status') }}
                    </div>
                @endif

                @if ($errors->any())
                    <div class="alert alert-danger small" role="alert">
                        {{ $errors->first() }}
                    </div>
                @endif

                <form method="POST" action="{{ route('login.store') }}">
                    @csrf

                    <div class="mb-3">
                        <label for="email" class="form-label small fw-semibold">
                            Email Address
                        </label>

                        <input
                            type="email"
                            id="email"
                            name="email"
                            class="form-control"
                            value="{{ old('email') }}"
                            maxlength="254"
                            autocomplete="username"
                            required
                            autofocus
                        >
                    </div>

                    <div class="mb-4">
                        <label for="password" class="form-label small fw-semibold">
                            Password
                        </label>

                        <input
                            type="password"
                            id="password"
                            name="password"
                            class="form-control"
                            autocomplete="current-password"
                            required
                        >
                    </div>

                    <button type="submit" class="btn btn-success w-100">
                        Sign In
                    </button>
                </form>

                <p class="text-secondary small text-center mt-4 mb-0">
                    For account access or password reset, contact your Administrator.
                </p>
            </div>
        </div>
    </main>
</body>
</html>