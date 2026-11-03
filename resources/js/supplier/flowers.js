import * as bootstrap from 'bootstrap';
import DataTable from 'datatables.net-bs5';
import 'datatables.net-bs5/css/dataTables.bootstrap5.css';
const FlowerManagement = {
    flowers: [
        { code: 'FLW-001', name: 'Red Rose', category: 'Roses', minimumStock: 100, description: 'Classic red roses suitable for romantic bouquets and special occasions.', status: 'Active', image: null },
        { code: 'FLW-002', name: 'White Rose', category: 'Roses', minimumStock: 100, description: 'Elegant white roses for weddings and formal arrangements.', status: 'Active', image: null },
        { code: 'FLW-003', name: 'Sunflower', category: 'Sunflowers', minimumStock: 50, description: 'Bright and cheerful sunflowers for decorative arrangements.', status: 'Active', image: null },
        { code: 'FLW-004', name: 'Pink Tulip', category: 'Tulips', minimumStock: 50, description: 'Fresh pink tulips for elegant floral arrangements.', status: 'Active', image: null },
        { code: 'FLW-005', name: 'White Lily', category: 'Lilies', minimumStock: 50, description: 'Beautiful white lilies with a soft and elegant appearance.', status: 'Active', image: null },
        { code: 'FLW-006', name: 'Pink Carnation', category: 'Carnations', minimumStock: 75, description: 'Pink carnations commonly used in mixed flower bouquets.', status: 'Inactive', image: null },
        { code: 'FLW-007', name: 'Gerbera Daisy', category: 'Gerberas', minimumStock: 50, description: 'Colorful gerbera daisies for cheerful floral designs.', status: 'Active', image: null },
        { code: 'FLW-008', name: "Baby's Breath", category: 'Fillers', minimumStock: 30, description: 'Delicate filler flowers for bouquet arrangements.', status: 'Inactive', image: null }
    ],
    dataTable: null,
    formModal: null,
    viewModal: null,
    elements: {},
    editingCode: null,

    init() {
        this.cacheElements();
        if (!this.elements.table || !this.elements.form) {
            console.error('Flower Management elements are missing.');
            return;
        }
        this.formModal = bootstrap.Modal.getOrCreateInstance(this.elements.formModal);
        this.viewModal = bootstrap.Modal.getOrCreateInstance(this.elements.viewModal);
        this.initializeDataTable();
        this.bindEvents();
    },

    cacheElements() {
        this.elements = {
            table: document.querySelector('#flowerTable'),
            tableBody: document.querySelector('#flowerTableBody'),
            search: document.querySelector('#flowerSearch'),
            pageLength: document.querySelector('#flowerPageLength'),
            addButton: document.querySelector('#addFlowerBtn, [data-add-flower]'),
            form: document.querySelector('#flowerForm'),
            formModal: document.querySelector('#flowerFormModal'),
            viewModal: document.querySelector('#flowerViewModal'),
            viewContent: document.querySelector('#flowerViewContent'),
            editingCode: document.querySelector('#flowerEditingCode'),
            code: document.querySelector('#flowerCode'),
            name: document.querySelector('#flowerName'),
            category: document.querySelector('#flowerCategory'),
            minimumStock: document.querySelector('#flowerMinimumStock'),
            status: document.querySelector('#flowerStatus'),
            description: document.querySelector('#flowerDescription'),
            image: document.querySelector('#flowerImage'),
            imagePreviewWrapper: document.querySelector('#flowerImagePreviewWrapper'),
            imagePreview: document.querySelector('#flowerImagePreview'),
            removeImageButton: document.querySelector('#removeFlowerImage'),
        };
    },

    initializeDataTable() {
        this.dataTable = new DataTable(this.elements.table, {
            pageLength: 5,
            lengthChange: false,
            paging: true,
            pagingType: 'simple_numbers',
            searching: true,
            ordering: true,
            order: [[0, 'asc']],
            autoWidth: false,
            processing: false,
            language: {
                emptyTable: 'No flower records available.',
                zeroRecords: 'No matching flowers found.',
                info: 'Showing _START_ to _END_ of _TOTAL_ entries',
                infoEmpty: 'Showing 0 to 0 of 0 entries',
                infoFiltered: '(filtered from _MAX_ total entries)',
                paginate: {
                    previous: '<i class="bi bi-chevron-left"></i>',
                    next: '<i class="bi bi-chevron-right"></i>'
                }
            },
            layout: {
                topStart: null,
                topEnd: null,
                bottomStart: 'info',
                bottomEnd: 'paging'
            },
            columnDefs: [
                { targets: [0, 1], className: 'dt-head-left dt-body-left' },
                { targets: 2, className: 'dt-head-right dt-body-right' },
                { targets: 3, className: 'dt-head-center dt-body-center' },
                { targets: 4, className: 'dt-head-center dt-body-center', orderable: false, searchable: false }
            ]
        });
        this.renderTable();
        this.bindTableControls();
    },

    bindTableControls() {
        const select = this.elements.pageLength;
        if (!select) return;
        select.value = String(this.dataTable.page.len());
        select.addEventListener('change', () => {
            this.dataTable.page.len(Number(select.value)).draw();
        });
        this.dataTable.on('length', (_event, _settings, length) => {
            select.value = String(length);
        });
    },

    bindEvents() {
        this.elements.addButton?.addEventListener('click', () => this.openAddModal());
        this.elements.form?.addEventListener('submit', (event) => {
            event.preventDefault();
            this.saveFlower();
        });
        this.elements.search?.addEventListener('input', () => this.applySearch());
        this.elements.search?.addEventListener('search', () => this.applySearch());
        this.elements.image?.addEventListener('change', (event) => {
            this.handleImagePreview(event);
        });
        this.elements.removeImageButton?.addEventListener('click', () => {
            this.removeImagePreview();
        });
        this.elements.tableBody?.addEventListener('click', (event) => {
            const button = event.target.closest('[data-action]');
            if (!button || button.disabled) return;
            const { action, code } = button.dataset;
            if (action === 'view') this.viewFlower(code);
            if (action === 'edit') this.editFlower(code);
            if (action === 'deactivate') this.deactivateFlower(code);
        });
        this.elements.formModal?.addEventListener('hidden.bs.modal', () => {
            this.clearForm();
        });
    },

    renderTable() {
        if (!this.dataTable) return;
        const rows = this.flowers.map((flower) => [
            this.renderFlowerDetails(flower),
            this.escapeHTML(flower.category),
            Number(flower.minimumStock).toLocaleString(),
            this.renderStatus(flower.status),
            this.renderActions(flower)
        ]);
        this.dataTable.clear();
        this.dataTable.rows.add(rows);
        this.dataTable.draw(false);
    },

    renderFlowerDetails(flower) {
        const imageUrl = flower.image instanceof File
            ? URL.createObjectURL(flower.image)
            : null;
        return `
            <div class="flower-table-details">
                ${
                    imageUrl
                        ? `<img src="${imageUrl}" class="flower-table-image" alt="">`
                        : `<div class="flower-table-placeholder"><i class="bi bi-flower1"></i></div>`
                }
                <div>
                    <strong>${this.escapeHTML(flower.name)}</strong>
                    <small>${this.escapeHTML(flower.code)}</small>
                </div>
            </div>
        `;
    },

    renderStatus(status) {
        const active = status === 'Active';
        return `
            <span class="flower-status-badge ${active ? 'active' : 'inactive'}">
                ${this.escapeHTML(status)}
            </span>
        `;
    },

    renderActions(flower) {
        const inactive = flower.status === 'Inactive';
        return `
            <div class="flower-actions">
                <button type="button" class="flower-action-btn view"
                    data-action="view" data-code="${this.escapeHTML(flower.code)}"
                    title="View" aria-label="View">
                    <i class="bi bi-eye"></i>
                </button>
                <button type="button" class="flower-action-btn edit"
                    data-action="edit" data-code="${this.escapeHTML(flower.code)}"
                    title="Edit" aria-label="Edit">
                    <i class="bi bi-pencil-square"></i>
                </button>
                <button type="button" class="flower-action-btn deactivate"
                    data-action="deactivate" data-code="${this.escapeHTML(flower.code)}"
                    title="${inactive ? 'Already inactive' : 'Deactivate'}"
                    aria-label="Deactivate" ${inactive ? 'disabled' : ''}>
                    <i class="bi bi-person-x"></i>
                </button>
            </div>
        `;
    },

    applySearch() {
        if (!this.dataTable) return;
        const query = this.elements.search?.value.trim() ?? '';
        this.dataTable.search(query, {
            regex: false,
            smart: true,
            caseInsensitive: true
        }).draw();
    },

    openAddModal() {
        this.clearForm();
        this.editingCode = null;
        this.elements.code.value = this.generateFlowerCode();
        this.elements.status.value = 'Active';
        this.setFormTitle('Add New Flower');
        this.formModal.show();
    },

    editFlower(code) {
        const flower = this.getFlower(code);
        if (!flower) return;
        this.clearForm();
        this.editingCode = code;
        this.elements.editingCode.value = flower.code;
        this.elements.code.value = flower.code;
        this.elements.name.value = flower.name;
        this.elements.category.value = flower.category;
        this.elements.minimumStock.value = flower.minimumStock;
        this.elements.status.value = flower.status;
        this.elements.description.value = flower.description;
        if (flower.image instanceof File) {
            this.showImagePreview(URL.createObjectURL(flower.image));
        }
        this.setFormTitle('Edit Flower');
        this.formModal.show();
    },

    setFormTitle(title) {
        const heading = this.elements.formModal.querySelector('.modal-title');
        if (heading) heading.textContent = title;
    },

    saveFlower() {
        const name = this.elements.name.value.trim();
        const category = this.elements.category.value;
        const minimumStock = Number(this.elements.minimumStock.value);
        const description = this.elements.description.value.trim();
        const status = this.elements.status.value;
        const imageFile = this.elements.image.files[0] || null;
        if (!name || !category || !description || !Number.isInteger(minimumStock) || minimumStock < 1) {
            alert('Please complete all required fields.');
            return;
        }
        if (imageFile && !imageFile.type.startsWith('image/')) {
            alert('Please select a valid image file.');
            return;
        }
        if (this.editingCode) {
            const flower = this.getFlower(this.editingCode);
            if (!flower) return;
            flower.name = name;
            flower.category = category;
            flower.minimumStock = minimumStock;
            flower.description = description;
            flower.status = status;
            if (imageFile) flower.image = imageFile;
        } else {
            this.flowers.push({
                code: this.generateFlowerCode(),
                name,
                category,
                minimumStock,
                description,
                status,
                image: imageFile
            });
        }
        this.renderTable();
        this.applySearch();
        this.formModal.hide();
    },

    viewFlower(code) {
        const flower = this.getFlower(code);
        if (!flower) return;
        const imageUrl = flower.image instanceof File
            ? URL.createObjectURL(flower.image)
            : null;
        this.elements.viewContent.innerHTML = `
            <div class="flower-view-header">
                ${
                    imageUrl
                        ? `<img src="${imageUrl}" class="flower-view-image" alt="">`
                        : `<div class="flower-view-placeholder"><i class="bi bi-flower1"></i></div>`
                }
                <div>
                    <h5>${this.escapeHTML(flower.name)}</h5>
                    <span>${this.escapeHTML(flower.code)}</span>
                    <div class="mt-2">${this.renderStatus(flower.status)}</div>
                </div>
            </div>
            <div class="flower-view-details">
                <div>
                    <label>Category</label>
                    <p>${this.escapeHTML(flower.category)}</p>
                </div>
                <div>
                    <label>Minimum Stock</label>
                    <p>${Number(flower.minimumStock).toLocaleString()}</p>
                </div>
                <div class="flower-form-full">
                    <label>Description</label>
                    <p>${this.escapeHTML(flower.description)}</p>
                </div>
            </div>
        `;
        this.viewModal.show();
    },

    deactivateFlower(code) {
        const flower = this.getFlower(code);
        if (!flower || flower.status !== 'Active') return;
        if (!window.confirm(`Are you sure you want to deactivate ${flower.name}?`)) {
            return;
        }
        flower.status = 'Inactive';
        this.renderTable();
        this.applySearch();
    },

    handleImagePreview(event) {
        const file = event.target.files[0];
        if (!file) {
            this.removeImagePreview();
            return;
        }
        if (!file.type.startsWith('image/')) {
            alert('Please select a valid image file.');
            this.elements.image.value = '';
            return;
        }
        this.showImagePreview(URL.createObjectURL(file));
    },

    showImagePreview(source) {
        this.elements.imagePreview.src = source;
        this.elements.imagePreviewWrapper.classList.remove('d-none');
    },

    removeImagePreview() {
        if (this.elements.image) this.elements.image.value = '';
        if (this.elements.imagePreview) this.elements.imagePreview.removeAttribute('src');
        this.elements.imagePreviewWrapper?.classList.add('d-none');
    },

    clearForm() {
        this.elements.form.reset();
        this.editingCode = null;
        if (this.elements.editingCode) this.elements.editingCode.value = '';
        if (this.elements.code) this.elements.code.value = '';
        this.removeImagePreview();
    },

    generateFlowerCode() {
        const highest = this.flowers.reduce((max, flower) => {
            const number = Number(flower.code.replace('FLW-', ''));
            return Math.max(max, number);
        }, 0);
        return `FLW-${String(highest + 1).padStart(3, '0')}`;
    },

    getFlower(code) {
        return this.flowers.find((flower) => flower.code === code);
    },

    escapeHTML(value) {
        return String(value ?? '').replace(/[&<>"']/g, (character) => ({
            '&': '&amp;',
            '<': '&lt;',
            '>': '&gt;',
            '"': '&quot;',
            "'": '&#039;'
        })[character]);
    }
};
if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', () => FlowerManagement.init(), { once: true });
} else {
    FlowerManagement.init();
}
