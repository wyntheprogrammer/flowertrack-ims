<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>@yield('title', 'Assigned Deliveries') - FlowerTrack IMS</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body>
    <div class="supplier-layout">
      <div class="supplier-main">
        <header class="supplier-navbar flex-wrap">
            <div class="d-flex align-items-center gap-3">
                <img src="{{ asset('images/gemmas-logo.png') }}" alt="Gemma's Flower Shop" width="56" height="56">
                <div><strong>FlowerTrack IMS</strong><div class="text-secondary">Assigned Deliveries</div></div>
            </div>
            <div class="d-flex flex-wrap align-items-center gap-3">
                <div class="supplier-notifications">
                    <button class="navbar-icon" type="button" id="supplierNotificationToggle"
                        aria-controls="supplierNotificationPanel" aria-expanded="false" aria-label="Show delivery alerts">
                        <i class="bi bi-bell" aria-hidden="true"></i>
                        <span class="notification-count" id="deliveryNotificationCount" hidden></span>
                    </button>
                    <section id="supplierNotificationPanel" class="supplier-notification-panel"
                        aria-labelledby="supplierNotificationHeading" hidden>
                        <h2 id="supplierNotificationHeading">Delivery Alerts</h2>
                        <a href="{{ route('delivery.deliveries') }}">Assigned deliveries <span id="deliveryActiveCount">0</span></a>
                        <a href="{{ route('delivery.deliveries') }}">Delivered orders awaiting payment <span id="deliveryUnpaidCount">0</span></a>
                    </section>
                </div>
                <span>{{ auth()->user()->name }}</span>
                <a href="{{ route('password.change') }}">Change Password</a>
                <form method="POST" action="{{ route('logout') }}">
                    @csrf
                    <button type="submit" class="btn btn-outline-success">Sign Out</button>
                </form>
            </div>
        </header>
        <main class="container-fluid px-3 px-lg-4 py-4">@yield('content')</main>
      </div>
    </div>
</body>
</html>
