@extends('layouts.supplier')

@section('title', 'Sales & Payments')

@section('content')
    <div class="sales-page">
        <div class="sales-summary">
            <div>
                <span>Net Sales</span>
                <strong id="salesNet">₱0.00</strong>
            </div>
            <div>
                <span>Sales Transactions</span>
                <strong id="salesCount">0</strong>
            </div>
            <div>
                <span>Net Sales Today</span>
                <strong id="salesToday">₱0.00</strong>
            </div>
            <div>
                <span>Net Sales This Month</span>
                <strong id="salesMonth">₱0.00</strong>
            </div>
        </div>

        <div class="sales-card">
            <div class="sales-header">
                <div>
                    <h1>Sales & Payments</h1>
                    <p>Manage selling prices, completed sales, and client payments.</p>
                </div>

                <button type="button" class="sales-primary-btn" id="recordPaymentBtn">
                    <i class="bi bi-plus-lg" aria-hidden="true"></i>
                    Record Payment
                </button>
            </div>

            <div class="sales-notice">
                <i class="bi bi-info-circle" aria-hidden="true"></i>
                <span>
                    Sample data only. Payment recording and confirmation are manual.
                    These records are not connected to actual orders or a payment gateway.
                </span>
            </div>

            <div class="sales-tabs" role="tablist" aria-label="Sales and payment sections">
                <button
                    type="button"
                    id="pricesTab"
                    role="tab"
                    aria-controls="pricesPanel"
                    aria-selected="true"
                    data-sales-tab="prices"
                    class="active"
                >
                    Selling Prices
                </button>
                <button
                    type="button"
                    id="recordsTab"
                    role="tab"
                    aria-controls="recordsPanel"
                    aria-selected="false"
                    tabindex="-1"
                    data-sales-tab="records"
                >
                    Sales Records
                </button>
                <button
                    type="button"
                    id="paymentsTab"
                    role="tab"
                    aria-controls="paymentsPanel"
                    aria-selected="false"
                    tabindex="-1"
                    data-sales-tab="payments"
                >
                    Payments
                </button>
            </div>

            <section id="pricesPanel" role="tabpanel" aria-labelledby="pricesTab">
                <div class="sales-toolbar">
                    <div class="sales-length">
                        <label for="pricesLength">Show</label>
                        <select id="pricesLength" data-length="prices">
                            <option value="5">5</option>
                            <option value="10">10</option>
                            <option value="25">25</option>
                            <option value="50">50</option>
                        </select>
                        <span>entries</span>
                    </div>
                    <div class="sales-search">
                        <label class="visually-hidden" for="pricesSearch">Search flowers</label>
                        <i class="bi bi-search" aria-hidden="true"></i>
                        <input
                            type="search"
                            id="pricesSearch"
                            data-search="prices"
                            placeholder="Search flower name or code..."
                            autocomplete="off"
                        >
                    </div>
                </div>

                <div class="sales-table-container">
                    <table id="pricesTable" class="sales-table">
                        <thead>
                            <tr>
                                <th scope="col">Flower Details</th>
                                <th scope="col">Category</th>
                                <th scope="col">Price / Stem</th>
                                <th scope="col">Updated</th>
                                <th scope="col">Actions</th>
                            </tr>
                        </thead>
                        <tbody></tbody>
                    </table>
                </div>
            </section>

            <section
                id="recordsPanel"
                role="tabpanel"
                aria-labelledby="recordsTab"
                hidden
            >
                <div class="sales-toolbar">
                    <div class="sales-length">
                        <label for="recordsLength">Show</label>
                        <select id="recordsLength" data-length="records">
                            <option value="5">5</option>
                            <option value="10">10</option>
                            <option value="25">25</option>
                            <option value="50">50</option>
                        </select>
                        <span>entries</span>
                    </div>
                    <div class="sales-search">
                        <label class="visually-hidden" for="recordsSearch">Search sales</label>
                        <i class="bi bi-search" aria-hidden="true"></i>
                        <input
                            type="search"
                            id="recordsSearch"
                            data-search="records"
                            placeholder="Search sale, order or client..."
                            autocomplete="off"
                        >
                    </div>
                </div>

                <div class="sales-table-container">
                    <table id="recordsTable" class="sales-table">
                        <thead>
                            <tr>
                                <th scope="col">Sale / Order</th>
                                <th scope="col">Client</th>
                                <th scope="col">Completed</th>
                                <th scope="col">Gross Sale</th>
                                <th scope="col">Refunds</th>
                                <th scope="col">Net Sale</th>
                                <th scope="col">Actions</th>
                            </tr>
                        </thead>
                        <tbody></tbody>
                    </table>
                </div>
            </section>

            <section
                id="paymentsPanel"
                role="tabpanel"
                aria-labelledby="paymentsTab"
                hidden
            >
                <div class="sales-toolbar">
                    <div class="sales-length">
                        <label for="paymentsLength">Show</label>
                        <select id="paymentsLength" data-length="payments">
                            <option value="5">5</option>
                            <option value="10">10</option>
                            <option value="25">25</option>
                            <option value="50">50</option>
                        </select>
                        <span>entries</span>
                    </div>
                    <div class="sales-search">
                        <label class="visually-hidden" for="paymentsSearch">Search payments</label>
                        <i class="bi bi-search" aria-hidden="true"></i>
                        <input
                            type="search"
                            id="paymentsSearch"
                            data-search="payments"
                            placeholder="Search payment, order or client..."
                            autocomplete="off"
                        >
                    </div>
                </div>

                <div class="sales-table-container">
                    <table id="paymentsTable" class="sales-table">
                        <thead>
                            <tr>
                                <th scope="col">Payment / Order</th>
                                <th scope="col">Client</th>
                                <th scope="col">Recorded</th>
                                <th scope="col">Method / Reference</th>
                                <th scope="col">Amount</th>
                                <th scope="col">Status</th>
                                <th scope="col">Actions</th>
                            </tr>
                        </thead>
                        <tbody></tbody>
                    </table>
                </div>
            </section>

            <p id="salesAnnouncement" class="sales-announcement" role="status"></p>
        </div>

        <div class="sales-insights">
            <div class="sales-card sales-insight-card">
                <h2>Top Selling Flowers</h2>
                <p>Completed-order quantities, before refund adjustments.</p>
                <div id="salesTopFlowers"></div>
            </div>

            <div class="sales-card sales-insight-card">
                <h2>Sales Trend</h2>
                <p>Net sales by completion date for the sample records.</p>
                <div id="salesTrend"></div>
            </div>
        </div>
    </div>

    {{-- Create payment / edit price / confirm / refund --}}
    <div
        class="modal fade"
        id="salesActionModal"
        tabindex="-1"
        aria-labelledby="salesActionTitle"
        aria-hidden="true"
    >
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content sales-modal">
                <div class="modal-header">
                    <h5 class="modal-title" id="salesActionTitle">Update</h5>
                    <button
                        type="button"
                        class="btn-close"
                        data-bs-dismiss="modal"
                        aria-label="Close"
                    ></button>
                </div>

                <form id="salesActionForm">
                    <div class="modal-body">
                        <div id="salesActionError" class="sales-error" role="alert" hidden></div>
                        <div id="salesActionFields"></div>
                    </div>
                    <div class="modal-footer">
                        <button
                            type="button"
                            class="sales-secondary-btn"
                            data-bs-dismiss="modal"
                        >
                            Cancel
                        </button>
                        <button type="submit" class="sales-primary-btn" id="salesActionSubmit">
                            Save
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    {{-- Order financial details --}}
    <div
        class="modal fade"
        id="salesViewModal"
        tabindex="-1"
        aria-labelledby="salesViewTitle"
        aria-hidden="true"
    >
        <div class="modal-dialog modal-dialog-centered modal-xl">
            <div class="modal-content sales-modal">
                <div class="modal-header">
                    <div>
                        <span class="sales-eyebrow">FINANCIAL DETAILS</span>
                        <h5 class="modal-title" id="salesViewTitle">Order Summary</h5>
                    </div>
                    <button
                        type="button"
                        class="btn-close"
                        data-bs-dismiss="modal"
                        aria-label="Close"
                    ></button>
                </div>
                <div class="modal-body" id="salesViewContent"></div>
                <div class="modal-footer">
                    <button
                        type="button"
                        class="sales-secondary-btn"
                        data-bs-dismiss="modal"
                    >
                        Close
                    </button>
                </div>
            </div>
        </div>
    </div>
@endsection