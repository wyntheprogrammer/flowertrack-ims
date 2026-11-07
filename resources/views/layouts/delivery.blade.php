<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>@yield('title', 'Assigned Deliveries') - FlowerTrack IMS</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body>
    <div class="container-fluid px-3 px-lg-4 py-4">
        <header class="d-flex flex-wrap justify-content-between align-items-center gap-3 mb-4">
            <div class="d-flex align-items-center gap-3">
                <img src="{{ asset('images/gemmas-logo.png') }}" alt="Gemma's Flower Shop" width="56" height="56">
                <div><strong>FlowerTrack IMS</strong><div class="text-secondary">Assigned Deliveries</div></div>
            </div>
            <div class="d-flex flex-wrap align-items-center gap-3">
                <span>{{ auth()->user()->name }}</span>
                <a href="{{ route('password.change') }}">Change Password</a>
                <form method="POST" action="{{ route('logout') }}">
                    @csrf
                    <button type="submit" class="btn btn-outline-success">Sign Out</button>
                </form>
            </div>
        </header>
        <main>@yield('content')</main>
    </div>
</body>
</html>
