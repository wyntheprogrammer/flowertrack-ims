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

const dateOffset = (offset = 0) => {
    const date = new Date();
    date.setDate(date.getDate() + offset);

    return [
        date.getFullYear(),
        String(date.getMonth() + 1).padStart(2, '0'),
        String(date.getDate()).padStart(2, '0')
    ].join('-');
};

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

// UI demonstration setting, not a finalized freshness policy.
const FRESHNESS_WARNING_DAYS = 3;

/* SAMPLE DATA AND BATCH RULES */

class BatchModel {
    constructor() {
        this.flowers = [
            ['FLW-001', 'Red Rose', 'Roses'],
            ['FLW-002', 'White Rose', 'Roses'],
            ['FLW-003', 'Sunflower', 'Sunflowers'],
            ['FLW-004', 'Pink Tulip', 'Tulips'],
            ['FLW-005', 'White Lily', 'Lilies'],
            ['FLW-006', 'Pink Carnation', 'Carnations'],
            ['FLW-007', 'Gerbera Daisy', 'Gerberas'],
            ['FLW-008', "Baby's Breath", 'Fillers']
        ].map(([code, name, category]) => ({
            code,
            name,
            category
        }));

        // Separate frontend samples.
        // These are not synchronized with Inventory yet.
        const samples = [
            // Flower, initial, remaining, age, expiry offset,
            // spoilage Stock-Out recorded, already marked Spoiled
            ['FLW-001', 100, 80, 8, 2, false, false],
            ['FLW-001', 150, 150, 3, 6, false, false],
            ['FLW-002', 50, 50, 4, 1, false, false],
            ['FLW-003', 120, 100, 3, -1, false, false],
            ['FLW-004', 30, 30, 2, null, false, false],
            ['FLW-006', 75, 25, 5, 2, false, false],
            ['FLW-007', 60, 60, 2, 5, false, false],
            ['FLW-008', 30, 0, 7, -2, false, false],
            ['FLW-005', 40, 0, 6, -1, true, false],
            ['FLW-002', 60, 0, 9, -3, true, true]
        ];

        this.batches = samples.map(([
            flower,
            initial,
            remaining,
            age,
            expiry,
            spoilageOut,
            spoiled
        ], index) => {
            const received = dateOffset(-age);

            const history = [{
                date: `${received} 08:00:00`,
                type: 'Stock-In',
                quantity: initial,
                remarks: 'Sample receipt from Inventory.',
                user: 'Administrator',
                reason: 'Receipt'
            }];

            if (initial > remaining) {
                history.push({
                    date: `${received} 10:00:00`,
                    type: 'Stock-Out',
                    quantity: initial - remaining,
                    remarks: spoilageOut
                        ? 'Spoiled flowers issued through Inventory.'
                        : 'Sample stock usage.',
                    user: 'Administrator',
                    reason: spoilageOut ? 'Spoilage' : 'Other use'
                });
            }

            return {
                id: `BAT-${String(index + 1).padStart(3, '0')}`,
                flower,
                initial,
                remaining,
                received,
                harvest: dateOffset(-age - 1),
                expiry: expiry === null ? '' : dateOffset(expiry),
                remarks: 'Sample batch record.',
                spoiled,
                spoilageReason: spoiled
                    ? 'Flowers deteriorated; spoilage Stock-Out already recorded.'
                    : '',
                history
            };
        });
    }

    flower(code) {
        return this.flowers.find((flower) => flower.code === code);
    }

    find(id) {
        return this.batches.find((batch) => batch.id === id);
    }

    status(batch) {
        if (batch.spoiled) return 'Spoiled';
        if (batch.remaining === 0) return 'Depleted';

        return batch.remaining === batch.initial
            ? 'Available'
            : 'Partially Used';
    }

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

        const days = dayNumber(batch.expiry) - dayNumber(dateOffset());

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

        if (days <= FRESHNESS_WARNING_DAYS) {
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

    update(id, data) {
        const batch = this.find(id);

        if (!batch) {
            throw new Error('Batch not found.');
        }

        if (!Number.isFinite(dayNumber(data.harvest))) {
            throw new Error('Enter a valid harvest date.');
        }

        if (data.harvest > batch.received) {
            throw new Error(
                'Harvest date cannot be later than Date Received.'
            );
        }

        if (
            data.expiry &&
            (
                !Number.isFinite(dayNumber(data.expiry)) ||
                data.expiry < data.harvest
            )
        ) {
            throw new Error(
                'Freshness / expiry date must be on or after the harvest date.'
            );
        }

        // Metadata only.
        // Quantities, flower, received date and history remain unchanged.
        batch.harvest = data.harvest;
        batch.expiry = data.expiry;
        batch.remarks = data.remarks.trim();
    }

    spoilBlocker(batch) {
        if (!batch) {
            return 'Batch not found.';
        }

        if (batch.spoiled) {
            return 'This batch is already marked as Spoiled.';
        }

        if (batch.remaining !== 0) {
            return (
                `Record the remaining ${number(batch.remaining)} stems ` +
                'as a spoilage Stock-Out in Inventory first.'
            );
        }

        const outs = batch.history.filter(
            (movement) => movement.type === 'Stock-Out'
        );

        const last = outs[outs.length - 1];

        if (
            !last ||
            last.reason !== 'Spoilage' ||
            last.quantity <= 0
        ) {
            return (
                'No matching final spoilage Stock-Out was found. ' +
                'A normally depleted batch cannot be marked as Spoiled here.'
            );
        }

        const issued = outs.reduce(
            (total, movement) => total + movement.quantity,
            0
        );

        if (issued !== batch.initial) {
            return (
                'Batch quantity and Stock-Out history do not reconcile. ' +
                'Review Inventory first.'
            );
        }

        return '';
    }

    markSpoiled(id, reason) {
        const batch = this.find(id);
        const blocker = this.spoilBlocker(batch);

        if (blocker) {
            throw new Error(blocker);
        }

        if (!reason.trim()) {
            throw new Error('Enter a spoilage reason.');
        }

        batch.spoiled = true;
        batch.spoilageReason = reason.trim();

        // Status change only.
        // Do not deduct stock or create another Stock-Out.
    }
}

/* PAGE INTERACTIONS */

const BatchUI = {
    model: new BatchModel(),
    table: null,

    el(id) {
        return document.getElementById(id);
    },

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

        this.spoilModal = Modal.getOrCreateInstance(
            this.el('batchSpoilModal')
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
                            return `${flower.name} ${flower.code} ${flower.category}`;
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
                                        ${flower.code} · ${flower.category}
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
        this.refresh();
    },

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


    bindEvents() {
        this.el('batchSearch').addEventListener(
            'input',
            () => this.applySearch()
        );

        this.el('batchSearch').addEventListener(
            'search',
            () => this.applySearch()
        );

        this.el('batchPageLength').addEventListener('change', (event) => {
            this.table.page.len(Number(event.target.value)).draw();
        });

        this.table.on('length', (_event, _settings, length) => {
            this.el('batchPageLength').value = String(length);
        });

        this.el('batchTableBody').addEventListener('click', (event) => {
            const button = event.target.closest('[data-batch-action]');

            if (!button || button.disabled) return;

            const batch = this.model.find(button.dataset.id);

            if (!batch) return;

            if (button.dataset.batchAction === 'view') {
                this.view(batch);
            }

            if (button.dataset.batchAction === 'edit') {
                this.edit(batch);
            }

            if (button.dataset.batchAction === 'spoil') {
                this.openSpoil(batch);
            }
        });

        this.el('batchEditForm').addEventListener('submit', (event) => {
            event.preventDefault();

            this.attempt('batchEditError', () => {
                this.model.update(this.el('batchEditingId').value, {
                    harvest: this.el('batchEditHarvest').value,
                    expiry: this.el('batchEditExpiry').value,
                    remarks: this.el('batchEditRemarks').value
                });

                this.editModal.hide();
                this.refresh();

                this.notice(
                    'Batch metadata updated in this UI session. Quantities are unchanged.'
                );
            });
        });

        this.el('batchSpoilForm').addEventListener('submit', (event) => {
            event.preventDefault();

            this.attempt('batchSpoilError', () => {
                this.model.markSpoiled(
                    this.el('batchSpoilingId').value,
                    this.el('batchSpoilReason').value
                );

                this.spoilModal.hide();
                this.refresh();

                this.notice(
                    'Batch marked as Spoiled. No additional stock was deducted.'
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
        this.el('batchNotice').textContent = message;
        this.el('batchNotice').hidden = false;
    },

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

    refresh() {
        this.table.rows().invalidate('data');
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

    view(batch) {
        const flower = this.model.flower(batch.flower);

        const fields = [
            ['Batch Number', batch.id],
            ['Flower', `${flower.name} (${flower.code})`],
            ['Date Received', batch.received],
            ['Harvest Date', batch.harvest],
            ['Freshness / Expiry', batch.expiry || 'Not set'],
            ['Initial Quantity', `${number(batch.initial)} stems`],
            ['Remaining Quantity', `${number(batch.remaining)} stems`]
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
            .sort((a, b) => b.date.localeCompare(a.date))
            .map((movement) => `
                <tr>
                    <td>${escapeHTML(movement.date)}</td>
                    <td>${movement.type}</td>
                    <td>
                        ${movement.type === 'Stock-In' ? '+' : '−'}${number(movement.quantity)}
                        stems
                    </td>
                    <td>${escapeHTML(movement.remarks || '—')}</td>
                    <td>${escapeHTML(movement.user)}</td>
                </tr>
            `).join('') ||
            '<tr><td colspan="5">No movement history.</td></tr>';

        this.viewModal.show();
    },

    edit(batch) {
        this.el('batchEditForm').reset();
        this.el('batchEditError').hidden = true;

        const values = {
            batchEditingId: batch.id,
            batchEditNumber: batch.id,
            batchEditFlower: this.model.flower(batch.flower).name,
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

    openSpoil(batch) {
        this.el('batchSpoilForm').reset();
        this.el('batchSpoilingId').value = batch.id;

        this.el('batchSpoilSummary').innerHTML = `
            <div class="batch-spoil-summary">
                <strong>
                    ${batch.id} ·
                    ${escapeHTML(this.model.flower(batch.flower).name)}
                </strong>
                <p>
                    Initial: ${number(batch.initial)} stems ·
                    Remaining: ${number(batch.remaining)} stems
                </p>
            </div>
        `;

        const blocker = this.model.spoilBlocker(batch);

        this.el('batchSpoilError').hidden = !blocker;
        this.el('batchSpoilError').textContent = blocker;
        this.el('batchSpoilConfirm').disabled = Boolean(blocker);

        this.spoilModal.show();
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