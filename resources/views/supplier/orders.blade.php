@extends('layouts.supplier')

@section('title', 'Order Management')

@section('content')
    <div class="order-page">
        <div class="order-card">
            <div class="order-header">
                <div>
                    <h1>Order Management</h1>
                    <p>Review client orders and manage preparation and fulfillment.</p>
                </div>

                <button type="button" class="order-primary-btn" id="addOrderBtn">
                    <i class="bi bi-plus-lg" aria-hidden="true"></i>
                    Add New Order
                </button>
            </div>

            <div class="order-notice">
                <i class="bi bi-info-circle" aria-hidden="true"></i>
                <span>
                    Confirmation reserves stock. Actual pickup collection deducts stock once; ready statuses do not deduct stock.
                </span>
            </div>

            <div class="order-toolbar">
                <div class="order-length">
                    <label for="orderPageLength">Show</label>
                    <select id="orderPageLength">
                        <option value="5">5</option>
                        <option value="10">10</option>
                        <option value="25">25</option>
                        <option value="50">50</option>
                    </select>
                    <span>entries</span>
                </div>

                <div class="order-search">
                    <label class="visually-hidden" for="orderSearch">Search orders</label>
                    <i class="bi bi-search" aria-hidden="true"></i>
                    <input
                        type="search"
                        id="orderSearch"
                        placeholder="Search order or client..."
                        autocomplete="off"
                    >
                </div>
            </div>

            <div class="order-table-container">
                <table id="orderTable"
                    data-url="{{ route('supplier.orders.data') }}"
                    data-store-url="{{ route('supplier.orders.store') }}"
                    data-show-url="{{ route('supplier.orders.show', ['order' => '__ORDER__']) }}"
                    data-update-url="{{ route('supplier.orders.update', ['order' => '__ORDER__']) }}"
                    data-action-url="{{ route('supplier.orders.action', ['order' => '__ORDER__']) }}">
                    <thead>
                        <tr>
                            <th scope="col">Order / Client</th>
                            <th scope="col">Order Date</th>
                            <th scope="col">Fulfillment</th>
                            <th scope="col">Total</th>
                            <th scope="col">Payment</th>
                            <th scope="col">Order Status</th>
                            <th scope="col">Actions</th>
                        </tr>
                    </thead>
                    <tbody></tbody>
                </table>
            </div>

            <p id="orderAnnouncement" class="order-announcement" role="status"></p>
        </div>
    </div>

    {{-- Add / Edit Order --}}
    <div
        class="modal fade"
        id="orderFormModal"
        tabindex="-1"
        aria-labelledby="orderFormTitle"
        aria-hidden="true"
    >
        <div class="modal-dialog modal-dialog-centered modal-xl">
            <div class="modal-content order-modal">
                <div class="modal-header">
                    <div>
                        <span class="order-eyebrow">ORDER INFORMATION</span>
                        <h5 class="modal-title" id="orderFormTitle">Add New Order</h5>
                    </div>
                    <button
                        type="button"
                        class="btn-close"
                        data-bs-dismiss="modal"
                        aria-label="Close"
                    ></button>
                </div>

                <form id="orderForm">
                    @csrf
                    <div class="modal-body">
                        <div id="orderFormError" class="order-error" role="alert" hidden></div>

                        <input type="hidden" id="orderEditingId">

                        <div class="order-form-grid">
                            <div class="order-field">
                                <label for="orderCode">Order ID</label>
                                <input type="text" id="orderCode" readonly>
                            </div>

                            <div class="order-field">
                                <label for="orderClient">Client <span>*</span></label>
                                <select id="orderClient" required></select>
                            </div>

                            <div class="order-field">
                                <label for="orderMethod">Fulfillment <span>*</span></label>
                                <select id="orderMethod" required>
                                    <option value="Delivery">Delivery</option>
                                    <option value="Customer Pickup">Customer Pickup</option>
                                </select>
                            </div>

                            <div class="order-field">
                                <label for="orderDeliveryFee">Delivery Fee</label>
                                <input type="text" id="orderDeliveryFee" readonly>
                                <small>System-configured fee, snapshotted when the order is saved.</small>
                            </div>

                            <div class="order-field order-full" id="orderAddressField">
                                <label for="orderAddress">Delivery Address <span>*</span></label>
                                <textarea id="orderAddress" rows="2" maxlength="500"></textarea>
                            </div>
                        </div>

                        <div class="order-section-heading">
                            <h6>Order Items</h6>
                            <button type="button" class="order-secondary-btn" id="addOrderItem">
                                <i class="bi bi-plus-lg" aria-hidden="true"></i>
                                Add Item
                            </button>
                        </div>

                        <div id="orderItemList" class="order-item-list"></div>

                        <p class="order-help">
                            Quantities follow each item’s Stem or Bouquet unit. Prices are read-only snapshots. Final totals are calculated by the system when saving.
                        </p>

                        <div class="order-totals">
                            <div>
                                <span>Subtotal</span>
                                <strong id="orderSubtotal">₱0.00</strong>
                            </div>
                            <div>
                                <span>Delivery Fee</span>
                                <strong id="orderFeeTotal">₱0.00</strong>
                            </div>
                            <div class="order-grand-total">
                                <span>Order Total</span>
                                <strong id="orderGrandTotal">₱0.00</strong>
                            </div>
                        </div>

                        <div class="order-field">
                            <label for="orderNotes">Order Notes</label>
                            <textarea id="orderNotes" rows="3" maxlength="1000"></textarea>
                        </div>
                    </div>

                    <div class="modal-footer">
                        <button
                            type="button"
                            class="order-secondary-btn"
                            data-bs-dismiss="modal"
                        >
                            Cancel
                        </button>
                        <button type="submit" class="order-primary-btn">
                            <i class="bi bi-check-lg" aria-hidden="true"></i>
                            Save Order
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    {{-- View --}}
    <div
        class="modal fade"
        id="orderViewModal"
        tabindex="-1"
        aria-labelledby="orderViewTitle"
        aria-hidden="true"
    >
        <div class="modal-dialog modal-dialog-centered modal-xl">
            <div class="modal-content order-modal">
                <div class="modal-header">
                    <div>
                        <span class="order-eyebrow">ORDER DETAILS</span>
                        <h5 class="modal-title" id="orderViewTitle">Order Details</h5>
                    </div>
                    <button
                        type="button"
                        class="btn-close"
                        data-bs-dismiss="modal"
                        aria-label="Close"
                    ></button>
                </div>
                <div class="modal-body" id="orderViewContent"></div>
                <div class="modal-footer">
                    <button
                        type="button"
                        class="order-secondary-btn"
                        data-bs-dismiss="modal"
                    >
                        Close
                    </button>
                </div>
            </div>
        </div>
    </div>

    {{-- Confirm / Update / Cancel --}}
    <div
        class="modal fade"
        id="orderActionModal"
        tabindex="-1"
        aria-labelledby="orderActionTitle"
        aria-hidden="true"
    >
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content order-modal">
                <div class="modal-header">
                    <h5 class="modal-title" id="orderActionTitle">Update Order</h5>
                    <button
                        type="button"
                        class="btn-close"
                        data-bs-dismiss="modal"
                        aria-label="Close"
                    ></button>
                </div>

                <form id="orderActionForm">
                    @csrf
                    <div class="modal-body">
                        <div id="orderActionError" class="order-error" role="alert" hidden></div>
                        <p id="orderActionMessage" class="order-action-message"></p>
                        <div class="form-check mb-3" id="orderCollectedField" hidden>
                            <input class="form-check-input" type="checkbox" id="orderCollected">
                            <label class="form-check-label" for="orderCollected">I confirm the customer has actually collected the complete order.</label>
                        </div>

                        <div class="order-field" id="orderCancelField" hidden>
                            <label for="orderCancelReason">
                                Cancellation Reason <span>*</span>
                            </label>
                            <textarea
                                id="orderCancelReason"
                                rows="3"
                                maxlength="500"
                            ></textarea>
                        </div>
                    </div>

                    <div class="modal-footer">
                        <button
                            type="button"
                            class="order-secondary-btn"
                            data-bs-dismiss="modal"
                        >
                            Back
                        </button>
                        <button type="submit" class="order-primary-btn" id="orderActionConfirm">
                            Confirm
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>
@endsection

