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
        timeZone: 'Asia/Manila',
        dateStyle: 'medium',
        timeStyle: 'short',
    }).format(new Date(value));
}

/* Format the schedule input in the shop's timezone. */
function localInputValue(value) {
    if (!value) return '';
    const parts = Object.fromEntries(new Intl.DateTimeFormat('en-GB', {
        timeZone: 'Asia/Manila', year: 'numeric', month: '2-digit', day: '2-digit',
        hour: '2-digit', minute: '2-digit', hourCycle: 'h23',
    }).formatToParts(new Date(value)).map((part) => [part.type, part.value]));
    return `${parts.year}-${parts.month}-${parts.day}T${parts.hour}:${parts.minute}`;
}

/* Send an explicit Manila timezone offset accepted by the PHP request rules. */
function schedulePayload(value) {
    if (!/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}$/.test(value)) {
        throw new Error('Choose a valid delivery schedule.');
    }
    const formatted = `${value}:00+08:00`;
    const time = new Date(formatted).getTime();
    if (!Number.isFinite(time) || time <= Date.now()) {
        throw new Error('Choose a future delivery date and time.');
    }
    return formatted;
}

class DeliveryManagementUI {
    /* Initialize the approved table layout using database records only. */
    constructor(element) {
        this.element = element;
        this.base = element.dataset.url.replace(/\/$/, '');
        this.csrf = element.dataset.csrf;
        this.deliveries = [];
        this.personnel = [];
        this.selected = null;
        this.busy = false;
        this.loading = false;
        this.manageModal = Modal.getOrCreateInstance(this.el('deliveryManageModal'));
        this.viewModal = Modal.getOrCreateInstance(this.el('deliveryViewModal'));
        this.initializeTable();
        this.mountScroller();
        this.bindEvents();
        this.load().catch((error) => this.announce(error.message));
    }

    /* Find a page or modal element. */
    el(id) { return document.getElementById(id); }

    /* Announce loading, success or recovery messages. */
    announce(message) { this.el('deliveryAnnouncement').textContent = message; }

    /* Match numeric account IDs and retain inactive personnel names on old records. */
    personName(id) {
        return this.personnel.find((person) => String(person.id) === String(id))?.name
            || this.deliveries.find((row) => id != null && String(row.personId) === String(id))?.personName
            || 'Unassigned';
    }

    /* Only current active delivery accounts may receive a new assignment. */
    activePerson(id) {
        return this.personnel.some((person) => String(person.id) === String(id));
    }

    /* Terminal deliveries expose details only. */
    terminal(delivery) { return ['Delivered', 'Cancelled'].includes(delivery.status); }

    /* Show connected actions; the server rechecks every workflow transition. */
    actionsFor(delivery) {
        if (this.terminal(delivery) || delivery.method !== 'Delivery') return [];
        if (delivery.status === 'Out for Delivery') {
            return delivery.orderStatus === 'Out for Delivery'
                ? [['deliver', 'Mark Delivered'], ['fail', 'Record Failed Attempt']] : [];
        }
        const states = ['Pending', 'Scheduled', 'Assigned', 'Rescheduled', 'Failed'];
        const orderReady = delivery.orderStatus === 'Ready for Delivery';
        const failedAttempt = delivery.status === 'Failed' && delivery.orderStatus === 'Out for Delivery';
        if (!states.includes(delivery.status) || (!orderReady && !failedAttempt)) return [];
        const actions = [
            ['schedule', delivery.schedule ? 'Reschedule Delivery' : 'Schedule Delivery'],
            ['assign', delivery.personId ? 'Reassign Personnel' : 'Assign Personnel'],
        ];
        if (['Assigned', 'Scheduled', 'Rescheduled'].includes(delivery.status)
            && delivery.schedule && this.activePerson(delivery.personId) && orderReady) {
            actions.push(['dispatch', 'Dispatch / Out for Delivery']);
        }
        actions.push(['cancel', 'Cancel Delivery']);
        return actions;
    }

    /* Preserve column alignment, native sorting and compact pagination. */
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

                                ${this.actionsFor(row).length ? `
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

    /* Keep horizontal scrolling inside the table only. */
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

    /* Refresh displayed rows while retaining the current page. */
    refresh() {
        this.table.clear();
        this.table.rows.add(this.deliveries);
        this.table.draw(false);
    }

    /* Read JSON while preserving Laravel validation errors and session failures. */
    async request(url, options = {}) {
        const response = await fetch(url, {
            ...options, credentials: 'same-origin',
            headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest',
                'X-CSRF-TOKEN': this.csrf, ...options.headers },
        });
        const result = await response.json().catch(() => null);
        if (!response.ok || !result || response.redirected) {
            const defaults = {
                401: 'Please sign in again.', 403: 'Your account cannot perform this action.',
                404: 'Delivery not found. Refresh the list.', 419: 'Your session expired. Refresh the page.',
                413: 'The upload exceeds the server limit. Choose a smaller image.',
            };
            const error = new Error(defaults[response.status]
                || (result?.errors ? Object.values(result.errors).flat().join(' ') : null)
                || (response.status >= 500 ? 'Server error. Refresh the delivery before retrying.' : result?.message)
                || 'Unable to load the response. Refresh the page.');
            error.status = response.status;
            error.errors = result?.errors || {};
            throw error;
        }
        return result;
    }

    /* Load the canonical list and active personnel; never create records on a GET. */
    async load() {
        this.loading = true;
        try {
            const result = await this.request(`${this.base}/data`);
            if (!Array.isArray(result.data) || !Array.isArray(result.personnel)) {
                throw new Error('Unexpected delivery response. Refresh the page.');
            }
            this.deliveries = result.data;
            this.personnel = result.personnel;
            this.refresh();
            this.announce(this.deliveries.length ? '' : 'No delivery records yet. Mark a Delivery order Ready for Delivery.');
        } finally { this.loading = false; }
    }

    /* Bind compact search, page size, modal actions and busy guards. */
    bindEvents() {
        this.el('deliverySearch').addEventListener('input', (event) => this.table.search(event.target.value).draw());
        this.el('deliveryPageLength').addEventListener('change', (event) => this.table.page.len(Number(event.target.value)).draw());
        this.element.addEventListener('click', (event) => {
            const button = event.target.closest('[data-delivery-action]');
            if (!button || this.busy || this.loading) return;
            const delivery = this.deliveries.find((row) => row.id === button.dataset.deliveryId);
            if (delivery) this.openRecord(delivery, button.dataset.deliveryAction);
        });
        this.el('deliveryAction').addEventListener('change', () => this.renderActionFields());
        this.el('deliveryManageForm').addEventListener('submit', (event) => {
            event.preventDefault(); this.saveAction();
        });
        this.el('deliveryManageModal').addEventListener('hide.bs.modal', (event) => {
            if (this.busy) event.preventDefault();
        });
        this.el('deliveryManageModal').addEventListener('hidden.bs.modal', () => {
            this.selected = null;
            this.el('deliveryManageForm').reset();
            this.el('deliveryActionFields').innerHTML = '';
        });
    }

    /* Retrieve current details and version before opening either modal. */
    async openRecord(delivery, action) {
        this.loading = true;
        try {
            const result = await this.request(`${this.base}/${Number(delivery.dbId)}`);
            if (!result.data?.version) throw new Error('Delivery details are unavailable.');
            this.deliveries = this.deliveries.map((row) => row.dbId === delivery.dbId ? result.data : row);
            this.refresh();
            if (action === 'view') this.showDetails(result.data);
            else this.openManage(result.data);
        } catch (error) { this.announce(error.message); }
        finally { this.loading = false; }
    }

    /* Show validation errors without discarding a retry's request identity. */
    showError(message = '') {
        this.el('deliveryManageError').textContent = message;
        this.el('deliveryManageError').hidden = !message;
    }

    /* Start an edit using the server version and supported actions. */
    openManage(delivery) {
        const actions = this.actionsFor(delivery);
        if (!actions.length) { this.announce('No actions are available for this delivery.'); return; }
        this.selected = { id: delivery.id, dbId: delivery.dbId, version: delivery.version,
            fingerprint: null, requestKey: null };
        this.showError();
        this.el('deliveryManageTitle').textContent = `Manage ${delivery.id}`;
        this.el('deliveryManageSummary').textContent =
            `${delivery.orderId} · ${delivery.client} · Current status: ${delivery.status}`;
        this.el('deliveryAction').innerHTML = actions.map(([value, label]) =>
            `<option value="${value}">${escapeHtml(label)}</option>`).join('');
        this.renderActionFields();
        this.manageModal.show();
    }

    /* Render inputs for the selected server-backed action. */
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
                    Times use Philippine time (Asia/Manila). Rescheduling a failed attempt
                    returns the linked order to Ready for Delivery.
                </p>
            `;
        }

        if (action === 'assign') {
            fields = `
                <div class="delivery-field">
                    <label for="deliveryPersonnel">Delivery Personnel <span>*</span></label>
                    <select id="deliveryPersonnel" required>
                        <option value="">Select personnel</option>
                        ${this.personnel
                            .filter((person) =>
                                true,
                            )
                            .map((person) => `
                                <option
                                    value="${escapeHtml(person.id)}"
                                    ${String(person.id) === String(delivery.personId) ? 'selected' : ''}
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
                    This changes the delivery and linked order to
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
                    Photo is uploaded to private storage. Confirm only after actual receipt.
                    Completion time is recorded automatically.
                </p>
            `;
        }

        if (action === 'fail') {
            fields = `
                <div class="delivery-field">
                    <label for="deliveryReason">Failure Reason <span>*</span></label>
                    <textarea id="deliveryReason" rows="3" maxlength="500" required></textarea>
                </div>
                <p class="delivery-help">A failed attempt keeps the order reservation.
                    Reschedule before dispatching again.</p>`;
        }

        if (action === 'cancel') {
            fields = `
                <div class="delivery-field">
                    <label for="deliveryReason">Cancellation Reason <span>*</span></label>
                    <textarea id="deliveryReason" rows="3" maxlength="500" required></textarea>
                </div>
                <p class="delivery-help">This cancels only the delivery record.
                    The linked order and its stock reservation remain unchanged.
                    Cancel the order separately in Order Management if needed.</p>`;
        }

        this.el('deliveryActionFields').innerHTML = fields;
    }

    /* Check that the selected upload can be opened as an image. */
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

            return;
        } finally {
            URL.revokeObjectURL(url);
        }
    }

    /* Submit only validated inputs; stock and workflow changes belong to the server. */
    async saveAction() {
        if (this.busy || !this.selected) return;
        this.showError();
        if (!this.el('deliveryManageForm').reportValidity()) return;
        const selected = this.selected;
        const delivery = this.deliveries.find((row) => row.id === selected.id);
        const action = this.el('deliveryAction').value;
        if (!delivery || !this.actionsFor(delivery).some(([value]) => value === action)) {
            this.showError('This action is unavailable. Close and refresh the delivery.'); return;
        }
        const values = { action, version: selected.version,
            reason: this.el('deliveryReason')?.value.trim() || '' };
        const file = this.el('deliveryProof')?.files[0];
        this.setBusy(true);
        try {
            if (action !== 'dispatch' && !values.reason) throw new Error('Enter the required remarks.');
            if (action === 'schedule') values.scheduled_at = schedulePayload(this.el('deliverySchedule').value);
            if (action === 'assign') {
                values.assigned_to = this.el('deliveryPersonnel').value;
                if (!this.activePerson(values.assigned_to)) throw new Error('Select active delivery personnel.');
            }
            let proofHash = null;
            if (action === 'deliver') {
                values.recipient_name = this.el('deliveryRecipient').value.trim();
                if (!values.recipient_name) throw new Error('Enter the recipient name.');
                await this.validatePhoto(file);
                proofHash = Array.from(new Uint8Array(await crypto.subtle.digest('SHA-256', await file.arrayBuffer())))
                    .map((byte) => byte.toString(16).padStart(2, '0')).join('');
            }
            const fingerprint = JSON.stringify([values, proofHash]);
            if (selected.fingerprint !== fingerprint) {
                selected.fingerprint = fingerprint;
                selected.requestKey = crypto.randomUUID();
            }
            const body = new FormData();
            Object.entries(values).forEach(([key, value]) => body.append(key, value));
            body.append('request_key', selected.requestKey);
            if (action === 'deliver') body.append('proof', file);
            const result = await this.request(`${this.base}/${Number(selected.dbId)}/action`, { method: 'POST', body });
            if (!result.data?.version) throw new Error('Update response is incomplete. Keep this dialog open and retry.');
            this.deliveries = this.deliveries.map((row) => row.dbId === result.data.dbId ? result.data : row);
            this.refresh();
            this.setBusy(false);
            this.manageModal.hide();
            try {
                await this.load();
                this.announce(`${result.data.id}: ${result.data.status}. ${result.message || 'Update saved.'}`);
            } catch {
                this.announce('Update saved. Refresh the page to reload the complete list.');
            }
        } catch (error) {
            this.showError(error.message || 'Could not verify the update. Keep this dialog open and retry.');
            if (error.errors?.version) {
                this.setBusy(false);
                this.manageModal.hide();
                await this.load().catch(() => {});
                this.announce('Delivery changed. Reopen it before applying another update.');
            }
        } finally { this.setBusy(false); }
    }

    /* Prevent edits and dismissal while an upload or write is in progress. */
    setBusy(value) {
        this.busy = value;
        this.el('deliveryManageModal').querySelectorAll('input, select, textarea, button')
            .forEach((control) => { control.disabled = value; });
    }

    /* Render canonical history and the protected proof endpoint. */
    showDetails(delivery) {
        this.el('deliveryViewTitle').textContent = `Delivery Details — ${delivery.id}`;
        const history = (delivery.history || []).map((entry) => `
            <li><strong>${escapeHtml(entry.status)}</strong> — ${escapeHtml(entry.note)}
                <small>${escapeHtml(formatDateTime(entry.date))} · ${escapeHtml(entry.actor)}</small></li>`).join('');
        const detail = (label, value, full = false) => `
            <div class="${full ? 'delivery-full' : ''}"><dt>${escapeHtml(label)}</dt><dd>${escapeHtml(value || '—')}</dd></div>`;
        const proof = delivery.hasProof ? `
            <dl class="delivery-detail-grid">
                ${detail('Recipient', delivery.recipient)}
                ${detail('Completed At', delivery.completedAt ? formatDateTime(delivery.completedAt) : '')}
                ${detail('Delivery Remarks', delivery.remarks, true)}
            </dl>
            <a href="${escapeHtml(`${this.base}/${Number(delivery.dbId)}/proof`)}" target="_blank" rel="noopener">Open delivery proof</a>
            <img class="delivery-proof" src="${escapeHtml(`${this.base}/${Number(delivery.dbId)}/proof`)}"
                alt="Proof of delivery for ${escapeHtml(delivery.id)}">`
            : '<p class="delivery-help">No completed delivery proof recorded.</p>';
        this.el('deliveryViewContent').innerHTML = `
            <dl class="delivery-detail-grid">
                ${detail('Delivery ID', delivery.id)}${detail('Order ID', delivery.orderId)}
                ${detail('Client', delivery.client)}${detail('Delivery Address', delivery.address, true)}
                ${detail('Assigned Personnel', this.personName(delivery.personId))}
                ${detail('Scheduled Delivery', formatDateTime(delivery.schedule))}
                <div><dt>Delivery Status</dt><dd>${badge(delivery.status)}</dd></div>
                ${detail('Linked Order Status', delivery.orderStatus)}
                ${detail('Inventory Coordination', delivery.status === 'Delivered' && delivery.orderStatus === 'Completed'
                    ? 'Completed delivery; reserved stock issued and sale recorded.'
                    : 'This delivery has not issued stock.', true)}
            </dl>
            <h6 class="delivery-section-title">Proof of Delivery</h6>${proof}
            <h6 class="delivery-section-title">Delivery History</h6>
            <ol class="delivery-history">${history}</ol>`;
        this.viewModal.show();
    }
}

/* Initialize once when the Delivery page exists. */
function initializeDeliveryManagement() {
    const table = document.getElementById('deliveryTable');
    if (!table || DataTable.isDataTable(table)) return;
    new DeliveryManagementUI(table);
}
if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', initializeDeliveryManagement, { once: true });
} else { initializeDeliveryManagement(); }
