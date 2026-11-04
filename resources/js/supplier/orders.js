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

function today() {
    const date = new Date();

    return [
        date.getFullYear(),
        String(date.getMonth() + 1).padStart(2, '0'),
        String(date.getDate()).padStart(2, '0'),
    ].join('-');
}

function formatDate(value) {
    const [year, month, day] = value.split('-').map(Number);

    return new Intl.DateTimeFormat('en-PH', {
        month: 'short',
        day: '2-digit',
        year: 'numeric',
    }).format(new Date(year, month - 1, day));
}

function badge(value) {
    const className = value.toLowerCase().replaceAll(' ', '-');

    return `
        <span class="order-badge ${className}">
            ${escapeHtml(value)}
        </span>
    `;
}

/*
 * Standalone UI prototype.
 * These records are not connected to Client, Inventory, Delivery or Sales.
 */
const SAMPLE_DELIVERY_FEE = 150;

const clients = [
    {
        id: 'CLI-001',
        name: 'Maria Santos',
        status: 'Active',
        address: 'Quezon City, Metro Manila',
    },
    {
        id: 'CLI-002',
        name: 'Carlo Reyes',
        status: 'Active',
        address: 'Makati City, Metro Manila',
    },
    {
        id: 'CLI-003',
        name: 'Angela Cruz',
        status: 'Inactive',
        address: 'Pasig City, Metro Manila',
    },
    {
        id: 'CLI-004',
        name: 'Daniel Garcia',
        status: 'Active',
        address: 'Marikina City, Metro Manila',
    },
];

/*
 * Prices simulate Sales-owned selling prices.
 * onHand represents current sample stock, before local reservations.
 */
const flowers = [
    { id: 'FLW-001', name: 'Red Rose', price: 60, onHand: 250 },
    { id: 'FLW-002', name: 'White Rose', price: 65, onHand: 120 },
    { id: 'FLW-003', name: 'Sunflower', price: 85, onHand: 100 },
    { id: 'FLW-004', name: 'Pink Tulip', price: 95, onHand: 80 },
    { id: 'FLW-005', name: 'White Lily', price: 110, onHand: 60 },
    { id: 'FLW-009', name: 'Classic Rose Bouquet', price: 1200, onHand: 10, itemType: 'Bouquet' },
    { id: 'FLW-010', name: 'Mixed Flower Bouquet', price: 1500, onHand: 8, itemType: 'Bouquet' },
].map((item) => ({ ...item, itemType: item.itemType || 'Stem', baseUnit: item.itemType || 'Stem' }));

function sampleOrder({
    id,
    clientId,
    date,
    method,
    status,
    payment = 'Unpaid',
    paid = 0,
    flowerId = 'FLW-001',
    quantity = 10,
    stockedOut = false,
}) {
    const client = clients.find((item) => item.id === clientId);
    const flower = flowers.find((item) => item.id === flowerId);

    return {
        id,
        clientId,
        clientName: client.name,
        date,
        method,
        address: method === 'Delivery' ? client.address : '',
        deliveryFee: method === 'Delivery' ? SAMPLE_DELIVERY_FEE : 0,
        status,
        payment,
        paid,
        notes: '',
        stockedOut,
        items: [{
            flowerId,
            flowerName: flower.name,
            quantity,
            unitPrice: flower.price,
            itemType: flower.itemType,
            baseUnit: flower.baseUnit,
        }],
        history: [{
            status,
            date: `${date}T09:00:00+08:00`,
            actor: 'Administrator (sample)',
            note: 'Sample order loaded for UI review.',
        }],
    };
}

const initialOrders = [
    sampleOrder({
        id: 'ORD-001',
        clientId: 'CLI-001',
        date: '2026-11-03',
        method: 'Delivery',
        status: 'Pending',
    }),
    sampleOrder({
        id: 'ORD-002',
        clientId: 'CLI-002',
        date: '2026-11-02',
        method: 'Customer Pickup',
        status: 'Confirmed',
        flowerId: 'FLW-002',
        quantity: 15,
        payment: 'Paid',
        paid: 975,
    }),
    sampleOrder({
        id: 'ORD-003',
        clientId: 'CLI-004',
        date: '2026-11-02',
        method: 'Delivery',
        status: 'Processing',
        flowerId: 'FLW-003',
        quantity: 20,
    }),
    sampleOrder({
        id: 'ORD-004',
        clientId: 'CLI-001',
        date: '2026-11-01',
        method: 'Delivery',
        status: 'Ready for Delivery',
        flowerId: 'FLW-004',
        quantity: 10,
    }),
    sampleOrder({
        id: 'ORD-005',
        clientId: 'CLI-002',
        date: '2026-10-31',
        method: 'Customer Pickup',
        status: 'Ready for Pickup',
        flowerId: 'FLW-005',
        quantity: 5,
        stockedOut: false,
        payment: 'Paid',
        paid: 550,
    }),
    sampleOrder({
        id: 'ORD-006',
        clientId: 'CLI-003',
        date: '2026-10-25',
        method: 'Customer Pickup',
        status: 'Completed',
        quantity: 10,
        stockedOut: true,
        payment: 'Paid',
        paid: 600,
    }),
];


const mixedSample = sampleOrder({
    id: 'ORD-007', clientId: 'CLI-001', date: today(), method: 'Delivery',
    status: 'Pending', flowerId: 'FLW-001', quantity: 5,
});
mixedSample.items.push({ flowerId: 'FLW-009', flowerName: 'Classic Rose Bouquet',
    quantity: 1, unitPrice: 1200, itemType: 'Bouquet', baseUnit: 'Bouquet' });
initialOrders.push(mixedSample);

class OrderManagementUI {
    constructor(element) {
        this.element = element;
        this.orders = structuredClone(initialOrders);
        this.stock = new Map(flowers.map((flower) => [
            flower.id,
            flower.onHand,
        ]));

        this.pendingAction = null;
        this.itemSequence = 0;

        this.formModal = Modal.getOrCreateInstance(this.el('orderFormModal'));
        this.viewModal = Modal.getOrCreateInstance(this.el('orderViewModal'));
        this.actionModal = Modal.getOrCreateInstance(this.el('orderActionModal'));

        this.initializeTable();
        this.mountScroller();
        this.bindEvents();
    }

    el(id) {
        return document.getElementById(id);
    }

    total(order) {
        return order.items.reduce(
            (sum, item) => sum + item.quantity * item.unitPrice,
            0,
        ) + order.deliveryFee;
    }

    hasReservation(order) {
        return !order.stockedOut
            && ['Confirmed', 'Processing', 'Ready for Delivery', 'Ready for Pickup', 'Out for Delivery']
                .includes(order.status);
    }

    available(flowerId, excludeOrderId = '') {
        const reserved = this.orders
            .filter((order) =>
                order.id !== excludeOrderId && this.hasReservation(order),
            )
            .reduce((sum, order) =>
                sum + order.items
                    .filter((item) => item.flowerId === flowerId)
                    .reduce((quantity, item) => quantity + item.quantity, 0),
            0);

        return (this.stock.get(flowerId) || 0) - reserved;
    }

    allocationError(items, excludeOrderId = '') {
        const quantities = new Map();

        items.forEach((item) => {
            quantities.set(
                item.flowerId,
                (quantities.get(item.flowerId) || 0) + item.quantity,
            );
        });

        for (const [flowerId, required] of quantities) {
            const available = this.available(flowerId, excludeOrderId);

            if (required > available) {
                const flower = flowers.find((item) => item.id === flowerId);

                return `${flower.name}: ${required} ${flower.baseUnit.toLowerCase()} units requested, but only ${available} available after sample reservations.`;
            }
        }

        return '';
    }

    nextStatus(order) {
        if (order.status === 'Pending') return 'Confirmed';
        if (order.status === 'Confirmed') return 'Processing';

        if (order.status === 'Processing') {
            return order.method === 'Delivery'
                ? 'Ready for Delivery'
                : 'Ready for Pickup';
        }

        if (order.status === 'Ready for Pickup') return 'Completed';

        // Delivery dispatch and Delivered belong to Delivery Management.
        return null;
    }

    editable(order) {
        return ['Pending', 'Confirmed'].includes(order.status);
    }

    initializeTable() {
        this.table = new DataTable(this.element, {
            data: this.orders,
            autoWidth: false,
            pageLength: 5,
            order: [[1, 'desc'], [0, 'desc']],
            layout: {
                topStart: null,
                topEnd: null,
                bottomStart: 'info',
                bottomEnd: 'paging',
            },
            language: {
                emptyTable: 'No orders available.',
                zeroRecords: 'No matching orders found.',
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
                                <div class="order-identity">
                                    <strong>${escapeHtml(value)}</strong>
                                    <small>${escapeHtml(row.clientName)}</small>
                                </div>
                            `
                            : `${value} ${row.clientName} ${row.clientId}`,
                },
                {
                    data: 'date',
                    render: (value, type) =>
                        type === 'display' ? formatDate(value) : value,
                },
                {
                    data: 'method',
                    render: (value, type) =>
                        type === 'display' ? escapeHtml(value) : value,
                },
                {
                    data: null,
                    render: (_value, type, row) =>
                        type === 'display' ? money(this.total(row)) : this.total(row),
                },
                {
                    data: 'payment',
                    render: (value, type) =>
                        type === 'display' ? badge(value) : value,
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
                    render: (_value, type, row) =>
                        type === 'display' ? this.actionButtons(row) : '',
                },
            ],
        });
    }

    actionButtons(order) {
        const button = (action, icon, label) => `
            <button
                type="button"
                class="order-action-btn ${action}"
                data-order-action="${action}"
                data-order-id="${escapeHtml(order.id)}"
                title="${escapeHtml(label)}"
                aria-label="${escapeHtml(label)} ${escapeHtml(order.id)}"
            >
                <i class="bi bi-${icon}" aria-hidden="true"></i>
            </button>
        `;

        return `
            <div class="order-actions">
                ${button('view', 'eye', 'View order')}
                ${this.editable(order)
                    ? button('edit', 'pencil-square', 'Edit order')
                    : ''}
                ${this.nextStatus(order)
                    ? button('advance', 'arrow-right-circle', `Move to ${this.nextStatus(order)}`)
                    : ''}
                ${!['Completed', 'Cancelled'].includes(order.status)
                    ? button('cancel', 'x-circle', 'Cancel order')
                    : ''}
            </div>
        `;
    }

    mountScroller() {
        const wrapper = document.createElement('div');

        wrapper.className = 'order-table-scroll';
        wrapper.tabIndex = 0;
        wrapper.setAttribute('role', 'region');
        wrapper.setAttribute('aria-label', 'Orders table. Scroll horizontally for all columns.');

        this.element.before(wrapper);
        wrapper.append(this.element);
    }

    refresh() {
        this.table.clear();
        this.table.rows.add(this.orders);
        this.table.draw(false);
    }

    bindEvents() {
        this.el('addOrderBtn').addEventListener('click', () => this.openForm());

        this.el('orderSearch').addEventListener('input', (event) => {
            this.table.search(event.target.value).draw();
        });

        this.el('orderPageLength').addEventListener('change', (event) => {
            this.table.page.len(Number(event.target.value)).draw();
        });

        this.el('orderMethod').addEventListener('change', () => {
            this.syncFulfillment();
        });

        this.el('orderClient').addEventListener('change', () => {
            const client = clients.find(
                (item) => item.id === this.el('orderClient').value,
            );

            this.el('orderAddress').value = client?.address || '';
        });

        this.el('addOrderItem').addEventListener('click', () => this.addItem());

        this.el('orderItemList').addEventListener('change', (event) => {
            const row = event.target.closest('.order-item-row');

            if (event.target.matches('[data-flower]')) {
                const flower = flowers.find(
                    (item) => item.id === event.target.value,
                );

                const existing = this.orders.find((order) => order.id === this.el('orderEditingId').value);
                const snapshot = existing?.items.find((item) => item.flowerId === flower?.id);
                row.dataset.unitPrice = String(snapshot?.unitPrice ?? flower?.price ?? 0);
                row.querySelector('[data-quantity-label]').textContent = `Quantity (${flower?.baseUnit || 'units'}) *`;
            }

            this.updateTotals();
        });

        this.el('orderItemList').addEventListener('input', () => this.updateTotals());

        this.el('orderItemList').addEventListener('click', (event) => {
            const button = event.target.closest('[data-remove-item]');

            if (!button) return;

            button.closest('.order-item-row').remove();
            this.updateTotals();
        });

        this.el('orderForm').addEventListener('submit', (event) => {
            event.preventDefault();
            this.saveOrder();
        });

        this.element.addEventListener('click', (event) => {
            const button = event.target.closest('[data-order-action]');

            if (!button) return;

            const order = this.orders.find(
                (item) => item.id === button.dataset.orderId,
            );

            if (!order) return;

            const action = button.dataset.orderAction;

            if (action === 'view') this.showOrder(order);
            else if (action === 'edit') this.openForm(order);
            else this.openAction(order, action);
        });

        this.el('orderActionForm').addEventListener('submit', (event) => {
            event.preventDefault();
            this.performAction();
        });

        this.el('orderActionModal').addEventListener('hidden.bs.modal', () => {
            this.pendingAction = null;
        });
    }

    nextId() {
        const maximum = this.orders.reduce(
            (highest, order) => Math.max(highest, Number(order.id.split('-')[1])),
            0,
        );

        return `ORD-${String(maximum + 1).padStart(3, '0')}`;
    }

    error(id, message = '') {
        this.el(id).textContent = message;
        this.el(id).hidden = !message;
    }

    openForm(order = null) {
        if (order && !this.editable(order)) return;

        this.el('orderForm').reset();
        this.error('orderFormError');
        this.el('orderEditingId').value = order?.id || '';
        this.el('orderCode').value = order?.id || this.nextId();
        this.el('orderFormTitle').textContent = order ? 'Edit Order' : 'Add New Order';

        this.el('orderClient').innerHTML = `
            <option value="">Select client</option>
            ${clients.map((client) => `
                <option
                    value="${escapeHtml(client.id)}"
                    ${client.id === order?.clientId ? 'selected' : ''}
                    ${client.status !== 'Active' && client.id !== order?.clientId ? 'disabled' : ''}
                >
                    ${escapeHtml(client.name)}
                    ${client.status === 'Inactive' ? '(Inactive)' : ''}
                </option>
            `).join('')}
        `;

        this.el('orderMethod').value = order?.method || 'Delivery';
        this.el('orderAddress').value = order?.address || '';
        this.el('orderNotes').value = order?.notes || '';
        this.el('orderItemList').innerHTML = '';

        if (order) order.items.forEach((item) => this.addItem(item));
        else this.addItem();

        this.syncFulfillment();
        this.formModal.show();
    }

    addItem(item = null) {
        const row = document.createElement('div');
        const sequence = ++this.itemSequence;

        row.className = 'order-item-row';
        row.dataset.unitPrice = String(item?.unitPrice || 0);

        row.innerHTML = `
            <div class="order-field">
                <label for="orderFlower${sequence}">Flower *</label>
                <select id="orderFlower${sequence}" data-flower required>
                    <option value="">Select flower</option>
                    ${flowers.map((flower) => `
                        <option
                            value="${escapeHtml(flower.id)}"
                            ${flower.id === item?.flowerId ? 'selected' : ''}
                        >
                            ${escapeHtml(flower.name)} · ${flower.baseUnit} · ${money(item?.flowerId === flower.id ? item.unitPrice : flower.price)} / ${flower.baseUnit}
                        </option>
                    `).join('')}
                </select>
            </div>

            <div class="order-field">
                <label for="orderQuantity${sequence}" data-quantity-label>Quantity (${item?.baseUnit || 'units'}) *</label>
                <input
                    type="number"
                    id="orderQuantity${sequence}"
                    data-quantity
                    min="1"
                    max="100000"
                    step="1"
                    value="${item?.quantity || 1}"
                    required
                >
            </div>

            <div class="order-field">
                <label>Subtotal</label>
                <output class="order-item-price" data-item-total></output>
            </div>

            <button
                type="button"
                class="order-remove-item"
                data-remove-item
                aria-label="Remove order item"
                title="Remove item"
            >
                <i class="bi bi-trash3" aria-hidden="true"></i>
            </button>
        `;

        this.el('orderItemList').append(row);
        this.updateTotals();
    }

    readItems() {
        return [...this.el('orderItemList').querySelectorAll('.order-item-row')]
            .map((row) => {
                const flower = flowers.find(
                    (item) => item.id === row.querySelector('[data-flower]').value,
                );

                return {
                    flowerId: flower?.id || '',
                    flowerName: flower?.name || '',
                    itemType: flower?.itemType || '',
                    baseUnit: flower?.baseUnit || '',
                    quantity: Number(row.querySelector('[data-quantity]').value),
                    unitPrice: Number(row.dataset.unitPrice),
                };
            });
    }

    formDeliveryFee() {
        if (this.el('orderMethod').value !== 'Delivery') return 0;

        const existing = this.orders.find(
            (order) => order.id === this.el('orderEditingId').value,
        );

        return existing?.method === 'Delivery'
            ? existing.deliveryFee
            : SAMPLE_DELIVERY_FEE;
    }

    syncFulfillment() {
        const delivery = this.el('orderMethod').value === 'Delivery';

        this.el('orderAddressField').hidden = !delivery;
        this.el('orderAddress').required = delivery;
        this.el('orderAddress').disabled = !delivery;

        this.updateTotals();
    }

    updateTotals() {
        let subtotal = 0;

        this.el('orderItemList').querySelectorAll('.order-item-row').forEach((row) => {
            const quantity = Number(row.querySelector('[data-quantity]').value);
            const price = Number(row.dataset.unitPrice);
            const lineTotal = Number.isFinite(quantity) ? quantity * price : 0;

            row.querySelector('[data-item-total]').textContent = money(lineTotal);
            subtotal += lineTotal;
        });

        const fee = this.formDeliveryFee();

        this.el('orderDeliveryFee').value = money(fee);
        this.el('orderSubtotal').textContent = money(subtotal);
        this.el('orderFeeTotal').textContent = money(fee);
        this.el('orderGrandTotal').textContent = money(subtotal + fee);
    }

    saveOrder() {
        this.error('orderFormError');

        if (!this.el('orderForm').reportValidity()) return;

        const existing = this.orders.find(
            (order) => order.id === this.el('orderEditingId').value,
        );

        if (existing && !this.editable(existing)) {
            this.error('orderFormError', 'This order can no longer be edited.');
            return;
        }

        const client = clients.find(
            (item) => item.id === this.el('orderClient').value,
        );

        if (!client || (!existing && client.status !== 'Active')
            || (existing && existing.clientId !== client.id && client.status !== 'Active')) {
            this.error('orderFormError', 'Select an active registered client.');
            return;
        }

        const items = this.readItems();

        if (!items.length || items.some((item) =>
            !item.flowerId
            || !Number.isSafeInteger(item.quantity)
            || item.quantity < 1
            || item.quantity > 100000
            || !Number.isFinite(item.unitPrice)
            || item.unitPrice <= 0,
        )) {
            this.error('orderFormError', 'Add at least one valid flower item and positive whole quantity.');
            return;
        }

        const method = this.el('orderMethod').value;
        const address = this.el('orderAddress').value.trim();

        if (!['Delivery', 'Customer Pickup'].includes(method)
            || (method === 'Delivery' && !address)) {
            this.error('orderFormError', 'Enter a delivery address for Delivery orders.');
            return;
        }

        const stockError = this.allocationError(items, existing?.id);

        if (stockError) {
            this.error('orderFormError', stockError);
            return;
        }

        /*
         * Retain price snapshots for existing flower items.
         * New flower selections use current sample prices.
         */
        if (existing) {
            for (const item of items) {
                const original = existing.items.find(
                    (entry) => entry.flowerId === item.flowerId,
                );

                if (original) {
                    item.unitPrice = original.unitPrice;
                    item.itemType = original.itemType;
                    item.baseUnit = original.baseUnit;
                }
            }
        }

        const record = {
            id: existing?.id || this.nextId(),
            clientId: client.id,
            clientName: client.name,
            date: existing?.date || today(),
            method,
            address: method === 'Delivery' ? address : '',
            deliveryFee: this.formDeliveryFee(),
            notes: this.el('orderNotes').value.trim(),
            items,
            status: existing?.status || 'Pending',
            payment: existing?.payment || 'Unpaid',
            paid: existing?.paid || 0,
            stockedOut: false,
            history: [
                ...(existing?.history || []),
                {
                    status: existing?.status || 'Pending',
                    date: new Date().toISOString(),
                    actor: 'Administrator (preview)',
                    note: existing ? 'Order information updated.' : 'Order created manually.',
                },
            ],
        };

        // Avoid changing financial terms after sample payments exist.
        if (existing?.paid > 0 && this.total(record) !== this.total(existing)) {
            this.error(
                'orderFormError',
                'This sample order has payments. Amount changes require the future Sales/Payments adjustment workflow.',
            );
            return;
        }

        if (existing) {
            this.orders = this.orders.map((order) =>
                order.id === existing.id ? record : order,
            );
        } else {
            this.orders.push(record);
        }

        this.refresh();
        this.formModal.hide();
        this.announce(`${record.id} saved in this preview.`);
    }

    openAction(order, action) {
        const next = this.nextStatus(order);

        if (action === 'advance' && !next) return;
        if (action === 'cancel' && ['Completed', 'Cancelled'].includes(order.status)) return;

        this.pendingAction = { id: order.id, action, expectedStatus: order.status };
        this.error('orderActionError');

        const cancelling = action === 'cancel';

        this.el('orderCancelField').hidden = !cancelling;
        this.el('orderCancelReason').required = cancelling;
        this.el('orderCancelReason').value = '';

        this.el('orderActionTitle').textContent = cancelling
            ? `Cancel ${order.id}`
            : `${order.id} — ${next}`;

        this.el('orderActionConfirm').textContent = cancelling
            ? 'Cancel Order'
            : `Mark ${next}`;

        let message = cancelling
            ? 'Enter a cancellation reason. Any active sample reservation will be released. Orders already stocked out require Inventory restoration before cancellation.'
            : `Move this order from ${order.status} to ${next}?`;

        if (next === 'Confirmed' && !cancelling) {
            message += ' Full sample stock availability will be checked. Confirmation reserves stock without deducting on-hand quantity.';
        }

        if (next === 'Ready for Pickup' && !cancelling) {
            message += ' Stock stays reserved while awaiting collection. No on-hand deduction occurs at this stage.';
        }

        if (next === 'Ready for Delivery' && !cancelling) {
            message += ' Delivery assignment and dispatch will be handled in Delivery Management; no delivery record is created by this prototype.';
        }

        if (next === 'Completed' && !cancelling) {
            message += ' Confirm the customer has actually collected the order. This simulates pickup Stock-Out once and consumes its reservation. Actual Inventory/FIFO and Sales integration are not connected.';
        }

        this.el('orderActionMessage').textContent = message;
        this.actionModal.show();
    }

    performAction() {
        this.error('orderActionError');

        const pending = this.pendingAction;
        if (!pending) return;

        const order = this.orders.find((item) => item.id === pending.id);

        if (!order || order.status !== pending.expectedStatus) {
            this.error('orderActionError', 'Order changed. Close this dialog and try again.');
            return;
        }

        if (pending.action === 'cancel') {
            if (['Completed', 'Cancelled'].includes(order.status)) return;

            const reason = this.el('orderCancelReason').value.trim();

            if (!reason) {
                this.error('orderActionError', 'Enter a cancellation reason.');
                return;
            }

            if (order.stockedOut) {
                this.error(
                    'orderActionError',
                    'Cancellation is blocked: restore/correct the previous Stock-Out through Inventory first. That cross-module workflow is not connected in this preview.',
                );
                return;
            }

            this.setStatus(order, 'Cancelled', reason);
        } else {
            const next = this.nextStatus(order);
            if (!next) return;

            if (next === 'Confirmed' || (next === 'Completed' && order.method === 'Customer Pickup')) {
                const stockError = this.allocationError(order.items, order.id);

                if (stockError) {
                    this.error('orderActionError', stockError);
                    return;
                }
            }

            if (next === 'Completed' && order.method === 'Customer Pickup' && !order.stockedOut) {
                // Sample aggregate deduction only; real FIFO is Inventory-owned.
                order.items.forEach((item) => {
                    this.stock.set(
                        item.flowerId,
                        this.stock.get(item.flowerId) - item.quantity,
                    );
                });

                order.stockedOut = true;
            }

            this.setStatus(order, next, 'Status updated in UI preview.');
        }

        this.refresh();
        this.actionModal.hide();
        this.announce(`${order.id} is now ${order.status}. Preview data only.`);
    }

    setStatus(order, status, note) {
        order.status = status;
        order.history.push({
            status,
            note,
            date: new Date().toISOString(),
            actor: 'Administrator (preview)',
        });
    }

    announce(message) {
        this.el('orderAnnouncement').textContent = message;
    }

    showOrder(order) {
        this.el('orderViewTitle').textContent = `Order Details — ${order.id}`;

        const stockState = order.stockedOut
            ? 'Sample Stock-Out already recorded'
            : this.hasReservation(order)
                ? 'Sample stock reserved; on-hand not deducted'
                : 'No active sample reservation';

        const history = order.history.map((entry) => `
            <li>
                <strong>${escapeHtml(entry.status)}</strong>
                — ${escapeHtml(entry.note)}
                <small>
                    ${escapeHtml(new Date(entry.date).toLocaleString('en-PH'))}
                    · ${escapeHtml(entry.actor)}
                </small>
            </li>
        `).join('');

        this.el('orderViewContent').innerHTML = `
            <dl class="order-profile-grid">
                <div>
                    <dt>Client</dt>
                    <dd>${escapeHtml(order.clientName)} · ${escapeHtml(order.clientId)}</dd>
                </div>
                <div>
                    <dt>Order Date</dt>
                    <dd>${formatDate(order.date)}</dd>
                </div>
                <div>
                    <dt>Fulfillment</dt>
                    <dd>${escapeHtml(order.method)}</dd>
                </div>
                <div>
                    <dt>Order Status</dt>
                    <dd>${badge(order.status)}</dd>
                </div>
                <div>
                    <dt>Payment Status</dt>
                    <dd>${badge(order.payment)}</dd>
                </div>
                <div>
                    <dt>Sample Confirmed Payments</dt>
                    <dd>${money(order.paid)}</dd>
                </div>
                <div>
                    <dt>Delivery Address</dt>
                    <dd>${escapeHtml(order.address || 'Not applicable')}</dd>
                </div>
                <div>
                    <dt>Inventory Coordination</dt>
                    <dd>${escapeHtml(stockState)}</dd>
                </div>
                <div class="order-full">
                    <dt>Notes</dt>
                    <dd>${escapeHtml(order.notes || 'No notes.')}</dd>
                </div>
            </dl>

            <h6 class="order-section-title">Order Items</h6>

            <div
                class="order-detail-scroll"
                tabindex="0"
                role="region"
                aria-label="Order item details"
            >
                <table class="order-detail-table">
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
                                <td>${escapeHtml(item.flowerName)}</td>
                                <td>${item.quantity} ${escapeHtml(item.baseUnit)}</td>
                                <td>${money(item.unitPrice)}</td>
                                <td>${money(item.quantity * item.unitPrice)}</td>
                            </tr>
                        `).join('')}
                    </tbody>
                </table>
            </div>

            <div class="order-totals">
                <div>
                    <span>Items Subtotal</span>
                    <strong>${money(this.total(order) - order.deliveryFee)}</strong>
                </div>
                <div>
                    <span>Delivery Fee</span>
                    <strong>${money(order.deliveryFee)}</strong>
                </div>
                <div class="order-grand-total">
                    <span>Order Total</span>
                    <strong>${money(this.total(order))}</strong>
                </div>
            </div>

            <p class="order-help">
                Payment information is read-only sample data.
                Sales & Payments owns payment recording and refunds.
                Delivery Management owns dispatch and Delivered confirmation.
            </p>

            <h6 class="order-section-title">Status History</h6>
            <ol class="order-history">${history}</ol>
        `;

        this.viewModal.show();
    }
}

function initializeOrderManagement() {
    const table = document.getElementById('orderTable');

    if (!table || DataTable.isDataTable(table)) return;

    new OrderManagementUI(table);
}

if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', initializeOrderManagement, {
        once: true,
    });
} else {
    initializeOrderManagement();
}


