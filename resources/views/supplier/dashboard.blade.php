@extends('layouts.supplier')

@section('title', 'Dashboard')

@section('content')
    @php
        $summary = $dashboard['summary'];
        $chart = $dashboard['chart'];
        $isAdmin = auth()->user()->role === \App\Models\User::ROLE_ADMIN;
        $money = static fn (int $cents): string => '₱'
            .number_format(intdiv($cents, 100), 0, '.', ',')
            .'.'.str_pad((string) ($cents % 100), 2, '0', STR_PAD_LEFT);
        $generated = \Carbon\CarbonImmutable::parse($dashboard['generatedAt']);
    @endphp

    <div id="supplierDashboard">
        <section class="welcome-banner">
            <div class="welcome-text">
                <h2>Welcome back, <span>{{ auth()->user()->name }}!</span></h2>
                <p>Here's what's happening with your flower business today.</p>
            </div>
        </section>

        <div class="dashboard-live-status">
            <span>Updated {{ $generated->format('M d, Y · h:i A') }} PHT</span>
            <a class="dashboard-refresh" href="{{ route('supplier.dashboard', ['unit' => $chart['unit']]) }}">
                <i class="bi bi-arrow-clockwise" aria-hidden="true"></i> Refresh
            </a>
        </div>

        <section class="summary-grid" aria-label="Business summary">
            <div class="summary-card">
                <div class="summary-icon"><i class="bi bi-box-seam" aria-hidden="true"></i></div>
                <div class="summary-info">
                    <small>On-Hand Inventory</small>
                    <h3>{{ number_format($summary['stems']) }} <span class="dashboard-muted">stems</span></h3>
                    <span class="summary-change">{{ number_format($summary['bouquets']) }} bouquets</span>
                </div>
            </div>

            <div class="summary-card">
                <div class="summary-icon red"><i class="bi bi-cash-stack" aria-hidden="true"></i></div>
                <div class="summary-info">
                    <small>Net Sales · All Time</small>
                    <h3>{{ $money($summary['netSalesCents']) }}</h3>
                    <span class="dashboard-muted">Completed sales less refunds</span>
                </div>
            </div>

            <div class="summary-card">
                <div class="summary-icon"><i class="bi bi-exclamation-triangle" aria-hidden="true"></i></div>
                <div class="summary-info">
                    <small>Low Stock Items</small>
                    <h3>{{ number_format($summary['lowStockItems']) }}</h3>
                    <span class="dashboard-muted">Active items below minimum available</span>
                </div>
            </div>

            <div class="summary-card">
                <div class="summary-icon yellow"><i class="bi bi-clock-history" aria-hidden="true"></i></div>
                <div class="summary-info">
                    <small>Pending Orders</small>
                    <h3>{{ number_format($summary['pendingOrders']) }}</h3>
                    <span class="dashboard-muted">Awaiting confirmation</span>
                </div>
            </div>
        </section>

        <section class="dashboard-grid">
            <div class="dashboard-panel">
                <div class="panel-header">
                    <h4 class="panel-title"><i class="bi bi-bar-chart-line" aria-hidden="true"></i> Inventory Overview</h4>
                    <form method="GET" action="{{ route('supplier.dashboard') }}" class="dashboard-unit-form">
                        <label class="visually-hidden" for="dashboardUnit">Product unit</label>
                        <select id="dashboardUnit" name="unit">
                            <option value="Stem" @selected($chart['unit'] === 'Stem')>Stems</option>
                            <option value="Bouquet" @selected($chart['unit'] === 'Bouquet')>Bouquets</option>
                        </select>
                        <button type="submit">Apply</button>
                    </form>
                </div>

                <p class="dashboard-note">Current on-hand stock · up to six items with the highest stock. Reservations are included in on-hand quantities.</p>

                @if (count($chart['items']))
                    @php
                        $maximum = max(1, ...array_column($chart['items'], 'onHand'));
                        $height = max(170, 30 + count($chart['items']) * 36);
                    @endphp
                    <svg class="dashboard-stock-chart" viewBox="0 0 700 {{ $height }}"
                         xmlns="http://www.w3.org/2000/svg" role="img"
                         aria-labelledby="liveStockTitle liveStockDescription">
                        <title id="liveStockTitle">Current {{ strtolower($chart['unit']) }} inventory</title>
                        <desc id="liveStockDescription">{{ collect($chart['items'])->map(fn ($item) => $item['name'].': '.$item['onHand'])->join('; ') }}</desc>
                        @foreach ($chart['items'] as $index => $item)
                            @php
                                $y = 18 + $index * 36;
                                $width = round($item['onHand'] / $maximum * 440, 2);
                            @endphp
                            <text x="0" y="{{ $y + 15 }}" fill="#66736a" font-size="12">{{ \Illuminate\Support\Str::limit($item['name'], 19) }}</text>
                            <rect x="150" y="{{ $y }}" width="440" height="23" rx="6" fill="#eef5eb" />
                            <rect x="150" y="{{ $y }}" width="{{ $width }}" height="23" rx="6" fill="#176b3a" />
                            <text x="605" y="{{ $y + 15 }}" fill="#202b24" font-size="12">{{ number_format($item['onHand']) }}</text>
                        @endforeach
                    </svg>
                @else
                    <p class="dashboard-empty">No {{ strtolower($chart['unit']) }} products registered yet.</p>
                @endif
            </div>

            <div class="dashboard-panel">
                <div class="panel-header"><h4 class="panel-title"><i class="bi bi-lightning-charge" aria-hidden="true"></i> Quick Actions</h4></div>
                <div class="quick-actions">
                    <a href="{{ route('supplier.flowers', ['create' => 1]) }}" class="quick-action">
                        <span><i class="bi bi-plus-circle" aria-hidden="true"></i></span><strong>Add Flower</strong>
                    </a>
                    <a href="{{ route('supplier.orders', ['create' => 1]) }}" class="quick-action">
                        <span><i class="bi bi-cart-plus" aria-hidden="true"></i></span><strong>New Order</strong>
                    </a>
                    <a href="{{ route($isAdmin ? 'supplier.reports' : 'supplier.inventory') }}" class="quick-action">
                        <span><i class="bi {{ $isAdmin ? 'bi-file-earmark-bar-graph' : 'bi-box-seam' }}" aria-hidden="true"></i></span>
                        <strong>{{ $isAdmin ? 'View Reports' : 'View Inventory' }}</strong>
                    </a>
                    <a href="{{ route('supplier.clients') }}" class="quick-action">
                        <span><i class="bi bi-people" aria-hidden="true"></i></span><strong>Manage Clients</strong>
                    </a>
                </div>
            </div>
        </section>

        <section class="lower-grid">
            <div class="dashboard-panel">
                <div class="panel-header">
                    <h4 class="panel-title"><i class="bi bi-trophy" aria-hidden="true"></i> Top Selling Products</h4>
                    <a href="{{ route('supplier.sales-payments') }}" class="panel-link">View All →</a>
                </div>
                <p class="dashboard-note">All-time gross item sales · before refunds · excludes delivery fees. Names use retained order snapshots.</p>
                @forelse ($dashboard['topProducts'] as $index => $product)
                    <div class="product-row">
                        <span class="product-rank">{{ $index + 1 }}</span>
                        <span class="product-image"><i class="bi bi-flower1" aria-hidden="true"></i></span>
                        <span class="product-name">{{ $product['name'] }}
                            <small>{{ $product['code'] }} · {{ number_format($product['quantity']) }} {{ $product['unit'] === 'Stem' ? 'stems' : 'bouquets' }}</small>
                        </span>
                        <span class="product-price">{{ $money($product['grossCents']) }}</span>
                    </div>
                @empty
                    <p class="dashboard-empty">No completed product sales yet.</p>
                @endforelse
            </div>

            <div class="dashboard-panel">
                <div class="panel-header">
                    <h4 class="panel-title"><i class="bi bi-activity" aria-hidden="true"></i> Recent Activities</h4>
                    @if ($isAdmin)
                        <a href="{{ route('supplier.audit-trail') }}" class="panel-link">Audit Trail →</a>
                    @endif
                </div>
                @forelse ($dashboard['recent'] as $activity)
                    <div class="activity-row">
                        <span class="activity-icon"><i class="bi {{ $activity['icon'] }}" aria-hidden="true"></i></span>
                        <span class="activity-description">{{ $activity['label'] }}
                            <small>{{ \Carbon\CarbonImmutable::parse($activity['date'])->format('M d, Y · h:i A') }} PHT</small>
                        </span>
                    </div>
                @empty
                    <p class="dashboard-empty">No operational activity yet.</p>
                @endforelse
            </div>

            <div class="promo-panel">
                <h3>Bloom<br>Your Business</h3>
                <p>Manage your flowers and grow your business with FlowerTrack.</p>
                <a href="{{ route('supplier.inventory') }}" class="promo-button">View Inventory →</a>
            </div>
        </section>
    </div>
@endsection