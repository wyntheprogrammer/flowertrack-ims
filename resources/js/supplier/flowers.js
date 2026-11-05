import * as bootstrap from 'bootstrap';
import DataTable from 'datatables.net-bs5';
import 'datatables.net-bs5/css/dataTables.bootstrap5.css';

/* ==================================================
   FLOWER MANAGEMENT — DATABASE BACKEND INTEGRATION
================================================== */

const FlowerManagement = {
    flowers: [],
    elements: {},
    dataTable: null,
    formModal: null,
    viewModal: null,
    notice: null,
    formErrors: null,
    editingId: null,
    imageRemoved: false,
    previewUrl: null,
    loading: false,
    saving: false,

    /* ==================================================
       INITIALIZE PAGE
    ================================================== */

    init() {
        this.cacheElements();
        if (!this.elements.table || !this.elements.form) return;
        this.formModal = bootstrap.Modal.getOrCreateInstance(this.elements.formModal);
        this.viewModal = bootstrap.Modal.getOrCreateInstance(this.elements.viewModal);
        this.createMessages();
        this.initializeDataTable();
        this.bindEvents();
        this.loadFlowers();
        window.addEventListener('pagehide', () => this.releasePreview());
    },

    /* ==================================================
       CACHE ELEMENTS
    ================================================== */

    cacheElements() {
        const ids = {
            table: 'flowerTable', search: 'flowerSearch', pageLength: 'flowerPageLength',
            form: 'flowerForm', formModal: 'flowerFormModal', viewModal: 'flowerViewModal',
            viewContent: 'flowerViewContent', editingCode: 'flowerEditingCode', code: 'flowerCode',
            name: 'flowerName', category: 'flowerCategory', itemType: 'flowerItemType',
            baseUnit: 'flowerBaseUnit', minimumHelp: 'flowerMinimumStockHelp',
            minimumStock: 'flowerMinimumStock', status: 'flowerStatus', description: 'flowerDescription',
            image: 'flowerImage', imagePreview: 'flowerImagePreview',
            imagePreviewWrapper: 'flowerImagePreviewWrapper', removeImageButton: 'removeFlowerImage',
        };
        this.elements = Object.fromEntries(
            Object.entries(ids).map(([key, id]) => [key, document.getElementById(id)])
        );
        this.elements.addButtons = document.querySelectorAll('#addFlowerBtn, [data-add-flower]');
        this.elements.submitButton = this.elements.form?.querySelector('[type="submit"]');
    },

    /* ==================================================
       CREATE ACCESSIBLE PAGE AND FORM MESSAGES
    ================================================== */

    createMessages() {
        this.notice = document.createElement('p');
        this.notice.className = 'small text-muted mb-3 px-4';
        this.notice.setAttribute('role', 'status');
        this.notice.setAttribute('aria-live', 'polite');
        const anchor = this.elements.table.closest('.flower-table-container') || this.elements.table;
        anchor.parentNode.insertBefore(this.notice, anchor);
        this.formErrors = document.createElement('div');
        this.formErrors.className = 'alert alert-danger d-none';
        this.formErrors.setAttribute('role', 'alert');
        (this.elements.form.querySelector('.modal-body') || this.elements.form).prepend(this.formErrors);
    },

    /* ==================================================
       SET PAGE MESSAGE
    ================================================== */

    setNotice(message, isError = false) {
        this.notice.textContent = message;
        this.notice.classList.toggle('text-danger', isError);
        this.notice.classList.toggle('text-muted', !isError);
    },

    /* ==================================================
       INITIALIZE DATATABLE AND PRESERVE COLUMN ALIGNMENT
    ================================================== */

    initializeDataTable() {
        this.dataTable = new DataTable(this.elements.table, {
            data: [], pageLength: 5, lengthChange: false, paging: true,
            pagingType: 'simple_numbers', searching: true, ordering: true,
            order: [[0, 'asc']], autoWidth: false,
            language: {
                emptyTable: 'No flower records available.',
                zeroRecords: 'No matching flowers found.',
                info: 'Showing _START_ to _END_ of _TOTAL_ entries',
                infoEmpty: 'Showing 0 to 0 of 0 entries',
                infoFiltered: '(filtered from _MAX_ total entries)',
                paginate: {
                    previous: '<i class="bi bi-chevron-left"></i>',
                    next: '<i class="bi bi-chevron-right"></i>',
                },
            },
            layout: { topStart: null, topEnd: null, bottomStart: 'info', bottomEnd: 'paging' },
            columnDefs: [
                { targets: [0, 2], className: 'dt-head-left dt-body-left' },
                { targets: [1, 3, 4], className: 'dt-head-center dt-body-center' },
                { targets: 5, className: 'dt-head-center dt-body-center', orderable: false, searchable: false },
            ],
        });
        if (this.elements.pageLength) this.elements.pageLength.value = String(this.dataTable.page.len());
    },

    /* ==================================================
       BIND SEARCH, FORM, IMAGE AND ROW ACTIONS
    ================================================== */

    bindEvents() {
        this.elements.addButtons.forEach(button => {
            button.addEventListener('click', () => this.openAddModal());
        });
        this.elements.form.addEventListener('submit', event => {
            event.preventDefault();
            this.saveFlower();
        });
        this.elements.search?.addEventListener('input', () => this.applySearch());
        this.elements.search?.addEventListener('search', () => this.applySearch());
        this.elements.pageLength?.addEventListener('change', () => {
            const length = Number(this.elements.pageLength.value);
            if ([5, 10, 25, 50].includes(length)) this.dataTable.page.len(length).draw();
        });
        this.dataTable.on('length', (_event, _settings, length) => {
            if (this.elements.pageLength) this.elements.pageLength.value = String(length);
        });
        this.elements.itemType.addEventListener('change', () => this.syncItemUnit());
        this.elements.image.addEventListener('change', () => this.handleImagePreview());
        this.elements.removeImageButton.addEventListener('click', () => {
            this.clearImage();
            this.imageRemoved = true;
        });
        this.elements.table.addEventListener('click', event => {
            const button = event.target.closest('button[data-action]');
            if (!button || button.disabled) return;
            const id = Number(button.dataset.id);
            if (button.dataset.action === 'view') this.viewFlower(id);
            if (button.dataset.action === 'edit') this.editFlower(id);
            if (button.dataset.action === 'deactivate') this.deactivateFlower(id);
        });
        this.elements.formModal.addEventListener('hide.bs.modal', event => {
            if (this.saving) event.preventDefault();
        });
        this.elements.formModal.addEventListener('hidden.bs.modal', () => this.clearForm());
    },

    /* ==================================================
       CHECK SERVER RESPONSE AND PRESERVE VALIDATION ERRORS
    ================================================== */

    async readResponse(response) {
        const isJson = (response.headers.get('content-type') || '').includes('application/json');
        let result = {};
        if (isJson) result = await response.json();
        if (!response.ok) {
            const messages = {
                401: 'Your session has expired. Please log in again.',
                403: 'Your account is not allowed to perform this action.',
                404: 'This flower record or endpoint could not be found. Refresh the page.',
                419: 'Your session token has expired. Refresh the page.',
                413: 'The uploaded file exceeds the server upload limit.',
            };
            const message = messages[response.status]
                || (response.status >= 500 ? 'The server could not complete the request.' : result.message)
                || 'Unable to complete the request.';
            const error = new Error(message);
            error.errors = result.errors || {};
            error.status = response.status;
            throw error;
        }
        if (!isJson) throw new Error('Unexpected response. Refresh the page and check your login.');
        return result;
    },

    /* ==================================================
       MAP DATABASE COLUMN NAMES TO UI FIELDS
    ================================================== */

    mapFlower(flower) {
        if (!flower || !Number.isSafeInteger(Number(flower.id)) || Number(flower.id) < 1
            || !flower.code || !['Stem', 'Bouquet'].includes(flower.product_type)
            || !['Active', 'Inactive'].includes(flower.status)
            || !Number.isInteger(Number(flower.minimum_stock)) || Number(flower.minimum_stock) < 1) {
            throw new Error('Invalid flower record received. Refresh the list before trying again.');
        }
        return {
            id: Number(flower.id), code: flower.code, name: flower.name,
            itemType: flower.product_type, category: flower.category,
            minimumStock: Number(flower.minimum_stock), description: flower.description,
            status: flower.status, imageUrl: flower.image_url || null,
        };
    },

    /* ==================================================
       LOAD RECORDS FROM DATABASE
    ================================================== */

    async loadFlowers() {
        if (this.loading || this.saving) return;
        this.loading = true;
        this.setAddDisabled(true);
        this.elements.table.setAttribute('aria-busy', 'true');
        this.setNotice('Loading flower records...');
        let loaded = false;
        try {
            const url = this.elements.table.dataset.url;
            if (!url) throw new Error('Missing flower data URL. Check the Blade table.');
            const response = await fetch(url, {
                headers: { Accept: 'application/json' }, credentials: 'same-origin', redirect: 'error',
            });
            const result = await this.readResponse(response);
            if (!Array.isArray(result.data)) throw new Error('Invalid flower list response.');
            this.flowers = result.data.map(flower => this.mapFlower(flower));
            this.renderTable();
            this.applySearch();
            this.setNotice(this.flowers.length ? '' : 'No flower records yet. Click Add New Flower to begin.');
            loaded = true;
        } catch (error) {
            console.error('Flower loading failed:', error);
            this.setNotice(error.message || 'Unable to load flower records.', true);
        } finally {
            this.loading = false;
            this.elements.table.setAttribute('aria-busy', 'false');
            this.setAddDisabled(!loaded);
        }
        if (loaded && new URLSearchParams(window.location.search).get('create') === '1') {
            this.openAddModal();
        }
    },

    /* ==================================================
       ADD BUTTON AVAILABILITY
    ================================================== */

    setAddDisabled(disabled) {
        this.elements.addButtons.forEach(button => {
            button.disabled = disabled;
            button.removeAttribute('title');
        });
    },

    /* ==================================================
       RENDER TABLE ROWS
    ================================================== */

    renderTable() {
        this.dataTable.clear();
        this.dataTable.rows.add(this.flowers.map(flower => [
            this.renderFlowerDetails(flower), this.escapeHTML(flower.itemType),
            this.escapeHTML(flower.category), this.formatQuantity(flower),
            this.renderStatus(flower.status), this.renderActions(flower),
        ]));
        this.dataTable.draw(false);
    },

    /* ==================================================
       RENDER IMAGE, NAME AND CODE
    ================================================== */

    renderFlowerDetails(flower) {
        return `<div class="flower-table-details">
            ${this.renderImage(flower, false)}
            <div><strong>${this.escapeHTML(flower.name)}</strong><small>${this.escapeHTML(flower.code)}</small></div>
        </div>`;
    },

    /* ==================================================
       SHARED IMAGE / PLACEHOLDER RENDERING
    ================================================== */

    renderImage(flower, large) {
        const prefix = large ? 'flower-view' : 'flower-table';
        const url = this.safeImageUrl(flower.imageUrl);
        return url
            ? `<img src="${this.escapeHTML(url)}" class="${prefix}-image" alt="" loading="lazy">`
            : `<div class="${prefix}-placeholder"><i class="bi bi-flower1" aria-hidden="true"></i></div>`;
    },

    /* ==================================================
       ACCEPT ONLY HTTP(S) IMAGE URLS
    ================================================== */

    safeImageUrl(value) {
        if (!value) return null;
        try {
            const url = new URL(value, window.location.href);
            return ['http:', 'https:'].includes(url.protocol) ? url.href : null;
        } catch {
            return null;
        }
    },

    /* ==================================================
       FORMAT QUANTITY WITHOUT MIXING STOCK UNITS
    ================================================== */

    formatQuantity(flower) {
        return `${flower.minimumStock.toLocaleString()} ${flower.itemType === 'Bouquet' ? 'bouquets' : 'stems'}`;
    },

    /* ==================================================
       STATUS BADGE
    ================================================== */

    renderStatus(status) {
        return `<span class="flower-status-badge ${status === 'Active' ? 'active' : 'inactive'}">${this.escapeHTML(status)}</span>`;
    },

    /* ==================================================
       VIEW / EDIT / DEACTIVATE ACTION BUTTONS
    ================================================== */

    renderActions(flower) {
        return `<div class="flower-actions">
            <button type="button" class="flower-action-btn view" data-action="view" data-id="${flower.id}" title="View" aria-label="View flower">
                <i class="bi bi-eye" aria-hidden="true"></i>
            </button>
            <button type="button" class="flower-action-btn edit" data-action="edit" data-id="${flower.id}" title="Edit" aria-label="Edit flower" ${this.saving ? 'disabled' : ''}>
                <i class="bi bi-pencil-square" aria-hidden="true"></i>
            </button>
            <button type="button" class="flower-action-btn deactivate" data-action="deactivate" data-id="${flower.id}" title="${flower.status === 'Inactive' ? 'Already inactive' : 'Deactivate'}" aria-label="Deactivate flower" ${this.saving || flower.status === 'Inactive' ? 'disabled' : ''}>
                <i class="bi bi-person-x" aria-hidden="true"></i>
            </button>
        </div>`;
    },

    /* ==================================================
       APPLY SEARCH WHILE PRESERVING DATATABLE SORTING
    ================================================== */

    applySearch() {
        this.dataTable.search(this.elements.search?.value.trim() || '', {
            regex: false, smart: true, caseInsensitive: true,
        }).draw();
    },

    /* ==================================================
       OPEN ADD FORM — CODE COMES FROM THE BACKEND
    ================================================== */

    openAddModal() {
        if (this.loading || this.saving) return;
        this.clearForm();
        this.elements.formModal.querySelector('.modal-title').textContent = 'Add New Flower';
        this.elements.code.value = 'Generated after saving';
        this.formModal.show();
    },

    /* ==================================================
       OPEN EDIT FORM — EXISTING ITEM TYPE IS IMMUTABLE
    ================================================== */

    editFlower(id) {
        if (this.loading || this.saving) return;
        const flower = this.flowers.find(record => record.id === id);
        if (!flower) return;
        this.clearForm();
        this.editingId = flower.id;
        this.elements.editingCode.value = flower.code;
        this.elements.code.value = flower.code;
        this.elements.name.value = flower.name;
        this.elements.itemType.value = flower.itemType;
        this.elements.itemType.disabled = true;
        this.elements.category.value = flower.category;
        this.elements.minimumStock.value = flower.minimumStock;
        this.elements.description.value = flower.description;
        this.elements.status.value = flower.status;
        this.syncItemUnit();
        const url = this.safeImageUrl(flower.imageUrl);
        if (url) this.showImagePreview(url);
        this.elements.formModal.querySelector('.modal-title').textContent = 'Edit Flower';
        this.formModal.show();
    },

    /* ==================================================
       RESET FORM AND TEMPORARY IMAGE STATE
    ================================================== */

    clearForm() {
        this.elements.form.reset();
        this.editingId = null;
        this.imageRemoved = false;
        this.elements.editingCode.value = '';
        this.elements.itemType.disabled = false;
        this.elements.itemType.value = 'Stem';
        this.elements.status.value = 'Active';
        this.clearImage();
        this.clearErrors();
        this.syncItemUnit();
    },

    /* ==================================================
       SYNC BASE UNIT WITH PRODUCT TYPE
    ================================================== */

    syncItemUnit() {
        const bouquet = this.elements.itemType.value === 'Bouquet';
        this.elements.baseUnit.value = bouquet ? 'Bouquet' : 'Stem';
        this.elements.minimumHelp.textContent = bouquet
            ? 'Minimum quantity in finished bouquets.' : 'Minimum quantity in stems.';
    },

    /* ==================================================
       READ CSRF TOKEN AND BUILD RECORD ENDPOINT
    ================================================== */

    csrfToken() {
        const token = this.elements.form.querySelector('input[name="_token"]')?.value;
        if (!token) throw new Error('Missing CSRF token. Add @csrf inside the Blade form.');
        return token;
    },

    recordUrl(action, id) {
        const template = this.elements.table.dataset[`${action}Url`];
        if (!template || !template.includes('__FLOWER__')) {
            throw new Error(`Missing ${action} URL template. Replace the Blade file too.`);
        }
        return template.replace('__FLOWER__', encodeURIComponent(String(id)));
    },

    /* ==================================================
       LOCK FORM AND WRITE ACTIONS WHILE SAVING
    ================================================== */

    setSaving(saving) {
        this.saving = saving;
        this.setAddDisabled(saving);
        this.elements.form.setAttribute('aria-busy', String(saving));
        for (const key of ['name', 'category', 'minimumStock', 'status', 'description', 'image', 'removeImageButton', 'submitButton']) {
            this.elements[key].disabled = saving;
        }
        this.elements.itemType.disabled = saving || this.editingId !== null;
        this.elements.table.querySelectorAll('[data-action="edit"], [data-action="deactivate"]').forEach(button => {
            const flower = this.flowers.find(record => record.id === Number(button.dataset.id));
            button.disabled = saving || (button.dataset.action === 'deactivate' && flower?.status === 'Inactive');
        });
    },

    /* ==================================================
       SUBMIT CREATE OR UPDATE WITH OPTIONAL IMAGE
    ================================================== */

    async saveFlower() {
        if (this.saving || !this.elements.form.reportValidity()) return;
        this.clearErrors();
        let saved = false;
        try {
            const editing = this.editingId !== null;
            const url = editing ? this.recordUrl('update', this.editingId) : this.elements.form.getAttribute('action');
            if (!url) throw new Error('Missing flower form action.');
            const data = new FormData();
            data.append('_token', this.csrfToken());
            data.append('name', this.elements.name.value.trim());
            // Explicitly append the disabled type field during Edit.
            data.append('product_type', this.elements.itemType.value);
            data.append('category', this.elements.category.value);
            data.append('minimum_stock', this.elements.minimumStock.value);
            data.append('description', this.elements.description.value.trim());
            data.append('status', this.elements.status.value);
            if (editing) data.append('remove_image', this.imageRemoved ? '1' : '0');
            const image = this.elements.image.files[0];
            if (image) data.append('image', image);
            this.setSaving(true);
            const response = await fetch(url, {
                method: 'POST', headers: { Accept: 'application/json' },
                credentials: 'same-origin', redirect: 'error', body: data,
            });
            const result = await this.readResponse(response);
            this.upsertFlower(result.data);
            this.setNotice(result.message || 'Flower saved successfully.');
            saved = true;
        } catch (error) {
            console.error('Flower saving failed:', error);
            this.showRequestError(error);
        } finally {
            if (this.saving) this.setSaving(false);
        }
        if (saved) this.formModal.hide();
    },

    /* ==================================================
       DEACTIVATE WITHOUT DELETING THE FLOWER RECORD
    ================================================== */

    async deactivateFlower(id) {
        if (this.saving || this.loading) return;
        const flower = this.flowers.find(record => record.id === id);
        if (!flower || flower.status === 'Inactive') return;
        if (!window.confirm(`Deactivate ${flower.name}? The record and its history will be retained.`)) return;
        try {
            const data = new FormData();
            data.append('_token', this.csrfToken());
            const url = this.recordUrl('deactivate', id);
            this.setSaving(true);
            const response = await fetch(url, {
                method: 'POST', headers: { Accept: 'application/json' },
                credentials: 'same-origin', redirect: 'error', body: data,
            });
            const result = await this.readResponse(response);
            this.upsertFlower(result.data);
            this.setNotice(result.message || 'Flower deactivated successfully.');
        } catch (error) {
            console.error('Flower deactivation failed:', error);
            this.setNotice(error.message || 'Unable to deactivate this flower.', true);
        } finally {
            if (this.saving) this.setSaving(false);
        }
    },

    /* ==================================================
       REPLACE UI RECORD ONLY AFTER BACKEND SUCCESS
    ================================================== */

    upsertFlower(data) {
        const flower = this.mapFlower(data);
        const index = this.flowers.findIndex(record => record.id === flower.id);
        if (index === -1) this.flowers.unshift(flower);
        else this.flowers.splice(index, 1, flower);
        this.renderTable();
        this.applySearch();
    },

    /* ==================================================
       DISPLAY VALIDATION ERRORS WITHOUT LOSING INPUT
    ================================================== */

    showRequestError(error) {
        const messages = Object.values(error.errors || {}).flat();
        if (messages.length) {
            this.showErrors(messages);
            return;
        }
        let message = error.message || 'Unable to complete saving.';
        if (![401, 403, 404, 413, 419, 422].includes(error.status)) {
            message += ' Refresh the page to check whether saving succeeded before retrying.';
        }
        this.showErrors([message]);
    },

    showErrors(messages) {
        this.formErrors.replaceChildren();
        const list = document.createElement('ul');
        list.className = 'mb-0';
        messages.forEach(message => {
            const item = document.createElement('li');
            item.textContent = String(message);
            list.appendChild(item);
        });
        this.formErrors.appendChild(list);
        this.formErrors.classList.remove('d-none');
        this.formErrors.scrollIntoView({ block: 'nearest' });
    },

    clearErrors() {
        this.formErrors.replaceChildren();
        this.formErrors.classList.add('d-none');
    },

    /* ==================================================
       PREVIEW NEW IMAGE OR RESTORE EXISTING IMAGE
    ================================================== */

    handleImagePreview() {
        const file = this.elements.image.files[0];
        this.releasePreview();
        this.hideImagePreview();
        if (!file) {
            if (!this.imageRemoved) this.restoreExistingImage();
            return;
        }
        if (!['image/jpeg', 'image/png', 'image/webp'].includes(file.type)
            || file.size > 2 * 1024 * 1024) {
            this.elements.image.value = '';
            if (!this.imageRemoved) this.restoreExistingImage();
            this.showErrors(['Select a JPG, PNG or WebP image no larger than 2 MB.']);
            return;
        }
        this.clearErrors();
        this.imageRemoved = false;
        this.previewUrl = URL.createObjectURL(file);
        this.showImagePreview(this.previewUrl);
    },

    restoreExistingImage() {
        const flower = this.flowers.find(record => record.id === this.editingId);
        const url = this.safeImageUrl(flower?.imageUrl);
        if (url) this.showImagePreview(url);
    },

    showImagePreview(url) {
        this.elements.imagePreview.src = url;
        this.elements.imagePreviewWrapper.classList.remove('d-none');
    },

    hideImagePreview() {
        this.elements.imagePreview.removeAttribute('src');
        this.elements.imagePreviewWrapper.classList.add('d-none');
    },

    clearImage() {
        this.elements.image.value = '';
        this.hideImagePreview();
        this.releasePreview();
    },

    releasePreview() {
        if (this.previewUrl) {
            URL.revokeObjectURL(this.previewUrl);
            this.previewUrl = null;
        }
    },

    /* ==================================================
       SHOW FLOWER DETAILS MODAL
    ================================================== */

    viewFlower(id) {
        const flower = this.flowers.find(record => record.id === id);
        if (!flower) return;
        this.elements.viewContent.innerHTML = `
            <div class="flower-view-header">
                ${this.renderImage(flower, true)}
                <div><h5>${this.escapeHTML(flower.name)}</h5>
                    <span>${this.escapeHTML(flower.code)}</span>
                    <div class="mt-2">${this.renderStatus(flower.status)}</div>
                </div>
            </div>
            <div class="flower-view-details">
                <div><label>Item Type / Base Unit</label><p>${this.escapeHTML(flower.itemType)} / ${this.escapeHTML(flower.itemType)}</p></div>
                <div><label>Category</label><p>${this.escapeHTML(flower.category)}</p></div>
                <div><label>Minimum Stock</label><p>${this.formatQuantity(flower)}</p></div>
                <div class="flower-form-full"><label>Description</label><p>${this.escapeHTML(flower.description)}</p></div>
            </div>`;
        this.viewModal.show();
    },

    /* ==================================================
       ESCAPE TEXT BEFORE INSERTING INTO HTML
    ================================================== */

    escapeHTML(value) {
        return String(value ?? '').replace(/[&<>"']/g, character => ({
            '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#039;',
        })[character]);
    },
};

/* ==================================================
   START WHEN THE PAGE IS READY
================================================== */

if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', () => FlowerManagement.init(), { once: true });
} else {
    FlowerManagement.init();
}
