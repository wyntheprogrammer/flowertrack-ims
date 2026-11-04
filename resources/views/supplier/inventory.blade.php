@extends('layouts.supplier')

@section('title', 'Inventory Management')

@section('content')
<div class="inv-page" id="inventoryPage">
    <div class="inv-card">

        <div class="inv-header">
            <div>
                <h1>Inventory Management</h1>
                <p>Monitor flower stocks, batch balances, and inventory movements.</p>
            </div>

            <div class="inv-header-actions">
                <button type="button" class="inv-btn secondary" id="openStockOut">
                    <i class="bi bi-box-arrow-up" aria-hidden="true"></i>
                    Stock-Out
                </button>

                <button type="button" class="inv-btn primary" id="openStockIn">
                    <i class="bi bi-plus-lg" aria-hidden="true"></i>
                    Stock-In
                </button>
            </div>
        </div>

        <div class="inv-demo-note">
            <i class="bi bi-info-circle" aria-hidden="true"></i>
            Sample data. Changes reset when you refresh.
        </div>

        <div class="inv-stats">
            <div class="inv-stat">
                <span>On-Hand Stock</span>
                <strong id="totalOnHand">0</strong>
                <small>Separate stem and bouquet quantities</small>
            </div>

            <div class="inv-stat">
                <span>Reserved Stock</span>
                <strong id="totalReserved">0</strong>
                <small>Held for confirmed orders</small>
            </div>

            <div class="inv-stat">
                <span>Available Stock</span>
                <strong id="totalAvailable">0</strong>
                <small>On hand minus reservations</small>
            </div>

            <div class="inv-stat">
                <span>Stock Alerts</span>
                <strong id="totalStockAlerts">0</strong>
                <small>Flowers with low / unavailable stock</small>
            </div>
        </div>

        <div class="inv-notice" id="inventoryNotice" role="status" hidden></div>

        <div class="inv-tabs" role="tablist" aria-label="Inventory sections">
            <button type="button"
                    class="active"
                    id="inventoryTab"
                    role="tab"
                    aria-controls="inventoryPanel"
                    aria-selected="true"
                    data-inventory-tab="inventory">
                Inventory List
            </button>

            <button type="button"
                    id="inventoryHistoryTab"
                    role="tab"
                    aria-controls="inventoryHistoryPanel"
                    aria-selected="false"
                    data-inventory-tab="history">
                Inventory History
            </button>
        </div>

        <div class="inv-toolbar">
            <div class="inv-length">
                <label for="inventoryPageLength">Show</label>
                <select id="inventoryPageLength">
                    <option value="5">5</option>
                    <option value="10">10</option>
                    <option value="25">25</option>
                    <option value="50">50</option>
                </select>
                <span>entries</span>
            </div>

            <div class="inv-search">
                <label class="visually-hidden" for="inventorySearch">
                    Search inventory
                </label>
                <i class="bi bi-search" aria-hidden="true"></i>
                <input type="search"
                       id="inventorySearch"
                       placeholder="Search flower, batch, or movement..."
                       autocomplete="off">
            </div>
        </div>

        <div class="inv-table-panel"
             id="inventoryPanel"
             role="tabpanel"
             aria-labelledby="inventoryTab">
            <table id="inventoryTable" class="inv-table">
                <thead>
                    <tr>
                        <th>Flower Details</th>
                        <th>On Hand</th>
                        <th>Reserved</th>
                        <th>Available</th>
                        <th>Min. Stock</th>
                        <th>Batches</th>
                        <th>Status</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody></tbody>
            </table>
        </div>

        <div class="inv-table-panel"
             id="inventoryHistoryPanel"
             role="tabpanel"
             aria-labelledby="inventoryHistoryTab"
             hidden>
            <table id="inventoryHistoryTable" class="inv-table history">
                <thead>
                    <tr>
                        <th>Date / Time</th>
                        <th>Flower / Batch</th>
                        <th>Movement</th>
                        <th>Quantity</th>
                        <th>Remarks</th>
                        <th>User</th>
                    </tr>
                </thead>
                <tbody></tbody>
            </table>
        </div>

    </div>
</div>

{{-- Stock-In / Edit Receipt --}}
<div class="modal fade inv-modal"
     id="stockInModal"
     tabindex="-1"
     aria-labelledby="stockInTitle"
     aria-hidden="true">
    <div class="modal-dialog modal-lg modal-dialog-centered">
        <div class="modal-content">

            <div class="modal-header">
                <div>
                    <h2 class="modal-title" id="stockInTitle">Stock-In</h2>
                    <p>Receive stock and create its batch record.</p>
                </div>
                <button type="button"
                        class="btn-close"
                        data-bs-dismiss="modal"
                        aria-label="Close"></button>
            </div>

            <form id="stockInForm">
                <div class="modal-body">
                    <div class="inv-error" id="stockInError" role="alert" hidden></div>

                    <div class="inv-form-grid">
                        <div class="inv-field">
                            <label for="inBatchCode">Batch Number</label>
                            <input id="inBatchCode" readonly>
                        </div>

                        <div class="inv-field">
                            <label for="inFlower">Flower <span>*</span></label>
                            <select id="inFlower" required></select>
                        </div>

                        <div class="inv-field">
                            <label for="inQuantity">Quantity <span>*</span></label>
                            <input type="number"
                                   id="inQuantity"
                                   min="1"
                                   step="1"
                                   required>
                        </div>

                        <div class="inv-field">
                            <label for="inUnit">Receiving Unit <span>*</span></label>
                            <select id="inUnit" required></select>
                        </div>

                        <p class="inv-hint inv-full"
                           id="inConversion"
                           aria-live="polite"></p>

                        <div class="inv-field">
                            <label for="inReceived">Date Received <span>*</span></label>
                            <input type="date" id="inReceived" required>
                        </div>

                        <div class="inv-field">
                            <label for="inHarvest">Harvest Date <span>*</span></label>
                            <input type="date" id="inHarvest" required>
                        </div>

                        <div class="inv-field">
                            <label for="inExpiry">Expected Freshness / Expiry</label>
                            <input type="date" id="inExpiry">
                            <small>Optional. This does not automatically deduct stock.</small>
                        </div>

                        <div class="inv-field inv-full">
                            <label for="inRemarks">Remarks</label>
                            <textarea id="inRemarks"
                                      rows="3"
                                      placeholder="Receipt notes or reason for a correction"></textarea>
                        </div>
                    </div>
                </div>

                <div class="modal-footer">
                    <button type="button"
                            class="inv-btn secondary"
                            data-bs-dismiss="modal">
                        Cancel
                    </button>
                    <button type="submit" class="inv-btn primary">
                        <i class="bi bi-check-lg"></i>
                        Save Stock-In
                    </button>
                </div>
            </form>

        </div>
    </div>
</div>

{{-- Stock-Out --}}
<div class="modal fade inv-modal"
     id="stockOutModal"
     tabindex="-1"
     aria-labelledby="stockOutTitle"
     aria-hidden="true">
    <div class="modal-dialog modal-lg modal-dialog-centered">
        <div class="modal-content">

            <div class="modal-header">
                <div>
                    <h2 class="modal-title" id="stockOutTitle">Stock-Out</h2>
                    <p>Issue stock using FIFO or an authorized batch override.</p>
                </div>
                <button type="button"
                        class="btn-close"
                        data-bs-dismiss="modal"
                        aria-label="Close"></button>
            </div>

            <form id="stockOutForm">
                <div class="modal-body">
                    <div class="inv-error" id="stockOutError" role="alert" hidden></div>

                    <div class="inv-form-grid">
                        <div class="inv-field">
                            <label for="outReason">Purpose <span>*</span></label>
                            <select id="outReason" required>
                                <option value="Other use">Other use</option>
                                <option value="Spoilage">Spoilage</option>
                                <option value="Customer order">Customer order</option>
                            </select>
                        </div>

                        <div class="inv-field">
                            <label for="outFlower">Flower <span>*</span></label>
                            <select id="outFlower" required></select>
                        </div>

                        <div class="inv-field inv-full d-none" id="outOrderGroup">
                            <label for="outOrder">Eligible Order <span>*</span></label>
                            <select id="outOrder"></select>
                            <small>
                                Delivery: Delivered only. Pickup: Ready for Pickup.
                                Full quantities only.
                            </small>
                        </div>

                        <div class="inv-field">
                            <label for="outQuantity">Quantity in Base Units <span>*</span></label>
                            <input type="number"
                                   id="outQuantity"
                                   min="1"
                                   step="1"
                                   value="1"
                                   required>
                        </div>

                        <p class="inv-hint inv-full" id="outBalance"></p>

                        <label class="inv-check inv-full">
                            <input type="checkbox" id="outOverride">
                            Override FIFO and select one batch
                        </label>

                        <div class="inv-field inv-full d-none" id="outBatchGroup">
                            <label for="outBatch">Batch <span>*</span></label>
                            <select id="outBatch"></select>
                        </div>

                        <div class="inv-preview inv-full">
                            <strong>Batch allocation preview</strong>
                            <p id="outAllocation" aria-live="polite"></p>
                        </div>

                        <div class="inv-field inv-full">
                            <label for="outRemarks">Remarks</label>
                            <textarea id="outRemarks"
                                      rows="3"
                                      placeholder="Required for spoilage or a FIFO override"></textarea>
                        </div>
                    </div>
                </div>

                <div class="modal-footer">
                    <button type="button"
                            class="inv-btn secondary"
                            data-bs-dismiss="modal">
                        Cancel
                    </button>
                    <button type="submit" class="inv-btn primary">
                        <i class="bi bi-check-lg"></i>
                        Save Stock-Out
                    </button>
                </div>
            </form>

        </div>
    </div>
</div>

{{-- Batch Details --}}
<div class="modal fade inv-modal"
     id="inventoryDetailModal"
     tabindex="-1"
     aria-labelledby="inventoryDetailTitle"
     aria-hidden="true">
    <div class="modal-dialog modal-xl modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header">
                <h2 class="modal-title" id="inventoryDetailTitle">Batch Details</h2>
                <button type="button"
                        class="btn-close"
                        data-bs-dismiss="modal"
                        aria-label="Close"></button>
            </div>

            <div class="modal-body" id="inventoryDetailContent"></div>

            <div class="modal-footer">
                <button type="button"
                        class="inv-btn secondary"
                        data-bs-dismiss="modal">
                    Close
                </button>
            </div>
        </div>
    </div>
</div>

{{-- Per-flower inventory unit configuration --}}
<div class="modal fade inv-modal"
     id="inventoryUnitModal"
     tabindex="-1"
     aria-labelledby="inventoryUnitTitle"
     aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">

            <div class="modal-header">
                <div>
                    <h2 class="modal-title" id="inventoryUnitTitle">Inventory Units</h2>
                    <p id="unitFlowerName"></p>
                </div>
                <button type="button"
                        class="btn-close"
                        data-bs-dismiss="modal"
                        aria-label="Close"></button>
            </div>

            <form id="inventoryUnitForm">
                <div class="modal-body">
                    <div class="inv-error"
                         id="inventoryUnitError"
                         role="alert"
                         hidden></div>

                    <input type="hidden" id="unitFlowerCode">

                    <p class="inv-muted">
                        Stem is the base unit. Set the number of stems in each
                        receiving unit.
                    </p>

                    <div class="inv-form-grid">
                        <div class="inv-field">
                            <label for="unitBunch">Stems per Bunch <span>*</span></label>
                            <input type="number" id="unitBunch" min="1" step="1" required>
                        </div>

                        <div class="inv-field">
                            <label for="unitBundle">Stems per Bundle <span>*</span></label>
                            <input type="number" id="unitBundle" min="1" step="1" required>
                        </div>

                        <div class="inv-field">
                            <label for="unitBox">Stems per Box <span>*</span></label>
                            <input type="number" id="unitBox" min="1" step="1" required>
                        </div>
                    </div>
                </div>

                <div class="modal-footer">
                    <button type="button"
                            class="inv-btn secondary"
                            data-bs-dismiss="modal">
                        Cancel
                    </button>
                    <button type="submit" class="inv-btn primary">
                        Save Units
                    </button>
                </div>
            </form>

        </div>
    </div>
</div>
@endsection
