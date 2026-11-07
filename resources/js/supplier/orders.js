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
    }).format(Number(value) / 100);

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

/* ==================================================
   ORDER MANAGEMENT — SERVER-AUTHORITATIVE STOCK AND MONEY
   Monetary values in JavaScript are integer centavos.
================================================== */

class OrderManagementUI {
    /* ==================================================
       CONSTRUCTOR
    ================================================== */

    constructor(element) {
        this.element = element;
        this.orders = [];
        this.clients = [];
        this.flowers = [];
        this.deliveryFeeCents = null;
        this.pendingAction = null;
        this.itemSequence = 0;
        this.ready = false;
        this.loading = false;
        this.saving = false;
        this.committed = false;
        this.editingVersion = null;
        this.requestKey = null;
        this.autoCreateHandled = false;
        this.formModal = Modal.getOrCreateInstance(this.el('orderFormModal'));
        this.viewModal = Modal.getOrCreateInstance(this.el('orderViewModal'));
        this.actionModal = Modal.getOrCreateInstance(this.el('orderActionModal'));
        this.initializeTable();
        this.mountScroller();
        this.bindEvents();
        this.loadOrders();
    }

    /* ==================================================
       EL
    ================================================== */

    el(id) { return document.getElementById(id); }
    /* ==================================================
       TOTAL
    ================================================== */

    total(order) { return order.totalCents; }
    /* ==================================================
       HAS RESERVATION
    ================================================== */

    hasReservation(order) {
        return !order.stockedOut && ['Confirmed','Processing','Ready for Delivery','Ready for Pickup','Out for Delivery'].includes(order.status);
    }
    /* ==================================================
       AVAILABLE
    ================================================== */

    available(flowerId, excludeOrderId = '') {
        const own = this.orders.find(order => order.id === excludeOrderId);
        return Number(this.flowers.find(flower => flower.id === flowerId)?.available || 0)
            + (own && this.hasReservation(own) ? own.items.filter(item => item.flowerId === flowerId)
                .reduce((sum, item) => sum + item.quantity, 0) : 0);
    }
    /* ==================================================
       NEXT STATUS
    ================================================== */

    nextStatus(order) {
        return order.status === 'Pending' && !order.canConfirm ? null : order.nextStatus;
    }
    /* ==================================================
       EDITABLE
    ================================================== */

    editable(order) { return order.editable && ['Pending', 'Confirmed'].includes(order.status); }

    /* ==================================================
       INITIALIZE TABLE
    ================================================== */

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

    /* ==================================================
       ACTION BUTTONS
    ================================================== */

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

    /* ==================================================
       MOUNT SCROLLER
    ================================================== */

    mountScroller() {
        const wrapper = document.createElement('div');

        wrapper.className = 'order-table-scroll';
        wrapper.tabIndex = 0;
        wrapper.setAttribute('role', 'region');
        wrapper.setAttribute('aria-label', 'Orders table. Scroll horizontally for all columns.');

        this.element.before(wrapper);
        wrapper.append(this.element);
    }

    /* ==================================================
       REFRESH
    ================================================== */

    refresh() {
        this.table.clear();
        this.table.rows.add(this.orders);
        this.table.draw(false);
    }

    /* ==================================================
       BIND EVENTS
    ================================================== */

    bindEvents() {
        this.el('addOrderBtn').addEventListener('click', () => this.openForm());

        ['input', 'search'].forEach(event => this.el('orderSearch').addEventListener(event, () => {
            this.table.search(this.el('orderSearch').value.trim(), {regex: false, smart: true, caseInsensitive: true}).draw();
        }));

        this.el('orderPageLength').addEventListener('change', (event) => {
            const length = Number(event.target.value);
            if ([5, 10, 25, 50].includes(length)) this.table.page.len(length).draw();
        });

        this.el('orderMethod').addEventListener('change', () => {
            this.syncFulfillment();
        });

        this.el('orderClient').addEventListener('change', () => {
            const client = this.clients.find(
                (item) => item.id === this.el('orderClient').value,
            );

            this.el('orderAddress').value = client?.address || '';
        });

        this.el('addOrderItem').addEventListener('click', () => this.addItem());

        this.el('orderItemList').addEventListener('change', (event) => {
            const row = event.target.closest('.order-item-row');

            if (event.target.matches('[data-flower]')) {
                const flower = this.flowers.find(
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

            if (!button || this.saving) return;

            button.closest('.order-item-row').remove();
            this.updateTotals();
        });

        this.el('orderForm').addEventListener('submit', (event) => {
            event.preventDefault();
            this.saveOrder();
        });

        this.element.addEventListener('click', (event) => {
            const button = event.target.closest('[data-order-action]');

            if (!button || button.disabled || !this.ready || this.saving) return;

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

        this.table.on('length', (_event, _settings, length) => { this.el('orderPageLength').value = String(length); });
        ['orderFormModal', 'orderActionModal'].forEach(id => this.el(id).addEventListener('hide.bs.modal', event => {
            if (this.saving && !this.committed) event.preventDefault();
        }));
        this.el('orderActionModal').addEventListener('hidden.bs.modal', () => {
            this.pendingAction = null;
        });
    }

    /* ==================================================
       ERROR
    ================================================== */

    error(id, message = '') {
        this.el(id).textContent = message;
        this.el(id).hidden = !message;
    }

    /* ==================================================
       OPEN FORM
    ================================================== */

    openForm(order = null) {
        if (!this.ready || this.saving) return;
        if (order && !this.editable(order)) return;

        this.el('orderForm').reset();
        this.editingVersion = order?.version || null;
        this.requestKey = order ? null : this.newRequestKey();
        this.error('orderFormError');
        this.el('orderEditingId').value = order?.id || '';
        this.el('orderCode').value = order?.id || 'Generated after saving';
        this.el('orderFormTitle').textContent = order ? 'Edit Order' : 'Add New Order';

        this.el('orderClient').innerHTML = `
            <option value="">Select client</option>
            ${this.clients.map((client) => `
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

        this.el('orderMethod').value = order?.method || (this.deliveryFeeCents === null ? 'Customer Pickup' : 'Delivery');
        this.el('orderAddress').value = order?.address || '';
        this.el('orderNotes').value = order?.notes || '';
        this.el('orderItemList').innerHTML = '';

        if (order) order.items.forEach((item) => this.addItem(item));
        else this.addItem();

        this.syncFulfillment();
        this.formModal.show();
    }

    /* ==================================================
       ADD ITEM
    ================================================== */

    addItem(item = null) {
        if (this.saving || this.el('orderItemList').querySelectorAll('.order-item-row').length >= 50) return;
        const row = document.createElement('div');
        const sequence = ++this.itemSequence;

        row.className = 'order-item-row';
        row.dataset.unitPrice = String(item?.unitPrice || 0);

        row.innerHTML = `
            <div class="order-field">
                <label for="orderFlower${sequence}">Flower *</label>
                <select id="orderFlower${sequence}" data-flower required>
                    <option value="">Select flower</option>
                    ${this.flowers.map((flower) => `
                        <option
                            value="${escapeHtml(flower.id)}"
                            ${flower.id === item?.flowerId ? 'selected' : ''}
                            ${(flower.status !== 'Active' || flower.price === null) && flower.id !== item?.flowerId ? 'disabled' : ''}
                        >
                            ${escapeHtml(flower.name)} · ${flower.baseUnit} · ${(item?.flowerId === flower.id || flower.price !== null) ? money(item?.flowerId === flower.id ? item.unitPrice : flower.price) : 'Not priced'} / ${flower.baseUnit}
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

    /* ==================================================
       READ ITEMS
    ================================================== */

    readItems() {
        return [...this.el('orderItemList').querySelectorAll('.order-item-row')]
            .map((row) => {
                const flower = this.flowers.find(
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

    /* ==================================================
       FORM DELIVERY FEE
    ================================================== */

    formDeliveryFee() {
        if (this.el('orderMethod').value !== 'Delivery') return 0;

        const existing = this.orders.find(
            (order) => order.id === this.el('orderEditingId').value,
        );

        return existing?.method === 'Delivery'
            ? existing.deliveryFee
            : this.deliveryFeeCents;
    }

    /* ==================================================
       SYNC FULFILLMENT
    ================================================== */

    syncFulfillment() {
        const delivery = this.el('orderMethod').value === 'Delivery';

        this.el('orderAddressField').hidden = !delivery;
        this.el('orderAddress').required = delivery;
        this.el('orderAddress').disabled = !delivery;

        this.updateTotals();
    }

    /* ==================================================
       UPDATE TOTALS
    ================================================== */

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

        this.el('orderDeliveryFee').value = fee === null ? 'Not configured' : money(fee);
        this.el('orderSubtotal').textContent = money(subtotal);
        this.el('orderFeeTotal').textContent = fee === null ? 'Not configured' : money(fee);
        this.el('orderGrandTotal').textContent = fee === null ? 'Delivery fee required' : money(subtotal + fee);
    }

    /* ==================================================
       SUBMIT CREATE / EDIT WITHOUT CLIENT-SUPPLIED PRICES
    ================================================== */

    

    async saveOrder() {
        if (!this.ready || this.saving || !this.el('orderForm').reportValidity()) return;
        this.error('orderFormError');
        const existing = this.orders.find(order => order.id === this.el('orderEditingId').value);
        if (existing && !this.editable(existing)) { this.error('orderFormError', 'This order is no longer editable.'); return; }
        const client = this.clients.find(item => item.id === this.el('orderClient').value);
        const items = this.readItems();
        if (!client || !items.length || items.some(item => !item.flowerId || !Number.isSafeInteger(item.quantity) || item.quantity < 1 || item.quantity > 100000)) {
            this.error('orderFormError', 'Select a client and at least one valid item with a positive whole quantity.'); return;
        }
        if (this.formDeliveryFee() === null) { this.error('orderFormError', 'Configure the fixed delivery fee before saving Delivery orders.'); return; }
        const payload = {
            client_id: client.dbId,
            method: this.el('orderMethod').value,
            address: this.el('orderAddress').value.trim(),
            notes: this.el('orderNotes').value.trim(),
            items: items.map(item => ({flower_id: this.flowers.find(flower => flower.id === item.flowerId).dbId, quantity: item.quantity})),
        };
        if (existing) payload.version = this.editingVersion;
        else payload.request_key = this.requestKey;
        await this.submit(existing ? this.endpoint('updateUrl', existing.dbId) : this.endpoint('storeUrl'), payload,
            'orderFormError', this.formModal);
    }

    /* ==================================================
       EXPLICIT TRANSITION / CANCELLATION CONFIRMATION
    ================================================== */

    

    openAction(order, action) {
        if (!this.ready || this.saving) return;
        const next = this.nextStatus(order);
        if (action === 'advance' && !next) return;
        if (action === 'cancel' && ['Completed','Cancelled'].includes(order.status)) return;
        const cancelling = action === 'cancel';
        const status = cancelling ? 'Cancelled' : next;
        this.pendingAction = {dbId: order.dbId, status, version: order.version};
        this.error('orderActionError');
        this.el('orderCancelField').hidden = !cancelling;
        this.el('orderCancelReason').required = cancelling;
        this.el('orderCancelReason').value = '';
        this.el('orderCollectedField').hidden = status !== 'Completed';
        this.el('orderCollected').required = status === 'Completed';
        this.el('orderCollected').checked = false;
        this.el('orderActionTitle').textContent = cancelling ? `Cancel ${order.id}` : `${order.id} — ${status}`;
        this.el('orderActionConfirm').textContent = cancelling ? 'Cancel Order' : `Mark ${status}`;
        const messages = {
            Confirmed: 'Confirmation checks all items and reserves the complete quantity. Physical stock is not deducted.',
            Processing: 'Begin preparing this order. Its stock remains reserved.',
            'Ready for Pickup': 'Mark ready while awaiting actual collection. Stock remains reserved.',
            'Ready for Delivery': 'Mark ready for Delivery Management. This does not dispatch or deduct stock.',
            Completed: 'Confirm actual collection of the complete order. This records FIFO Stock-Out once, consumes its reservation, and creates one Sales Record.',
            Cancelled: 'Cancel this order and release its active stock reservations. Enter the reason. Existing payment records are retained.',
        };
        this.el('orderActionMessage').textContent = messages[status] || '';
        this.actionModal.show();
    }

    /* ==================================================
       PERFORM ACTION
    ================================================== */

    async performAction() {
        if (!this.ready || this.saving || !this.pendingAction || !this.el('orderActionForm').reportValidity()) return;
        const pending = this.pendingAction;
        await this.submit(this.endpoint('actionUrl', pending.dbId), {
            status: pending.status, version: pending.version,
            reason: this.el('orderCancelReason').value.trim(),
            collected: pending.status === 'Completed' && this.el('orderCollected').checked,
        }, 'orderActionError', this.actionModal);
    }

    /* ==================================================
       POST ONCE; ALWAYS RELOAD REAL STOCK / RESERVATIONS
    ================================================== */

    

    async submit(url, payload, errorId, modal) {
        if (this.saving || !this.ready) return;
        this.error(errorId);
        this.saving = true;
        this.committed = false;
        this.updateControls();
        try {
            const result = await this.request(url, payload);
            this.mapOrder(result.data);
            this.committed = true;
            modal.hide();
            await this.loadOrders(result.message || 'Order saved successfully.');
        } catch (error) {
            const messages = Object.values(error.errors || {}).flat();
            let message = messages.length ? messages.join('\n') : error.message;
            if (error.errors?.version) message += ' Close this dialog, refresh, and reopen the order.';
            if (!messages.length && ![401,403,404,419,422].includes(error.status)) {
                message += ' Refresh to check the saved order before retrying; keep the same create form to reuse its request key.';
            }
            this.error(errorId, message);
        } finally {
            this.saving = false;
            this.committed = false;
            this.updateControls();
        }
    }

    /* ==================================================
       PUBLIC ORDER RESPONSE MAPPING — ALL MONEY IN CENTAVOS
    ================================================== */

    

    mapOrder(row) {
        if (!row || !Number.isSafeInteger(row.dbId) || row.dbId < 1 || !/^[a-f0-9]{64}$/.test(row.version)
            || !Number.isSafeInteger(row.totalCents) || row.totalCents < 1
            || !Number.isSafeInteger(row.subtotalCents) || row.subtotalCents < 1
            || !Number.isSafeInteger(row.deliveryFeeCents) || row.deliveryFeeCents < 0
            || !Number.isSafeInteger(row.paidCents) || row.paidCents < 0
            || !['Delivery', 'Customer Pickup'].includes(row.method)
            || !Array.isArray(row.items) || !Array.isArray(row.history)) {
            throw new Error('Invalid order response. Refresh before retrying.');
        }
        if (row.items.some(item => !Number.isSafeInteger(item.quantity) || item.quantity < 1
            || !Number.isSafeInteger(item.unitPriceCents) || item.unitPriceCents < 1
            || !['Stem', 'Bouquet'].includes(item.baseUnit))) {
            throw new Error('Invalid order item snapshot. Refresh before retrying.');
        }
        return {...row, deliveryFee: row.deliveryFeeCents, paid: row.paidCents,
            items: row.items.map(item => ({...item, unitPrice: item.unitPriceCents}))};
    }

    /* ==================================================
       ENDPOINTS AND CSRF-PROTECTED JSON REQUESTS
    ================================================== */

    

    endpoint(name, id = null) {
        let url = this.element.dataset[name];
        if (!url) throw new Error('Missing order endpoint. Replace the Blade file too.');
        if (id !== null) {
            if (!url.includes('__ORDER__')) throw new Error('Invalid order endpoint template.');
            url = url.replace('__ORDER__', encodeURIComponent(String(id)));
        }
        return url;
    }

    /* ==================================================
       REQUEST
    ================================================== */

    async request(url, payload = null) {
        const headers = {Accept: 'application/json'};
        if (payload !== null) {
            const token = this.el('orderForm').querySelector('[name="_token"]')?.value;
            if (!token) throw new Error('Missing CSRF token. Replace the Blade form too.');
            headers['X-CSRF-TOKEN'] = token;
            headers['Content-Type'] = 'application/json';
        }
        const response = await fetch(url, {method: payload === null ? 'GET' : 'POST', headers,
            credentials: 'same-origin', redirect: 'error', ...(payload === null ? {} : {body: JSON.stringify(payload)})});
        const isJson = (response.headers.get('content-type') || '').includes('application/json');
        const result = isJson ? await response.json() : {};
        if (!response.ok) {
            const messages = {401:'Your session expired. Log in again.',403:'Your account cannot perform this action.',404:'The order or endpoint was not found. Refresh.',419:'Your session token expired. Refresh.'};
            const error = new Error(messages[response.status] || (response.status >= 500 ? 'The server could not complete the request.' : result.message) || 'Unable to complete the request.');
            error.status = response.status; error.errors = result.errors || {}; throw error;
        }
        if (!isJson) throw new Error('Unexpected response. Refresh and check your login.');
        return result;
    }

    /* ==================================================
       LOAD AUTHORITATIVE ORDERS, CATALOG AND AVAILABILITY
    ================================================== */

    

    async loadOrders(successMessage = '') {
        if (this.loading) return false;
        this.loading = true; this.ready = false; this.updateControls();
        this.announce('Loading orders and available stock...');
        try {
            const result = await this.request(this.endpoint('url'));
            if (!Array.isArray(result.data) || !Array.isArray(result.clients) || !Array.isArray(result.flowers)) throw new Error('Invalid order list response.');
            this.orders = result.data.map(row => this.mapOrder(row));
            this.clients = result.clients;
            this.flowers = result.flowers.map(flower => ({...flower,price:flower.priceCents}));
            this.deliveryFeeCents = result.deliveryFeeCents;
            this.refresh(); this.ready = true;
            this.announce(successMessage || (this.orders.length ? '' : 'No orders yet. Create an order using an active client and priced catalog items.'));
            return true;
        } catch (error) {
            this.announce(successMessage ? `${successMessage} The list could not reload. Refresh before another transaction.` : error.message);
            return false;
        } finally {
            this.loading = false; this.updateControls();
            if (this.ready && !this.saving && !this.autoCreateHandled && new URLSearchParams(window.location.search).get('create') === '1') {
                this.autoCreateHandled = true; this.openForm();
            }
        }
    }

    /* ==================================================
       DISABLE WRITES WHILE REQUESTS ARE RUNNING
    ================================================== */

    

    updateControls() {
        const disabled = !this.ready || this.loading || this.saving;
        this.el('addOrderBtn').disabled = disabled;
        this.element.querySelectorAll('[data-order-action]').forEach(button => {button.disabled = disabled;});
        ['orderForm','orderActionForm'].forEach(id => {
            this.el(id).querySelectorAll('input,select,textarea,button').forEach(field => {
                if (field.type !== 'hidden') field.disabled = this.saving;
            });
        });
        this.el('orderAddress').disabled = this.saving || this.el('orderMethod').value !== 'Delivery';
    }

    /* ==================================================
       LOAD REAL DETAILS INCLUDING PAYMENT / STATUS HISTORY
    ================================================== */

    

    async showOrder(order) {
        if (!this.ready || this.saving) return;
        try {
            const result = await this.request(this.endpoint('showUrl', order.dbId));
            this.renderOrder(this.mapOrder(result.data));
        } catch (error) { this.announce(error.message || 'Unable to load order details.'); }
    }

    /* ==================================================
       ANNOUNCE
    ================================================== */

    announce(message) { this.el('orderAnnouncement').textContent = message; }

    /* ==================================================
       UUID RETAINED FOR SAME-FORM CREATION RETRIES
    ================================================== */

    

    newRequestKey() {
        if (globalThis.crypto.randomUUID) return globalThis.crypto.randomUUID();
        const bytes = globalThis.crypto.getRandomValues(new Uint8Array(16));
        bytes[6]=(bytes[6]&15)|64; bytes[8]=(bytes[8]&63)|128;
        const h=[...bytes].map(value=>value.toString(16).padStart(2,'0')).join('');
        return `${h.slice(0,8)}-${h.slice(8,12)}-${h.slice(12,16)}-${h.slice(16,20)}-${h.slice(20)}`;
    }

    /* ==================================================
       RENDER ORDER
    ================================================== */

    renderOrder(order) {
        this.el('orderViewTitle').textContent = `Order Details — ${order.id}`;

        const stockState = order.stockedOut
            ? 'Stock-Out recorded at actual fulfillment'
            : this.hasReservation(order)
                ? 'Stock reserved; on-hand not deducted'
                : 'No active reservation';

        const history = order.history.map((entry) => `
            <li>
                <strong>${escapeHtml(entry.status)}</strong>
                — ${escapeHtml(entry.note)}
                <small>
                    ${escapeHtml(new Date(entry.date).toLocaleString('en-PH', {timeZone: 'Asia/Manila'}))}
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
                    <dt>Confirmed Payments</dt>
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
                Payment information is read-only.
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

    const ui = new OrderManagementUI(table);
    if (new URLSearchParams(window.location.search).get('create') === '1') ui.openForm();
}

if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', initializeOrderManagement, {
        once: true,
    });
} else {
    initializeOrderManagement();
}




