import { Modal } from 'bootstrap';
import DataTable from 'datatables.net-bs5';
import 'datatables.net-bs5/css/dataTables.bootstrap5.css';

/* HELPERS */

const escapeHTML = (value) => String(value ?? '').replace(
    /[&<>"']/g,
    (character) => ({
        '&': '&amp;',
        '<': '&lt;',
        '>': '&gt;',
        '"': '&quot;',
        "'": '&#039;'
    })[character]
);

const number = (value) => Number(value).toLocaleString();

const dayNumber = (value) => {
    if (!/^\d{4}-\d{2}-\d{2}$/.test(value)) return NaN;

    const [year, month, day] = value.split('-').map(Number);
    const date = new Date(Date.UTC(year, month - 1, day));

    if (
        date.getUTCFullYear() !== year ||
        date.getUTCMonth() !== month - 1 ||
        date.getUTCDate() !== day
    ) {
        return NaN;
    }

    return date.getTime() / 86400000;
};

/* ==================================================
   DATABASE SNAPSHOT — NO LOCAL STOCK MUTATIONS
================================================== */
class BatchModel {
    /* ==================================================
       CONSTRUCTOR
    ================================================== */

    constructor() {
        this.flowers = [];
        this.batches = [];
        this.today = '';
        this.warningDays = 3;
    }

    /* ==================================================
       HYDRATE
    ================================================== */

    hydrate(result) {
        const data = result?.data;
        if (!data || !Array.isArray(data.flowers) || !Array.isArray(data.batches)
            || !Number.isFinite(dayNumber(result.today))
            || !Number.isInteger(result.warning_days) || result.warning_days < 0) {
            throw new Error('Invalid batch response. Refresh the page.');
        }
        for (const flower of data.flowers) {
            if (!['Stem', 'Bouquet'].includes(flower.baseUnit)) throw new Error('Invalid batch item unit.');
        }
        for (const batch of data.batches) {
            if (!Number.isSafeInteger(batch.dbId) || batch.dbId < 1
                || !Number.isSafeInteger(batch.initial) || batch.initial < 1
                || !Number.isSafeInteger(batch.remaining) || batch.remaining < 0
                || batch.remaining > batch.initial
                || typeof batch.spoiled !== 'boolean'
                || !/^[a-f0-9]{64}$/.test(batch.version)
                || !data.flowers.some(flower => flower.code === batch.flower)
                || !Number.isFinite(dayNumber(batch.received))
                || !Number.isFinite(dayNumber(batch.harvest))
                || (batch.expiry && !Number.isFinite(dayNumber(batch.expiry)))
                || !Array.isArray(batch.history)) {
                throw new Error('Invalid batch record. Refresh the page.');
            }
            for (const movement of batch.history) {
                if (!Number.isSafeInteger(movement.quantity)) throw new Error('Invalid movement quantity.');
            }
        }
        this.flowers = data.flowers;
        this.batches = data.batches;
        this.today = result.today;
        this.warningDays = result.warning_days;
    }

    /* ==================================================
       FLOWER
    ================================================== */

    flower(code) {
        return this.flowers.find((flower) => flower.code === code);
    }

    /* ==================================================
       UNIT LABEL
    ================================================== */

    unitLabel(batch) {
        return this.flower(batch.flower).baseUnit === 'Bouquet' ? 'bouquets' : 'stems';
    }

    /* ==================================================
       FIND
    ================================================== */

    find(id) {
        return this.batches.find((batch) => batch.id === id);
    }

    /* ==================================================
       STATUS
    ================================================== */

    status(batch) {
        if (batch.spoiled) return 'Spoiled';
        if (batch.remaining === 0) return 'Depleted';

        return batch.remaining === batch.initial
            ? 'Available'
            : 'Partially Used';
    }

    /* ==================================================
       FRESHNESS
    ================================================== */

    freshness(batch) {
        if (batch.spoiled || batch.remaining === 0) {
            return {
                style: 'unset',
                text: 'No remaining stock',
                warning: false
            };
        }

        if (!batch.expiry) {
            return {
                style: 'unset',
                text: 'Not set',
                warning: false
            };
        }

        const days = dayNumber(batch.expiry) - dayNumber(this.today);

        if (days < 0) {
            return {
                style: 'expired',
                text: 'Date passed',
                warning: true
            };
        }

        if (days === 0) {
            return {
                style: 'soon',
                text: 'Due today',
                warning: true
            };
        }

        if (days <= this.warningDays) {
            return {
                style: 'soon',
                text: `Due in ${days} day${days === 1 ? '' : 's'}`,
                warning: true
            };
        }

        return {
            style: 'fresh',
            text: `${days} days remaining`,
            warning: false
        };
    }

}

const BatchUI = {
    model: new BatchModel(),
    table: null,
    ready: false,
    loading: false,
    saving: false,
    committed: false,
    editingId: null,
    editingVersion: null,
    requestKey: null,

    /* ==================================================
       EL
    ================================================== */

    el(id) {
        return document.getElementById(id);
    },

    /* ==================================================
       INIT
    ================================================== */

    init() {
        if (
            !this.el('batchPage') ||
            DataTable.isDataTable('#batchTable')
        ) {
            return;
        }

        this.viewModal = Modal.getOrCreateInstance(
            this.el('batchViewModal')
        );

        this.editModal = Modal.getOrCreateInstance(
            this.el('batchEditModal')
        );

        this.table = new DataTable('#batchTable', {
            data: this.model.batches,
            pageLength: 5,
            lengthChange: false,
            autoWidth: false,
            searching: true,
            ordering: true,
            order: [[2, 'desc']],

            layout: {
                topStart: null,
                topEnd: null,
                bottomStart: 'info',
                bottomEnd: 'paging'
            },

            language: {
                emptyTable: 'No batches available.',
                zeroRecords: 'No matching batches found.',
                info: 'Showing _START_ to _END_ of _TOTAL_ entries',
                infoEmpty: 'Showing 0 entries'
            },

            columns: [
                {
                    data: 'id',
                    render: (value, type) => type === 'display'
                        ? `<strong>${escapeHTML(value)}</strong>`
                        : value
                },
                {
                    data: 'flower',
                    render: (code, type) => {
                        const flower = this.model.flower(code);

                        if (type !== 'display') {
                            return `${flower.name} ${flower.code} ${flower.category} ${flower.baseUnit}`;
                        }

                        return `
                            <div class="batch-flower-details">
                                <span class="batch-flower-icon">
                                    <i class="bi bi-flower1"
                                       aria-hidden="true"></i>
                                </span>

                                <div>
                                    <strong>${escapeHTML(flower.name)}</strong>
                                    <small>
                                        ${escapeHTML(flower.code)} · ${escapeHTML(flower.category)} · ${escapeHTML(flower.baseUnit)}
                                    </small>
                                </div>
                            </div>
                        `;
                    }
                },
                {
                    data: 'received'
                },
                {
                    data: 'initial',
                    render: (value, type) =>
                        type === 'display' ? number(value) : value
                },
                {
                    data: 'remaining',
                    render: (value, type) =>
                        type === 'display' ? number(value) : value
                },
                {
                    data: 'expiry',
                    render: (value, type, batch) => {
                        if (type === 'sort' || type === 'type') {
                            return value || '9999-12-31';
                        }

                        const freshness = this.model.freshness(batch);

                        if (type !== 'display') {
                            return `${value} ${freshness.text}`;
                        }

                        return `
                            ${value || '—'}
                            <div>
                                <span class="batch-freshness-badge ${freshness.style}">
                                    ${freshness.text}
                                </span>
                            </div>
                        `;
                    }
                },
                {
                    data: null,
                    render: (_value, type, batch) => type === 'display'
                        ? this.badge(batch)
                        : this.model.status(batch)
                },
                {
                    data: null,
                    orderable: false,
                    searchable: false,
                    render: (_value, type, batch) => type === 'display'
                        ? this.actions(batch)
                        : ''
                }
            ],

            columnDefs: [
                {
                    targets: [0, 1],
                    className: 'dt-head-left dt-body-left'
                },
                {
                    targets: [2, 3, 4, 5, 6, 7],
                    className: 'dt-head-center dt-body-center'
                }
            ]
        });

        this.mountTableScroller();
        this.bindEvents();
        this.reload();
    },

    /* ==================================================
       MOUNT TABLE SCROLLER
    ================================================== */

    mountTableScroller() {
        const table = this.el('batchTable');

        if (table.parentElement.classList.contains('batch-table-scroll')) {
            return;
        }

        const scroller = document.createElement('div');

        scroller.className = 'batch-table-scroll';
        scroller.tabIndex = 0;

        scroller.setAttribute('role', 'region');
        scroller.setAttribute(
            'aria-label',
            'Batch table. Scroll horizontally to see all columns.'
        );

        table.before(scroller);
        scroller.append(table);
    },

    /* ==================================================
       BADGE
    ================================================== */

    badge(batch) {
        const status = this.model.status(batch);

        const style = {
            Available: 'available',
            'Partially Used': 'partial',
            Depleted: 'depleted',
            Spoiled: 'spoiled'
        }[status];

        return `
            <span class="batch-status-badge ${style}">
                ${status}
            </span>
        `;
    },

    /* ==================================================
       ACTIONS
    ================================================== */

    actions(batch) {
        return `
        <div class="batch-actions">
            <button type="button"
                    class="batch-action-btn view"
                    data-batch-action="view"
                    data-id="${batch.id}"
                    title="View batch"
                    aria-label="View ${batch.id}">
                <i class="bi bi-eye" aria-hidden="true"></i>
            </button>

            <button type="button"
                    class="batch-action-btn edit"
                    data-batch-action="edit"
                    data-id="${batch.id}"
                    title="Edit metadata"
                    aria-label="Edit ${batch.id} metadata">
                <i class="bi bi-pencil-square" aria-hidden="true"></i>
            </button>
        </div>
    `;
    },


    /* ==================================================
       BIND EVENTS
    ================================================== */

    bindEvents() {
        ['input', 'search'].forEach(event => {
            this.el('batchSearch').addEventListener(event, () => this.applySearch());
        });
        this.el('batchPageLength').addEventListener('change', event => {
            const length = Number(event.target.value);
            if ([5, 10, 25, 50].includes(length)) this.table.page.len(length).draw();
        });
        this.table.on('length', (_event, _settings, length) => {
            this.el('batchPageLength').value = String(length);
        });
        this.el('batchTableBody').addEventListener('click', event => {
            const button = event.target.closest('[data-batch-action]');
            if (!button || button.disabled || !this.ready || this.saving) return;
            const batch = this.model.find(button.dataset.id);
            if (!batch) return;
            if (button.dataset.batchAction === 'view') this.view(batch);
            if (button.dataset.batchAction === 'edit') this.edit(batch);
        });
        this.el('batchEditForm').addEventListener('submit', event => {
            event.preventDefault();
            this.save();
        });
        this.el('batchEditModal').addEventListener('hide.bs.modal', event => {
            if (this.saving && !this.committed) event.preventDefault();
        });
        this.el('batchEditModal').addEventListener('hidden.bs.modal', () => {
            this.editingId = null;
            this.editingVersion = null;
            this.requestKey = null;
        });
        window.addEventListener('focus', () => {
            const modalOpen = this.el('batchEditModal').classList.contains('show')
                || this.el('batchViewModal').classList.contains('show');
            if (this.ready && !this.saving && !this.loading && !modalOpen) this.reload();
        });
    },

    /* ==================================================
       NOTICE
    ================================================== */

    notice(message) {
        this.el('batchNotice').textContent = message;
        this.el('batchNotice').hidden = false;
    },

    /* ==================================================
       APPLY SEARCH
    ================================================== */

    applySearch() {
        this.table.search(
            this.el('batchSearch').value.trim(),
            {
                regex: false,
                smart: true,
                caseInsensitive: true
            }
        ).draw();
    },

    /* ==================================================
       REFRESH
    ================================================== */

    refresh() {
        this.table.clear();
        this.table.rows.add(this.model.batches);
        this.applySearch();

        const statuses = {
            Available: 'availableBatchCount',
            'Partially Used': 'partialBatchCount',
            Depleted: 'depletedBatchCount',
            Spoiled: 'spoiledBatchCount'
        };

        for (const [status, id] of Object.entries(statuses)) {
            this.el(id).textContent = this.model.batches.filter(
                (batch) => this.model.status(batch) === status
            ).length;
        }

        const warnings = this.model.batches.filter(
            (batch) => this.model.freshness(batch).warning
        ).length;

        this.el('batchFreshnessWarning').hidden = warnings === 0;

        this.el('batchFreshnessWarning').textContent =
            `${warnings} usable batch${warnings === 1 ? '' : 'es'} ` +
            'reached or are approaching the expected freshness date. ' +
            'Review them; dates do not automatically change stock or batch status.';
    },

    /* ==================================================
       VIEW
    ================================================== */

    view(batch) {
        const flower = this.model.flower(batch.flower);

        const fields = [
            ['Batch Number', batch.id],
            ['Item', `${flower.name} (${flower.code})`],
            ['Item Type / Base Unit', flower.baseUnit],
            ['Date Received', batch.received],
            ['Harvest Date', batch.harvest],
            ['Freshness / Expiry', batch.expiry || 'Not set'],
            ['Initial Quantity', `${number(batch.initial)} ${this.model.unitLabel(batch)}`],
            ['Remaining Quantity', `${number(batch.remaining)} ${this.model.unitLabel(batch)}`]
        ];

        this.el('batchViewTitle').textContent =
            `${batch.id} — Batch Details`;

        this.el('batchViewContent').innerHTML = `
            <div class="batch-detail-grid">
                ${fields.map(([label, value]) => `
                    <div class="batch-detail-item">
                        <label>${label}</label>
                        <p>${escapeHTML(value)}</p>
                    </div>
                `).join('')}

                <div class="batch-detail-item">
                    <label>Status</label>
                    ${this.badge(batch)}
                </div>

                <div class="batch-detail-item batch-detail-full">
                    <label>Remarks</label>
                    <p>${escapeHTML(batch.remarks || '—')}</p>
                </div>

                ${batch.spoiled ? `
                    <div class="batch-detail-item batch-detail-full">
                        <label>Spoilage Reason</label>
                        <p>${escapeHTML(batch.spoilageReason)}</p>
                    </div>
                ` : ''}
            </div>
        `;

        this.el('batchMovementBody').innerHTML = batch.history
            .slice()
            .sort((a, b) => b.id - a.id)
            .map((movement) => `
                <tr>
                    <td>${escapeHTML(movement.date)}</td>
                    <td>${escapeHTML(movement.type)}</td>
                    <td>
                        ${movement.quantity > 0 ? '+' : ''}${number(movement.quantity)}
                        ${this.model.unitLabel(batch)}
                    </td>
                    <td>${escapeHTML(movement.remarks || '—')}${this.changeDetails(movement)}</td>
                    <td>${escapeHTML(movement.user)}</td>
                </tr>
            `).join('') ||
            '<tr><td colspan="5">No movement history.</td></tr>';

        this.viewModal.show();
    },

    /* ==================================================
       EDIT
    ================================================== */

    edit(batch) {
        if (!this.ready || this.saving) return;
        this.editingId = batch.dbId;
        this.editingVersion = batch.version;
        this.requestKey = this.newRequestKey();
        this.el('batchEditForm').reset();
        this.el('batchEditError').hidden = true;

        const values = {
            batchEditingId: batch.id,
            batchEditNumber: batch.id,
            batchEditFlower: `${this.model.flower(batch.flower).name} (${this.model.flower(batch.flower).baseUnit})`,
            batchEditReceived: batch.received,
            batchEditHarvest: batch.harvest,
            batchEditInitial: batch.initial,
            batchEditRemaining: batch.remaining,
            batchEditExpiry: batch.expiry,
            batchEditRemarks: batch.remarks
        };

        for (const [id, value] of Object.entries(values)) {
            this.el(id).value = value;
        }

        this.el('batchEditHarvest').max = batch.received;
        this.editModal.show();
    },

    /* ==================================================
       SHOW BEFORE / AFTER VALUES IN MOVEMENT HISTORY
    ================================================== */

    

    changeDetails(movement) {
        const before = movement.metadata?.before;
        const after = movement.metadata?.after;
        if (!before || !after) return '';
        const labels = {
            received: 'Received', harvest: 'Harvest', expiry: 'Freshness / expiry',
            quantity: 'Receiving quantity', unit: 'Receiving unit', factor: 'Conversion factor',
            initial: 'Initial', remaining: 'Remaining', remarks: 'Remarks',
        };
        const changes = Object.entries(labels).filter(([key]) => before[key] !== after[key]);
        if (!changes.length) return '';
        return `<details class="mt-1"><summary>View changes</summary><ul class="mb-0">${changes.map(([key, label]) =>
            `<li>${label}: ${escapeHTML(before[key] ?? '—')} → ${escapeHTML(after[key] ?? '—')}</li>`
        ).join('')}</ul></details>`;
    },

    /* ==================================================
       GENERATE UUID AND RETAIN IT FOR SAFE SAVE RETRIES
    ================================================== */

    

    newRequestKey() {
        if (globalThis.crypto.randomUUID) return globalThis.crypto.randomUUID();
        const bytes = globalThis.crypto.getRandomValues(new Uint8Array(16));
        bytes[6] = (bytes[6] & 15) | 64;
        bytes[8] = (bytes[8] & 63) | 128;
        const hex = [...bytes].map(value => value.toString(16).padStart(2, '0')).join('');
        return `${hex.slice(0,8)}-${hex.slice(8,12)}-${hex.slice(12,16)}-${hex.slice(16,20)}-${hex.slice(20)}`;
    },

    /* ==================================================
       READ ENDPOINTS FROM LARAVEL NAMED ROUTES
    ================================================== */

    

    endpoint(update = false) {
        const url = this.el('batchPage').dataset[update ? 'updateUrl' : 'url'];
        if (!url) throw new Error('Missing batch endpoint. Replace the Blade file too.');
        if (!update) return url;
        if (!url.includes('__BATCH__') || !this.editingId) throw new Error('Invalid batch update endpoint.');
        return url.replace('__BATCH__', encodeURIComponent(String(this.editingId)));
    },

    /* ==================================================
       FETCH JSON WITH SESSION AND CSRF HANDLING
    ================================================== */

    

    async request(url, payload = null) {
        const headers = { Accept: 'application/json' };
        if (payload !== null) {
            const token = this.el('batchEditForm').querySelector('[name="_token"]')?.value;
            if (!token) throw new Error('Missing CSRF token. Add @csrf inside the batch form.');
            headers['X-CSRF-TOKEN'] = token;
            headers['Content-Type'] = 'application/json';
        }
        const response = await fetch(url, {
            method: payload === null ? 'GET' : 'POST', headers,
            credentials: 'same-origin', redirect: 'error',
            ...(payload === null ? {} : { body: JSON.stringify(payload) }),
        });
        const isJson = (response.headers.get('content-type') || '').includes('application/json');
        const result = isJson ? await response.json() : {};
        if (!response.ok) {
            const messages = {
                401: 'Your session has expired. Log in again.',
                403: 'Your account cannot perform this action.',
                404: 'The batch or endpoint was not found. Refresh the page.',
                419: 'Your session token has expired. Refresh the page.',
            };
            const error = new Error(messages[response.status]
                || (response.status >= 500 ? 'The server could not complete the request.' : result.message)
                || 'Unable to complete the request.');
            error.errors = result.errors || {};
            error.status = response.status;
            throw error;
        }
        if (!isJson) throw new Error('Unexpected response. Refresh the page and check your login.');
        return result;
    },

    /* ==================================================
       LOAD SHARED INVENTORY BATCHES AND REAL MOVEMENTS
    ================================================== */

    

    async reload(successMessage = '') {
        if (this.loading) return false;
        this.loading = true;
        this.ready = false;
        this.updateAvailability();
        this.notice('Loading batch records...');
        try {
            const result = await this.request(this.endpoint());
            this.model.hydrate(result);
            this.refresh();
            this.ready = true;
            this.notice(successMessage || (this.model.batches.length
                ? '' : 'No batches yet. Receive stock in Inventory Management first.'));
            this.el('batchNotice').hidden = !this.el('batchNotice').textContent;
            return true;
        } catch (error) {
            console.error('Batch loading failed:', error);
            this.notice(successMessage
                ? `${successMessage} The list could not refresh. Reload this page before making another change.`
                : error.message);
            return false;
        } finally {
            this.loading = false;
            this.updateAvailability();
        }
    },

    /* ==================================================
       DISABLE ACTIONS DURING LOADING OR SAVING
    ================================================== */

    

    updateAvailability() {
        const disabled = !this.ready || this.loading || this.saving;
        this.el('batchTableBody').querySelectorAll('[data-batch-action]').forEach(button => {
            button.disabled = disabled;
        });
        ['batchEditHarvest', 'batchEditExpiry', 'batchEditRemarks'].forEach(id => {
            this.el(id).disabled = this.saving;
        });
        this.el('batchEditForm').querySelector('[type="submit"]').disabled = disabled;
        this.el('batchEditForm').setAttribute('aria-busy', String(this.saving));
    },

    /* ==================================================
       SAVE METADATA ONLY — NEVER CHANGE STOCK IN THIS PAGE
    ================================================== */

    

    async save() {
        if (!this.ready || this.saving || !this.el('batchEditForm').reportValidity()) return;
        this.el('batchEditError').hidden = true;
        this.saving = true;
        this.committed = false;
        this.updateAvailability();
        try {
            const result = await this.request(this.endpoint(true), {
                request_key: this.requestKey,
                version: this.editingVersion,
                harvest: this.el('batchEditHarvest').value,
                expiry: this.el('batchEditExpiry').value || null,
                remarks: this.el('batchEditRemarks').value.trim(),
            });
            this.committed = true;
            this.editModal.hide();
            await this.reload(result.message || 'Batch information updated. Stock quantities are unchanged.');
        } catch (error) {
            console.error('Batch update failed:', error);
            const validation = Object.values(error.errors || {}).flat();
            let message = validation.length ? validation.join('\n') : error.message;
            if (error.errors?.version) message += ' Close this form, refresh the page, and reopen Edit.';
            if (!validation.length && ![401, 403, 404, 419, 422].includes(error.status)) {
                message += ' Retry this same form to reuse the request key, or refresh to check history.';
            }
            this.el('batchEditError').textContent = message;
            this.el('batchEditError').hidden = false;
        } finally {
            this.saving = false;
            this.committed = false;
            this.updateAvailability();
        }
    }

};

if (document.readyState === 'loading') {
    document.addEventListener(
        'DOMContentLoaded',
        () => BatchUI.init(),
        { once: true }
    );
} else {
    BatchUI.init();
}

