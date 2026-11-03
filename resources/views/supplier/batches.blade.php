@extends('layouts.supplier')

@section('title', 'Batch Management')

@section('content')
<div class="batch-page" id="batchPage">
    <div class="batch-card">

        {{-- Header --}}
        <div class="batch-header">
            <div>
                <h1>Batch Management</h1>
                <p>Monitor flower batches, freshness, and movement history.</p>
            </div>

            <a href="{{ url('/supplier/inventory') }}"
               class="batch-primary-btn">
                <i class="bi bi-box-seam" aria-hidden="true"></i>
                Go to Inventory
            </a>
        </div>

        {{-- Overview --}}
        <div class="batch-stats">
            <div class="batch-stat">
                <span>Available</span>
                <strong id="availableBatchCount">0</strong>
                <small>Unused batches with usable stock</small>
            </div>

            <div class="batch-stat">
                <span>Partially Used</span>
                <strong id="partialBatchCount">0</strong>
                <small>Batches with remaining stock</small>
            </div>

            <div class="batch-stat">
                <span>Depleted</span>
                <strong id="depletedBatchCount">0</strong>
                <small>Fully consumed batches</small>
            </div>

            <div class="batch-stat">
                <span>Spoiled</span>
                <strong id="spoiledBatchCount">0</strong>
                <small>Manually marked after Stock-Out</small>
            </div>
        </div>

        <div class="batch-notice"
             id="batchNotice"
             role="status"
             hidden></div>

        <div class="batch-warning"
             id="batchFreshnessWarning"
             role="status"
             hidden></div>

        {{-- Show entries and search --}}
        <div class="batch-toolbar">
            <div class="batch-length-control">
                <label for="batchPageLength">Show</label>

                <select id="batchPageLength">
                    <option value="5">5</option>
                    <option value="10">10</option>
                    <option value="25">25</option>
                    <option value="50">50</option>
                </select>

                <span>entries</span>
            </div>

            <div class="batch-search-wrapper">
                <label class="visually-hidden" for="batchSearch">
                    Search batches
                </label>

                <i class="bi bi-search" aria-hidden="true"></i>

                <input type="search"
                       id="batchSearch"
                       placeholder="Search batch number or flower..."
                       autocomplete="off">
            </div>
        </div>

        {{-- Batch list --}}
        <div class="batch-table-container">
            <table id="batchTable" class="batch-table">
                <thead>
                    <tr>
                        <th>Batch Number</th>
                        <th>Flower Details</th>
                        <th>Received</th>
                        <th>Initial</th>
                        <th>Remaining</th>
                        <th>Freshness / Expiry</th>
                        <th>Status</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody id="batchTableBody"></tbody>
            </table>
        </div>

    </div>
</div>

{{-- View Batch and Movement History --}}
<div class="modal fade batch-modal"
     id="batchViewModal"
     tabindex="-1"
     aria-labelledby="batchViewTitle"
     aria-hidden="true">
    <div class="modal-dialog modal-xl modal-dialog-centered">
        <div class="modal-content">

            <div class="modal-header">
                <div>
                    <h2 class="modal-title" id="batchViewTitle">
                        Batch Details
                    </h2>
                    <p>Batch information and inventory movement history.</p>
                </div>

                <button type="button"
                        class="btn-close"
                        data-bs-dismiss="modal"
                        aria-label="Close"></button>
            </div>

            <div class="modal-body">
                <div id="batchViewContent"></div>

                <div class="batch-history-heading">
                    <h3>Movement History</h3>
                    <p>Stock-In and Stock-Out records for this batch.</p>
                </div>

                <div class="batch-history-container">
                    <table class="batch-history-table">
                        <thead>
                            <tr>
                                <th>Date / Time</th>
                                <th>Movement</th>
                                <th>Quantity</th>
                                <th>Remarks</th>
                                <th>User</th>
                            </tr>
                        </thead>
                        <tbody id="batchMovementBody"></tbody>
                    </table>
                </div>
            </div>

            <div class="modal-footer">
                <button type="button"
                        class="batch-secondary-btn"
                        data-bs-dismiss="modal">
                    Close
                </button>
            </div>

        </div>
    </div>
</div>

{{-- Edit Metadata Only --}}
<div class="modal fade batch-modal"
     id="batchEditModal"
     tabindex="-1"
     aria-labelledby="batchEditTitle"
     aria-hidden="true">
    <div class="modal-dialog modal-lg modal-dialog-centered">
        <div class="modal-content">

            <div class="modal-header">
                <div>
                    <h2 class="modal-title" id="batchEditTitle">
                        Edit Batch Information
                    </h2>
                    <p>Update harvest date, freshness date, and batch notes.</p>
                </div>

                <button type="button"
                        class="btn-close"
                        data-bs-dismiss="modal"
                        aria-label="Close"></button>
            </div>

            <form id="batchEditForm">
                <div class="modal-body">
                    <div class="batch-error"
                         id="batchEditError"
                         role="alert"
                         hidden></div>

                    <input type="hidden" id="batchEditingId">

                    <div class="batch-form-grid">
                        <div class="batch-form-field">
                            <label for="batchEditNumber">Batch Number</label>
                            <input type="text" id="batchEditNumber" readonly>
                        </div>

                        <div class="batch-form-field">
                            <label for="batchEditFlower">Flower</label>
                            <input type="text" id="batchEditFlower" readonly>
                        </div>

                        <div class="batch-form-field">
                            <label for="batchEditReceived">Date Received</label>
                            <input type="date" id="batchEditReceived" readonly>
                        </div>

                        <div class="batch-form-field">
                            <label for="batchEditHarvest">
                                Harvest Date <span>*</span>
                            </label>
                            <input type="date" id="batchEditHarvest" required>
                        </div>

                        <div class="batch-form-field">
                            <label for="batchEditInitial">Initial Quantity</label>
                            <input type="number" id="batchEditInitial" readonly>
                        </div>

                        <div class="batch-form-field">
                            <label for="batchEditRemaining">Remaining Quantity</label>
                            <input type="number" id="batchEditRemaining" readonly>
                        </div>

                        <div class="batch-form-field batch-form-full">
                            <label for="batchEditExpiry">
                                Expected Freshness / Expiry Date
                            </label>
                            <input type="date" id="batchEditExpiry">
                            <small>
                                Optional. Freshness warnings do not automatically
                                deduct stock or mark a batch as spoiled.
                            </small>
                        </div>

                        <div class="batch-form-field batch-form-full">
                            <label for="batchEditRemarks">Remarks</label>
                            <textarea id="batchEditRemarks"
                                      rows="3"
                                      placeholder="Enter batch notes..."></textarea>
                        </div>
                    </div>

                    <p class="batch-form-note">
                        Quantities and receiving records are managed through
                        Inventory Management.
                    </p>
                </div>

                <div class="modal-footer">
                    <button type="button"
                            class="batch-secondary-btn"
                            data-bs-dismiss="modal">
                        Cancel
                    </button>

                    <button type="submit" class="batch-primary-btn">
                        <i class="bi bi-check-lg" aria-hidden="true"></i>
                        Save Changes
                    </button>
                </div>
            </form>

        </div>
    </div>
</div>

{{-- Mark Batch as Spoiled --}}
<div class="modal fade batch-modal"
     id="batchSpoilModal"
     tabindex="-1"
     aria-labelledby="batchSpoilTitle"
     aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">

            <div class="modal-header">
                <div>
                    <h2 class="modal-title" id="batchSpoilTitle">
                        Mark Batch as Spoiled
                    </h2>
                    <p>Record the reason and preserve batch history.</p>
                </div>

                <button type="button"
                        class="btn-close"
                        data-bs-dismiss="modal"
                        aria-label="Close"></button>
            </div>

            <form id="batchSpoilForm">
                <div class="modal-body">
                    <input type="hidden" id="batchSpoilingId">

                    <div id="batchSpoilSummary"></div>

                    <div class="batch-warning">
                        Record the remaining spoiled quantity as a Stock-Out
                        with spoilage remarks in Inventory Management first.
                        This action does not deduct stock.
                    </div>

                    <div class="batch-error"
                         id="batchSpoilError"
                         role="alert"
                         hidden></div>

                    <div class="batch-form-field">
                        <label for="batchSpoilReason">
                            Spoilage Reason <span>*</span>
                        </label>
                        <textarea id="batchSpoilReason"
                                  rows="3"
                                  placeholder="Explain why the batch is spoiled..."
                                  required></textarea>
                    </div>
                </div>

                <div class="modal-footer">
                    <button type="button"
                            class="batch-secondary-btn"
                            data-bs-dismiss="modal">
                        Cancel
                    </button>

                    <button type="submit"
                            class="batch-danger-btn"
                            id="batchSpoilConfirm"
                            disabled>
                        Mark as Spoiled
                    </button>
                </div>
            </form>

        </div>
    </div>
</div>
@endsection