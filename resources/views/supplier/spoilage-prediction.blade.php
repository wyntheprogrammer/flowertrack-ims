@extends('layouts.supplier')

@section('title', 'Spoilage Prediction')

@section('content')
    <div class="prediction-page">
        <div class="prediction-summary">
            <div class="prediction-summary-card">
                <span class="prediction-summary-icon prediction-neutral">
                    <i class="bi bi-layers" aria-hidden="true"></i>
                </span>
                <div>
                    <span>Monitored Batches</span>
                    <strong id="predictionTotal">0</strong>
                </div>
            </div>

            <div class="prediction-summary-card">
                <span class="prediction-summary-icon prediction-low">
                    <i class="bi bi-shield-check" aria-hidden="true"></i>
                </span>
                <div>
                    <span>Low Risk</span>
                    <strong id="predictionLow">0</strong>
                </div>
            </div>

            <div class="prediction-summary-card">
                <span class="prediction-summary-icon prediction-medium">
                    <i class="bi bi-exclamation-circle" aria-hidden="true"></i>
                </span>
                <div>
                    <span>Medium Risk</span>
                    <strong id="predictionMedium">0</strong>
                </div>
            </div>

            <div class="prediction-summary-card">
                <span class="prediction-summary-icon prediction-high">
                    <i class="bi bi-exclamation-triangle" aria-hidden="true"></i>
                </span>
                <div>
                    <span>High / Critical Risk</span>
                    <strong id="predictionUrgent">0</strong>
                </div>
            </div>
        </div>

        <div class="prediction-card">
            <div class="prediction-header">
                <div>
                    <h1>Spoilage Prediction</h1>
                    <p>Review batch risk indicators and recommended actions.</p>
                </div>

                <span class="prediction-demo-label">
                    <i class="bi bi-info-circle" aria-hidden="true"></i>
                    Sample Data
                </span>
            </div>

            <div class="prediction-notice">
                <i class="bi bi-info-circle" aria-hidden="true"></i>
                <p>
                    These are sample advisory results for UI review.
                    Inspect actual flower condition before taking action.
                    Record confirmed spoilage through Inventory Management.
                </p>
            </div>

            <div class="prediction-toolbar">
                <div class="prediction-length">
                    <label for="predictionPageLength">Show</label>
                    <select id="predictionPageLength">
                        <option value="5">5</option>
                        <option value="10">10</option>
                        <option value="25">25</option>
                        <option value="50">50</option>
                    </select>
                    <span>entries</span>
                </div>

                <div class="prediction-search">
                    <label class="visually-hidden" for="predictionSearch">
                        Search batch or flower
                    </label>
                    <i class="bi bi-search" aria-hidden="true"></i>
                    <input
                        type="search"
                        id="predictionSearch"
                        placeholder="Search batch or flower..."
                        autocomplete="off"
                    >
                </div>
            </div>

            <div class="prediction-table-container">
                <table id="predictionTable" class="prediction-table">
                    <thead>
                        <tr>
                            <th scope="col">Batch</th>
                            <th scope="col">Flower Details</th>
                            <th scope="col">Remaining</th>
                            <th scope="col">Freshness / Expiry</th>
                            <th scope="col">Risk Level</th>
                            <th scope="col">Recommendation</th>
                            <th scope="col">Actions</th>
                        </tr>
                    </thead>
                    <tbody></tbody>
                </table>
            </div>
        </div>
    </div>

    <div
        class="modal fade"
        id="predictionViewModal"
        tabindex="-1"
        aria-labelledby="predictionViewModalLabel"
        aria-hidden="true"
    >
        <div class="modal-dialog modal-dialog-centered modal-lg">
            <div class="modal-content prediction-modal">
                <div class="modal-header">
                    <div>
                        <span class="prediction-eyebrow">BATCH ADVISORY</span>
                        <h5 class="modal-title" id="predictionViewModalLabel">
                            Risk Assessment
                        </h5>
                    </div>

                    <button
                        type="button"
                        class="btn-close"
                        data-bs-dismiss="modal"
                        aria-label="Close"
                    ></button>
                </div>

                <div class="modal-body" id="predictionViewContent"></div>

                <div class="modal-footer">
                    <button
                        type="button"
                        class="prediction-secondary-btn"
                        data-bs-dismiss="modal"
                    >
                        Close
                    </button>
                </div>
            </div>
        </div>
    </div>
@endsection