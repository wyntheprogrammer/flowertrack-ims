
@extends('layouts.supplier')

@section('title', 'Dashboard')

@section('content')

    <!-- Welcome Banner -->
    <section class="welcome-banner">
        <div class="welcome-text">
            <h2>Welcome back, <span>Administrator!</span></h2>
            <p>Here's what's happening with your flower business today.</p>
            <small class="dashboard-sample-label">UI preview · sample dashboard figures</small>
        </div>
    </section>

    <!-- Summary Cards -->
    <section class="summary-grid">

        <div class="summary-card">
            <div class="summary-icon">
                <i class="bi bi-box-seam"></i>
            </div>
            <div class="summary-info">
                <small>Total Inventory</small>
                <h3>2,480</h3>
                <span class="summary-change">↑ +5.2%</span>
            </div>
        </div>

        <div class="summary-card">
            <div class="summary-icon red">
                <i class="bi bi-cash-stack"></i>
            </div>
            <div class="summary-info">
                <small>Total Sales</small>
                <h3>₱248,750</h3>
                <span class="summary-change">↑ +8.1%</span>
            </div>
        </div>

        <div class="summary-card">
            <div class="summary-icon">
                <i class="bi bi-exclamation-triangle"></i>
            </div>
            <div class="summary-info">
                <small>Low Stock Items</small>
                <h3>24</h3>
                <span class="summary-change negative">↓ -2.3%</span>
            </div>
        </div>

        <div class="summary-card">
            <div class="summary-icon yellow">
                <i class="bi bi-clock-history"></i>
            </div>
            <div class="summary-info">
                <small>Pending Orders</small>
                <h3>12</h3>
                <span class="summary-change">↑ +1.1%</span>
            </div>
        </div>

    </section>

    <!-- Inventory Overview and Quick Actions -->
    <section class="dashboard-grid">

        <div class="dashboard-panel">

            <div class="panel-header">
                <h4 class="panel-title">
                    <i class="bi bi-bar-chart-line"></i> Inventory Overview
                </h4>

                <select id="dashboardPeriod" aria-label="Sample inventory chart period" class="form-select form-select-sm"
                        style="width: 125px;">
                    <option value="month">This Month</option>
                    <option value="last">Last Month</option>
                    <option value="year">This Year</option>
                </select>
            </div>

            <p class="supplier-chart-note" id="dashboardChartNote">Sample loose-stem on-hand quantities. Bouquets and sales amounts are excluded.</p>
            <svg id="dashboardStockChart" class="inventory-chart" viewBox="0 0 700 205"
                 xmlns="http://www.w3.org/2000/svg" role="img" aria-labelledby="dashboardChartTitle dashboardChartDescription">
                 <title id="dashboardChartTitle">Sample loose-stem inventory</title>
                 <desc id="dashboardChartDescription">Loading sample chart.</desc>
                 <g id="dashboardChartContent"></g>
            </svg>


        </div>

        <!-- Quick Actions -->
        <div class="dashboard-panel">

            <div class="panel-header">
                <h4 class="panel-title">
                    <i class="bi bi-lightning-charge"></i> Quick Actions
                </h4>
            </div>

            <div class="quick-actions">

                <a href="{{ route('supplier.flowers', ['create' => 1]) }}" class="quick-action">
                    <span><i class="bi bi-plus-circle"></i></span>
                    <strong>Add Flower</strong>
                </a>

                <a href="{{ route('supplier.orders', ['create' => 1]) }}" class="quick-action">
                    <span><i class="bi bi-cart-plus"></i></span>
                    <strong>New Order</strong>
                </a>

                <a href="{{ route('supplier.reports') }}" class="quick-action">
                    <span><i class="bi bi-file-earmark-bar-graph"></i></span>
                    <strong>View Reports</strong>
                </a>

                <a href="{{ route('supplier.clients') }}" class="quick-action">
                    <span><i class="bi bi-people"></i></span>
                    <strong>Manage Clients</strong>
                </a>

            </div>

        </div>

    </section>

    <!-- Lower Dashboard -->
    <section class="lower-grid">

        <!-- Top Selling Products -->
        <div class="dashboard-panel">

            <div class="panel-header">
                <h4 class="panel-title">
                    <i class="bi bi-trophy"></i> Top Selling Products
                </h4>

                <a href="{{ route('supplier.sales-payments') }}" class="panel-link">View All →</a>
            </div>

            <div class="product-row">
                <span class="product-rank">1</span>
                <span class="product-image">🌹</span>
                <span class="product-name">Red Roses Bouquet</span>
                <span class="product-price">₱32,500</span>
            </div>

            <div class="product-row">
                <span class="product-rank">2</span>
                <span class="product-image">🌻</span>
                <span class="product-name">Sunflower Bouquet</span>
                <span class="product-price">₱28,750</span>
            </div>

            <div class="product-row">
                <span class="product-rank">3</span>
                <span class="product-image">💐</span>
                <span class="product-name">Mixed Flowers</span>
                <span class="product-price">₱25,200</span>
            </div>

        </div>

        <!-- Recent Activities -->
        <div class="dashboard-panel">

            <div class="panel-header">
                <h4 class="panel-title">
                    <i class="bi bi-activity"></i> Recent Activities
                </h4>

                <a href="{{ route('supplier.audit-trail') }}" class="panel-link">View All →</a>
            </div>

            <div class="activity-row">
                <span class="activity-icon">
                    <i class="bi bi-cart-check"></i>
                </span>
                <span class="activity-description">
                    New sale completed
                    <small>Today · 3:24 PM</small>
                </span>
                <span class="activity-value">₱2,750</span>
            </div>

            <div class="activity-row">
                <span class="activity-icon">
                    <i class="bi bi-box-seam"></i>
                </span>
                <span class="activity-description">
                    Inventory updated
                    <small>Today · 2:17 PM</small>
                </span>
                <span class="activity-value">+50 pcs</span>
            </div>

            <div class="activity-row">
                <span class="activity-icon">
                    <i class="bi bi-person-plus"></i>
                </span>
                <span class="activity-description">
                    New client added
                    <small>Today · 10:42 AM</small>
                </span>
                <span class="activity-value">New</span>
            </div>

        </div>

        <!-- Promotional Panel -->
        <div class="promo-panel">

            <h3>Bloom<br>Your Business</h3>

            <p>Manage your flowers and grow your business with FlowerTrack.</p>

            <a href="{{ route('supplier.inventory') }}" class="promo-button">
                View Inventory →
            </a>

        </div>

    </section>

@endsection

