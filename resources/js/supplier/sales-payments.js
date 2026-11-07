import DataTable from 'datatables.net-bs5';
import { Modal } from 'bootstrap';

// Escape API text before inserting it into approved table and modal markup.
const escapeHtml = (value) =>
    String(value ?? '').replace(/[&<>"']/g, (character) => ({
        '&': '&amp;',
        '<': '&lt;',
        '>': '&gt;',
        '"': '&quot;',
        "'": '&#039;',
    })[character]);

const MAX_CENTS = 999999999999;

// Format integer centavos without losing fractional cents at large aggregate totals.
function money(centavos) {
    if (!Number.isSafeInteger(centavos)) throw new Error('Financial total exceeds safe integer precision.');
    const absolute = Math.abs(centavos);
    const whole = BigInt(Math.floor(absolute / 100));
    const signed = centavos < 0 ? (whole === 0n ? -0 : -whole) : whole;
    const fraction = String(absolute % 100).padStart(2, '0');
    return new Intl.NumberFormat('en-PH', {style: 'currency', currency: 'PHP'})
        .formatToParts(signed).map((part) => part.type === 'fraction' ? fraction : part.value).join('');
}

// Refuse unsafe addition instead of rounding an accumulated financial amount.
function addCents(total, value) {
    if (!Number.isSafeInteger(total) || !Number.isSafeInteger(value)
        || !Number.isSafeInteger(total + value)) throw new Error('Financial total exceeds safe integer precision.');
    return total + value;
}

// Multiply whole-unit quantities by integer-centavo snapshots with overflow checks.
function itemTotal(item) {
    if (!Number.isSafeInteger(item.quantity) || item.quantity < 1
        || !Number.isSafeInteger(item.unitPrice) || item.unitPrice < 0 || item.unitPrice > MAX_CENTS
        || !Number.isSafeInteger(item.quantity * item.unitPrice)) {
        throw new Error('Invalid or unsafe order-item amount.');
    }
    return item.quantity * item.unitPrice;
}

// Reject partial decimals, negative values and floating-point parsing shortcuts.
function parseMoney(value) {
    const text = String(value).trim();

    if (!/^\d{1,10}(\.\d{1,2})?$/.test(text)) return null;

    const [whole, decimal = ''] = text.split('.');
    const result = Number(whole) * 100 + Number(decimal.padEnd(2, '0'));

    return Number.isSafeInteger(result) && result > 0 && result <= MAX_CENTS ? result : null;
}

// Date-based summaries use the business timezone, including near midnight.
function dateKey(value) {
    const date = new Date(value);
    if (Number.isNaN(date.getTime())) return '';
    const parts = new Intl.DateTimeFormat('en-US', {timeZone: 'Asia/Manila', year: 'numeric', month: '2-digit', day: '2-digit'}).formatToParts(date);
    const part = (type) => parts.find((entry) => entry.type === type)?.value;
    return `${part('year')}-${part('month')}-${part('day')}`;
}

// Display server timestamps in the same timezone used for summary totals.
function dateTime(value) {
    if (!value) return '—';
    const date = new Date(value);
    if (Number.isNaN(date.getTime())) return '—';
    return new Intl.DateTimeFormat('en-PH', {timeZone: 'Asia/Manila', dateStyle: 'medium', timeStyle: 'short'}).format(date);
}

// Convert integer centavos to an exact decimal string for API writes.
function decimalMoney(centavos) {
    if (!Number.isSafeInteger(centavos) || centavos < 0) throw new Error('Invalid money amount.');
    return `${Math.floor(centavos / 100)}.${String(centavos % 100).padStart(2, '0')}`;
}

// Generate one idempotency key for each intended mutation, retained on retry.
function requestKey() {
    if (globalThis.crypto?.randomUUID) return globalThis.crypto.randomUUID();
    if (!globalThis.crypto?.getRandomValues) throw new Error('A secure browser context is required to record financial changes.');
    const bytes = globalThis.crypto.getRandomValues(new Uint8Array(16));
    bytes[6] = (bytes[6] & 15) | 64;
    bytes[8] = (bytes[8] & 63) | 128;
    const hex = [...bytes].map((value) => value.toString(16).padStart(2, '0')).join('');
    return `${hex.slice(0, 8)}-${hex.slice(8, 12)}-${hex.slice(12, 16)}-${hex.slice(16, 20)}-${hex.slice(20)}`;
}

// Render status labels without allowing markup from API values.
function badge(value) {
    return `
        <span class="sales-badge ${String(value).toLowerCase().replaceAll(' ', '-').replace(/[^a-z0-9-]/g, '')}">
            ${escapeHtml(value)}
        </span>
    `;
}

// Keep primary and secondary identifiers visually grouped and escaped.
function cell(primary, secondary = '') {
    return `
        <div class="sales-cell">
            <strong>${escapeHtml(primary)}</strong>
            ${secondary ? `<small>${escapeHtml(secondary)}</small>` : ''}
        </div>
    `;
}

class SalesPaymentsUI {
    // Initialize empty UI collections; database reads populate the tables.
    constructor() {
        this.page = document.querySelector('.sales-page');
        this.baseUrl = this.page.dataset.url.replace(/\/$/, '');
        this.csrf = this.page.dataset.csrf || document.querySelector('meta[name="csrf-token"]')?.content || '';
        this.prices = [];
        this.orders = [];
        this.payments = [];
        this.refunds = [];
        this.history = [];
        this.sales = [];
        this.permissions = {};
        this.tables = {};
        this.action = null;
        this.pendingOperation = null;
        this.submitting = false;
        this.loading = false;
        this.loaded = false;
        this.readPromise = null;
        this.actionModal = Modal.getOrCreateInstance(this.el('salesActionModal'));
        this.viewModal = Modal.getOrCreateInstance(this.el('salesViewModal'));
        this.initializeTables();
        this.bindEvents();
        this.loadData().catch((error) => this.pageError(error.message));
    }

    // Resolve an existing element by its stable UI ID.
    el(id) {
        return document.getElementById(id);
    }

    // Find a canonical order by its display code.
    order(id) {
        return this.orders.find((order) => order.id === id);
    }

    // Calculate the immutable item-price snapshots and delivery fee in centavos.
    orderTotal(order) {
        return addCents(order.items.reduce((sum, item) => addCents(sum, itemTotal(item)), 0), order.deliveryFee);
    }

    // Count confirmed receipts, including receipts later refunded.
    confirmedPaid(orderId) {
        return this.payments
            .filter((payment) =>
                payment.orderId === orderId && ['Confirmed', 'Refunded'].includes(payment.status),
            )
            .reduce((sum, payment) => addCents(sum, payment.amount), 0);
    }

    // Sum recorded refund amounts for an order.
    refunded(orderId) {
        return this.refunds
            .filter((refund) => refund.orderId === orderId)
            .reduce((sum, refund) => addCents(sum, refund.amount), 0);
    }

    // Sum receipts still awaiting manual verification.
    pendingPaid(orderId) {
        return this.payments
            .filter((payment) =>
                payment.orderId === orderId && payment.status === 'Pending',
            )
            .reduce((sum, payment) => addCents(sum, payment.amount), 0);
    }

    // Refunds take precedence; only full confirmed receipts mark an order Paid.
    paymentStatus(order) {
        if (this.refunded(order.id) > 0) return 'Refunded';

        const paid = this.confirmedPaid(order.id);

        if (!paid) return 'Unpaid';
        return paid === this.orderTotal(order) ? 'Paid' : 'Unpaid';
    }

    // Allow one full receipt only for non-cancelled orders without any prior receipt or refund.
    eligibleForPayment(order) {
        return Boolean(order)
            && order.status !== 'Cancelled'
            && this.refunded(order.id) === 0
            && !this.payments.some((payment) => payment.orderId === order.id);
    }

    // Render an accessible action button with escaped identifiers.
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

    // Preserve native DataTables sorting and the approved financial table columns.
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
            {
                data: 'price', width: '18%', className: 'sales-money',
                render: (value, type) => type === 'display'
                    ? (value === null ? 'Not set' : money(value))
                    : (value ?? -1),
            },
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
                        ? (this.permissions.managePrices && row.canEdit !== false
                            ? this.icon('price', row.id, 'pencil-square', `Edit price ${row.name}`, 'edit') : '')
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
                            ${this.permissions.confirm && row.status === 'Pending'
                                && this.order(row.orderId)?.status !== 'Cancelled'
                                ? this.icon('confirm', row.id, 'check-lg', `Confirm ${row.id}`, 'confirm')
                                : ''}
                            ${this.permissions.refund && ['Confirmed', 'Refunded'].includes(row.status)
                                && this.paymentRefundable(row) > 0
                                ? this.icon('refund', row.id, 'arrow-counterclockwise', `Refund ${row.id}`, 'refund')
                                : ''}
                        </div>
                    `;
                },
            },
        ], [[2, 'desc']]);
    }

    // Build one searchable table with compact controls and horizontal scrolling.
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

    // Connect tabs, compact search controls and manual financial actions.
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
        this.el('salesRetryRequest').addEventListener('click', () => this.retryRequest());
        this.el('salesReloadData').addEventListener('click', () => {
            this.loadData().catch((error) => this.pageError(error.message));
        });
        this.el('salesActionModal').addEventListener('hide.bs.modal', (event) => {
            if (this.submitting) event.preventDefault();
        });

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

    // Update accessible tab state and recalculate the visible table widths.
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

    // Return the confirmed payment amount not already refunded.
    paymentRefundable(payment) {
        const refunded = this.refunds
            .filter((refund) => refund.paymentId === payment.id)
            .reduce((sum, refund) => addCents(sum, refund.amount), 0);

        return payment.amount - refunded;
    }

    // Show validation feedback safely inside the action modal.
    error(message = '') {
        this.el('salesActionError').textContent = message;
        this.el('salesActionError').hidden = !message;
    }

    // Wrap labelled modal input markup using the approved form styles.
    field(label, content) {
        return `<div class="sales-field">${label}${content}</div>`;
    }

    // Reload authoritative data and verify permission before opening a mutation form.
    async openAction(type, id = '') {
        if (this.submitting || this.pendingOperation) {
            this.announce('Resolve the pending request using Retry before starting another change.');
            return;
        }
        try { await this.loadData(); }
        catch (error) { this.pageError(error.message); return; }
        const permission = {price: 'managePrices', payment: 'recordPayment', confirm: 'confirm', refund: 'refund'}[type];
        if (!permission || !this.permissions[permission]) {
            this.announce('You do not have permission to perform this action.');
            return;
        }
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
                    Changes apply to future orders. Existing order
                    and Sales Record prices remain unchanged.
                </p>
                ${this.field(
                    `<label for="financeAmount">Selling Price / ${escapeHtml(flower.baseUnit)} *</label>`,
                    `<input type="number" id="financeAmount" min="0.01" max="9999999999.99"
                        step="0.01" value="${flower.price === null ? '' : decimalMoney(flower.price)}" required>`,
                )}
            `;
        }

        if (type === 'payment') {
            const eligible = this.orders.filter((order) => this.eligibleForPayment(order));

            if (!eligible.length) {
                this.announce('No unpaid orders are eligible for a full payment.');
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
                        <option value="GCash">GCash</option>
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
            if (!payment || payment.status !== 'Pending'
                || this.order(payment.orderId)?.status === 'Cancelled') return;

            title = `Confirm ${payment.id}`;

            fields = `
                <p class="sales-help">
                    Confirm receipt of ${money(payment.amount)}
                    via ${escapeHtml(payment.method)}
                    for ${escapeHtml(payment.orderId)}?
                    Verify the actual receipt before confirming this payment.
                </p>
                ${this.field(
                    '<label for="financeReason">Verification Remarks *</label>',
                    '<textarea id="financeReason" rows="3" maxlength="500" required></textarea>',
                )}
            `;
        }

        if (type === 'refund') {
            const payment = this.payments.find((item) => item.id === id);

            if (!payment || !['Confirmed', 'Refunded'].includes(payment.status)
                || this.paymentRefundable(payment) <= 0) return;

            title = `Record Refund — ${payment.id}`;

            fields = `
                <p class="sales-help">
                    Refundable from this payment:
                    <strong>${money(this.paymentRefundable(payment))}</strong>.
                    Saving records an Admin-approved refund.
                    Original payment history is retained.
                </p>
                ${this.field(
                    '<label for="financeAmount">Refund Amount *</label>',
                    `<input type="number" id="financeAmount" min="0.01"
                        max="${decimalMoney(this.paymentRefundable(payment))}"
                        step="0.01" required>`,
                )}
                ${this.field(
                    '<label for="financeReason">Refund Reason *</label>',
                    '<textarea id="financeReason" rows="3" maxlength="500" required></textarea>',
                )}
                <p class="sales-help">
                    This records the refund only; it does not transfer money.
                    Further collection on refunded orders is blocked.
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

    // Require a reference for GCash and bank transfers, but not Cash.
    syncReference() {
        const online = this.el('financeMethod').value !== 'Cash';

        this.el('financeReferenceField').hidden = !online;
        this.el('financeReference').required = online;
        this.el('financeReference').disabled = !online;
    }

    // Fix the receipt amount to the selected order total; installments are excluded.
    updateOrderHelp() {
        const order = this.order(this.el('financeOrder').value);
        if (!order) return;

        this.el('financeAmount').value = decimalMoney(this.orderTotal(order));
        this.el('financeOrderHelp').textContent =
            `Full payment required: ${money(this.orderTotal(order))}. No installments or additional payments.`;
    }

    // Validate the intended mutation and send one versioned idempotent API request.
    async saveAction() {
        this.error();
        if (this.submitting || this.loading) return;
        if (this.pendingOperation) { await this.retryRequest(); return; }
        if (!this.action || !this.el('salesActionForm').reportValidity()) return;
        const { type, id } = this.action;
        const amount = this.el('financeAmount') ? parseMoney(this.el('financeAmount').value) : null;
        const reason = this.el('financeReason')?.value.trim() || '';
        if (['price', 'payment', 'refund'].includes(type) && amount === null) {
            this.error('Enter a positive amount with no more than two decimal places.'); return;
        }
        if (['confirm', 'refund'].includes(type) && !reason) {
            this.error('Enter the required remarks or reason.'); return;
        }
        const required = {price: 'managePrices', payment: 'recordPayment', confirm: 'confirm', refund: 'refund'}[type];
        if (!this.permissions[required]) { this.error('This action is not permitted.'); return; }
        let path;
        let payload;
        if (type === 'price') {
            const flower = this.prices.find((item) => item.id === id);
            if (!flower || flower.canEdit === false) { this.error('This price cannot be edited.'); return; }
            path = `/prices/${flower.dbId}`;
            payload = { version: flower.version, amount: decimalMoney(amount) };
        } else if (type === 'payment') {
            const order = this.order(this.el('financeOrder').value);
            const method = this.el('financeMethod').value;
            const reference = method === 'Cash' ? '' : this.el('financeReference').value.trim();
            if (!this.eligibleForPayment(order)) { this.error('This order is no longer eligible for payment.'); return; }
            if (!['Cash', 'GCash', 'Bank Transfer'].includes(method)) { this.error('Select a valid payment method.'); return; }
            if (method !== 'Cash' && !reference) { this.error('Enter the transfer or GCash reference number.'); return; }
            if (amount !== this.orderTotal(order)) { this.error('A single payment must match the full order total.'); return; }
            path = '/payments';
            payload = { order_id: order.dbId, version: order.version, method, reference, amount: decimalMoney(amount) };
        } else {
            const payment = this.payments.find((item) => item.id === id);
            if (!payment) { this.error('Payment not found. Refresh the records.'); return; }
            const order = this.order(payment.orderId);
            if (type === 'confirm') {
                if (payment.status !== 'Pending' || !order || order.status === 'Cancelled'
                    || this.refunded(order.id) > 0) {
                    this.error('This payment can no longer be confirmed.'); return;
                }
                path = `/payments/${payment.dbId}/confirm`;
                payload = { version: payment.version, reason };
            } else if (type === 'refund') {
                if (!['Confirmed', 'Refunded'].includes(payment.status)
                    || amount > this.paymentRefundable(payment)) {
                    this.error('Refund exceeds the confirmed amount still available.'); return;
                }
                path = `/payments/${payment.dbId}/refund`;
                payload = { version: payment.version, amount: decimalMoney(amount), reason };
            } else return;
        }
        await this.performWrite({ path, payload: { ...payload, request_key: requestKey() } });
    }

    // Keep the exact request key and payload when the server result is uncertain.
    async retryRequest() {
        if (!this.pendingOperation || this.submitting) return;
        await this.performWrite(this.pendingOperation);
    }

    // Accept only canonical server data; never create or change financial rows locally.
    async performWrite(operation) {
        if (this.submitting) return;
        this.pendingOperation = operation;
        this.submitting = true;
        this.setBusy(true);
        try {
            const response = await this.request(operation.path, {method: 'POST', body: JSON.stringify(operation.payload)});
            this.acceptData(response.data);
            this.pendingOperation = null;
            this.el('salesRetryRequest').hidden = true;
            this.pageError('');
            this.error();
            this.submitting = false;
            this.actionModal.hide();
            this.announce(response.message || 'Saved successfully.');
        } catch (error) {
            if (error.definitive) {
                this.pendingOperation = null;
                this.el('salesRetryRequest').hidden = true;
                this.error(error.message);
                this.announce(error.message);
                if ([403, 409, 419, 422].includes(error.status)) {
                    this.submitting = false;
                    this.actionModal.hide();
                    await this.loadData().catch((loadError) => this.pageError(loadError.message));
                }
            } else {
                const message = 'The result could not be confirmed. Use Retry pending request to safely repeat the same request.';
                this.error(message);
                this.pageError(message);
                this.el('salesRetryRequest').hidden = false;
            }
        } finally {
            this.submitting = false;
            this.setBusy(false);
        }
    }

    // Use authenticated same-origin JSON requests with Laravel CSRF protection.
    async request(path, options = {}) {
        let response;
        try {
            response = await fetch(`${this.baseUrl}${path}`, {
                credentials: 'same-origin',
                ...options,
                headers: {
                    'Accept': 'application/json',
                    'Content-Type': 'application/json',
                    'X-Requested-With': 'XMLHttpRequest',
                    'X-CSRF-TOKEN': this.csrf,
                    ...options.headers,
                },
            });
        } catch {
            throw new Error('Connection interrupted. The server result is unknown; retry the same request.');
        }
        let payload;
        try { payload = await response.json(); }
        catch {
            const error = new Error(response.ok ? 'The server returned an unreadable response.' : `Request failed (${response.status}).`);
            error.status = response.status;
            error.definitive = response.status >= 400 && response.status < 500 && response.status !== 408;
            throw error;
        }
        if (!response.ok) {
            const validation = payload.errors && Object.values(payload.errors).flat().filter((value) => typeof value === 'string').join(' ');
            const error = new Error(validation || payload.message || `Request failed (${response.status}).`);
            error.status = response.status;
            error.definitive = response.status >= 400 && response.status < 500 && response.status !== 408;
            throw error;
        }
        return payload;
    }

    // Fetch current database records before opening financial actions or details.
    async loadData() {
        if (this.readPromise) return this.readPromise;
        this.loading = true;
        this.el('salesReloadData').disabled = true;
        this.el('recordPaymentBtn').disabled = true;
        this.readPromise = (async () => {
            try {
                const data = await this.request('/data');
                this.acceptData(data.data || data);
                this.loaded = true;
                if (!this.pendingOperation) this.pageError('');
            } finally {
                this.loading = false;
                this.readPromise = null;
                this.el('salesReloadData').disabled = false;
                this.setBusy(this.submitting);
            }
        })();
        return this.readPromise;
    }

    // Validate the dataset envelope before replacing any UI collection.
    acceptData(data) {
        if (!data || !['flowers', 'orders', 'sales', 'payments', 'refunds', 'history'].every((key) => Array.isArray(data[key]))
            || !data.permissions || typeof data.permissions !== 'object') {
            throw new Error('The server returned an incomplete financial dataset.');
        }
        // Reject invalid or unsafe integer data before replacing the previous canonical state.
        const storedMoney = (value) => Number.isSafeInteger(value) && value >= 0 && value <= MAX_CENTS;
        const amounts = [...data.payments.map((row) => row.amount), ...data.refunds.map((row) => row.amount),
            ...data.sales.map((row) => row.gross), ...data.flowers.filter((row) => row.price !== null).map((row) => row.price),
            ...data.orders.map((row) => row.deliveryFee)];
        if (!amounts.every(storedMoney)) throw new Error('The server returned an invalid financial amount.');
        data.orders.forEach((order) => this.orderTotal(order));
        data.sales.forEach((sale) => (sale.items || []).forEach((item) => itemTotal(item)));
        for (const amounts of [data.payments.map((row) => row.amount), data.refunds.map((row) => row.amount), data.sales.map((row) => row.gross)]) {
            amounts.reduce(addCents, 0);
        }
        this.prices = data.flowers;
        this.orders = data.orders;
        this.sales = data.sales;
        this.payments = data.payments;
        this.refunds = data.refunds;
        this.history = data.history;
        this.permissions = data.permissions;
        this.el('recordPaymentBtn').hidden = !this.permissions.recordPayment;
        this.refresh();
    }

    // Lock write controls while a request is in flight or awaits a safe retry.
    setBusy(busy) {
        this.el('salesActionSubmit').disabled = busy || Boolean(this.pendingOperation);
        this.el('recordPaymentBtn').disabled = busy || this.loading || !this.loaded || Boolean(this.pendingOperation);
        this.el('salesRetryRequest').disabled = busy;
        this.el('salesActionFields').querySelectorAll('input, select, textarea').forEach((input) => {
            input.disabled = busy || Boolean(this.pendingOperation);
        });
        this.page.setAttribute('aria-busy', String(busy || this.loading));
    }

    // Present connection and API errors without injecting response HTML.
    pageError(message) {
        this.el('salesPageError').textContent = message;
        this.el('salesPageError').hidden = !message;
    }

    // Announce results through the live status region.
    announce(message) {
        this.el('salesAnnouncement').textContent = message;
    }

    // Replace displayed table rows with canonical server collections.
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

    // Compute net completed sales and separate Stem/Bouquet rankings from server snapshots.
    renderSummary() {
        const net = (sale) => sale.gross - this.refunded(sale.orderId);
        const today = dateKey(new Date());
        const month = today.slice(0, 7);

        this.el('salesNet').textContent =
            money(this.sales.reduce((sum, sale) => addCents(sum, net(sale)), 0));

        this.el('salesCount').textContent = this.sales.length;

        this.el('salesToday').textContent = money(
            this.sales.filter((sale) => dateKey(sale.date) === today)
                .reduce((sum, sale) => addCents(sum, net(sale)), 0),
        );

        this.el('salesMonth').textContent = money(
            this.sales.filter((sale) => dateKey(sale.date).slice(0, 7) === month)
                .reduce((sum, sale) => addCents(sum, net(sale)), 0),
        );

        const quantities = new Map();
        const dates = new Map();

        this.sales.forEach((sale) => {
            (sale.items || this.order(sale.orderId)?.items || []).forEach((item) => {
                const key = `${item.baseUnit}|${item.name}`;
                quantities.set(key, addCents(quantities.get(key) || 0, item.quantity));
            });

            const key = dateKey(sale.date);
            dates.set(key, addCents(dates.get(key) || 0, net(sale)));
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

    // Render accessible text values alongside decorative chart bars.
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

    // Reload and display preserved order, receipt and refund history.
    async showOrder(orderId) {
        if (this.submitting) return;
        try { await this.loadData(); }
        catch (error) { this.pageError(error.message); return; }
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
                <div><dt>Sales Record</dt><dd>${escapeHtml(sale?.id || 'No Sales Record')}</dd></div>
                <div><dt>Order Total</dt><dd>${money(this.orderTotal(order))}</dd></div>
                <div><dt>Confirmed Payments</dt><dd>${money(paid)}</dd></div>
                <div><dt>Refunded</dt><dd>${money(refunded)}</dd></div>
                <div><dt>Net Collected</dt><dd>${money(paid - refunded)}</dd></div>
                <div><dt>Outstanding Before Refunds</dt><dd>${money(Math.max(0, this.orderTotal(order) - paid))}</dd></div>
                <div><dt>Pending Payments</dt><dd>${money(this.pendingPaid(orderId))}</dd></div>
            </dl>

            <p class="sales-help">
                Refunds are shown separately and do not automatically reopen collection
                for this order. Net sales are recognized from Completed orders,
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
                                <td class="sales-money">${money(item.unitPrice)}</td>
                                <td class="sales-money">${money(itemTotal(item))}</td>
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

            <h6 class="sales-section-title">Activity History</h6>
            <ol class="sales-history">
                ${history.length ? history.map((entry) => `
                    <li>
                        ${escapeHtml(entry.note)}
                        <small>${dateTime(entry.date)} · ${escapeHtml(entry.actor)}</small>
                    </li>
                `).join('') : '<li>No recorded activity for this order.</li>'}
            </ol>
        `;

        this.viewModal.show();
    }
}

// Initialize once when the Sales & Payments page is present.
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