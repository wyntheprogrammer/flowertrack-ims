import { Modal } from 'bootstrap';
import DataTable from 'datatables.net-bs5';
import 'datatables.net-bs5/css/dataTables.bootstrap5.css';

/* ==================================================
   SHARED TEXT, NUMBER AND LOCAL-DATE HELPERS
================================================== */

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

const today = () => {
    const parts = Object.fromEntries(
        new Intl.DateTimeFormat('en-US', {
            timeZone: 'Asia/Manila', year: 'numeric', month: '2-digit', day: '2-digit',
        }).formatToParts(new Date()).map(({ type, value }) => [type, value])
    );
    return `${parts.year}-${parts.month}-${parts.day}`;
};

/* ==================================================
   READ-ONLY DATABASE SNAPSHOT AND ALLOCATION PREVIEW
   All mutations are performed by the Laravel backend.
================================================== */
class InventoryModel {
    /* ==================================================
       CONSTRUCTOR
    ================================================== */

    constructor() {
        this.flowers = [];
        this.batches = [];
        this.movements = [];
    }

    /* ==================================================
       HYDRATE
    ================================================== */

    hydrate(data) {
        if (!data || !Array.isArray(data.flowers) || !Array.isArray(data.batches)
            || !Array.isArray(data.movements)) throw new Error('Invalid inventory response.');
        for (const flower of data.flowers) {
            if (!Number.isSafeInteger(Number(flower.id)) || Number(flower.id) < 1
                || !['Stem', 'Bouquet'].includes(flower.baseUnit)
                || !Number.isSafeInteger(Number(flower.reserved)) || Number(flower.reserved) < 0) {
                throw new Error('Invalid flower balance received.');
            }
        }
        for (const batch of data.batches) {
            if (!Number.isSafeInteger(Number(batch.dbId)) || Number(batch.dbId) < 1
                || !Number.isSafeInteger(Number(batch.remaining)) || Number(batch.remaining) < 0
                || Number(batch.remaining) > Number(batch.initial)) {
                throw new Error('Invalid batch balance received.');
            }
        }
        this.flowers = data.flowers;
        this.batches = data.batches;
        this.movements = data.movements;
    }

    /* ==================================================
       FLOWER
    ================================================== */

    flower(code) { return this.flowers.find(flower => flower.code === code); }
    /* ==================================================
       BATCHES FOR
    ================================================== */

    batchesFor(code) { return this.batches.filter(batch => batch.flower === code); }
    /* ==================================================
       ON HAND
    ================================================== */

    onHand(code) {
        return this.batchesFor(code).filter(batch => !batch.spoiled)
            .reduce((sum, batch) => sum + Number(batch.remaining), 0);
    }
    /* ==================================================
       RESERVED
    ================================================== */

    reserved(code) { return Number(this.flower(code)?.reserved || 0); }
    /* ==================================================
       AVAILABLE
    ================================================== */

    available(code) { return this.onHand(code) - this.reserved(code); }
    /* ==================================================
       STATUS
    ================================================== */

    status(code) {
        const available = this.available(code);
        return available <= 0 ? 'Out of Stock'
            : available < this.flower(code).minimum ? 'Low Stock' : 'Normal';
    }
    /* ==================================================
       UNIT LABEL
    ================================================== */

    unitLabel(code) { return this.flower(code)?.baseUnit === 'Bouquet' ? 'bouquets' : 'stems'; }

    /* ==================================================
       ALLOCATION
    ================================================== */

    allocation(code, quantity, selectedBatch = '') {
        if (!Number.isSafeInteger(quantity) || quantity < 1) {
            throw new Error('Enter a positive whole-number quantity in the item base unit.');
        }
        const batches = this.batchesFor(code)
            .filter(batch => !batch.spoiled && batch.remaining > 0 && (!selectedBatch || batch.id === selectedBatch))
            .sort((a, b) => a.received.localeCompare(b.received) || a.dbId - b.dbId);
        let pending = quantity;
        const result = [];
        for (const batch of batches) {
            const amount = Math.min(pending, batch.remaining);
            if (amount) result.push({ batch, amount });
            pending -= amount;
            if (!pending) break;
        }
        if (pending) throw new Error(selectedBatch
            ? 'Selected batch has insufficient stock. Use FIFO to split across batches.'
            : 'Insufficient physical stock.');
        return result;
    }
}

const InventoryUI = {
    model: new InventoryModel(),
    tables: {},
    active: 'inventory',
    editing: '',
    ready: false,
    busy: false,
    loading: false,
    committed: false,
    requestKeys: {},

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
        if (!this.el('inventoryPage')) return;

        this.formModal = Modal.getOrCreateInstance(this.el('stockInModal'));
        this.outModal = Modal.getOrCreateInstance(this.el('stockOutModal'));
        this.detailModal = Modal.getOrCreateInstance(
            this.el('inventoryDetailModal')
        );
        this.unitModal = Modal.getOrCreateInstance(
            this.el('inventoryUnitModal')
        );

        const options = {
            pageLength: 5,
            lengthChange: false,
            searching: true,
            ordering: true,
            autoWidth: false,
            layout: {
                topStart: null,
                topEnd: null,
                bottomStart: 'info',
                bottomEnd: 'paging'
            },
            language: {
                emptyTable: 'No inventory records.',
                zeroRecords: 'No matching records found.',
                info: 'Showing _START_ to _END_ of _TOTAL_ entries',
                infoEmpty: 'Showing 0 entries'
            }
        };

        this.tables.inventory = new DataTable('#inventoryTable', {
            ...options,
            order: [[0, 'asc']],
            columnDefs: [
                {
                    targets: [1, 2, 3, 4, 5, 6],
                    className: 'dt-head-center dt-body-center'
                },
                {
                    targets: 7,
                    orderable: false,
                    searchable: false,
                    className: 'dt-head-center dt-body-center'
                }
            ]
        });

        this.tables.history = new DataTable('#inventoryHistoryTable', {
            ...options,
            order: [[0, 'desc']],
            columnDefs: [
                {
                    targets: 3,
                    className: 'dt-head-right dt-body-right',
                    type: 'num'
                }
            ]
        });

        this.bindEvents();
        this.reload();
    },

    /* ==================================================
       BIND EVENTS
    ================================================== */

    bindEvents() {
        this.el('openStockIn').addEventListener('click', () => this.openIn());
        this.el('openStockOut').addEventListener('click', () => this.openOut());
        ['input', 'search'].forEach(event => {
            this.el('inventorySearch').addEventListener(event, () => this.search());
        });
        this.el('inventoryPageLength').addEventListener('change', event => {
            const length = Number(event.target.value);
            if ([5, 10, 25, 50].includes(length)) this.tables[this.active].page.len(length).draw();
        });
        document.querySelectorAll('[data-inventory-tab]').forEach(button => {
            button.addEventListener('click', () => {
                this.active = button.dataset.inventoryTab;
                document.querySelectorAll('[data-inventory-tab]').forEach(tab => {
                    const selected = tab === button;
                    tab.classList.toggle('active', selected);
                    tab.setAttribute('aria-selected', String(selected));
                });
                this.el('inventoryPanel').hidden = this.active !== 'inventory';
                this.el('inventoryHistoryPanel').hidden = this.active !== 'history';
                this.el('inventoryPageLength').value = String(this.tables[this.active].page.len());
                this.search();
                this.tables[this.active].columns.adjust();
            });
        });
        this.el('inventoryPage').addEventListener('click', event => {
            const button = event.target.closest('[data-inventory-action]');
            if (!button || button.disabled || !this.ready || this.busy) return;
            if (button.dataset.inventoryAction === 'details') this.details(button.dataset.code);
            if (button.dataset.inventoryAction === 'units') this.openUnits(button.dataset.code);
        });
        this.el('inventoryDetailContent').addEventListener('click', event => {
            const button = event.target.closest('[data-edit-receipt]');
            if (!button || button.disabled || this.busy) return;
            this.el('inventoryDetailModal').addEventListener('hidden.bs.modal', () => {
                this.openIn(button.dataset.editReceipt);
            }, { once: true });
            this.detailModal.hide();
        });
        this.el('stockInForm').addEventListener('submit', event => {
            event.preventDefault();
            this.saveIn();
        });
        this.el('stockOutForm').addEventListener('submit', event => {
            event.preventDefault();
            this.saveOut();
        });
        this.el('inventoryUnitForm').addEventListener('submit', event => {
            event.preventDefault();
            this.saveUnits();
        });
        this.el('inFlower').addEventListener('change', () => this.fillUnits());
        ['inQuantity', 'inUnit'].forEach(id => {
            this.el(id).addEventListener('input', () => this.inPreview());
        });
        ['outFlower', 'outReason', 'outOverride'].forEach(id => {
            this.el(id).addEventListener('change', () => this.syncOut());
        });
        ['outQuantity', 'outBatch'].forEach(id => {
            this.el(id).addEventListener('input', () => this.outPreview());
        });
        ['stockInModal', 'stockOutModal', 'inventoryUnitModal'].forEach(id => {
            this.el(id).addEventListener('hide.bs.modal', event => {
                if (this.busy && !this.committed) event.preventDefault();
            });
        });
    },

    /* ==================================================
       NOTICE
    ================================================== */

    notice(message) {
        this.el('inventoryNotice').textContent = message;
        this.el('inventoryNotice').hidden = false;
    },

    /* ==================================================
       BADGE
    ================================================== */

    badge(status) {
        const style = {
            Normal: 'normal',
            'Low Stock': 'low',
            'Out of Stock': 'empty',
            Available: 'normal',
            'Partially Used': 'low',
            Depleted: 'empty',
            Spoiled: 'empty'
        }[status];

        return `
            <span class="inv-badge ${style}">
                ${escapeHTML(status)}
            </span>
        `;
    },

    /* ==================================================
       REFRESH
    ================================================== */

    refresh() {
        const model = this.model;

        const inventoryRows = model.flowers.map((flower) => [
            `
                <div class="inv-flower">
                    <span class="inv-flower-icon">
                        <i class="bi bi-flower1" aria-hidden="true"></i>
                    </span>
                    <div>
                        <strong>${escapeHTML(flower.name)}</strong>
                        <small>${escapeHTML(flower.code)} · ${escapeHTML(flower.category)} · ${escapeHTML(flower.baseUnit)}${flower.status === 'Inactive' ? ' · Inactive' : ''}</small>
                    </div>
                </div>
            `,
            model.onHand(flower.code),
            model.reserved(flower.code),
            model.available(flower.code),
            flower.minimum,
            model.batchesFor(flower.code).filter(
                (batch) => !batch.spoiled && batch.remaining > 0
            ).length,
            this.badge(model.status(flower.code)),
            `
                <div class="inv-actions">
                    <button type="button"
                            class="inv-icon-btn"
                            data-inventory-action="details"
                            data-code="${flower.code}"
                            title="View batches"
                            aria-label="View ${escapeHTML(flower.name)} batches">
                        <i class="bi bi-eye"></i>
                    </button>

                    <button type="button"
                            class="inv-icon-btn edit"
                            ${flower.baseUnit === 'Bouquet' ? 'hidden' : ''}
                            data-inventory-action="units"
                            data-code="${flower.code}"
                            title="Configure units"
                            aria-label="Configure ${escapeHTML(flower.name)} units">
                        <i class="bi bi-sliders"></i>
                    </button>
                </div>
            `
        ]);

        this.tables.inventory.clear().rows.add(inventoryRows).draw(false);

        const historyRows = model.movements.map((movement) => [
            escapeHTML(movement.date),
            `
                <strong>${escapeHTML(model.flower(movement.flower).name)}</strong>
                <small class="inv-cell-note">${movement.batch} · ${model.flower(movement.flower).baseUnit}</small>
            `,
            `
                <span class="inv-badge ${
                    movement.quantity >= 0 ? 'normal' : 'empty'
                }">
                    ${movement.type}
                </span>
            `,
            movement.quantity,
            escapeHTML(movement.remarks || '—'),
            escapeHTML(movement.user)
        ]);

        this.tables.history.clear().rows.add(historyRows).draw(false);

        const total = (method) => ['Stem', 'Bouquet'].map((unit) => {
            const quantity = model.flowers.filter((flower) => flower.baseUnit === unit)
                .reduce((sum, flower) => sum + model[method](flower.code), 0);
            return `${number(quantity)} ${unit === 'Bouquet' ? 'bouquets' : 'stems'}`;
        }).join(' / ');
        this.el('totalOnHand').textContent = total('onHand');
        this.el('totalReserved').textContent = total('reserved');
        this.el('totalAvailable').textContent = total('available');

        this.el('totalStockAlerts').textContent = model.flowers.filter(
            (flower) => model.status(flower.code) !== 'Normal'
        ).length;

        this.search();
    },

    /* ==================================================
       SEARCH
    ================================================== */

    search() {
        this.tables[this.active].search(
            this.el('inventorySearch').value.trim(),
            {
                regex: false,
                smart: true,
                caseInsensitive: true
            }
        ).draw();
    },

    /* ==================================================
       FLOWER OPTIONS
    ================================================== */

    flowerOptions(activeOnly = false) {
        return this.model.flowers.filter(flower => !activeOnly || flower.status === 'Active')
            .map(flower => `<option value="${escapeHTML(flower.code)}">${escapeHTML(flower.name)} — ${escapeHTML(flower.code)} (${flower.baseUnit})${flower.status === 'Inactive' ? ' · Inactive' : ''}</option>`)
            .join('');
    },

    /* ==================================================
       OPEN IN
    ================================================== */

    openIn(id = '') {
        if (!this.ready || this.busy) return;
        const batch = id ? this.model.batches.find(item => item.id === id) : null;
        if (id && !batch) return;
        if (batch?.spoiled) {
            this.notice('A spoiled batch cannot be corrected from this screen.');
            return;
        }
        this.el('stockInForm').reset();
        this.editing = id;
        this.el('stockInError').hidden = true;
        this.el('inFlower').innerHTML = this.flowerOptions(!batch);
        if (!this.el('inFlower').options.length) {
            this.notice('Add or activate a flower in Flower Management before receiving stock.');
            return;
        }
        this.requestKeys.stockIn = this.newRequestKey();
        this.el('stockInTitle').textContent = batch ? 'Edit Stock-In' : 'Stock-In';
        this.el('inBatchCode').value = batch?.id || 'Generated after saving';
        if (batch) this.el('inFlower').value = batch.flower;
        this.el('inFlower').disabled = Boolean(batch);
        this.el('inQuantity').value = batch?.quantity ?? 1;
        this.el('inReceived').value = batch?.received || today();
        this.el('inReceived').max = today();
        this.el('inHarvest').value = batch?.harvest || today();
        this.el('inExpiry').value = batch?.expiry || '';
        this.el('inRemarks').value = batch?.remarks || '';
        this.el('inRemarks').required = Boolean(batch);
        this.fillUnits(batch);
        this.formModal.show();
    },

    /* ==================================================
       FILL UNITS
    ================================================== */

    fillUnits(batch = null) {
        const flower = this.model.flower(this.el('inFlower').value);
        const units = { ...flower.units };

        if (batch) {
            units[batch.unit] = batch.factor;
        }

        this.el('inUnit').innerHTML = Object.entries(units).map(
            ([unit, factor]) => `
                <option value="${unit}" data-factor="${factor}">
                    ${unit} (${factor} ${this.model.unitLabel(flower.code)})
                </option>
            `
        ).join('');

        this.el('inUnit').value = batch?.unit ?? flower.baseUnit;
        this.inPreview();
    },

    /* ==================================================
       IN PREVIEW
    ================================================== */

    inPreview() {
        const total = Number(this.el('inQuantity').value) *
            Number(this.el('inUnit').selectedOptions[0]?.dataset.factor ?? 1);

        this.el('inConversion').textContent =
            `${number(total || 0)} ${this.model.unitLabel(this.el('inFlower').value)} will be recorded.`;
    },

    /* ==================================================
       OPEN OUT
    ================================================== */

    openOut() {
        if (!this.ready || this.busy) return;
        this.el('stockOutForm').reset();
        this.el('stockOutError').hidden = true;
        this.el('outFlower').innerHTML = this.flowerOptions();
        if (!this.el('outFlower').options.length) {
            this.notice('No catalog records are available. Add a flower first.');
            return;
        }
        this.requestKeys.stockOut = this.newRequestKey();
        this.syncOut();
        this.outModal.show();
    },

    /* ==================================================
       SYNC OUT
    ================================================== */

    syncOut() {
        this.el('outOrderGroup').classList.add('d-none');
        this.el('outOrder').required = false;
        this.el('outFlower').disabled = false;
        this.el('outQuantity').readOnly = false;
        const code = this.el('outFlower').value;
        const previous = this.el('outBatch').value;
        this.el('outBatch').innerHTML = this.model.batchesFor(code)
            .filter(batch => !batch.spoiled && batch.remaining > 0)
            .map(batch => `<option value="${escapeHTML(batch.id)}">${escapeHTML(batch.id)} · ${batch.received} · ${number(batch.remaining)} ${this.model.unitLabel(code)}</option>`)
            .join('');
        if ([...this.el('outBatch').options].some(option => option.value === previous)) {
            this.el('outBatch').value = previous;
        }
        const override = this.el('outOverride').checked;
        this.el('outBatchGroup').classList.toggle('d-none', !override);
        this.el('outBatch').required = override;
        this.el('outRemarks').required = override || this.el('outReason').value === 'Spoilage';
        this.el('outBalance').textContent = `${number(this.model.onHand(code))} on hand · `
            + `${number(this.model.reserved(code))} reserved · ${number(this.model.available(code))} available.`;
        this.outPreview();
    },

    /* ==================================================
       OUT DATA
    ================================================== */

    outData() {
        return {
            flower: this.el('outFlower').value,
            quantity: Number(this.el('outQuantity').value),
            reason: this.el('outReason').value,
            batch: this.el('outOverride').checked ? this.el('outBatch').value : '',
            remarks: this.el('outRemarks').value.trim(),
        };
    },

    /* ==================================================
       OUT PREVIEW
    ================================================== */

    outPreview() {
        const data = this.outData();

        try {
            const plan = this.model.allocation(
                data.flower,
                data.quantity,
                data.batch
            );

            this.el('outAllocation').textContent = plan.map(
                ({ batch, amount }) =>
                    `${batch.id}: ${number(amount)} ${this.model.unitLabel(batch.flower)}`
            ).join(' · ');
        } catch (error) {
            this.el('outAllocation').textContent = error.message;
        }
    },

    /* ==================================================
       DETAILS
    ================================================== */

    details(code) {
        const flower = this.model.flower(code);

        this.el('inventoryDetailTitle').textContent =
            `${flower.name} — Batch Details`;

        const batchRows = this.model.batchesFor(code).map((batch) => {
            const status = batch.spoiled
                ? 'Spoiled'
                : batch.remaining === 0
                    ? 'Depleted'
                    : batch.remaining === batch.initial
                        ? 'Available'
                        : 'Partially Used';

            return `
                <tr>
                    <td>${batch.id}</td>
                    <td>${batch.received}</td>
                    <td>${batch.harvest}</td>
                    <td>${batch.expiry || '—'}</td>
                    <td>${number(batch.initial)}</td>
                    <td>${number(batch.remaining)}</td>
                    <td>${this.badge(status)}</td>
                    <td>
                        <button type="button"
                                class="inv-icon-btn edit"
                                ${batch.spoiled ? 'disabled title="Spoiled batch cannot be corrected"' : ''}
                                data-edit-receipt="${batch.id}"
                                aria-label="Edit ${batch.id} receipt">
                            <i class="bi bi-pencil-square"></i>
                        </button>
                    </td>
                </tr>
            `;
        }).join('');

        const historyRows = this.model.movements
            .filter((movement) => movement.flower === code)
            .slice()
            .sort((a, b) => b.id - a.id)
            .map((movement) => `
                <tr>
                    <td>${escapeHTML(movement.date)}</td>
                    <td>${movement.batch}</td>
                    <td>${movement.type}</td>
                    <td>${movement.quantity}</td>
                    <td>${escapeHTML(movement.remarks || '—')}</td>
                </tr>
            `).join('');

        this.el('inventoryDetailContent').innerHTML = `
            <p class="inv-muted">
                ${flower.code} ·
                Minimum stock: ${flower.minimum} ${this.model.unitLabel(code)} ·
                On hand: ${this.model.onHand(code)} ·
                Reserved: ${this.model.reserved(code)} ·
                Available: ${this.model.available(code)}
            </p>

            <div class="inv-detail-scroll">
                <table class="inv-detail-table">
                    <thead>
                        <tr>
                            <th>Batch</th>
                            <th>Received</th>
                            <th>Harvest</th>
                            <th>Freshness / expiry</th>
                            <th>Initial</th>
                            <th>Remaining</th>
                            <th>Status</th>
                            <th>Receipt</th>
                        </tr>
                    </thead>
                    <tbody>
                        ${batchRows ||
                            '<tr><td colspan="8">No batches received yet.</td></tr>'}
                    </tbody>
                </table>
            </div>

            <h6 class="mt-4">Movement history</h6>

            <div class="inv-detail-scroll">
                <table class="inv-detail-table">
                    <thead>
                        <tr>
                            <th>Date / Time</th>
                            <th>Batch</th>
                            <th>Movement</th>
                            <th>Quantity (${flower.baseUnit})</th>
                            <th>Remarks</th>
                        </tr>
                    </thead>
                    <tbody>
                        ${historyRows ||
                            '<tr><td colspan="5">No movements yet.</td></tr>'}
                    </tbody>
                </table>
            </div>
        `;

        this.detailModal.show();
    },

    /* ==================================================
       OPEN UNITS
    ================================================== */

    openUnits(code) {
        if (!this.ready || this.busy) return;
        const flower = this.model.flower(code);
        if (flower.baseUnit === 'Bouquet') {
            this.notice('Bouquets are received as finished Bouquet units; no stem conversion.');
            return;
        }

        this.el('unitFlowerCode').value = code;
        this.el('unitFlowerName').textContent = flower.name;
        this.el('inventoryUnitError').hidden = true;

        for (const unit of ['Bunch', 'Bundle', 'Box']) {
            this.el(`unit${unit}`).value = flower.units[unit];
        }

        this.unitModal.show();
    },

    /* ==================================================
       GENERATE IDEMPOTENCY KEY FOR EACH OPEN RECEIPT FORM
       Retries keep the same key to prevent duplicate stock.
    ================================================== */

    

    newRequestKey() {
        if (globalThis.crypto.randomUUID) return globalThis.crypto.randomUUID();
        const bytes = globalThis.crypto.getRandomValues(new Uint8Array(16));
        bytes[6] = (bytes[6] & 15) | 64;
        bytes[8] = (bytes[8] & 63) | 128;
        const hex = [...bytes].map(value => value.toString(16).padStart(2, '0')).join('');
        return `${hex.slice(0, 8)}-${hex.slice(8, 12)}-${hex.slice(12, 16)}-${hex.slice(16, 20)}-${hex.slice(20)}`;
    },

    /* ==================================================
       NAMED ROUTE URLS COME FROM THE BLADE PAGE
    ================================================== */

    

    endpoint(name, id = null) {
        let url = this.el('inventoryPage').dataset[name];
        if (!url) throw new Error(`Missing inventory endpoint: ${name}. Replace the Blade file too.`);
        if (id !== null) {
            const token = name === 'correctionUrl' ? '__BATCH__' : '__FLOWER__';
            if (!url.includes(token)) throw new Error('Invalid inventory route template.');
            url = url.replace(token, encodeURIComponent(String(id)));
        }
        return url;
    },

    /* ==================================================
       FETCH JSON WITH CSRF AND SESSION VALIDATION
    ================================================== */

    

    async request(url, payload = null) {
        const headers = { Accept: 'application/json' };
        if (payload !== null) {
            const token = this.el('stockInForm').querySelector('[name="_token"]')?.value;
            if (!token) throw new Error('Missing CSRF token. Replace the Blade file too.');
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
                403: 'Your account is not allowed to perform this action.',
                404: 'The record or endpoint was not found. Refresh the page.',
                419: 'Your session token has expired. Refresh the page.',
            };
            const error = new Error(messages[response.status]
                || (response.status >= 500 ? 'The server could not complete the request.' : result.message)
                || 'Unable to complete the request.');
            error.errors = result.errors || {};
            error.status = response.status;
            throw error;
        }
        if (!isJson) throw new Error('Unexpected server response. Refresh and check your login.');
        return result;
    },

    /* ==================================================
       RELOAD CANONICAL DATABASE BALANCES AFTER EACH WRITE
    ================================================== */

    

    async reload(successMessage = '') {
        if (this.loading) return false;
        this.loading = true;
        this.ready = false;
        this.updateAvailability();
        this.notice('Loading inventory records...');
        try {
            const result = await this.request(this.endpoint('url'));
            this.model.hydrate(result.data);
            this.refresh();
            this.ready = true;
            this.notice(successMessage || 'Inventory records loaded.');
            return true;
        } catch (error) {
            console.error('Inventory loading failed:', error);
            this.notice(successMessage
                ? `${successMessage} Balances could not reload. Refresh this page before another transaction.`
                : error.message);
            return false;
        } finally {
            this.loading = false;
            this.updateAvailability();
        }
    },

    /* ==================================================
       ENABLE WRITES ONLY AFTER LOADING AND OUTSIDE A SAVE
    ================================================== */

    

    updateAvailability() {
        const disabled = !this.ready || this.busy || this.loading;
        ['openStockIn', 'openStockOut'].forEach(id => { this.el(id).disabled = disabled; });
        document.querySelectorAll('[data-inventory-action], [data-edit-receipt]').forEach(button => {
            const batch = button.dataset.editReceipt
                ? this.model.batches.find(item => item.id === button.dataset.editReceipt) : null;
            button.disabled = disabled || Boolean(batch?.spoiled);
        });
        ['stockInForm', 'stockOutForm', 'inventoryUnitForm'].forEach(id => {
            this.el(id).querySelectorAll('input, select, textarea, button').forEach(control => {
                if (control.type !== 'hidden') control.disabled = this.busy;
            });
        });
        this.el('inFlower').disabled = this.busy || Boolean(this.editing);
    },

    /* ==================================================
       POST A TRANSACTION THEN REFRESH THE DATABASE SNAPSHOT
    ================================================== */

    

    async submit(url, payload, errorId, modal) {
        if (this.busy || !this.ready) return;
        this.el(errorId).hidden = true;
        this.busy = true;
        this.committed = false;
        this.updateAvailability();
        try {
            const result = await this.request(url, payload);
            this.committed = true;
            modal.hide();
            await this.reload(result.message || 'Inventory transaction saved.');
        } catch (error) {
            console.error('Inventory saving failed:', error);
            const messages = Object.values(error.errors || {}).flat();
            let message = messages.length ? messages.join('\n') : error.message;
            if (!messages.length && ![401, 403, 404, 419, 422].includes(error.status)) {
                message += ' Retry this same form to reuse its request key, or refresh to check the saved history.';
            }
            this.el(errorId).textContent = message;
            this.el(errorId).hidden = false;
        } finally {
            this.busy = false;
            this.committed = false;
            this.updateAvailability();
        }
    },

    /* ==================================================
       CREATE STOCK-IN OR APPEND A RECEIPT CORRECTION
       Conversion factors are calculated by the backend.
    ================================================== */

    

    async saveIn() {
        if (this.busy || !this.ready || !this.el('stockInForm').reportValidity()) return;
        const flower = this.model.flower(this.el('inFlower').value);
        const batch = this.model.batches.find(item => item.id === this.editing);
        const payload = {
            request_key: this.requestKeys.stockIn,
            quantity: Number(this.el('inQuantity').value),
            unit: this.el('inUnit').value,
            received: this.el('inReceived').value,
            harvest: this.el('inHarvest').value,
            expiry: this.el('inExpiry').value || null,
            remarks: this.el('inRemarks').value.trim(),
        };
        if (!batch) payload.flower_id = flower.id;
        await this.submit(batch ? this.endpoint('correctionUrl', batch.dbId) : this.endpoint('stockInUrl'),
            payload, 'stockInError', this.formModal);
    },

    /* ==================================================
       ISSUE STOCK WITH SERVER-ENFORCED FIFO / RESERVATIONS
    ================================================== */

    

    async saveOut() {
        if (this.busy || !this.ready || !this.el('stockOutForm').reportValidity()) return;
        const data = this.outData();
        const flower = this.model.flower(data.flower);
        const batch = this.model.batches.find(item => item.id === data.batch);
        await this.submit(this.endpoint('stockOutUrl'), {
            request_key: this.requestKeys.stockOut,
            flower_id: flower.id, quantity: data.quantity, reason: data.reason,
            batch_id: batch?.dbId || null, remarks: data.remarks,
        }, 'stockOutError', this.outModal);
    },

    /* ==================================================
       SAVE UNIT SETTINGS FOR FUTURE RECEIPTS ONLY
    ================================================== */

    

    async saveUnits() {
        if (this.busy || !this.ready || !this.el('inventoryUnitForm').reportValidity()) return;
        const flower = this.model.flower(this.el('unitFlowerCode').value);
        await this.submit(this.endpoint('unitsUrl', flower.id), {
            bunch_factor: Number(this.el('unitBunch').value),
            bundle_factor: Number(this.el('unitBundle').value),
            box_factor: Number(this.el('unitBox').value),
        }, 'inventoryUnitError', this.unitModal);
    }

};

if (document.readyState === 'loading') {
    document.addEventListener(
        'DOMContentLoaded',
        () => InventoryUI.init(),
        { once: true }
    );
} else {
    InventoryUI.init();
}


