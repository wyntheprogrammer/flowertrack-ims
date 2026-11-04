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

const money = (value) =>
    new Intl.NumberFormat('en-PH', {
        style: 'currency',
        currency: 'PHP',
    }).format(value);

function formatDate(value) {
    const [year, month, day] = value.split('-').map(Number);

    return new Intl.DateTimeFormat('en-PH', {
        month: 'short',
        day: '2-digit',
        year: 'numeric',
    }).format(new Date(year, month - 1, day));
}

function localToday() {
    const date = new Date();

    return [
        date.getFullYear(),
        String(date.getMonth() + 1).padStart(2, '0'),
        String(date.getDate()).padStart(2, '0'),
    ].join('-');
}

function initials(name) {
    return name.trim()
        .split(/\s+/)
        .slice(0, 2)
        .map((part) => Array.from(part)[0])
        .join('')
        .toUpperCase();
}

function statusBadge(status) {
    return `
        <span class="client-badge ${status.toLowerCase()}">
            ${escapeHtml(status)}
        </span>
    `;
}

/*
 * UI sample data only.
 * Passwords are never included in these records.
 */
const sampleClients = [
    {
        id: 'CLI-001',
        name: 'Maria Santos',
        email: 'maria@example.com',
        phone: '0917 000 1001',
        address: 'Quezon City, Metro Manila',
        registered: '2026-10-01',
        status: 'Active',
    },
    {
        id: 'CLI-002',
        name: 'Carlo Reyes',
        email: 'carlo@example.com',
        phone: '0917 000 1002',
        address: 'Makati City, Metro Manila',
        registered: '2026-10-05',
        status: 'Active',
    },
    {
        id: 'CLI-003',
        name: 'Angela Cruz',
        email: 'angela@example.com',
        phone: '0917 000 1003',
        address: 'Pasig City, Metro Manila',
        registered: '2026-10-08',
        status: 'Inactive',
    },
    {
        id: 'CLI-004',
        name: 'Daniel Garcia',
        email: 'daniel@example.com',
        phone: '0917 000 1004',
        address: 'Marikina City, Metro Manila',
        registered: '2026-10-12',
        status: 'Active',
    },
    {
        id: 'CLI-005',
        name: 'Isabel Ramos',
        email: 'isabel@example.com',
        phone: '0917 000 1005',
        address: 'Manila, Metro Manila',
        registered: '2026-10-18',
        status: 'Active',
    },
    {
        id: 'CLI-006',
        name: 'Paolo Mendoza',
        email: 'paolo@example.com',
        phone: '0917 000 1006',
        address: 'Antipolo City, Rizal',
        registered: '2026-10-25',
        status: 'Inactive',
    },
];

/*
 * Separate sample order records.
 * Profile totals are derived from these records, never edited manually.
 * Final order/payment definitions will be integrated with the backend.
 */
const sampleOrders = [
    {
        id: 'ORD-001',
        clientId: 'CLI-001',
        date: '2026-10-10',
        status: 'Completed',
        amount: 2500,
    },
    {
        id: 'ORD-002',
        clientId: 'CLI-001',
        date: '2026-10-26',
        status: 'Completed',
        amount: 1800,
    },
    {
        id: 'ORD-003',
        clientId: 'CLI-001',
        date: '2026-11-03',
        status: 'Pending',
        amount: 3200,
    },
    {
        id: 'ORD-004',
        clientId: 'CLI-002',
        date: '2026-10-22',
        status: 'Completed',
        amount: 4200,
    },
    {
        id: 'ORD-005',
        clientId: 'CLI-003',
        date: '2026-10-15',
        status: 'Completed',
        amount: 1500,
    },
    {
        id: 'ORD-006',
        clientId: 'CLI-004',
        date: '2026-11-02',
        status: 'Confirmed',
        amount: 2800,
    },
    {
        id: 'ORD-007',
        clientId: 'CLI-005',
        date: '2026-10-30',
        status: 'Cancelled',
        amount: 1200,
    },
];

class ClientManagementUI {
    constructor(element) {
        this.element = element;
        this.clients = sampleClients.map((client) => ({ ...client }));
        this.orders = sampleOrders;
        this.pendingStatusId = null;

        this.form = this.el('clientForm');
        this.formModal = Modal.getOrCreateInstance(this.el('clientFormModal'));
        this.viewModal = Modal.getOrCreateInstance(this.el('clientViewModal'));
        this.statusModal = Modal.getOrCreateInstance(this.el('clientStatusModal'));

        this.initializeTable();
        this.mountScroller();
        this.bindEvents();
    }

    el(id) {
        return document.getElementById(id);
    }

    initializeTable() {
        this.table = new DataTable(this.element, {
            data: this.clients,
            autoWidth: false,
            pageLength: 5,
            order: [[2, 'desc']],
            layout: {
                topStart: null,
                topEnd: null,
                bottomStart: 'info',
                bottomEnd: 'paging',
            },
            language: {
                emptyTable: 'No clients available.',
                zeroRecords: 'No matching clients found.',
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
                    data: 'name',
                    render: (value, type, row) => {
                        if (type !== 'display') {
                            return `${value} ${row.id}`;
                        }

                        return `
                            <div class="client-identity">
                                <span class="client-avatar" aria-hidden="true">
                                    ${escapeHtml(initials(value))}
                                </span>
                                <div>
                                    <strong>${escapeHtml(value)}</strong>
                                    <small>${escapeHtml(row.id)}</small>
                                </div>
                            </div>
                        `;
                    },
                },
                {
                    data: 'email',
                    render: (value, type, row) => {
                        if (type !== 'display') {
                            return value;
                        }

                        return `
                            <div class="client-contact">
                                ${escapeHtml(value)}
                                <small>${escapeHtml(row.phone)}</small>
                            </div>
                        `;
                    },
                },
                {
                    data: 'registered',
                    searchable: false,
                    render: (value, type) =>
                        type === 'display' ? formatDate(value) : value,
                },
                {
                    data: 'status',
                    searchable: false,
                    render: (value, type) =>
                        type === 'display' ? statusBadge(value) : value,
                },
                {
                    data: null,
                    searchable: false,
                    orderable: false,
                    render: (_value, type, row) => {
                        if (type !== 'display') {
                            return '';
                        }

                        const action = row.status === 'Active'
                            ? 'Deactivate'
                            : 'Activate';

                        const icon = row.status === 'Active'
                            ? 'person-dash'
                            : 'person-check';

                        return `
                            <div class="client-actions">
                                <button
                                    type="button"
                                    class="client-action-btn view"
                                    data-client-action="view"
                                    data-client-id="${escapeHtml(row.id)}"
                                    aria-label="View ${escapeHtml(row.name)}"
                                    title="View client"
                                >
                                    <i class="bi bi-eye" aria-hidden="true"></i>
                                </button>

                                <button
                                    type="button"
                                    class="client-action-btn edit"
                                    data-client-action="edit"
                                    data-client-id="${escapeHtml(row.id)}"
                                    aria-label="Edit ${escapeHtml(row.name)}"
                                    title="Edit client"
                                >
                                    <i class="bi bi-pencil-square" aria-hidden="true"></i>
                                </button>

                                <button
                                    type="button"
                                    class="client-action-btn ${action.toLowerCase()}"
                                    data-client-action="status"
                                    data-client-id="${escapeHtml(row.id)}"
                                    aria-label="${action} ${escapeHtml(row.name)}"
                                    title="${action} account"
                                >
                                    <i class="bi bi-${icon}" aria-hidden="true"></i>
                                </button>
                            </div>
                        `;
                    },
                },
            ],
        });
    }

    mountScroller() {
        const wrapper = document.createElement('div');

        wrapper.className = 'client-table-scroll';
        wrapper.tabIndex = 0;
        wrapper.setAttribute('role', 'region');
        wrapper.setAttribute(
            'aria-label',
            'Clients table. Scroll horizontally to see all columns.',
        );

        this.element.before(wrapper);
        wrapper.append(this.element);
    }

    bindEvents() {
        this.el('addClientBtn').addEventListener('click', () => this.openForm());

        this.el('clientSearch').addEventListener('input', (event) => {
            this.table.search(event.target.value).draw();
        });

        this.el('clientPageLength').addEventListener('change', (event) => {
            this.table.page.len(Number(event.target.value)).draw();
        });

        this.element.addEventListener('click', (event) => {
            const button = event.target.closest('[data-client-action]');

            if (!button) {
                return;
            }

            const client = this.clients.find(
                (item) => item.id === button.dataset.clientId,
            );

            if (!client) {
                return;
            }

            switch (button.dataset.clientAction) {
                case 'view':
                    this.showProfile(client);
                    break;
                case 'edit':
                    this.openForm(client);
                    break;
                case 'status':
                    this.openStatusConfirmation(client);
                    break;
            }
        });

        this.form.addEventListener('submit', (event) => {
            event.preventDefault();
            this.saveClient();
        });

        this.el('confirmClientStatus').addEventListener('click', () => {
            this.confirmStatus();
        });

        this.el('clientFormModal').addEventListener('hidden.bs.modal', () => {
            this.form.reset();
            this.el('clientPassword').value = '';
            this.hideFormError();
        });

        this.el('clientStatusModal').addEventListener('hidden.bs.modal', () => {
            this.pendingStatusId = null;
        });
    }

    refreshTable(resetPaging = true) {
        this.table.clear();
        this.table.rows.add(this.clients);
        this.table.draw(resetPaging);
    }

    nextId() {
        const maximum = this.clients.reduce((highest, client) =>
            Math.max(highest, Number(client.id.split('-')[1]) || 0), 0);

        return `CLI-${String(maximum + 1).padStart(3, '0')}`;
    }

    hideFormError() {
        this.el('clientFormError').hidden = true;
        this.el('clientFormError').textContent = '';
    }

    showFormError(message) {
        this.el('clientFormError').textContent = message;
        this.el('clientFormError').hidden = false;
    }

    openForm(client = null) {
        this.form.reset();
        this.hideFormError();

        const editing = Boolean(client);

        this.el('clientFormTitle').textContent =
            editing ? 'Edit Client' : 'Add New Client';

        this.el('clientEditingId').value = client?.id || '';
        this.el('clientCode').value = client?.id || this.nextId();
        this.el('clientName').value = client?.name || '';
        this.el('clientEmail').value = client?.email || '';
        this.el('clientPhone').value = client?.phone || '';
        this.el('clientAddress').value = client?.address || '';
        this.el('clientStatus').value = client?.status || 'Active';

        this.el('clientPasswordField').hidden = editing;
        this.el('clientPassword').disabled = editing;
        this.el('clientPassword').required = !editing;
        this.el('clientPassword').value = '';
        this.el('clientAccountNote').hidden = editing;

        this.formModal.show();
    }

    saveClient() {
        this.hideFormError();

        if (!this.form.reportValidity()) {
            return;
        }

        const editingId = this.el('clientEditingId').value;
        const existing = this.clients.find((client) => client.id === editingId);

        if (editingId && !existing) {
            this.showFormError('Client record was not found. Reopen the form.');
            return;
        }

        const name = this.el('clientName').value.trim();
        const email = this.el('clientEmail').value.trim().toLowerCase();
        const phone = this.el('clientPhone').value.trim();
        const address = this.el('clientAddress').value.trim();
        const status = this.el('clientStatus').value;

        if (!name || !email || !phone || !address) {
            this.showFormError('Complete all required fields.');
            return;
        }

        if (!['Active', 'Inactive'].includes(status)) {
            this.showFormError('Select a valid account status.');
            return;
        }

        const duplicate = this.clients.some((client) =>
            client.id !== editingId
            && client.email.toLowerCase() === email,
        );

        if (duplicate) {
            this.showFormError('This email address already belongs to another client.');
            this.el('clientEmail').focus();
            return;
        }

        /*
         * Explicit allowlist: the temporary password is never copied,
         * logged, persisted or included in a client record.
         */
        const record = {
            id: existing?.id || this.nextId(),
            name,
            email,
            phone,
            address,
            registered: existing?.registered || localToday(),
            status,
        };

        if (existing) {
            this.clients = this.clients.map((client) =>
                client.id === editingId ? record : client,
            );
        } else {
            this.clients.push(record);
        }

        this.el('clientPassword').value = '';
        this.refreshTable(false);
        this.formModal.hide();
        this.announce(`${record.name} ${existing ? 'updated' : 'added'} in this preview.`);
    }

    openStatusConfirmation(client) {
        this.pendingStatusId = client.id;

        const deactivate = client.status === 'Active';

        this.el('clientStatusTitle').textContent =
            deactivate ? 'Deactivate Client' : 'Activate Client';

        this.el('clientStatusMessage').textContent = deactivate
            ? `Deactivate ${client.name}? In the completed system, inactive clients cannot place new orders. Existing orders and history remain accessible. This preview only changes the displayed status.`
            : `Activate ${client.name}? In the completed system, reactivation restores eligibility to place new orders. This preview only changes the displayed status.`;

        this.el('confirmClientStatus').textContent =
            deactivate ? 'Deactivate' : 'Activate';

        this.statusModal.show();
    }

    confirmStatus() {
        const client = this.clients.find(
            (item) => item.id === this.pendingStatusId,
        );

        if (!client) {
            return;
        }

        client.status = client.status === 'Active' ? 'Inactive' : 'Active';

        this.refreshTable(false);
        this.statusModal.hide();
        this.announce(`${client.name} is now ${client.status.toLowerCase()} in this preview.`);
    }

    announce(message) {
        this.el('clientAnnouncement').textContent = message;
    }

    showProfile(client) {
        const orders = this.orders
            .filter((order) => order.clientId === client.id)
            .slice()
            .sort((a, b) => b.date.localeCompare(a.date));

        const completed = orders.filter((order) => order.status === 'Completed');
        const ongoing = orders.filter((order) =>
            ['Pending', 'Confirmed', 'Processing', 'Ready for Delivery', 'Out for Delivery', 'Ready for Pickup']
                .includes(order.status),
        );

        // Explicit prototype definition: completed sample order amounts.
        const purchases = completed.reduce((sum, order) => sum + order.amount, 0);

        const history = orders.length
            ? orders.map((order) => `
                <tr>
                    <td>${escapeHtml(order.id)}</td>
                    <td>${formatDate(order.date)}</td>
                    <td>
                        <span class="client-badge order">
                            ${escapeHtml(order.status)}
                        </span>
                    </td>
                    <td>${money(order.amount)}</td>
                </tr>
            `).join('')
            : `
                <tr>
                    <td colspan="4" class="client-history-empty">
                        No sample orders for this client.
                    </td>
                </tr>
            `;

        this.el('clientViewTitle').textContent = `Client Details — ${client.id}`;

        this.el('clientViewContent').innerHTML = `
            <div class="client-profile-heading">
                <span class="client-avatar" aria-hidden="true">
                    ${escapeHtml(initials(client.name))}
                </span>
                <div>
                    <h3>${escapeHtml(client.name)}</h3>
                    <p>${escapeHtml(client.id)}</p>
                </div>
                ${statusBadge(client.status)}
            </div>

            <dl class="client-profile-grid">
                <div>
                    <dt>Email Address</dt>
                    <dd>${escapeHtml(client.email)}</dd>
                </div>
                <div>
                    <dt>Contact Number</dt>
                    <dd>${escapeHtml(client.phone)}</dd>
                </div>
                <div>
                    <dt>Registration Date</dt>
                    <dd>${formatDate(client.registered)}</dd>
                </div>
                <div>
                    <dt>Address</dt>
                    <dd>${escapeHtml(client.address)}</dd>
                </div>
            </dl>

            <h4 class="client-section-title">Purchase Summary</h4>

            <div class="client-purchase-summary">
                <div>
                    <span>Total Orders</span>
                    <strong>${orders.length}</strong>
                </div>
                <div>
                    <span>Completed Purchases</span>
                    <strong>${money(purchases)}</strong>
                </div>
                <div>
                    <span>Completed Orders</span>
                    <strong>${completed.length}</strong>
                </div>
                <div>
                    <span>Pending / Ongoing</span>
                    <strong>${ongoing.length}</strong>
                </div>
            </div>

            <p class="client-form-note">
                Sample order records only. Completed Purchases is the sum of
                completed sample order amounts, not a payment balance.
                Backend integration will use authoritative order and payment rules.
            </p>

            <h4 class="client-section-title">Order History</h4>

            <div
                class="client-history-scroll"
                tabindex="0"
                role="region"
                aria-label="Client order history"
            >
                <table class="client-history">
                    <thead>
                        <tr>
                            <th scope="col">Order ID</th>
                            <th scope="col">Date</th>
                            <th scope="col">Status</th>
                            <th scope="col">Amount</th>
                        </tr>
                    </thead>
                    <tbody>${history}</tbody>
                </table>
            </div>
        `;

        this.viewModal.show();
    }
}

function initializeClientManagement() {
    const table = document.getElementById('clientTable');

    if (!table || DataTable.isDataTable(table)) {
        return;
    }

    new ClientManagementUI(table);
}

if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', initializeClientManagement, {
        once: true,
    });
} else {
    initializeClientManagement();
}
