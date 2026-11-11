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
        <aside class="supplier-sidebar" id="supplierSidebar">

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

            @php
                $account = auth()->user();
                $isAdmin = $account->role === \App\Models\User::ROLE_ADMIN;
                $roleLabel = $isAdmin ? 'Administrator' : 'Supplier';
                $dashboardSummary = $dashboard['summary'] ?? null;
                $alertCount = $dashboardSummary
                    ? $dashboardSummary['lowStockItems'] + $dashboardSummary['pendingOrders']
                    : null;
            @endphp

            <nav class="sidebar-nav">
                @foreach ($supplierNavigation as [$module, $label, $icon])
                    @continue(! $isAdmin && in_array($module, ['reports', 'users', 'audit-trail'], true))
                    @php
                        $routeName = 'supplier.' . $module;
                        $isActive = request()->routeIs($routeName, $routeName . '.*');
                    @endphp

                    <a href="{{ route($routeName) }}" class="sidebar-link {{ $isActive ? 'active' : '' }}" @if ($isActive)
                    aria-current="page" @endif aria-label="{{ $label }}" title="{{ $label }}">
                        <span class="sidebar-icon">
                            <i class="bi {{ $icon }}" aria-hidden="true"></i>
                        </span>

                        <span class="sidebar-label">{{ $label }}</span>
                    </a>
                @endforeach
            </nav>


            <div class="sidebar-divider"></div>

            <div class="sidebar-user">
                <div class="sidebar-avatar">{{ mb_strtoupper(mb_substr($account->name, 0, 1)) }}</div>
                <div class="sidebar-user-info">
                    <strong>{{ $account->name }}</strong>
                    <small>{{ $roleLabel }}</small>
                </div>
            </div>

            <form method="POST" action="{{ route('logout') }}" class="mt-3">
                @csrf

                <button type="submit" class="btn btn-outline-light w-100">
                    <i class="bi bi-box-arrow-right" aria-hidden="true"></i>
                    Sign Out
                </button>
            </form>

        </aside>

        <!-- Main Area -->
        <main class="supplier-main">

            <!-- Top Navbar -->
            <header class="supplier-navbar">

                <div class="navbar-left">


                    <button class="navbar-menu" type="button" id="supplierMenuToggle" aria-controls="supplierSidebar"
                        aria-expanded="true" aria-label="Toggle navigation">
                        <i class="bi bi-list" aria-hidden="true"></i>
                    </button>

                    <div class="navbar-brand">
                        <div class="navbar-brand-name">FlowerTrack IMS</div>
                        <div class="navbar-brand-subtitle">Inventory Management System</div>
                    </div>

                </div>

                <div class="navbar-right">

                    <div class="supplier-notifications">
                        <button class="navbar-icon" type="button" id="supplierNotificationToggle"
                            aria-controls="supplierNotificationPanel" aria-expanded="false"
                            aria-label="Show inventory and order alerts">
                            <i class="bi bi-bell" aria-hidden="true"></i>
                            @if ($alertCount !== null && $alertCount > 0)
                                <span class="notification-count">{{ $alertCount > 99 ? '99+' : $alertCount }}</span>
                            @endif
                        </button>
                        <section id="supplierNotificationPanel" class="supplier-notification-panel"
                            aria-labelledby="supplierNotificationHeading" hidden>
                            <h2 id="supplierNotificationHeading">Operational Alerts</h2>
                            <a href="{{ route('supplier.inventory') }}">
                                Review low-stock items
                                @if ($dashboardSummary) · {{ number_format($dashboardSummary['lowStockItems']) }} @endif
                            </a>
                            <a href="{{ route('supplier.orders') }}">
                                Review pending orders
                                @if ($dashboardSummary) · {{ number_format($dashboardSummary['pendingOrders']) }} @endif
                            </a>
                            @if ($dashboardSummary)
                                <p>Counts reflect the Dashboard snapshot. Refresh the Dashboard for current counts.</p>
                            @else
                                <p><a href="{{ route('supplier.dashboard') }}">Open Dashboard for current counts.</a></p>
                            @endif
                        </section>
                    </div>

                    <div class="navbar-date">
                        <i class="bi bi-calendar3" aria-hidden="true"></i> &nbsp; {{ now('Asia/Manila')->format('M d, Y') }}
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