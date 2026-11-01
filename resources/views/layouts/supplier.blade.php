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

            
            <nav class="sidebar-nav">

                <a href="{{ url('/supplier/dashboard') }}"
                    class="sidebar-link {{ request()->is('supplier/dashboard') ? 'active' : '' }}">
                    <span class="sidebar-icon"><i class="bi bi-house-door"></i></span>
                    <span class="sidebar-label">Dashboard</span>
                </a>

                <a href="#" class="sidebar-link">
                    <span class="sidebar-icon"><i class="bi bi-flower1"></i></span>
                    <span class="sidebar-label">Flower Management</span>
                </a>

                <a href="#" class="sidebar-link">
                    <span class="sidebar-icon"><i class="bi bi-box-seam"></i></span>
                    <span class="sidebar-label">Inventory Management</span>
                </a>

                <a href="#" class="sidebar-link">
                    <span class="sidebar-icon"><i class="bi bi-layers"></i></span>
                    <span class="sidebar-label">Batch Management</span>
                </a>

                <a href="#" class="sidebar-link">
                    <span class="sidebar-icon"><i class="bi bi-graph-up-arrow"></i></span>
                    <span class="sidebar-label">Spoilage Prediction</span>
                </a>

                <a href="#" class="sidebar-link">
                    <span class="sidebar-icon"><i class="bi bi-people"></i></span>
                    <span class="sidebar-label">Client Management</span>
                </a>

                <a href="#" class="sidebar-link">
                    <span class="sidebar-icon"><i class="bi bi-cart3"></i></span>
                    <span class="sidebar-label">Order Management</span>
                </a>

                <a href="#" class="sidebar-link">
                    <span class="sidebar-icon"><i class="bi bi-truck"></i></span>
                    <span class="sidebar-label">Delivery Management</span>
                </a>

                <a href="#" class="sidebar-link">
                    <span class="sidebar-icon"><i class="bi bi-cash-coin"></i></span>
                    <span class="sidebar-label">Sales & Payments</span>
                </a>

                <a href="#" class="sidebar-link">
                    <span class="sidebar-icon"><i class="bi bi-bar-chart"></i></span>
                    <span class="sidebar-label">Reports</span>
                </a>

                <a href="#" class="sidebar-link">
                    <span class="sidebar-icon"><i class="bi bi-person-gear"></i></span>
                    <span class="sidebar-label">User Management</span>
                </a>

                <a href="#" class="sidebar-link">
                    <span class="sidebar-icon"><i class="bi bi-clock-history"></i></span>
                    <span class="sidebar-label">Audit Trail</span>
                </a>

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