@extends('layouts.supplier')

@section('title', 'Delivery Management')

@section('content')
    <div class="delivery-page">
        <div class="delivery-card">
            <div class="delivery-header">
                <div>
                    <h1>Delivery Management</h1>
                    <p>Manage delivery assignments, schedules, and fulfillment updates.</p>
                </div>

            </div>

            <div class="delivery-notice">
                <i class="bi bi-info-circle" aria-hidden="true"></i>
                <span>
                    Deliveries come from orders marked Ready for Delivery.
                    Stock is deducted only after actual delivery is confirmed.
                </span>
            </div>

            <div class="delivery-toolbar">
                <div class="delivery-length">
                    <label for="deliveryPageLength">Show</label>
                    <select id="deliveryPageLength">
                        <option value="5">5</option>
                        <option value="10">10</option>
                        <option value="25">25</option>
                        <option value="50">50</option>
                    </select>
                    <span>entries</span>
                </div>

                <div class="delivery-search">
                    <label class="visually-hidden" for="deliverySearch">
                        Search deliveries
                    </label>
                    <i class="bi bi-search" aria-hidden="true"></i>
                    <input
                        type="search"
                        id="deliverySearch"
                        placeholder="Search delivery, order or client..."
                        autocomplete="off"
                    >
                </div>
            </div>

            <div class="delivery-table-container">
                <table id="deliveryTable"
                    data-url="{{ route('supplier.deliveries') }}"
                    data-csrf="{{ csrf_token() }}">
                    <thead>
                        <tr>
                            <th scope="col">Delivery / Order</th>
                            <th scope="col">Client / Address</th>
                            <th scope="col">Personnel</th>
                            <th scope="col">Schedule</th>
                            <th scope="col">Status</th>
                            <th scope="col">Actions</th>
                        </tr>
                    </thead>
                    <tbody></tbody>
                </table>
            </div>

            <p id="deliveryAnnouncement" class="delivery-announcement" role="status"></p>
        </div>
    </div>

    {{-- Manage Delivery --}}
    <div
        class="modal fade"
        id="deliveryManageModal"
        tabindex="-1"
        aria-labelledby="deliveryManageTitle"
        aria-hidden="true"
    >
        <div class="modal-dialog modal-dialog-centered modal-lg">
            <div class="modal-content delivery-modal">
                <div class="modal-header">
                    <div>
                        <span class="delivery-eyebrow">DELIVERY OPERATIONS</span>
                        <h5 class="modal-title" id="deliveryManageTitle">Manage Delivery</h5>
                    </div>
                    <button
                        type="button"
                        class="btn-close"
                        data-bs-dismiss="modal"
                        aria-label="Close"
                    ></button>
                </div>

                <form id="deliveryManageForm">
                    <div class="modal-body">
                        <div id="deliveryManageError" class="delivery-error" role="alert" hidden></div>

                        <p id="deliveryManageSummary" class="delivery-help"></p>

                        <div class="delivery-field">
                            <label for="deliveryAction">Action</label>
                            <select id="deliveryAction" required></select>
                        </div>

                        <div id="deliveryActionFields" class="delivery-action-fields"></div>
                    </div>

                    <div class="modal-footer">
                        <button
                            type="button"
                            class="delivery-secondary-btn"
                            data-bs-dismiss="modal"
                        >
                            Cancel
                        </button>
                        <button type="submit" class="delivery-primary-btn">
                            <i class="bi bi-check-lg" aria-hidden="true"></i>
                            Save Update
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    {{-- Delivery Details --}}
    <div
        class="modal fade"
        id="deliveryViewModal"
        tabindex="-1"
        aria-labelledby="deliveryViewTitle"
        aria-hidden="true"
    >
        <div class="modal-dialog modal-dialog-centered modal-xl">
            <div class="modal-content delivery-modal">
                <div class="modal-header">
                    <div>
                        <span class="delivery-eyebrow">DELIVERY INFORMATION</span>
                        <h5 class="modal-title" id="deliveryViewTitle">Delivery Details</h5>
                    </div>
                    <button
                        type="button"
                        class="btn-close"
                        data-bs-dismiss="modal"
                        aria-label="Close"
                    ></button>
                </div>

                <div class="modal-body" id="deliveryViewContent"></div>

                <div class="modal-footer">
                    <button
                        type="button"
                        class="delivery-secondary-btn"
                        data-bs-dismiss="modal"
                    >
                        Close
                    </button>
                </div>
            </div>
        </div>
    </div>
@endsection
