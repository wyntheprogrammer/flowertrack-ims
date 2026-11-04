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

// Money is stored as integer centavos.
const money = (centavos) =>
    new Intl.NumberFormat('en-PH', {
        style: 'currency',
        currency: 'PHP',
    }).format(centavos / 100);

function parseMoney(value) {
    const text = String(value).trim();

    if (!/^\d{1,7}(\.\d{1,2})?$/.test(text)) return null;

    const [whole, decimal = ''] = text.split('.');
    const result = Number(whole) * 100 + Number(decimal.padEnd(2, '0'));

    return Number.isSafeInteger(result) && result > 0 ? result : null;
}

function dateKey(value) {
    const date = new Date(value);

    return [
        date.getFullYear(),
        String(date.getMonth() + 1).padStart(2, '0'),
        String(date.getDate()).padStart(2, '0'),
    ].join('-');
}

function dateTime(value) {
    return new Intl.DateTimeFormat('en-PH', {
        dateStyle: 'medium',
        timeStyle: 'short',
    }).format(new Date(value));
}

function daysAgo(days) {
    const date = new Date();
    date.setDate(date.getDate() - days);
    date.setHours(10, 0, 0, 0);
    return date.toISOString();
}

function badge(value) {
    return `
        <span class="sales-badge ${value.toLowerCase().replaceAll(' ', '-')}">
            ${escapeHtml(value)}
        </span>
    `;
}

function cell(primary, secondary = '') {
    return `
        <div class="sales-cell">
            <strong>${escapeHtml(primary)}</strong>
            ${secondary ? `<small>${escapeHtml(secondary)}</small>` : ''}
        </div>
    `;
}

/*
 * Standalone samples: no backend, payment gateway or live Orders integration.
 * Price snapshots and completed-order sales stay independent of current prices.
 */
const initialPrices = [
    { id: 'FLW-001', name: 'Red Rose', category: 'Roses', price: 6000, updated: daysAgo(3) },
    { id: 'FLW-002', name: 'White Rose', category: 'Roses', price: 6500, updated: daysAgo(3) },
    { id: 'FLW-003', name: 'Sunflower', category: 'Sunflowers', price: 8500, updated: daysAgo(2) },
    { id: 'FLW-004', name: 'Pink Tulip', category: 'Tulips', price: 9500, updated: daysAgo(2) },
    { id: 'FLW-005', name: 'White Lily', category: 'Lilies', price: 11000, updated: daysAgo(1) },
    { id: 'FLW-006', name: 'Pink Carnation', category: 'Carnations', price: 4500, updated: daysAgo(1) },
    { id: 'FLW-009', name: 'Classic Rose Bouquet', category: 'Roses', price: 120000, updated: daysAgo(0), itemType: 'Bouquet' },
    { id: 'FLW-010', name: 'Mixed Flower Bouquet', category: 'Mixed Flowers', price: 150000, updated: daysAgo(0), itemType: 'Bouquet' },
].map((item) => ({ ...item, itemType: item.itemType || 'Stem', baseUnit: item.itemType || 'Stem' }));

const initialOrders = [
    {
        id: 'ORD-001',
        client: 'Maria Santos',
        status: 'Completed',
        completedAt: daysAgo(0),
        deliveryFee: 15000,
        items: [{ flowerId: 'FLW-001', name: 'Red Rose', quantity: 20, price: 6000 }],
    },
    {
        id: 'ORD-002',
        client: 'Carlo Reyes',
        status: 'Completed',
        completedAt: daysAgo(1),
        deliveryFee: 0,
        items: [{ flowerId: 'FLW-002', name: 'White Rose', quantity: 10, price: 6500 }],
    },
    {
        id: 'ORD-003',
        client: 'Daniel Garcia',
        status: 'Confirmed',
        completedAt: null,
        deliveryFee: 15000,
        items: [{ flowerId: 'FLW-003', name: 'Sunflower', quantity: 10, price: 8500 }],
    },
    {
        id: 'ORD-004',
        client: 'Isabel Ramos',
        status: 'Processing',
        completedAt: null,
        deliveryFee: 0,
        items: [{ flowerId: 'FLW-004', name: 'Pink Tulip', quantity: 10, price: 9500 }],
    },
    {
        id: 'ORD-005',
        client: 'Paolo Mendoza',
        status: 'Cancelled',
        completedAt: null,
        deliveryFee: 0,
        items: [{ flowerId: 'FLW-005', name: 'White Lily', quantity: 5, price: 11000 }],
    },
    {
        id: 'ORD-006',
        client: 'Maria Santos',
        status: 'Pending',
        completedAt: null,
        deliveryFee: 0,
        items: [{ flowerId: 'FLW-006', name: 'Pink Carnation', quantity: 20, price: 4500 }],
    },
];

const initialPayments = [
    {
        id: 'PAY-001',
        orderId: 'ORD-001',
        method: 'Cash',
        reference: '',
        amount: 135000,
        status: 'Confirmed',
        date: daysAgo(0),
        confirmedAt: daysAgo(0),
    },
    {
        id: 'PAY-002',
        orderId: 'ORD-002',
        method: 'Bank Transfer',
        reference: 'SAMPLE-BANK-001',
        amount: 65000,
        status: 'Confirmed',
        date: daysAgo(1),
        confirmedAt: daysAgo(1),
    },
    {
        id: 'PAY-003',
        orderId: 'ORD-003',
        method: 'E-wallet',
        reference: 'SAMPLE-WALLET-001',
        amount: 100000,
        status: 'Confirmed',
        date: daysAgo(0),
        confirmedAt: daysAgo(0),
    },
    {
        id: 'PAY-004',
        orderId: 'ORD-004',
        method: 'Bank Transfer',
        reference: 'SAMPLE-BANK-002',
        amount: 95000,
        status: 'Pending',
        date: daysAgo(0),
        confirmedAt: null,
    },
];

initialOrders.push({
    id: 'ORD-007', client: 'Maria Santos', status: 'Completed', completedAt: daysAgo(0),
    deliveryFee: 15000,
    items: [
        { flowerId: 'FLW-001', name: 'Red Rose', quantity: 5, price: 6000 },
        { flowerId: 'FLW-009', name: 'Classic Rose Bouquet', quantity: 1, price: 120000 },
    ],
});
initialOrders.forEach((order) => {
    order.items = order.items.map((item) => {
        const product = initialPrices.find((entry) => entry.id === item.flowerId);
        return { ...item, itemType: product.itemType, baseUnit: product.baseUnit };
    });
});
initialPayments.push({ id: 'PAY-005', orderId: 'ORD-007', method: 'Cash', reference: '',
    amount: 165000, status: 'Confirmed', date: daysAgo(0), confirmedAt: daysAgo(0) });

class SalesPaymentsUI {
    constructor() {
        this.prices = structuredClone(initialPrices);
        this.orders = structuredClone(initialOrders);
        this.payments = structuredClone(initialPayments);
        this.refunds = [];
        this.history = [];
        this.sales = [];
        this.tables = {};
        this.action = null;

        this.actionModal = Modal.getOrCreateInstance(this.el('salesActionModal'));
        this.viewModal = Modal.getOrCreateInstance(this.el('salesViewModal'));

        this.syncSales();
        this.initializeTables();
        this.bindEvents();
        this.renderSummary();
    }

    el(id) {
        return document.getElementById(id);
    }

    order(id) {
        return this.orders.find((order) => order.id === id);
    }

    orderTotal(order) {
        return order.items.reduce(
            (sum, item) => sum + item.quantity * item.price,
            0,
        ) + order.deliveryFee;
    }

    confirmedPaid(orderId) {
        return this.payments
            .filter((payment) =>
                payment.orderId === orderId && payment.status === 'Confirmed',
            )
            .reduce((sum, payment) => sum + payment.amount, 0);
    }

    refunded(orderId) {
        return this.refunds
            .filter((refund) => refund.orderId === orderId)
            .reduce((sum, refund) => sum + refund.amount, 0);
    }

    pendingPaid(orderId) {
        return this.payments
            .filter((payment) =>
                payment.orderId === orderId && payment.status === 'Pending',
            )
            .reduce((sum, payment) => sum + payment.amount, 0);
    }

    paymentStatus(order) {
        if (this.refunded(order.id) > 0) return 'Refunded';

        const paid = this.confirmedPaid(order.id);

        if (!paid) return 'Unpaid';
        return paid === this.orderTotal(order) ? 'Paid' : 'Unpaid';
    }

    eligibleForPayment(order) {
        return Boolean(order)
            && order.status !== 'Cancelled'
            && this.refunded(order.id) === 0
            && !this.payments.some((payment) => payment.orderId === order.id);
    }

    nextId(records, prefix) {
        const maximum = records.reduce(
            (highest, record) =>
                Math.max(highest, Number(record.id.split('-')[1]) || 0),
            0,
        );

        return `${prefix}-${String(maximum + 1).padStart(3, '0')}`;
    }

    syncSales() {
        /*
         * Exactly one sample Sales Record per completed order.
         * Existing records are preserved instead of regenerated.
         */
        this.orders.filter((order) => order.status === 'Completed').forEach((order) => {
            if (this.sales.some((sale) => sale.orderId === order.id)) return;

            this.sales.push({
                id: this.nextId(this.sales, 'SAL'),
                orderId: order.id,
                client: order.client,
                date: order.completedAt,
                items: structuredClone(order.items),
                deliveryFee: order.deliveryFee,
                gross: this.orderTotal(order),
            });
        });
    }

    icon(action, id, icon, label, extra = '') {
        return `
            <button
                type="button"
                class="sales-icon-btn ${extra}"
                data-finance-action="${action}"
                data-record-id="${escapeHtml(id)}"
                title="${escapeHtml(label)}"
                aria-label="${escapeHtml(label)}"
            >
                <i class="bi bi-${icon}" aria-hidden="true"></i>
            </button>
        `;
    }

    initializeTables() {
        const moneyColumn = (getter) => ({
            data: null,
            className: 'sales-money',
            render: (_value, type, row) => {
                const amount = getter(row);
                return type === 'display' ? money(amount) : amount;
            },
        });

        this.tables.prices = this.makeTable('pricesTable', this.prices, [
            {
                data: 'name',
                width: '30%',
                render: (value, type, row) =>
                    type === 'display' ? cell(value, `${row.id} · ${row.baseUnit}`) : `${value} ${row.id} ${row.baseUnit}`,
            },
            {
                data: 'category',
                width: '20%',
                render: (value, type) => type === 'display' ? escapeHtml(value) : value,
            },
            { ...moneyColumn((row) => row.price), width: '18%' },
            {
                data: 'updated',
                width: '22%',
                render: (value, type) => type === 'display' ? dateTime(value) : value,
            },
            {
                data: null,
                width: '10%',
                className: 'sales-center',
                orderable: false,
                searchable: false,
                render: (_value, type, row) =>
                    type === 'display'
                        ? this.icon('price', row.id, 'pencil-square', `Edit price ${row.name}`, 'edit')
                        : '',
            },
        ]);

        this.tables.records = this.makeTable('recordsTable', this.sales, [
            {
                data: 'id',
                render: (value, type, row) =>
                    type === 'display' ? cell(value, row.orderId) : `${value} ${row.orderId}`,
            },
            {
                data: 'client',
                render: (value, type) => type === 'display' ? escapeHtml(value) : value,
            },
            {
                data: 'date',
                render: (value, type) => type === 'display' ? dateTime(value) : value,
            },
            moneyColumn((row) => row.gross),
            moneyColumn((row) => this.refunded(row.orderId)),
            moneyColumn((row) => row.gross - this.refunded(row.orderId)),
            {
                data: null,
                className: 'sales-center',
                orderable: false,
                searchable: false,
                render: (_value, type, row) =>
                    type === 'display'
                        ? this.icon('view', row.orderId, 'eye', `View ${row.id}`)
                        : '',
            },
        ], [[2, 'desc']]);

        this.tables.payments = this.makeTable('paymentsTable', this.payments, [
            {
                data: 'id',
                render: (value, type, row) =>
                    type === 'display' ? cell(value, row.orderId) : `${value} ${row.orderId}`,
            },
            {
                data: null,
                render: (_value, type, row) => {
                    const client = this.order(row.orderId)?.client || '';
                    return type === 'display' ? escapeHtml(client) : client;
                },
            },
            {
                data: 'date',
                render: (value, type) => type === 'display' ? dateTime(value) : value,
            },
            {
                data: 'method',
                render: (value, type, row) =>
                    type === 'display'
                        ? cell(value, row.reference || 'No reference required')
                        : `${value} ${row.reference}`,
            },
            moneyColumn((row) => row.amount),
            {
                data: 'status',
                className: 'sales-center',
                render: (value, type) => type === 'display' ? badge(value) : value,
            },
            {
                data: null,
                className: 'sales-center',
                orderable: false,
                searchable: false,
                render: (_value, type, row) => {
                    if (type !== 'display') return '';

                    return `
                        <div class="sales-actions">
                            ${this.icon('view', row.orderId, 'eye', `View ${row.id}`)}
                            ${row.status === 'Pending'
                                ? this.icon('confirm', row.id, 'check-lg', `Confirm ${row.id}`, 'confirm')
                                : ''}
                            ${row.status === 'Confirmed'
                                && this.paymentRefundable(row) > 0
                                ? this.icon('refund', row.id, 'arrow-counterclockwise', `Refund ${row.id}`, 'refund')
                                : ''}
                        </div>
                    `;
                },
            },
        ], [[2, 'desc']]);
    }

    makeTable(id, data, columns, order = [[0, 'asc']]) {
        const tableElement = this.el(id);

        const table = new DataTable(tableElement, {
            data,
            columns,
            order,
            autoWidth: false,
            pageLength: 5,
            layout: {
                topStart: null,
                topEnd: null,
                bottomStart: 'info',
                bottomEnd: 'paging',
            },
            language: {
                emptyTable: 'No records available.',
                zeroRecords: 'No matching records found.',
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
        });

        const wrapper = document.createElement('div');
        wrapper.className = 'sales-table-scroll';
        wrapper.tabIndex = 0;
        wrapper.setAttribute('role', 'region');
        wrapper.setAttribute('aria-label', 'Financial table. Scroll horizontally for all columns.');

        tableElement.before(wrapper);
        wrapper.append(tableElement);

        return table;
    }

    bindEvents() {
        document.querySelectorAll('[data-sales-tab]').forEach((button) => {
            button.addEventListener('click', () => this.switchTab(button.dataset.salesTab));

            button.addEventListener('keydown', (event) => {
                const tabs = [...document.querySelectorAll('[data-sales-tab]')];
                const index = tabs.indexOf(button);
                let next = index;

                if (event.key === 'ArrowRight') next = (index + 1) % tabs.length;
                else if (event.key === 'ArrowLeft') next = (index - 1 + tabs.length) % tabs.length;
                else if (event.key === 'Home') next = 0;
                else if (event.key === 'End') next = tabs.length - 1;
                else return;

                event.preventDefault();
                tabs[next].focus();
                this.switchTab(tabs[next].dataset.salesTab);
            });
        });

        document.querySelectorAll('[data-search]').forEach((input) => {
            input.addEventListener('input', () => {
                this.tables[input.dataset.search].search(input.value).draw();
            });
        });

        document.querySelectorAll('[data-length]').forEach((select) => {
            select.addEventListener('change', () => {
                this.tables[select.dataset.length].page.len(Number(select.value)).draw();
            });
        });

        document.querySelector('.sales-page').addEventListener('click', (event) => {
            const button = event.target.closest('[data-finance-action]');
            if (!button) return;

            if (button.dataset.financeAction === 'view') {
                this.showOrder(button.dataset.recordId);
            } else {
                this.openAction(button.dataset.financeAction, button.dataset.recordId);
            }
        });

        this.el('recordPaymentBtn').addEventListener('click', () => this.openAction('payment'));

        this.el('salesActionForm').addEventListener('submit', (event) => {
            event.preventDefault();
            this.saveAction();
        });

        this.el('salesActionFields').addEventListener('change', (event) => {
            if (event.target.id === 'financeMethod') this.syncReference();
            if (event.target.id === 'financeOrder') this.updateOrderHelp();
        });

        this.el('salesActionModal').addEventListener('hidden.bs.modal', () => {
            this.action = null;
            this.el('salesActionFields').innerHTML = '';
        });
    }

    switchTab(key) {
        ['prices', 'records', 'payments'].forEach((name) => {
            const selected = key === name;
            const tab = this.el(`${name}Tab`);

            tab.classList.toggle('active', selected);
            tab.setAttribute('aria-selected', String(selected));
            tab.tabIndex = selected ? 0 : -1;
            this.el(`${name}Panel`).hidden = !selected;
        });

        this.tables[key].columns.adjust();
    }

    paymentRefundable(payment) {
        const refunded = this.refunds
            .filter((refund) => refund.paymentId === payment.id)
            .reduce((sum, refund) => sum + refund.amount, 0);

        return payment.amount - refunded;
    }

    error(message = '') {
        this.el('salesActionError').textContent = message;
        this.el('salesActionError').hidden = !message;
    }

    field(label, content) {
        return `<div class="sales-field">${label}${content}</div>`;
    }

    openAction(type, id = '') {
        this.action = { type, id };
        this.error();

        let title = '';
        let fields = '';

        if (type === 'price') {
            const flower = this.prices.find((item) => item.id === id);
            if (!flower) return;

            title = `Edit Price — ${flower.name}`;

            fields = `
                <p class="sales-help">
                    Changes apply to future orders. Existing sample order
                    and Sales Record prices remain unchanged.
                </p>
                ${this.field(
                    `<label for="financeAmount">Selling Price / ${escapeHtml(flower.baseUnit)} *</label>`,
                    `<input type="number" id="financeAmount" min="0.01" max="9999999.99"
                        step="0.01" value="${(flower.price / 100).toFixed(2)}" required>`,
                )}
            `;
        }

        if (type === 'payment') {
            const eligible = this.orders.filter((order) => this.eligibleForPayment(order));

            if (!eligible.length) {
                this.announce('No unpaid sample orders are eligible for a full payment.');
                return;
            }

            title = 'Record Payment';

            fields = `
                ${this.field(
                    '<label for="financeOrder">Order *</label>',
                    `<select id="financeOrder" required>
                        ${eligible.map((order) => `
                            <option value="${order.id}">
                                ${escapeHtml(order.id)} · ${escapeHtml(order.client)}
                            </option>
                        `).join('')}
                    </select>`,
                )}
                <p id="financeOrderHelp" class="sales-help"></p>

                ${this.field(
                    '<label for="financeMethod">Payment Method *</label>',
                    `<select id="financeMethod" required>
                        <option value="Cash">Cash</option>
                        <option value="Bank Transfer">Bank Transfer</option>
                        <option value="E-wallet">E-wallet</option>
                    </select>`,
                )}

                ${this.field(
                    '<label for="financeAmount">Full Order Amount *</label>',
                    '<input type="number" id="financeAmount" step="0.01" readonly required>',
                )}

                <div class="sales-field" id="financeReferenceField" hidden>
                    <label for="financeReference">Reference Number *</label>
                    <input type="text" id="financeReference" maxlength="100">
                </div>

                <p class="sales-help">
                    One full payment per order. The amount is fixed to the complete order total.
                    Recording creates Pending verification; only confirmation marks the order Paid.
                </p>
            `;
        }

        if (type === 'confirm') {
            const payment = this.payments.find((item) => item.id === id);
            if (!payment || payment.status !== 'Pending') return;

            title = `Confirm ${payment.id}`;

            fields = `
                <p class="sales-help">
                    Confirm receipt of ${money(payment.amount)}
                    via ${escapeHtml(payment.method)}
                    for ${escapeHtml(payment.orderId)}?
                    This preview does not verify a bank or wallet transaction.
                </p>
                ${this.field(
                    '<label for="financeReason">Verification Remarks *</label>',
                    '<textarea id="financeReason" rows="3" maxlength="500" required></textarea>',
                )}
            `;
        }

        if (type === 'refund') {
            const payment = this.payments.find((item) => item.id === id);

            if (!payment || payment.status !== 'Confirmed'
                || this.paymentRefundable(payment) <= 0) return;

            title = `Record Refund — ${payment.id}`;

            fields = `
                <p class="sales-help">
                    Refundable from this payment:
                    <strong>${money(this.paymentRefundable(payment))}</strong>.
                    Saving records an Admin-approved sample refund.
                    Original payment history is retained.
                </p>
                ${this.field(
                    '<label for="financeAmount">Refund Amount *</label>',
                    `<input type="number" id="financeAmount" min="0.01"
                        max="${(this.paymentRefundable(payment) / 100).toFixed(2)}"
                        step="0.01" required>`,
                )}
                ${this.field(
                    '<label for="financeReason">Refund Reason *</label>',
                    '<textarea id="financeReason" rows="3" maxlength="500" required></textarea>',
                )}
                <p class="sales-help">
                    This records the refund only; it does not transfer money.
                    Further collection on refunded sample orders is blocked
                    until the detailed adjustment workflow is connected.
                </p>
            `;
        }

        this.el('salesActionTitle').textContent = title;
        this.el('salesActionFields').innerHTML = fields;

        this.el('salesActionSubmit').textContent = {
            price: 'Save Price',
            payment: 'Record Payment',
            confirm: 'Confirm Payment',
            refund: 'Approve & Record Refund',
        }[type];

        if (type === 'payment') {
            this.syncReference();
            this.updateOrderHelp();
        }

        this.actionModal.show();
    }

    syncReference() {
        const online = this.el('financeMethod').value !== 'Cash';

        this.el('financeReferenceField').hidden = !online;
        this.el('financeReference').required = online;
        this.el('financeReference').disabled = !online;
    }

    updateOrderHelp() {
        const order = this.order(this.el('financeOrder').value);
        if (!order) return;

        this.el('financeAmount').value = (this.orderTotal(order) / 100).toFixed(2);
        this.el('financeOrderHelp').textContent =
            `Full payment required: ${money(this.orderTotal(order))}. No installments or additional payments.`;
    }

    saveAction() {
        this.error();

        if (!this.action || !this.el('salesActionForm').reportValidity()) return;

        const { type, id } = this.action;
        const now = new Date().toISOString();
        const amount = this.el('financeAmount')
            ? parseMoney(this.el('financeAmount').value)
            : null;
        const reason = this.el('financeReason')?.value.trim() || '';

        if (['price', 'payment', 'refund'].includes(type) && amount === null) {
            this.error('Enter a positive amount with no more than two decimal places.');
            return;
        }

        if (['confirm', 'refund'].includes(type) && !reason) {
            this.error('Enter the required remarks or reason.');
            return;
        }

        let message = '';

        if (type === 'price') {
            const flower = this.prices.find((item) => item.id === id);
            if (!flower) return;

            const previous = flower.price;
            flower.price = amount;
            flower.updated = now;

            this.log('', `Price ${flower.id}: ${money(previous)} → ${money(amount)}.`);
            message = `${flower.name} price updated. Existing order snapshots retained.`;
        }

        if (type === 'payment') {
            const order = this.order(this.el('financeOrder').value);
            const method = this.el('financeMethod').value;
            const reference = method === 'Cash'
                ? ''
                : this.el('financeReference').value.trim();

            if (!this.eligibleForPayment(order)) {
                this.error('This order is not eligible for a new payment in this preview.');
                return;
            }

            if (!['Cash', 'Bank Transfer', 'E-wallet'].includes(method)) {
                this.error('Select a valid payment method.');
                return;
            }

            if (method !== 'Cash' && !reference) {
                this.error('Enter the transfer or E-wallet reference number.');
                return;
            }

            if (reference && this.payments.some((payment) =>
                payment.method === method
                && payment.reference.toLowerCase() === reference.toLowerCase(),
            )) {
                this.error('That reference is already recorded for this method.');
                return;
            }

            if (amount !== this.orderTotal(order)) {
                this.error(`Pay the full order total of ${money(this.orderTotal(order))} in one transaction.`);
                return;
            }

            const payment = {
                id: this.nextId(this.payments, 'PAY'),
                orderId: order.id,
                method,
                reference,
                amount,
                status: 'Pending',
                date: now,
                confirmedAt: null,
            };

            this.payments.push(payment);
            this.log(order.id, `${payment.id}: ${money(amount)} recorded as Pending via ${method}.`);
            message = `${payment.id} recorded. Confirm it after verification.`;
        }

        if (type === 'confirm') {
            const payment = this.payments.find((item) => item.id === id);
            if (!payment || payment.status !== 'Pending') return;

            const order = this.order(payment.orderId);

            if (!order || order.status === 'Cancelled' || this.refunded(order.id) > 0) {
                this.error('This order is no longer eligible for payment confirmation.');
                return;
            }

            if (payment.amount !== this.orderTotal(order)
                || this.payments.some((item) => item.orderId === order.id && item.id !== payment.id)) {
                this.error('Exactly one payment matching the full order total is required.');
                return;
            }

            payment.status = 'Confirmed';
            payment.confirmedAt = now;

            this.log(order.id, `${payment.id} confirmed. ${reason}`);
            message = `${payment.id} confirmed.`;
        }

        if (type === 'refund') {
            const payment = this.payments.find((item) => item.id === id);

            if (!payment || payment.status !== 'Confirmed') return;

            if (amount > this.paymentRefundable(payment)) {
                this.error('Refund exceeds the remaining refundable payment amount.');
                return;
            }

            const refund = {
                id: this.nextId(this.refunds, 'REF'),
                paymentId: payment.id,
                orderId: payment.orderId,
                amount,
                reason,
                date: now,
                actor: 'Administrator (preview)',
            };

            this.refunds.push(refund);
            this.log(payment.orderId, `${refund.id}: ${money(amount)} refunded against ${payment.id}. ${reason}`);
            message = `${refund.id} recorded. Original payment retained.`;
        }

        this.refresh();
        this.actionModal.hide();
        this.announce(message);
    }

    log(orderId, note) {
        this.history.push({
            orderId,
            note,
            date: new Date().toISOString(),
            actor: 'Administrator (preview)',
        });
    }

    announce(message) {
        this.el('salesAnnouncement').textContent = message;
    }

    refresh() {
        [
            ['prices', this.prices],
            ['records', this.sales],
            ['payments', this.payments],
        ].forEach(([key, data]) => {
            this.tables[key].clear();
            this.tables[key].rows.add(data);
            this.tables[key].draw(false);
        });

        this.renderSummary();
    }

    renderSummary() {
        const net = (sale) => sale.gross - this.refunded(sale.orderId);
        const today = dateKey(new Date());
        const month = today.slice(0, 7);

        this.el('salesNet').textContent =
            money(this.sales.reduce((sum, sale) => sum + net(sale), 0));

        this.el('salesCount').textContent = this.sales.length;

        this.el('salesToday').textContent = money(
            this.sales.filter((sale) => dateKey(sale.date) === today)
                .reduce((sum, sale) => sum + net(sale), 0),
        );

        this.el('salesMonth').textContent = money(
            this.sales.filter((sale) => dateKey(sale.date).slice(0, 7) === month)
                .reduce((sum, sale) => sum + net(sale), 0),
        );

        const quantities = new Map();
        const dates = new Map();

        this.sales.forEach((sale) => {
            sale.items.forEach((item) => {
                const key = `${item.baseUnit}|${item.name}`;
                quantities.set(key, (quantities.get(key) || 0) + item.quantity);
            });

            const key = dateKey(sale.date);
            dates.set(key, (dates.get(key) || 0) + net(sale));
        });

        const trend = [...dates].sort((a, b) => a[0].localeCompare(b[0]));
        // Rank quantities within each unit; do not compare stems to bouquets.
        this.el('salesTopFlowers').innerHTML = ['Stem', 'Bouquet'].map((unit) => {
            const values = [...quantities].filter(([key]) => key.startsWith(`${unit}|`))
                .sort((a, b) => b[1] - a[1]).slice(0, 5);
            const maximum = Math.max(1, ...values.map(([, value]) => value));
            return `<h3 class="sales-section-title">${unit === 'Stem' ? 'Loose Stems' : 'Finished Bouquets'}</h3>`
                + (values.length ? values.map(([key, value]) => `
                    <div class="sales-bar-row">
                        <div class="sales-bar-label"><span>${escapeHtml(key.split('|').slice(1).join('|'))}</span>
                        <strong>${value} ${unit === 'Stem' ? 'stems' : 'bouquets'}</strong></div>
                        <div class="sales-bar-track" aria-hidden="true"><div class="sales-bar-fill" style="width:${value / maximum * 100}%"></div></div>
                    </div>`).join('') : '<p class="sales-help">No completed sales for this unit.</p>');
        }).join('');
        this.renderBars('salesTrend', trend, money);
    }

    renderBars(id, values, formatter) {
        const maximum = Math.max(1, ...values.map(([, value]) => value));

        this.el(id).innerHTML = values.length ? values.map(([label, value]) => `
            <div class="sales-bar-row">
                <div class="sales-bar-label">
                    <span>${escapeHtml(label)}</span>
                    <strong>${escapeHtml(formatter(value))}</strong>
                </div>
                <div class="sales-bar-track" aria-hidden="true">
                    <div class="sales-bar-fill" style="width:${Math.max(0, value / maximum * 100)}%"></div>
                </div>
            </div>
        `).join('') : '<p class="sales-help">No completed sales yet.</p>';
    }

    showOrder(orderId) {
        const order = this.order(orderId);
        if (!order) return;

        const payments = this.payments.filter((payment) => payment.orderId === orderId);
        const refunds = this.refunds.filter((refund) => refund.orderId === orderId);
        const history = this.history.filter((entry) => entry.orderId === orderId);
        const sale = this.sales.find((record) => record.orderId === orderId);
        const paid = this.confirmedPaid(orderId);
        const refunded = this.refunded(orderId);

        this.el('salesViewTitle').textContent = `Financial Details — ${orderId}`;

        this.el('salesViewContent').innerHTML = `
            <dl class="sales-detail-grid">
                <div><dt>Client</dt><dd>${escapeHtml(order.client)}</dd></div>
                <div><dt>Order Status</dt><dd>${badge(order.status)}</dd></div>
                <div><dt>Payment Status</dt><dd>${badge(this.paymentStatus(order))}</dd></div>
                <div><dt>Sales Record</dt><dd>${escapeHtml(sale?.id || 'Not recognized: order is not Completed')}</dd></div>
                <div><dt>Order Total</dt><dd>${money(this.orderTotal(order))}</dd></div>
                <div><dt>Confirmed Payments</dt><dd>${money(paid)}</dd></div>
                <div><dt>Refunded</dt><dd>${money(refunded)}</dd></div>
                <div><dt>Net Collected</dt><dd>${money(paid - refunded)}</dd></div>
                <div><dt>Outstanding Before Refunds</dt><dd>${money(Math.max(0, this.orderTotal(order) - paid))}</dd></div>
                <div><dt>Pending Payments</dt><dd>${money(this.pendingPaid(orderId))}</dd></div>
            </dl>

            <p class="sales-help">
                Refunds are shown separately and do not automatically reopen collection
                in this preview. Net sales are recognized from Completed orders,
                independently of confirmed payments.
            </p>

            <h6 class="sales-section-title">Order Price Snapshots</h6>
            <div class="sales-detail-scroll" tabindex="0" role="region" aria-label="Order items">
                <table class="sales-detail-table">
                    <thead>
                        <tr>
                            <th scope="col">Flower</th>
                            <th scope="col">Quantity / Unit</th>
                            <th scope="col">Unit Price</th>
                            <th scope="col">Subtotal</th>
                        </tr>
                    </thead>
                    <tbody>
                        ${order.items.map((item) => `
                            <tr>
                                <td>${escapeHtml(item.name)}</td>
                                <td>${item.quantity} ${escapeHtml(item.baseUnit)}</td>
                                <td class="sales-money">${money(item.price)}</td>
                                <td class="sales-money">${money(item.price * item.quantity)}</td>
                            </tr>
                        `).join('')}
                        <tr>
                            <td colspan="3">Delivery Fee</td>
                            <td class="sales-money">${money(order.deliveryFee)}</td>
                        </tr>
                    </tbody>
                </table>
            </div>

            <h6 class="sales-section-title">Payment History</h6>
            <div class="sales-detail-scroll" tabindex="0" role="region" aria-label="Payment history">
                <table class="sales-detail-table">
                    <thead>
                        <tr>
                            <th scope="col">Payment</th>
                            <th scope="col">Date</th>
                            <th scope="col">Method / Reference</th>
                            <th scope="col">Amount</th>
                            <th scope="col">Status</th>
                        </tr>
                    </thead>
                    <tbody>
                        ${payments.length ? payments.map((payment) => `
                            <tr>
                                <td>${escapeHtml(payment.id)}</td>
                                <td>${dateTime(payment.date)}</td>
                                <td>${cell(payment.method, payment.reference)}</td>
                                <td class="sales-money">${money(payment.amount)}</td>
                                <td>${badge(payment.status)}</td>
                            </tr>
                        `).join('') : '<tr><td colspan="5">No payments recorded.</td></tr>'}
                    </tbody>
                </table>
            </div>

            <h6 class="sales-section-title">Refund History</h6>
            <div class="sales-detail-scroll" tabindex="0" role="region" aria-label="Refund history">
                <table class="sales-detail-table">
                    <thead>
                        <tr>
                            <th scope="col">Refund / Payment</th>
                            <th scope="col">Date</th>
                            <th scope="col">Reason</th>
                            <th scope="col">Amount</th>
                        </tr>
                    </thead>
                    <tbody>
                        ${refunds.length ? refunds.map((refund) => `
                            <tr>
                                <td>${cell(refund.id, refund.paymentId)}</td>
                                <td>${dateTime(refund.date)}</td>
                                <td>${escapeHtml(refund.reason)}</td>
                                <td class="sales-money">${money(refund.amount)}</td>
                            </tr>
                        `).join('') : '<tr><td colspan="4">No refunds recorded.</td></tr>'}
                    </tbody>
                </table>
            </div>

            <h6 class="sales-section-title">Preview Activity</h6>
            <ol class="sales-history">
                ${history.length ? history.map((entry) => `
                    <li>
                        ${escapeHtml(entry.note)}
                        <small>${dateTime(entry.date)} · ${escapeHtml(entry.actor)}</small>
                    </li>
                `).join('') : '<li>No new activity in this session.</li>'}
            </ol>
        `;

        this.viewModal.show();
    }
}

function initializeSalesPayments() {
    if (!document.getElementById('pricesTable')
        || DataTable.isDataTable('#pricesTable')) return;

    new SalesPaymentsUI();
}

if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', initializeSalesPayments, {
        once: true,
    });
} else {
    initializeSalesPayments();
}

