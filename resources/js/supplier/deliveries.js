import DataTable from 'datatables.net-bs5';
import { Modal } from 'bootstrap';

const escapeHtml = (value) =>
    String(value ?? '').replace(/[&<>"']/g, (character) => ({
        '&': '&amp;',
        '<': '&lt;',
        '>': '&gt;',
        '"': '&quot;',
        "'": '&#039;',
    })[character]);

function badge(value) {
    const className = value.toLowerCase().replaceAll(' ', '-');

    return `
        <span class="delivery-badge ${className}">
            ${escapeHtml(value)}
        </span>
    `;
}

function formatDateTime(value) {
    if (!value) return 'Not scheduled';

    return new Intl.DateTimeFormat('en-PH', {
        dateStyle: 'medium',
        timeStyle: 'short',
    }).format(new Date(value));
}

function localInputValue(value) {
    if (!value) return '';

    const date = new Date(value);
    const pad = (number) => String(number).padStart(2, '0');

    return `${date.getFullYear()}-${pad(date.getMonth() + 1)}-${pad(date.getDate())}`
        + `T${pad(date.getHours())}:${pad(date.getMinutes())}`;
}

/*
 * Standalone UI sample data.
 * No real accounts, order integration, uploads or Inventory movements.
 */
const personnel = [
    { id: 'USR-003', name: 'Juan Dela Cruz', active: true, role: 'Delivery Personnel' },
    { id: 'USR-004', name: 'Mark Reyes', active: true, role: 'Delivery Personnel' },
    { id: 'USR-005', name: 'Leo Santos', active: false, role: 'Delivery Personnel' },
];

const tomorrow = new Date();
tomorrow.setDate(tomorrow.getDate() + 1);
tomorrow.setHours(9, 0, 0, 0);

function scheduleAfter(hours) {
    return new Date(tomorrow.getTime() + hours * 3600000).toISOString();
}

function makeDelivery({
    id,
    orderId,
    client,
    address,
    status = 'Pending',
    personId = '',
    schedule = '',
    orderStatus = 'Ready for Delivery',
}) {
    return {
        id,
        orderId,
        client,
        address,
        contact: '0917 000 1000',
        method: 'Delivery',
        orderStatus,
        status,
        personId,
        schedule,
        notes: '',
        proof: null,
        completedAt: null,
        sampleStockOut: false,
        revision: 0,
        history: [{
            date: new Date().toISOString(),
            actor: 'Administrator (sample)',
            status,
            note: 'Sample delivery loaded for UI review.',
        }],
    };
}

const sampleDeliveries = [
    makeDelivery({
        id: 'DEL-001',
        orderId: 'ORD-004',
        client: 'Maria Santos',
        address: 'Quezon City, Metro Manila',
    }),
    makeDelivery({
        id: 'DEL-002',
        orderId: 'ORD-007',
        client: 'Carlo Reyes',
        address: 'Makati City, Metro Manila',
        status: 'Scheduled',
        schedule: scheduleAfter(0),
    }),
    makeDelivery({
        id: 'DEL-003',
        orderId: 'ORD-008',
        client: 'Daniel Garcia',
        address: 'Marikina City, Metro Manila',
        status: 'Assigned',
        personId: 'USR-003',
        schedule: scheduleAfter(1),
    }),
    makeDelivery({
        id: 'DEL-004',
        orderId: 'ORD-009',
        client: 'Isabel Ramos',
        address: 'Manila, Metro Manila',
        status: 'Out for Delivery',
        personId: 'USR-004',
        schedule: scheduleAfter(2),
        orderStatus: 'Out for Delivery',
    }),
    makeDelivery({
        id: 'DEL-005',
        orderId: 'ORD-010',
        client: 'Paolo Mendoza',
        address: 'Antipolo City, Rizal',
        status: 'Failed',
        personId: 'USR-003',
        schedule: scheduleAfter(3),
        orderStatus: 'Out for Delivery',
    }),
    makeDelivery({
        id: 'DEL-006',
        orderId: 'ORD-011',
        client: 'Maria Santos',
        address: 'Pasig City, Metro Manila',
        status: 'Rescheduled',
        personId: 'USR-004',
        schedule: scheduleAfter(4),
    }),
];

sampleDeliveries[4].history.push({
    date: new Date().toISOString(),
    actor: 'Mark Reyes (sample)',
    status: 'Failed',
    note: 'Sample failed attempt: recipient unavailable.',
});

class DeliveryManagementUI {
    constructor(element) {
        this.element = element;
        this.deliveries = structuredClone(sampleDeliveries);
        this.selected = null;
        this.busy = false;
        this.proofUrls = new Set();

        this.manageModal = Modal.getOrCreateInstance(this.el('deliveryManageModal'));
        this.viewModal = Modal.getOrCreateInstance(this.el('deliveryViewModal'));

        this.initializeTable();
        this.mountScroller();
        this.bindEvents();
    }

    el(id) {
        return document.getElementById(id);
    }

    personName(id) {
        return personnel.find((person) => person.id === id)?.name || 'Unassigned';
    }

    terminal(delivery) {
        return ['Delivered', 'Cancelled'].includes(delivery.status);
    }

    activePerson(id) {
        return personnel.some((person) =>
            person.id === id
            && person.active
            && person.role === 'Delivery Personnel',
        );
    }

    actionsFor(delivery) {
        if (this.terminal(delivery)) return [];

        if (delivery.status === 'Out for Delivery') {
            return [
                ['deliver', 'Mark Delivered'],
                ['fail', 'Record Failed Attempt'],
            ];
        }

        const actions = [
            ['schedule', delivery.schedule ? 'Reschedule Delivery' : 'Schedule Delivery'],
            ['assign', delivery.personId ? 'Reassign Personnel' : 'Assign Personnel'],
        ];

        if (
            ['Assigned', 'Scheduled', 'Rescheduled'].includes(delivery.status)
            && delivery.schedule
            && this.activePerson(delivery.personId)
            && delivery.orderStatus === 'Ready for Delivery'
        ) {
            actions.push(['dispatch', 'Dispatch / Out for Delivery']);
        }

        actions.push(['cancel', 'Cancel Delivery']);

        return actions;
    }

    initializeTable() {
        this.table = new DataTable(this.element, {
            data: this.deliveries,
            autoWidth: false,
            pageLength: 5,
            order: [[0, 'asc']],
            layout: {
                topStart: null,
                topEnd: null,
                bottomStart: 'info',
                bottomEnd: 'paging',
            },
            language: {
                emptyTable: 'No deliveries available.',
                zeroRecords: 'No matching deliveries found.',
                info: 'Showing _START_ to _END_ of _TOTAL_ entries',
                infoEmpty: 'Showing 0 to 0 of 0 entries',
                infoFiltered: '(filtered from _MAX_ total entries)',
                paginate: {
                    first: '«',
                    previous: '‹',
                    next: '›',
                    last: '»',
                },
            },
            columns: [
                {
                    data: 'id',
                    render: (value, type, row) =>
                        type === 'display'
                            ? `
                                <div class="delivery-cell">
                                    <strong>${escapeHtml(value)}</strong>
                                    <small>${escapeHtml(row.orderId)}</small>
                                </div>
                            `
                            : `${value} ${row.orderId}`,
                },
                {
                    data: 'client',
                    render: (value, type, row) =>
                        type === 'display'
                            ? `
                                <div class="delivery-cell">
                                    <strong>${escapeHtml(value)}</strong>
                                    <small>${escapeHtml(row.address)}</small>
                                </div>
                            `
                            : value,
                },
                {
                    data: 'personId',
                    render: (value, type) => {
                        const name = this.personName(value);
                        return type === 'display' ? escapeHtml(name) : name;
                    },
                },
                {
                    data: 'schedule',
                    render: (value, type) => {
                        if (type === 'sort' || type === 'type') {
                            return value ? new Date(value).getTime() : 0;
                        }

                        return type === 'display'
                            ? escapeHtml(formatDateTime(value))
                            : value;
                    },
                },
                {
                    data: 'status',
                    render: (value, type) =>
                        type === 'display' ? badge(value) : value,
                },
                {
                    data: null,
                    orderable: false,
                    searchable: false,
                    render: (_value, type, row) => {
                        if (type !== 'display') return '';

                        return `
                            <div class="delivery-actions">
                                <button
                                    type="button"
                                    class="delivery-action-btn view"
                                    data-delivery-action="view"
                                    data-delivery-id="${escapeHtml(row.id)}"
                                    title="View delivery"
                                    aria-label="View ${escapeHtml(row.id)}"
                                >
                                    <i class="bi bi-eye" aria-hidden="true"></i>
                                </button>

                                ${!this.terminal(row) ? `
                                    <button
                                        type="button"
                                        class="delivery-action-btn manage"
                                        data-delivery-action="manage"
                                        data-delivery-id="${escapeHtml(row.id)}"
                                        title="Manage delivery"
                                        aria-label="Manage ${escapeHtml(row.id)}"
                                    >
                                        <i class="bi bi-pencil-square" aria-hidden="true"></i>
                                    </button>
                                ` : ''}
                            </div>
                        `;
                    },
                },
            ],
        });
    }

    mountScroller() {
        const wrapper = document.createElement('div');

        wrapper.className = 'delivery-table-scroll';
        wrapper.tabIndex = 0;
        wrapper.setAttribute('role', 'region');
        wrapper.setAttribute(
            'aria-label',
            'Deliveries table. Scroll horizontally to see all columns.',
        );

        this.element.before(wrapper);
        wrapper.append(this.element);
    }

    refresh() {
        this.table.clear();
        this.table.rows.add(this.deliveries);
        this.table.draw(false);
    }

    bindEvents() {
        this.el('deliverySearch').addEventListener('input', (event) => {
            this.table.search(event.target.value).draw();
        });

        this.el('deliveryPageLength').addEventListener('change', (event) => {
            this.table.page.len(Number(event.target.value)).draw();
        });

        this.element.addEventListener('click', (event) => {
            const button = event.target.closest('[data-delivery-action]');
            if (!button) return;

            const delivery = this.deliveries.find(
                (item) => item.id === button.dataset.deliveryId,
            );

            if (!delivery) return;

            if (button.dataset.deliveryAction === 'view') {
                this.showDetails(delivery);
            } else {
                this.openManage(delivery);
            }
        });

        this.el('deliveryAction').addEventListener('change', () => {
            this.renderActionFields();
        });

        this.el('deliveryManageForm').addEventListener('submit', async (event) => {
            event.preventDefault();
            await this.saveAction();
        });

        this.el('deliveryManageModal').addEventListener('hidden.bs.modal', () => {
            this.selected = null;
            this.el('deliveryManageForm').reset();
            this.el('deliveryActionFields').innerHTML = '';
        });

        window.addEventListener('pagehide', () => {
            this.proofUrls.forEach((url) => URL.revokeObjectURL(url));
            this.proofUrls.clear();
        });
    }

    showError(message = '') {
        this.el('deliveryManageError').textContent = message;
        this.el('deliveryManageError').hidden = !message;
    }

    openManage(delivery) {
        const actions = this.actionsFor(delivery);
        if (!actions.length) return;

        this.selected = {
            id: delivery.id,
            revision: delivery.revision,
        };

        this.showError();

        this.el('deliveryManageTitle').textContent = `Manage ${delivery.id}`;
        this.el('deliveryManageSummary').textContent =
            `${delivery.orderId} · ${delivery.client} · Current status: ${delivery.status}`;

        this.el('deliveryAction').innerHTML = actions.map(([value, label]) => `
            <option value="${value}">${escapeHtml(label)}</option>
        `).join('');

        this.renderActionFields();
        this.manageModal.show();
    }

    renderActionFields() {
        this.showError();

        const delivery = this.deliveries.find(
            (item) => item.id === this.selected?.id,
        );

        if (!delivery) return;

        const action = this.el('deliveryAction').value;
        let fields = '';

        if (action === 'schedule') {
            fields = `
                <div class="delivery-field">
                    <label for="deliverySchedule">
                        Scheduled Date and Time <span>*</span>
                    </label>
                    <input
                        type="datetime-local"
                        id="deliverySchedule"
                        value="${localInputValue(delivery.schedule)}"
                        required
                    >
                </div>
                <div class="delivery-field">
                    <label for="deliveryReason">Schedule Remarks <span>*</span></label>
                    <textarea id="deliveryReason" rows="3" maxlength="500" required></textarea>
                </div>
                <p class="delivery-help">
                    Choose a future schedule. Rescheduling a failed attempt
                    returns the sample linked order to Ready for Delivery.
                </p>
            `;
        }

        if (action === 'assign') {
            fields = `
                <div class="delivery-field">
                    <label for="deliveryPersonnel">Delivery Personnel <span>*</span></label>
                    <select id="deliveryPersonnel" required>
                        <option value="">Select personnel</option>
                        ${personnel
                            .filter((person) =>
                                person.active && person.role === 'Delivery Personnel',
                            )
                            .map((person) => `
                                <option
                                    value="${escapeHtml(person.id)}"
                                    ${person.id === delivery.personId ? 'selected' : ''}
                                >
                                    ${escapeHtml(person.name)}
                                </option>
                            `).join('')}
                    </select>
                </div>
                <div class="delivery-field">
                    <label for="deliveryReason">Assignment Remarks <span>*</span></label>
                    <textarea id="deliveryReason" rows="3" maxlength="500" required></textarea>
                </div>
            `;
        }

        if (action === 'dispatch') {
            fields = `
                <p class="delivery-help">
                    Confirm the order has been handed to the assigned personnel.
                    This changes the sample delivery and linked order to
                    Out for Delivery. Dispatch does not deduct inventory.
                </p>
                <div class="delivery-field">
                    <label for="deliveryReason">Dispatch Notes</label>
                    <textarea id="deliveryReason" rows="3" maxlength="500"></textarea>
                </div>
            `;
        }

        if (action === 'deliver') {
            fields = `
                <div class="delivery-field">
                    <label for="deliveryRecipient">Recipient Name <span>*</span></label>
                    <input type="text" id="deliveryRecipient" maxlength="120" required>
                </div>
                <div class="delivery-field">
                    <label for="deliveryReason">Delivery Remarks <span>*</span></label>
                    <textarea id="deliveryReason" rows="3" maxlength="500" required></textarea>
                </div>
                <div class="delivery-field">
                    <label for="deliveryProof">Photo Proof <span>*</span></label>
                    <input
                        type="file"
                        id="deliveryProof"
                        accept="image/jpeg,image/png,image/webp"
                        required
                    >
                </div>
                <p class="delivery-help">
                    JPEG, PNG or WebP; maximum 5 MB.
                    Photo stays in this browser session and is not uploaded.
                    Completion time is recorded automatically.
                </p>
            `;
        }

        if (action === 'fail' || action === 'cancel') {
            fields = `
                <div class="delivery-field">
                    <label for="deliveryReason">
                        ${action === 'fail' ? 'Failure Reason' : 'Cancellation Reason'}
                        <span>*</span>
                    </label>
                    <textarea id="deliveryReason" rows="3" maxlength="500" required></textarea>
                </div>
                <p class="delivery-help">
                    ${action === 'fail'
                        ? 'A failed delivery remains in history and does not automatically cancel the order. Reschedule it before another dispatch.'
                        : 'Cancelling this delivery does not cancel the linked order or release its reservation. Order cancellation remains an Order Management operation.'}
                </p>
            `;
        }

        this.el('deliveryActionFields').innerHTML = fields;
    }

    async validatePhoto(file) {
        const supported = ['image/jpeg', 'image/png', 'image/webp'];

        if (!file || !supported.includes(file.type)) {
            throw new Error('Select a JPEG, PNG or WebP proof image.');
        }

        if (file.size > 5 * 1024 * 1024) {
            throw new Error('Proof image must be 5 MB or smaller.');
        }

        const url = URL.createObjectURL(file);

        try {
            await new Promise((resolve, reject) => {
                const image = new Image();
                image.onload = resolve;
                image.onerror = () => reject(new Error('The selected image could not be opened.'));
                image.src = url;
            });

            return url;
        } catch (error) {
            URL.revokeObjectURL(url);
            throw error;
        }
    }

    async saveAction() {
        if (this.busy || !this.selected) return;

        this.showError();

        if (!this.el('deliveryManageForm').reportValidity()) return;

        const selection = { ...this.selected };
        const delivery = this.deliveries.find((item) => item.id === selection.id);
        const action = this.el('deliveryAction').value;

        if (!delivery || delivery.revision !== selection.revision) {
            this.showError('Delivery changed. Close and reopen this dialog.');
            return;
        }

        if (!this.actionsFor(delivery).some(([value]) => value === action)) {
            this.showError('This action is no longer available.');
            return;
        }

        const reason = this.el('deliveryReason')?.value.trim() || '';

        if (['schedule', 'assign', 'deliver', 'fail', 'cancel'].includes(action) && !reason) {
            this.showError('Enter the required remarks or reason.');
            return;
        }

        const changes = {};
        let note = reason;
        let proofUrl = null;

        try {
            if (action === 'schedule') {
                const input = this.el('deliverySchedule').value;
                const date = new Date(input);

                if (!input || !Number.isFinite(date.getTime()) || date <= new Date()) {
                    throw new Error('Choose a future delivery date and time.');
                }

                const wasScheduled = Boolean(delivery.schedule);

                if (wasScheduled && date.toISOString() === delivery.schedule) {
                    throw new Error('Choose a different date/time to reschedule.');
                }

                changes.schedule = date.toISOString();
                changes.status = wasScheduled || delivery.status === 'Failed'
                    ? 'Rescheduled'
                    : delivery.personId ? 'Assigned' : 'Scheduled';

                if (delivery.status === 'Failed') {
                    changes.orderStatus = 'Ready for Delivery';
                }

                note = `Schedule: ${formatDateTime(delivery.schedule)} → ${formatDateTime(changes.schedule)}. ${reason}`;
            }

            if (action === 'assign') {
                const personId = this.el('deliveryPersonnel').value;

                if (!this.activePerson(personId)) {
                    throw new Error('Select active, authorized delivery personnel.');
                }

                if (personId === delivery.personId) {
                    throw new Error('Select different personnel to reassign.');
                }

                changes.personId = personId;

                // Failed deliveries still require rescheduling before dispatch.
                changes.status = delivery.status === 'Failed'
                    ? 'Failed'
                    : 'Assigned';

                note = `Personnel: ${this.personName(delivery.personId)} → ${this.personName(personId)}. ${reason}`;
            }

            if (action === 'dispatch') {
                if (
                    delivery.method !== 'Delivery'
                    || delivery.orderStatus !== 'Ready for Delivery'
                    || !delivery.schedule
                    || !this.activePerson(delivery.personId)
                    || !['Assigned', 'Scheduled', 'Rescheduled'].includes(delivery.status)
                ) {
                    throw new Error('Dispatch requires a ready order, schedule and active assigned personnel.');
                }

                changes.status = 'Out for Delivery';
                changes.orderStatus = 'Out for Delivery';
                note = `Dispatched with ${this.personName(delivery.personId)}. ${reason}`;
            }

            if (action === 'fail') {
                changes.status = 'Failed';
                note = `Failed attempt: ${reason}`;
            }

            if (action === 'cancel') {
                changes.status = 'Cancelled';
                note = `Delivery cancelled: ${reason}. Linked order unchanged.`;
            }

            if (action === 'deliver') {
                const recipient = this.el('deliveryRecipient').value.trim();
                const file = this.el('deliveryProof').files[0];

                if (!recipient) {
                    throw new Error('Enter the recipient name.');
                }

                this.setBusy(true);
                proofUrl = await this.validatePhoto(file);

                // Recheck after asynchronous image validation.
                if (
                    this.selected?.id !== selection.id
                    || delivery.revision !== selection.revision
                    || delivery.status !== 'Out for Delivery'
                ) {
                    throw new Error('Delivery changed or dialog closed. Reopen and try again.');
                }

                changes.status = 'Delivered';
                changes.orderStatus = 'Completed';
                changes.completedAt = new Date().toISOString();
                changes.sampleStockOut = true;
                changes.proof = {
                    recipient,
                    remarks: reason,
                    name: file.name,
                    url: proofUrl,
                };

                note = `Received by ${recipient}. ${reason}. Sample order completed; actual Inventory Stock-Out is not connected.`;
            }

            Object.assign(delivery, changes);
            delivery.revision += 1;

            delivery.history.push({
                date: new Date().toISOString(),
                actor: 'Administrator (preview)',
                status: delivery.status,
                note,
            });

            if (proofUrl) {
                this.proofUrls.add(proofUrl);
                proofUrl = null;
            }

            this.refresh();
            this.manageModal.hide();

            this.el('deliveryAnnouncement').textContent =
                `${delivery.id} updated: ${delivery.status}. Preview data only.`;
        } catch (error) {
            if (proofUrl) URL.revokeObjectURL(proofUrl);
            this.showError(error.message || 'Unable to update delivery.');
        } finally {
            this.setBusy(false);
        }
    }

    setBusy(value) {
        this.busy = value;

        this.el('deliveryManageForm')
            .querySelector('button[type="submit"]').disabled = value;

        this.el('deliveryAction').disabled = value;
    }

    showDetails(delivery) {
        this.el('deliveryViewTitle').textContent = `Delivery Details — ${delivery.id}`;

        const history = delivery.history.map((entry) => `
            <li>
                <strong>${escapeHtml(entry.status)}</strong>
                — ${escapeHtml(entry.note)}
                <small>
                    ${escapeHtml(formatDateTime(entry.date))}
                    · ${escapeHtml(entry.actor)}
                </small>
            </li>
        `).join('');

        const proof = delivery.proof ? `
            <h6 class="delivery-section-title">Proof of Delivery</h6>

            <dl class="delivery-detail-grid">
                <div>
                    <dt>Recipient</dt>
                    <dd>${escapeHtml(delivery.proof.recipient)}</dd>
                </div>
                <div>
                    <dt>Completed At</dt>
                    <dd>${escapeHtml(formatDateTime(delivery.completedAt))}</dd>
                </div>
                <div class="delivery-full">
                    <dt>Delivery Remarks</dt>
                    <dd>${escapeHtml(delivery.proof.remarks)}</dd>
                </div>
            </dl>

            <img
                class="delivery-proof"
                src="${escapeHtml(delivery.proof.url)}"
                alt="Proof of delivery for ${escapeHtml(delivery.id)}"
            >
            <p class="delivery-help">
                ${escapeHtml(delivery.proof.name)}
                · Session preview only; not uploaded.
            </p>
        ` : `
            <h6 class="delivery-section-title">Proof of Delivery</h6>
            <p class="delivery-help">No completed delivery proof recorded.</p>
        `;

        this.el('deliveryViewContent').innerHTML = `
            <dl class="delivery-detail-grid">
                <div>
                    <dt>Delivery ID</dt>
                    <dd>${escapeHtml(delivery.id)}</dd>
                </div>
                <div>
                    <dt>Order ID</dt>
                    <dd>${escapeHtml(delivery.orderId)}</dd>
                </div>
                <div>
                    <dt>Client</dt>
                    <dd>${escapeHtml(delivery.client)}</dd>
                </div>
                <div>
                    <dt>Client Contact</dt>
                    <dd>${escapeHtml(delivery.contact)}</dd>
                </div>
                <div class="delivery-full">
                    <dt>Delivery Address Snapshot</dt>
                    <dd>${escapeHtml(delivery.address)}</dd>
                </div>
                <div>
                    <dt>Assigned Personnel</dt>
                    <dd>${escapeHtml(this.personName(delivery.personId))}</dd>
                </div>
                <div>
                    <dt>Scheduled Delivery</dt>
                    <dd>${escapeHtml(formatDateTime(delivery.schedule))}</dd>
                </div>
                <div>
                    <dt>Delivery Status</dt>
                    <dd>${badge(delivery.status)}</dd>
                </div>
                <div>
                    <dt>Sample Linked Order Status</dt>
                    <dd>${escapeHtml(delivery.orderStatus)}</dd>
                </div>
                <div class="delivery-full">
                    <dt>Inventory Coordination</dt>
                    <dd>${delivery.sampleStockOut
                        ? 'Delivered trigger simulated once. Actual stock deduction is not connected.'
                        : 'No sample Delivered Stock-Out trigger recorded.'}</dd>
                </div>
            </dl>

            ${proof}

            <h6 class="delivery-section-title">Delivery History</h6>
            <ol class="delivery-history">${history}</ol>
        `;

        this.viewModal.show();
    }
}

function initializeDeliveryManagement() {
    const table = document.getElementById('deliveryTable');

    if (!table || DataTable.isDataTable(table)) return;

    new DeliveryManagementUI(table);
}

if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', initializeDeliveryManagement, {
        once: true,
    });
} else {
    initializeDeliveryManagement();
}