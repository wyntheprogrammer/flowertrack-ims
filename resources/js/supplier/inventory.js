import { Modal } from 'bootstrap';
import DataTable from 'datatables.net-bs5';
import 'datatables.net-bs5/css/dataTables.bootstrap5.css';

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
    const date = new Date();

    return [
        date.getFullYear(),
        String(date.getMonth() + 1).padStart(2, '0'),
        String(date.getDate()).padStart(2, '0')
    ].join('-');
};

const timestamp = () =>
    `${today()} ${new Date().toTimeString().slice(0, 8)}`;

const daysAgo = (days) => {
    const date = new Date();
    date.setDate(date.getDate() - days);

    return [
        date.getFullYear(),
        String(date.getMonth() + 1).padStart(2, '0'),
        String(date.getDate()).padStart(2, '0')
    ].join('-');
};

/* UI-only state. Backend integration will replace these sample records. */
class InventoryModel {
    constructor() {
        this.flowers = [
            ['FLW-001', 'Red Rose', 'Roses', 100],
            ['FLW-002', 'White Rose', 'Roses', 100],
            ['FLW-003', 'Sunflower', 'Sunflowers', 50],
            ['FLW-004', 'Pink Tulip', 'Tulips', 50],
            ['FLW-005', 'White Lily', 'Lilies', 50],
            ['FLW-006', 'Pink Carnation', 'Carnations', 75],
            ['FLW-007', 'Gerbera Daisy', 'Gerberas', 50],
            ['FLW-008', "Baby's Breath", 'Fillers', 30]
        ].map(([code, name, category, minimum]) => ({
            code,
            name,
            category,
            minimum,
            units: {
                Stem: 1,
                Bunch: 10,
                Bundle: 20,
                Box: 100
            }
        }));

        this.batches = [];
        this.movements = [];

        this.orders = [
            {
                id: 'ORD-001',
                flower: 'FLW-001',
                quantity: 40,
                method: 'Delivery',
                status: 'Delivered',
                stockedOut: false
            },
            {
                id: 'ORD-002',
                flower: 'FLW-002',
                quantity: 30,
                method: 'Delivery',
                status: 'Processing',
                stockedOut: false
            },
            {
                id: 'ORD-003',
                flower: 'FLW-003',
                quantity: 20,
                method: 'Pickup',
                status: 'Ready for Pickup',
                stockedOut: false
            },
            {
                id: 'ORD-004',
                flower: 'FLW-004',
                quantity: 10,
                method: 'Pickup',
                status: 'Completed',
                stockedOut: false
            }
        ];

        const samples = [
            ['FLW-001', 100, 80, 8],
            ['FLW-001', 150, 150, 3],
            ['FLW-002', 50, 50, 4],
            ['FLW-003', 120, 100, 3],
            ['FLW-004', 30, 30, 2],
            ['FLW-006', 75, 25, 5],
            ['FLW-007', 60, 60, 2],
            ['FLW-008', 30, 0, 7]
        ];

        for (const [flower, initial, remaining, age] of samples) {
            const batch = {
                id: this.nextBatch(),
                flower,
                initial,
                remaining,
                received: daysAgo(age),
                harvest: daysAgo(age + 1),
                expiry: '',
                quantity: initial,
                unit: 'Stem',
                factor: 1,
                remarks: 'Opening sample receipt.',
                spoiled: false
            };

            this.batches.push(batch);

            this.log(
                batch,
                'Stock-In',
                initial,
                batch.remarks,
                `${batch.received} 08:00:00`
            );

            if (initial > remaining) {
                this.log(
                    batch,
                    'Stock-Out',
                    -(initial - remaining),
                    'Sample stock usage.',
                    `${batch.received} 10:00:00`
                );
            }
        }
    }

    flower(code) {
        return this.flowers.find((flower) => flower.code === code);
    }

    nextBatch() {
        const highest = this.batches.reduce(
            (max, batch) => Math.max(max, Number(batch.id.slice(4))),
            0
        );

        return `BAT-${String(highest + 1).padStart(3, '0')}`;
    }

    batchesFor(code) {
        return this.batches.filter((batch) => batch.flower === code);
    }

    onHand(code) {
        return this.batchesFor(code)
            .filter((batch) => !batch.spoiled)
            .reduce((sum, batch) => sum + batch.remaining, 0);
    }

    reserved(code) {
        return this.orders
            .filter((order) => order.flower === code && !order.stockedOut)
            .reduce((sum, order) => sum + order.quantity, 0);
    }

    available(code) {
        return this.onHand(code) - this.reserved(code);
    }

    status(code) {
        const available = this.available(code);

        if (available <= 0) return 'Out of Stock';
        if (available < this.flower(code).minimum) return 'Low Stock';

        return 'Normal';
    }

    eligibleOrder(order) {
        return order && !order.stockedOut && (
            (order.method === 'Delivery' && order.status === 'Delivered') ||
            (order.method === 'Pickup' && order.status === 'Completed')
        );
    }

    log(batch, type, quantity, remarks, date = timestamp()) {
        this.movements.push({
            id: this.movements.length + 1,
            batch: batch.id,
            flower: batch.flower,
            type,
            quantity,
            remarks,
            date,
            user: 'Administrator'
        });
    }

    receive(data) {
        const flower = this.flower(data.flower);
        const batch = data.id
            ? this.batches.find((item) => item.id === data.id)
            : null;

        if (!flower || (data.id && !batch)) {
            throw new Error('Select a valid flower or receipt.');
        }

        if (batch && batch.flower !== data.flower) {
            throw new Error('The flower on an existing receipt cannot change.');
        }

        if (
            !Number.isSafeInteger(data.quantity) ||
            data.quantity < 1 ||
            !Number.isSafeInteger(data.factor) ||
            data.factor < 1 ||
            !Number.isSafeInteger(data.quantity * data.factor)
        ) {
            throw new Error(
                'Quantity and conversion must be positive whole numbers.'
            );
        }

        if (
            !data.received ||
            !data.harvest ||
            data.harvest > data.received ||
            (data.expiry && data.expiry < data.harvest)
        ) {
            throw new Error(
                'Check the received, harvest and freshness dates.'
            );
        }

        if (batch && !data.remarks.trim()) {
            throw new Error(
                'Add remarks explaining the receipt correction.'
            );
        }

        const initial = data.quantity * data.factor;

        if (batch) {
            const consumed = batch.initial - batch.remaining;

            if (initial < consumed) {
                throw new Error(
                    'Received stock cannot be lower than stock already issued from this batch.'
                );
            }

            if (
                this.onHand(batch.flower) + initial - batch.initial <
                this.reserved(batch.flower)
            ) {
                throw new Error(
                    'This correction would reduce stock below existing reservations.'
                );
            }

            if (batch.spoiled) {
                throw new Error(
                    'A spoiled batch cannot be changed from this screen.'
                );
            }

            Object.assign(batch, data, {
                initial,
                remaining: initial - consumed
            });

            const movement = this.movements.find(
                (item) => item.batch === batch.id && item.type === 'Stock-In'
            );

            Object.assign(movement, {
                quantity: initial,
                remarks: data.remarks,
                date: `${data.received} 08:00:00`
            });
        } else {
            const created = {
                ...data,
                id: this.nextBatch(),
                initial,
                remaining: initial,
                spoiled: false
            };

            this.batches.push(created);

            this.log(
                created,
                'Stock-In',
                initial,
                data.remarks,
                `${data.received} ${new Date().toTimeString().slice(0, 8)}`
            );
        }
    }

    allocation(code, quantity, selectedBatch = '') {
        if (!Number.isSafeInteger(quantity) || quantity < 1) {
            throw new Error(
                'Enter a positive whole-number quantity in stems.'
            );
        }

        const batches = this.batchesFor(code)
            .filter((batch) =>
                !batch.spoiled &&
                batch.remaining > 0 &&
                (!selectedBatch || batch.id === selectedBatch)
            )
            .sort((a, b) =>
                a.received.localeCompare(b.received) ||
                a.id.localeCompare(b.id)
            );

        let pending = quantity;
        const result = [];

        for (const batch of batches) {
            const amount = Math.min(pending, batch.remaining);

            if (amount) result.push({ batch, amount });

            pending -= amount;

            if (!pending) break;
        }

        if (pending) {
            throw new Error(
                selectedBatch
                    ? 'Selected batch has insufficient stock. Use FIFO to split across batches.'
                    : 'Insufficient physical stock.'
            );
        }

        return result;
    }

    issue(data) {
        if (!this.flower(data.flower)) {
            throw new Error('Select a flower.');
        }

        const order = data.reason === 'Customer order'
            ? this.orders.find((item) => item.id === data.order)
            : null;

        if (
            data.reason === 'Customer order' &&
            (
                !this.eligibleOrder(order) ||
                order.flower !== data.flower ||
                order.quantity !== data.quantity
            )
        ) {
            throw new Error(
                'Select an eligible Delivered / collected Completed pickup order and issue its full quantity.'
            );
        }

        if (
            (data.reason === 'Spoilage' || data.batch) &&
            !data.remarks.trim()
        ) {
            throw new Error(
                'Remarks are required for spoilage or a FIFO override.'
            );
        }

        const available = this.available(data.flower) +
            (order ? order.quantity : 0);

        if (data.quantity > available) {
            throw new Error(
                `Only ${number(available)} stems are available for this transaction after other reservations.`
            );
        }

        const plan = this.allocation(
            data.flower,
            data.quantity,
            data.batch
        );

        for (const { batch, amount } of plan) {
            batch.remaining -= amount;

            this.log(
                batch,
                'Stock-Out',
                -amount,
                `${data.reason}${order ? ` (${order.id})` : ''}` +
                `${data.batch ? ' · FIFO override' : ''}` +
                `${data.remarks ? ` — ${data.remarks}` : ''}`
            );
        }

        if (order) order.stockedOut = true;

        return plan;
    }
}

/* PAGE INTERACTIONS */
const InventoryUI = {
    model: new InventoryModel(),
    tables: {},
    active: 'inventory',
    editing: '',

    el(id) {
        return document.getElementById(id);
    },

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
        this.refresh();
    },

    bindEvents() {
        this.el('openStockIn').addEventListener(
            'click',
            () => this.openIn()
        );

        this.el('openStockOut').addEventListener(
            'click',
            () => this.openOut()
        );

        this.el('inventorySearch').addEventListener(
            'input',
            () => this.search()
        );

        this.el('inventorySearch').addEventListener(
            'search',
            () => this.search()
        );

        this.el('inventoryPageLength').addEventListener('change', (event) => {
            this.tables[this.active]
                .page.len(Number(event.target.value))
                .draw();
        });

        document.querySelectorAll('[data-inventory-tab]').forEach((button) => {
            button.addEventListener('click', () => {
                this.active = button.dataset.inventoryTab;

                document.querySelectorAll('[data-inventory-tab]').forEach((tab) => {
                    const selected = tab === button;
                    tab.classList.toggle('active', selected);
                    tab.setAttribute('aria-selected', String(selected));
                });

                this.el('inventoryPanel').hidden =
                    this.active !== 'inventory';

                this.el('inventoryHistoryPanel').hidden =
                    this.active !== 'history';

                this.el('inventoryPageLength').value =
                    String(this.tables[this.active].page.len());

                this.search();
                this.tables[this.active].columns.adjust();
            });
        });

        this.el('inventoryPage').addEventListener('click', (event) => {
            const button = event.target.closest('[data-inventory-action]');
            if (!button) return;

            if (button.dataset.inventoryAction === 'details') {
                this.details(button.dataset.code);
            }

            if (button.dataset.inventoryAction === 'units') {
                this.openUnits(button.dataset.code);
            }
        });

        this.el('inventoryDetailContent').addEventListener('click', (event) => {
            const button = event.target.closest('[data-edit-receipt]');
            if (!button) return;

            this.el('inventoryDetailModal').addEventListener(
                'hidden.bs.modal',
                () => this.openIn(button.dataset.editReceipt),
                { once: true }
            );

            this.detailModal.hide();
        });

        this.el('stockInForm').addEventListener('submit', (event) => {
            event.preventDefault();

            this.attempt('stockInError', () => {
                const option = this.el('inUnit').selectedOptions[0];

                this.model.receive({
                    id: this.editing,
                    flower: this.el('inFlower').value,
                    quantity: Number(this.el('inQuantity').value),
                    unit: option.value,
                    factor: Number(option.dataset.factor),
                    received: this.el('inReceived').value,
                    harvest: this.el('inHarvest').value,
                    expiry: this.el('inExpiry').value,
                    remarks: this.el('inRemarks').value.trim()
                });

                this.formModal.hide();
                this.refresh();
                this.notice('Stock-In saved in this UI session.');
            });
        });

        this.el('inFlower').addEventListener(
            'change',
            () => this.fillUnits()
        );

        ['inQuantity', 'inUnit'].forEach((id) => {
            this.el(id).addEventListener('input', () => this.inPreview());
        });

        this.el('stockOutForm').addEventListener('submit', (event) => {
            event.preventDefault();

            this.attempt('stockOutError', () => {
                this.model.issue(this.outData());
                this.outModal.hide();
                this.refresh();

                this.notice(
                    'Stock-Out saved. Batch balances and history updated.'
                );
            });
        });

        ['outFlower', 'outReason', 'outOrder', 'outOverride'].forEach((id) => {
            this.el(id).addEventListener('change', () => this.syncOut());
        });

        ['outQuantity', 'outBatch'].forEach((id) => {
            this.el(id).addEventListener('input', () => this.outPreview());
        });

        this.el('inventoryUnitForm').addEventListener('submit', (event) => {
            event.preventDefault();

            this.attempt('inventoryUnitError', () => {
                const flower = this.model.flower(
                    this.el('unitFlowerCode').value
                );

                const units = { Stem: 1 };

                for (const unit of ['Bunch', 'Bundle', 'Box']) {
                    const factor = Number(this.el(`unit${unit}`).value);

                    if (!Number.isSafeInteger(factor) || factor < 1) {
                        throw new Error(
                            'Enter positive whole-number conversion factors.'
                        );
                    }

                    units[unit] = factor;
                }

                flower.units = units;
                this.unitModal.hide();

                this.notice(
                    'Unit conversions updated for new receipts. Existing batch conversions are preserved.'
                );
            });
        });
    },

    attempt(id, action) {
        this.el(id).hidden = true;

        try {
            action();
        } catch (error) {
            this.el(id).textContent = error.message;
            this.el(id).hidden = false;
        }
    },

    notice(message) {
        this.el('inventoryNotice').textContent = message;
        this.el('inventoryNotice').hidden = false;
    },

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
                        <small>${flower.code} · ${flower.category}</small>
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
                <small class="inv-cell-note">${movement.batch}</small>
            `,
            `
                <span class="inv-badge ${
                    movement.type === 'Stock-In' ? 'normal' : 'empty'
                }">
                    ${movement.type}
                </span>
            `,
            movement.quantity,
            escapeHTML(movement.remarks || '—'),
            escapeHTML(movement.user)
        ]);

        this.tables.history.clear().rows.add(historyRows).draw(false);

        const total = (method) => model.flowers.reduce(
            (sum, flower) => sum + model[method](flower.code),
            0
        );

        this.el('totalOnHand').textContent = number(total('onHand'));
        this.el('totalReserved').textContent = number(total('reserved'));
        this.el('totalAvailable').textContent = number(total('available'));

        this.el('totalStockAlerts').textContent = model.flowers.filter(
            (flower) => model.status(flower.code) !== 'Normal'
        ).length;

        this.search();
    },

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

    flowerOptions() {
        return this.model.flowers.map((flower) => `
            <option value="${flower.code}">
                ${escapeHTML(flower.name)} — ${flower.code}
            </option>
        `).join('');
    },

    openIn(id = '') {
        this.el('stockInForm').reset();
        this.editing = id;
        this.el('stockInError').hidden = true;
        this.el('inFlower').innerHTML = this.flowerOptions();

        const batch = this.model.batches.find((item) => item.id === id);

        this.el('stockInTitle').textContent = batch
            ? 'Edit Stock-In'
            : 'Stock-In';

        this.el('inBatchCode').value =
            batch?.id ?? this.model.nextBatch();

        this.el('inFlower').value =
            batch?.flower ?? this.model.flowers[0].code;

        this.el('inFlower').disabled = Boolean(batch);
        this.el('inQuantity').value = batch?.quantity ?? 1;
        this.el('inReceived').value = batch?.received ?? today();
        this.el('inHarvest').value = batch?.harvest ?? today();
        this.el('inExpiry').value = batch?.expiry ?? '';
        this.el('inRemarks').value = batch?.remarks ?? '';

        this.fillUnits(batch);
        this.formModal.show();
    },

    fillUnits(batch = null) {
        const flower = this.model.flower(this.el('inFlower').value);
        const units = { ...flower.units };

        if (batch) {
            units[batch.unit] = batch.factor;
        }

        this.el('inUnit').innerHTML = Object.entries(units).map(
            ([unit, factor]) => `
                <option value="${unit}" data-factor="${factor}">
                    ${unit} (${factor} stem${factor > 1 ? 's' : ''})
                </option>
            `
        ).join('');

        this.el('inUnit').value = batch?.unit ?? 'Stem';
        this.inPreview();
    },

    inPreview() {
        const total = Number(this.el('inQuantity').value) *
            Number(this.el('inUnit').selectedOptions[0]?.dataset.factor ?? 1);

        this.el('inConversion').textContent =
            `${number(total || 0)} stems will be recorded.`;
    },

    openOut() {
        this.el('stockOutForm').reset();
        this.el('stockOutError').hidden = true;
        this.el('outFlower').innerHTML = this.flowerOptions();

        this.el('outOrder').innerHTML =
            '<option value="">Select eligible order</option>' +
            this.model.orders
                .filter((order) => this.model.eligibleOrder(order))
                .map((order) => `
                    <option value="${order.id}">
                        ${order.id} ·
                        ${this.model.flower(order.flower).name} ·
                        ${order.quantity} stems ·
                        ${order.status}
                    </option>
                `).join('');

        this.syncOut();
        this.outModal.show();
    },

    syncOut() {
        const forOrder =
            this.el('outReason').value === 'Customer order';

        const order = forOrder
            ? this.model.orders.find(
                (item) => item.id === this.el('outOrder').value
            )
            : null;

        this.el('outOrderGroup').classList.toggle('d-none', !forOrder);
        this.el('outOrder').required = forOrder;
        this.el('outFlower').disabled = forOrder;
        this.el('outQuantity').readOnly = forOrder;

        if (order) {
            this.el('outFlower').value = order.flower;
            this.el('outQuantity').value = order.quantity;
        }

        const code = this.el('outFlower').value;
        const previous = this.el('outBatch').value;

        this.el('outBatch').innerHTML = this.model.batchesFor(code)
            .filter((batch) => !batch.spoiled && batch.remaining > 0)
            .map((batch) => `
                <option value="${batch.id}">
                    ${batch.id} · ${batch.received} · ${batch.remaining} stems
                </option>
            `).join('');

        if (
            [...this.el('outBatch').options].some(
                (option) => option.value === previous
            )
        ) {
            this.el('outBatch').value = previous;
        }

        const override = this.el('outOverride').checked;

        this.el('outBatchGroup').classList.toggle('d-none', !override);
        this.el('outBatch').required = override;

        this.el('outRemarks').required =
            override || this.el('outReason').value === 'Spoilage';

        const available = this.model.available(code) +
            (order ? order.quantity : 0);

        this.el('outBalance').textContent =
            `${number(this.model.onHand(code))} on hand · ` +
            `${number(this.model.reserved(code))} reserved · ` +
            `${number(available)} eligible for this transaction.`;

        this.outPreview();
    },

    outData() {
        return {
            flower: this.el('outFlower').value,
            quantity: Number(this.el('outQuantity').value),
            reason: this.el('outReason').value,
            order: this.el('outOrder').value,
            batch: this.el('outOverride').checked
                ? this.el('outBatch').value
                : '',
            remarks: this.el('outRemarks').value.trim()
        };
    },

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
                    `${batch.id}: ${number(amount)} stems`
            ).join(' · ');
        } catch (error) {
            this.el('outAllocation').textContent = error.message;
        }
    },

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
            .reverse()
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
                Minimum stock: ${flower.minimum} stems ·
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
                            <th>Stems</th>
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

    openUnits(code) {
        const flower = this.model.flower(code);

        this.el('unitFlowerCode').value = code;
        this.el('unitFlowerName').textContent = flower.name;
        this.el('inventoryUnitError').hidden = true;

        for (const unit of ['Bunch', 'Bundle', 'Box']) {
            this.el(`unit${unit}`).value = flower.units[unit];
        }

        this.unitModal.show();
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
