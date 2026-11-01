
@extends('layouts.supplier')

@section('title', 'Dashboard')

@section('content')

    <!-- Welcome Banner -->
    <section class="welcome-banner">
        <div class="welcome-text">
            <h2>Welcome back, <span>Administrator!</span></h2>
            <p>Here's what's happening with your flower business today.</p>
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

                <select class="form-select form-select-sm"
                        style="width: 125px;">
                    <option>This Month</option>
                    <option>Last Month</option>
                    <option>This Year</option>
                </select>
            </div>

            <div class="chart-legend">
                <span><i class="legend-dot"></i> Sales</span>
                <span><i class="legend-dot light"></i> Inventory Stock</span>
            </div>

            <!-- Sample Chart -->
            <svg class="inventory-chart"
                 viewBox="0 0 700 205"
                 xmlns="http://www.w3.org/2000/svg">

                <defs>
                    <linearGradient id="salesFill" x1="0" y1="0" x2="0" y2="1">
                        <stop offset="0%" stop-color="#198754" stop-opacity=".18"/>
                        <stop offset="100%" stop-color="#198754" stop-opacity="0"/>
                    </linearGradient>

                    <linearGradient id="stockFill" x1="0" y1="0" x2="0" y2="1">
                        <stop offset="0%" stop-color="#a6ce91" stop-opacity=".2"/>
                        <stop offset="100%" stop-color="#a6ce91" stop-opacity="0"/>
                    </linearGradient>
                </defs>

                <!-- Grid Lines -->
                <g stroke="#e8eee7" stroke-width="1">
                    <line x1="45" y1="25" x2="680" y2="25"/>
                    <line x1="45" y1="65" x2="680" y2="65"/>
                    <line x1="45" y1="105" x2="680" y2="105"/>
                    <line x1="45" y1="145" x2="680" y2="145"/>
                    <line x1="45" y1="175" x2="680" y2="175"/>
                </g>

                <!-- Y Labels -->
                <g class="chart-label">
                    <text x="8" y="29">4k</text>
                    <text x="8" y="69">3k</text>
                    <text x="8" y="109">2k</text>
                    <text x="8" y="149">1k</text>
                    <text x="17" y="179">0</text>
                </g>

                <!-- Sales Area -->
                <path d="M55 125 C120 118 145 80 250 78 S365 65 430 50 S560 45 665 48 L665 175 L55 175 Z"
                      fill="url(#salesFill)"/>

                <!-- Inventory Area -->
                <path d="M55 150 C125 142 170 130 250 126 S360 110 430 102 S560 92 665 87 L665 175 L55 175 Z"
                      fill="url(#stockFill)"/>

                <!-- Lines -->
                <path d="M55 125 C120 118 145 80 250 78 S365 65 430 50 S560 45 665 48"
                      fill="none" stroke="#168047" stroke-width="3"/>

                <path d="M55 150 C125 142 170 130 250 126 S360 110 430 102 S560 92 665 87"
                      fill="none" stroke="#a6ce91" stroke-width="3"/>

                <!-- Data Points -->
                <g fill="#168047" stroke="#fff" stroke-width="2">
                    <circle cx="55" cy="125" r="5"/>
                    <circle cx="250" cy="78" r="5"/>
                    <circle cx="430" cy="50" r="5"/>
                    <circle cx="665" cy="48" r="5"/>
                </g>

                <g fill="#a6ce91" stroke="#fff" stroke-width="2">
                    <circle cx="55" cy="150" r="5"/>
                    <circle cx="250" cy="126" r="5"/>
                    <circle cx="430" cy="102" r="5"/>
                    <circle cx="665" cy="87" r="5"/>
                </g>

                <!-- X Labels -->
                <g class="chart-label" text-anchor="middle">
                    <text x="55" y="198">Week 1</text>
                    <text x="250" y="198">Week 2</text>
                    <text x="430" y="198">Week 3</text>
                    <text x="665" y="198">Week 4</text>
                </g>

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

                <a href="#" class="quick-action">
                    <span><i class="bi bi-plus-circle"></i></span>
                    <strong>Add Flower</strong>
                </a>

                <a href="#" class="quick-action">
                    <span><i class="bi bi-cart-plus"></i></span>
                    <strong>New Sale</strong>
                </a>

                <a href="#" class="quick-action">
                    <span><i class="bi bi-file-earmark-bar-graph"></i></span>
                    <strong>View Reports</strong>
                </a>

                <a href="#" class="quick-action">
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

                <a href="#" class="panel-link">View All →</a>
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

                <a href="#" class="panel-link">View All →</a>
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

            <a href="#" class="promo-button">
                View Inventory →
            </a>

        </div>

    </section>

@endsection
