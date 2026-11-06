@extends('layouts.supplier')

@section('title', 'Client Management')

@section('content')
    <div class="client-page">
        <div class="client-card">
            <div class="client-header">
                <div>
                    <h1>Client Management</h1>
                    <p>Manage client information, account status, and order history.</p>
                </div>

                <button type="button" class="client-primary-btn" id="addClientBtn">
                    <i class="bi bi-plus-lg" aria-hidden="true"></i>
                    Add New Client
                </button>
            </div>

            <div class="client-notice">
                <i class="bi bi-info-circle" aria-hidden="true"></i>
                Manage client accounts and contact details. Deactivation preserves existing records.
            </div>

            
            <div class="client-toolbar">
                <div class="client-length">
                    <label for="clientPageLength">Show</label>
                    <select id="clientPageLength">
                        <option value="5">5</option>
                        <option value="10">10</option>
                        <option value="25">25</option>
                        <option value="50">50</option>
                    </select>
                    <span>entries</span>
                </div>

                <div class="client-search">
                    <label for="clientSearch" class="visually-hidden">Search clients</label>
                    <i class="bi bi-search" aria-hidden="true"></i>
                    <input
                        type="search"
                        id="clientSearch"
                        placeholder="Search client ID, name or email..."
                        autocomplete="off"
                    >
                </div>
            </div>

            <div class="client-table-container">
                <table id="clientTable"
                    data-url="{{ route('supplier.clients.data') }}"
                    data-store-url="{{ route('supplier.clients.store') }}"
                    data-show-url="{{ route('supplier.clients.show', ['client' => '__CLIENT__']) }}"
                    data-update-url="{{ route('supplier.clients.update', ['client' => '__CLIENT__']) }}"
                    data-status-url="{{ route('supplier.clients.status', ['client' => '__CLIENT__']) }}">
                    <thead>
                        <tr>
                            <th scope="col">Client Details</th>
                            <th scope="col">Contact Information</th>
                            <th scope="col">Registered</th>
                            <th scope="col">Status</th>
                            <th scope="col">Actions</th>
                        </tr>
                    </thead>
                    <tbody></tbody>
                </table>
            </div>

            <p id="clientAnnouncement" class="client-form-note px-4 py-2" role="status" aria-live="polite" hidden></p>
        </div>
    </div>

    {{-- Add / Edit --}}
    <div
        class="modal fade"
        id="clientFormModal"
        tabindex="-1"
        aria-labelledby="clientFormTitle"
        aria-hidden="true"
    >
        <div class="modal-dialog modal-dialog-centered modal-lg">
            <div class="modal-content client-modal">
                <div class="modal-header">
                    <div>
                        <span class="client-eyebrow">CLIENT INFORMATION</span>
                        <h5 class="modal-title" id="clientFormTitle">Add New Client</h5>
                    </div>
                    <button
                        type="button"
                        class="btn-close"
                        data-bs-dismiss="modal"
                        aria-label="Close"
                    ></button>
                </div>

                <form id="clientForm">
                    @csrf
                    <div class="modal-body">
                        <div id="clientFormError" class="client-form-error" role="alert" hidden></div>

                        <input type="hidden" id="clientEditingId">

                        <div class="client-form-grid">
                            <div class="client-field">
                                <label for="clientCode">Client ID</label>
                                <input type="text" id="clientCode" readonly>
                                <small>Automatically generated.</small>
                            </div>

                            <div class="client-field">
                                <label for="clientStatus">Account Status</label>
                                <select id="clientStatus" required>
                                    <option value="Active">Active</option>
                                    <option value="Inactive">Inactive</option>
                                </select>
                            </div>

                            <div class="client-field">
                                <label for="clientName">Full Name <span>*</span></label>
                                <input
                                    type="text"
                                    id="clientName"
                                    maxlength="120"
                                    autocomplete="name"
                                    required
                                >
                            </div>

                            <div class="client-field">
                                <label for="clientEmail">Email Address <span>*</span></label>
                                <input
                                    type="email"
                                    id="clientEmail"
                                    maxlength="254"
                                    autocomplete="email"
                                    required
                                >
                            </div>

                            <div class="client-field">
                                <label for="clientPhone">Contact Number <span>*</span></label>
                                <input
                                    type="tel"
                                    id="clientPhone"
                                    maxlength="30"
                                    autocomplete="tel"
                                    required
                                >
                            </div>

                            <div class="client-field" id="clientPasswordField">
                                <label for="clientPassword">
                                    Temporary Password <span>*</span>
                                </label>
                                <input
                                    type="password"
                                    id="clientPassword"
                                    minlength="12"
                                    maxlength="72"
                                    autocomplete="new-password"
                                    aria-describedby="clientPasswordHelp"
                                >
                                <small id="clientPasswordHelp">
                                    Use at least 12 characters, up to 72 bytes.
                                    The password is stored securely and is not shown after saving.
                                </small>
                            </div>

                            <div class="client-field client-full">
                                <label for="clientAddress">Address <span>*</span></label>
                                <textarea
                                    id="clientAddress"
                                    rows="3"
                                    maxlength="500"
                                    autocomplete="street-address"
                                    required
                                ></textarea>
                            </div>
                        </div>

                        <p class="client-form-note" id="clientAccountNote">
                            New client accounts require a password change at their first sign-in.
                        </p>
                    </div>

                    <div class="modal-footer">
                        <button
                            type="button"
                            class="client-secondary-btn"
                            data-bs-dismiss="modal"
                        >
                            Cancel
                        </button>
                        <button type="submit" class="client-primary-btn">
                            <i class="bi bi-check-lg" aria-hidden="true"></i>
                            Save Client
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    {{-- Profile / History --}}
    <div
        class="modal fade"
        id="clientViewModal"
        tabindex="-1"
        aria-labelledby="clientViewTitle"
        aria-hidden="true"
    >
        <div class="modal-dialog modal-dialog-centered modal-xl">
            <div class="modal-content client-modal">
                <div class="modal-header">
                    <div>
                        <span class="client-eyebrow">CLIENT PROFILE</span>
                        <h5 class="modal-title" id="clientViewTitle">Client Details</h5>
                    </div>
                    <button
                        type="button"
                        class="btn-close"
                        data-bs-dismiss="modal"
                        aria-label="Close"
                    ></button>
                </div>

                <div class="modal-body" id="clientViewContent"></div>

                <div class="modal-footer">
                    <button
                        type="button"
                        class="client-secondary-btn"
                        data-bs-dismiss="modal"
                    >
                        Close
                    </button>
                </div>
            </div>
        </div>
    </div>

    {{-- Status confirmation --}}
    <div
        class="modal fade"
        id="clientStatusModal"
        tabindex="-1"
        aria-labelledby="clientStatusTitle"
        aria-describedby="clientStatusMessage"
        aria-hidden="true"
    >
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content client-modal">
                <div class="modal-header">
                    <h5 class="modal-title" id="clientStatusTitle">Update Account Status</h5>
                    <button
                        type="button"
                        class="btn-close"
                        data-bs-dismiss="modal"
                        aria-label="Close"
                    ></button>
                </div>

                <div class="modal-body">
                    <p class="client-status-message" id="clientStatusMessage"></p>
                    <div id="clientStatusError" class="client-form-error" role="alert" hidden></div>
                </div>

                <div class="modal-footer">
                    <button
                        type="button"
                        class="client-secondary-btn"
                        data-bs-dismiss="modal"
                    >
                        Cancel
                    </button>
                    <button type="button" class="client-primary-btn" id="confirmClientStatus">
                        Confirm
                    </button>
                </div>
            </div>
        </div>
    </div>
@endsection
