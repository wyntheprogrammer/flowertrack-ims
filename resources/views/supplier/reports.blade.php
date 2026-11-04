@extends('layouts.supplier')
@section('title', 'Reports')
@section('content')
<div class="report-page" id="reportPage">
    <div class="report-card">
        <div class="report-header">
            <div><h1>Reports</h1><p>Review business activity and export report records.</p></div>
            <div class="report-export-actions">
                <button type="button" data-export="pdf"><i class="bi bi-file-earmark-pdf" aria-hidden="true"></i> PDF</button>
                <button type="button" data-export="excel"><i class="bi bi-file-earmark-excel" aria-hidden="true"></i> Excel</button>
                <button type="button" data-export="print"><i class="bi bi-printer" aria-hidden="true"></i> Print</button>
            </div>
        </div>
        <p class="report-notice">Sample report data only. Reports are read-only and are not yet connected to operational modules. Access must be enforced for Admin during backend integration.</p>
        <div class="report-selector">
            <label for="reportType">Report Type</label>
            <select id="reportType">
                <option value="sales">Sales</option><option value="payments">Payments</option>
                <option value="inventory">Inventory</option><option value="orders">Orders</option>
                <option value="delivery">Delivery</option><option value="clients">Clients</option>
                <option value="spoilage">Spoilage</option>
            </select>
        </div>
        <div class="report-summary" id="reportSummary"></div>
        <p class="report-definition" id="reportDefinition"></p>
        <div class="report-chart" id="reportChart"></div>
        <div class="report-toolbar">
            <div class="report-length"><label for="reportLength">Show</label><select id="reportLength"><option>5</option><option>10</option><option>25</option><option>50</option></select><span>entries</span></div>
            <div class="report-search"><label class="visually-hidden" for="reportSearch">Search report</label><i class="bi bi-search" aria-hidden="true"></i><input id="reportSearch" type="search" placeholder="Search report records..." autocomplete="off"></div>
        </div>
        <div class="report-table-container" id="reportTableHost"></div>
        <p class="report-status" id="reportStatus" role="status"></p>
    </div>
</div>
@endsection
