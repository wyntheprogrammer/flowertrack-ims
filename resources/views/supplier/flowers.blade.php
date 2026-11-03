@extends('layouts.supplier')

@section('title', 'Flower Management')

@section('content')
    <div class="flower-page">
        <div class="flower-catalog-card">
            <div class="flower-page-header">
                <div>
                    <h1>Flower Management</h1>
                    <p>Manage your flower catalog, categories, and availability.</p>
                </div>
                <button type="button" class="flower-primary-btn" id="addFlowerBtn" data-add-flower>
                    <i class="bi bi-plus-lg" aria-hidden="true"></i> Add New Flower
                </button>
            </div>

            <div class="flower-toolbar flower-datatable-controls" id="flowerToolbar">
                <div class="flower-length-control">
                    <label for="flowerPageLength">Show</label>
                    <select id="flowerPageLength">
                        <option value="5">5</option>
                        <option value="10">10</option>
                        <option value="25">25</option>
                        <option value="50">50</option>
                    </select>
                    <span>entries</span>
                </div>

                <div class="flower-search-wrapper">
                    <label class="visually-hidden" for="flowerSearch">Search flowers</label>
                    <i class="bi bi-search" aria-hidden="true"></i>
                    <input type="search" id="flowerSearch" placeholder="Search flower name or code..." autocomplete="off">
                </div>
            </div>

            <div class="flower-table-container">
                <table id="flowerTable" class="flower-table">
                    <thead>
                        <tr>
                            <th>Flower Details</th>
                            <th>Category</th>
                            <th>Minimum Stock</th>
                            <th>Status</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody id="flowerTableBody"></tbody>
                </table>
            </div>
        </div>
    </div>

    {{-- Add / Edit Flower Modal --}}
    <div class="modal fade" id="flowerFormModal" tabindex="-1" aria-labelledby="flowerFormModalLabel" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered modal-lg">
            <div class="modal-content flower-modal-content">
                <div class="modal-header flower-modal-header">
                    <div>
                        <span class="flower-eyebrow">FLOWER CATALOG</span>
                        <h5 class="modal-title" id="flowerFormModalLabel">Add New Flower</h5>
                        <p>Enter the flower information below.</p>
                    </div>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <form id="flowerForm">
                    <div class="modal-body flower-modal-body">
                        <input type="hidden" id="flowerEditingCode">
                        <div class="flower-form-grid">
                            <div class="flower-form-group">
                                <label for="flowerCode">Flower Code</label>
                                <input type="text" id="flowerCode" readonly>
                                <small>Automatically generated flower code.</small>
                            </div>
                            <div class="flower-form-group">
                                <label for="flowerStatus">Status <span>*</span></label>
                                <select id="flowerStatus" required>
                                    <option value="Active">Active</option>
                                    <option value="Inactive">Inactive</option>
                                </select>
                            </div>
                            <div class="flower-form-group">
                                <label for="flowerName">Flower Name <span>*</span></label>
                                <input type="text" id="flowerName" placeholder="Enter flower name" required>
                            </div>
                            <div class="flower-form-group">
                                <label for="flowerCategory">Category <span>*</span></label>
                                <select id="flowerCategory" required>
                                    <option value="">Select category</option>
                                    <option value="Roses">Roses</option>
                                    <option value="Sunflowers">Sunflowers</option>
                                    <option value="Tulips">Tulips</option>
                                    <option value="Lilies">Lilies</option>
                                    <option value="Carnations">Carnations</option>
                                    <option value="Gerberas">Gerberas</option>
                                    <option value="Fillers">Fillers</option>
                                    <option value="Orchids">Orchids</option>
                                    <option value="Others">Others</option>
                                </select>
                            </div>
                            <div class="flower-form-group">
                                <label for="flowerMinimumStock">Minimum Stock <span>*</span></label>
                                <input type="number" id="flowerMinimumStock" min="1" step="1"
                                    placeholder="Enter minimum stock" required>
                            </div>
                            <div class="flower-form-group flower-form-full">
                                <label for="flowerDescription">Description <span>*</span></label>
                                <textarea id="flowerDescription" rows="4" placeholder="Enter flower description"
                                    required></textarea>
                            </div>
                            <div class="flower-form-group flower-form-full">
                                <label for="flowerImage">Primary Flower Image</label>
                                <input type="file" id="flowerImage" accept="image/*">
                                <small>Image is optional. Select one primary image.</small>
                                <div id="flowerImagePreviewWrapper" class="flower-image-preview-wrapper d-none">
                                    <img id="flowerImagePreview" alt="Flower preview">
                                    <button type="button" id="removeFlowerImage" class="flower-remove-image">
                                        <i class="bi bi-x-lg"></i> Remove image
                                    </button>
                                </div>
                            </div>
                        </div>
                    </div>
                    <div class="modal-footer flower-modal-footer">
                        <button type="button" class="flower-secondary-btn" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="flower-primary-btn"><i class="bi bi-check-lg"></i> Save Flower</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    {{-- View Flower Modal --}}
    <div class="modal fade" id="flowerViewModal" tabindex="-1" aria-labelledby="flowerViewModalLabel" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content flower-modal-content">
                <div class="modal-header flower-modal-header">
                    <div>
                        <span class="flower-eyebrow">FLOWER INFORMATION</span>
                        <h5 class="modal-title" id="flowerViewModalLabel">Flower Details</h5>
                    </div>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body flower-modal-body" id="flowerViewContent"></div>
                <div class="modal-footer flower-modal-footer">
                    <button type="button" class="flower-secondary-btn" data-bs-dismiss="modal">Close</button>
                </div>
            </div>
        </div>
    </div>
@endsection

@push('scripts')
    @vite('resources/js/supplier/flowers.js')
@endpush