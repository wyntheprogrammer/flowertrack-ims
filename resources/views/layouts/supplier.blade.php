<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">

<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">

    <title>@yield('title', 'Dashboard') - FlowerTrack IMS</title>

    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>

<body>

    <div class="supplier-layout">

        <!-- Sidebar -->
        <aside class="supplier-sidebar">

            <div class="sidebar-brand">
                <div class="sidebar-logo">
                    <img src="{{ asset('images/gemmas-logo.png') }}" alt="Gemma's Flower Shop Logo">
                </div>
            </div>

            <div class="sidebar-divider"></div>

            @php
                $supplierNavigation = [
                    ['dashboard', 'Dashboard', 'bi-house-door'],
                    ['flowers', 'Flower Management', 'bi-flower1'],
                    ['inventory', 'Inventory Management', 'bi-box-seam'],
                    ['batches', 'Batch Management', 'bi-layers'],
                    ['spoilage-prediction', 'Spoilage Prediction', 'bi-graph-up-arrow'],
                    ['clients', 'Client Management', 'bi-people'],
                    ['orders', 'Order Management', 'bi-cart3'],
                    ['deliveries', 'Delivery Management', 'bi-truck'],
                    ['sales-payments', 'Sales & Payments', 'bi-cash-coin'],
                    ['reports', 'Reports', 'bi-bar-chart'],
                    ['users', 'User Management', 'bi-person-gear'],
                    ['audit-trail', 'Audit Trail', 'bi-clock-history'],
                ];
            @endphp

            <nav class="sidebar-nav">
                @foreach ($supplierNavigation as [$module, $label, $icon])
                    @php
                        $routeName = 'supplier.' . $module;
                        $isActive = request()->routeIs($routeName, $routeName . '.*');
                    @endphp

                    <a href="{{ route($routeName) }}" class="sidebar-link {{ $isActive ? 'active' : '' }}" @if ($isActive)
                    aria-current="page" @endif>
                        <span class="sidebar-icon">
                            <i class="bi {{ $icon }}" aria-hidden="true"></i>
                        </span>

                        <span class="sidebar-label">{{ $label }}</span>
                    </a>
                @endforeach
            </nav>


            <div class="sidebar-divider"></div>

            <div class="sidebar-user">
                <div class="sidebar-avatar">A</div>
                <div class="sidebar-user-info">
                    <strong>Administrator</strong>
                    <small>System Admin</small>
                </div>
            </div>

        </aside>

        <!-- Main Area -->
        <main class="supplier-main">

            <!-- Top Navbar -->
            <header class="supplier-navbar">

                <div class="navbar-left">


                    <button class="navbar-menu" type="button">
                        ☰
                    </button>

                    <div class="navbar-brand">
                        <div class="navbar-brand-name">FlowerTrack IMS</div>
                        <div class="navbar-brand-subtitle">Inventory Management System</div>
                    </div>

                </div>

                <div class="navbar-right">

                    <button class="navbar-icon" type="button">
                        ♟
                        <span class="notification-count">2</span>
                    </button>

                    <div class="navbar-date">
                        ▣ &nbsp; {{ now()->format('M d, Y') }} &nbsp;⌄
                    </div>

                </div>

            </header>

            <!-- Page Content -->
            <section class="dashboard-content">
                @yield('content')
            </section>

        </main>

    </div>

</body>

</html>