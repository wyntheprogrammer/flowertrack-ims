import DataTable from 'datatables.net-bs5';
import { Modal } from 'bootstrap';
import { renderOrderItemFlowers } from './flower-table';

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

class DeliveryManagementUI {
    /* Initialize the approved table layout using database records only. */
    constructor(element) {
        this.element = element;
        this.base = element.dataset.url.replace(/\/$/, '');
        this.csrf = element.dataset.csrf;
        this.isPersonnel = element.dataset.role === 'delivery';
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
        if (this.isPersonnel) {
            if (delivery.status === 'Delivered' && delivery.orderStatus === 'Delivered' && !delivery.paymentRecorded) {
                return [['payment', 'Record Payment']];
            }
            if (delivery.status === 'Out for Delivery') {
                return [['deliver', 'Mark Delivered'], ['fail', 'Record Failed Attempt']];
            }
            return ['Assigned', 'Scheduled', 'Rescheduled', 'Failed'].includes(delivery.status)
                ? [['dispatch', 'Start Delivery']] : [];
        }
        if (!['Pending', 'Assigned', 'Scheduled', 'Rescheduled', 'Failed'].includes(delivery.status)) return [];
        return [['assign', delivery.personId ? 'Reassign Personnel' : 'Assign Personnel'], ['cancel', 'Cancel Delivery']];
    }

    /* Preserve column alignment, native sorting and compact pagination. */
    initializeTable() {
        this.table = new DataTable(this.element, {
            data: this.deliveries,
            autoWidth: false,
            pageLength: 5,
            orderFixed: { pre: [[4, 'asc']] },
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
                    data: 'items',
                    orderable: false,
                    searchable: false,
                    render: (items, type) => type === 'display' ? renderOrderItemFlowers(items, escapeHtml) : '',
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
                    data: 'status',
                    render: (value, type, row) => {
                        const status = this.isPersonnel && value === 'Delivered' && row.orderStatus === 'Completed'
                            ? 'Completed' : value;
                        if (type === 'sort' || type === 'type') return this.statusRank(status);
                        if (type !== 'display') return status;
                        const payment = {
                            Confirmed: 'Paid',
                            Pending: 'Payment awaiting confirmation',
                            Refunded: 'Refunded',
                        }[row.paymentStatus] ?? 'Awaiting payment';
                        return value === 'Delivered'
                            ? `${badge(status)}<small class="delivery-payment-status">${payment}</small>`
                            : badge(status);
                    },
                },
                {
                    data: null,
                    orderable: false,
                    searchable: false,
                    render: (_value, type, row) => {
                        if (type !== 'display') return '';
                        const actions = this.actionsFor(row);
                        const actionLabel = actions[0]?.[0] === 'payment' ? 'Record Payment' : 'Manage Delivery';

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

                                ${actions.length ? `
                                    <button
                                        type="button"
                                        class="delivery-action-btn manage"
                                        data-delivery-action="manage"
                                        data-delivery-id="${escapeHtml(row.id)}"
                                        title="${actionLabel}"
                                        aria-label="${actionLabel} ${escapeHtml(row.id)}"
                                    >
                                        <i class="bi ${actions[0][0] === 'payment' ? 'bi-cash-coin' : 'bi-pencil-square'}" aria-hidden="true"></i>
                                    </button>
                                ` : ''}
                            </div>
                        `;
                    },
                },
            ],
        });
    }

    statusRank(status) {
        if (status === 'Pending') return 0;
        if (status === 'Completed') return 2;
        if (status === 'Delivered') return this.isPersonnel ? 1 : 3;
        if (status === 'Cancelled') return this.isPersonnel ? 3 : 2;
        return 1;
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
            if (this.isPersonnel) {
                const active = this.deliveries.filter((delivery) => !this.terminal(delivery)).length;
                const unpaid = this.deliveries.filter((delivery) => this.actionsFor(delivery)[0]?.[0] === 'payment').length;
                const count = this.el('deliveryNotificationCount');
                count.textContent = active + unpaid > 99 ? '99+' : active + unpaid;
                count.hidden = active + unpaid === 0;
                this.el('deliveryActiveCount').textContent = active;
                this.el('deliveryUnpaidCount').textContent = unpaid;
            }
            this.refresh();
            this.announce(this.deliveries.length ? '' : (this.isPersonnel ? 'No deliveries are assigned to your account.' : 'No delivery records yet. Mark a Delivery order Ready for Delivery.'));
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
        this.el('deliveryActionFields').addEventListener('change', (event) => {
            if (event.target.id === 'deliveryPaymentOrder') {
                const delivery = this.deliveries.find((row) => row.id === event.target.value);
                if (!delivery) return;
                this.selected = { id: delivery.id, dbId: delivery.dbId, version: delivery.version };
                this.el('deliveryProof').value = '';
                this.el('deliveryPaymentReference').value = '';
                this.el('deliveryReason').value = '';
                this.updatePaymentFields(delivery);
            }
            if (event.target.id === 'deliveryPaymentMethod') this.syncPaymentReference();
        });
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
    setPaymentDialog(recordingPayment) {
        this.el('deliveryManageModal').querySelector('.modal-dialog').classList.toggle('modal-lg', !recordingPayment);
        this.el('deliveryManageModal').querySelector('.delivery-eyebrow').hidden = recordingPayment;
        this.el('deliveryActionField').hidden = recordingPayment;
        this.el('deliveryAction').disabled = recordingPayment;
        this.el('deliveryManageTitle').textContent = recordingPayment ? 'Record Payment' : 'Manage Delivery';
        this.el('deliveryManageSubmit').textContent = recordingPayment ? 'Record Payment' : 'Save Update';
        this.el('deliveryManageSubmit').hidden = false;
    }

    openManage(delivery) {
        const actions = this.actionsFor(delivery);
        if (!actions.length) { this.announce('No actions are available for this delivery.'); return; }
        this.selected = { id: delivery.id, dbId: delivery.dbId, version: delivery.version,
            fingerprint: null, requestKey: null };
        this.showError();
        const recordingPayment = actions[0][0] === 'payment';
        this.setPaymentDialog(recordingPayment);
        if (!recordingPayment) this.el('deliveryManageTitle').textContent = `Manage ${delivery.id}`;
        this.el('deliveryManageSummary').textContent = recordingPayment
            ? '' : `${delivery.orderId} · ${delivery.client} · Current status: ${delivery.status}`;
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

        if (action === 'payment') {
            const eligible = this.deliveries.filter((row) => this.actionsFor(row)[0]?.[0] === 'payment');
            fields = `
                <div class="delivery-field">
                    <label for="deliveryPaymentOrder">Order <span>*</span></label>
                    <select id="deliveryPaymentOrder" required>
                        ${eligible.map((row) => `<option value="${escapeHtml(row.id)}" ${row.id === delivery.id ? 'selected' : ''}>${escapeHtml(row.orderId)} · ${escapeHtml(row.client)}</option>`).join('')}
                    </select>
                </div>
                <p class="delivery-help" id="deliveryPaymentHelp"></p>
                <div class="delivery-field"><label for="deliveryPaymentMethod">Payment Method</label>
                    <select id="deliveryPaymentMethod" required><option>Cash</option><option>Bank Transfer</option><option>GCash</option></select></div>
                <div class="delivery-field"><label for="deliveryPaymentAmount">Full Order Amount (PHP)</label>
                    <input id="deliveryPaymentAmount" type="number" step="0.01" readonly></div>
                <div class="delivery-field" id="deliveryPaymentReferenceField"><label for="deliveryPaymentReference">Transaction Reference <span>*</span></label>
                    <input id="deliveryPaymentReference" maxlength="100"></div>
                <div class="delivery-field"><label for="deliveryReason">Payment Remarks</label>
                    <textarea id="deliveryReason" rows="2" maxlength="500" required></textarea></div>
                <div class="delivery-field" id="deliveryPaymentProofField"><label for="deliveryProof">Payment Photo <span>*</span></label>
                    <input id="deliveryProof" type="file" accept="image/jpeg,image/png,image/webp"></div>
                <p class="delivery-help">Sales & Payments will review and confirm this payment.</p>`;
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
                    Confirm you are starting this delivery.
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
                    Start the delivery again when you are ready.</p>`;
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
        if (action === 'payment') {
            this.updatePaymentFields(delivery);
            this.syncPaymentReference();
        }
    }

    updatePaymentFields(delivery) {
        const amount = delivery.totalCents / 100;
        this.el('deliveryPaymentAmount').value = amount.toFixed(2);
        const formatted = new Intl.NumberFormat('en-PH', { style: 'currency', currency: 'PHP' }).format(amount);
        this.el('deliveryPaymentHelp').textContent = `Full payment required: ${formatted}.`;
    }

    syncPaymentReference() {
        const online = this.el('deliveryPaymentMethod').value !== 'Cash';
        this.el('deliveryPaymentReferenceField').hidden = !online;
        this.el('deliveryPaymentReference').required = online;
        this.el('deliveryPaymentReference').disabled = !online;
        this.el('deliveryPaymentProofField').hidden = !online;
        const proof = this.el('deliveryProof');
        proof.required = online;
        proof.disabled = !online;
        if (!online) proof.value = '';
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
            if (action === 'payment') {
                values.payment_method = this.el('deliveryPaymentMethod').value;
                values.payment_amount = this.el('deliveryPaymentAmount').value;
                values.payment_reference = values.payment_method === 'Cash' ? '' : this.el('deliveryPaymentReference').value.trim();
            }
            if (action === 'assign') {
                values.assigned_to = this.el('deliveryPersonnel').value;
                if (!this.activePerson(values.assigned_to)) throw new Error('Select active delivery personnel.');
            }
            if (action === 'deliver') {
                values.recipient_name = this.el('deliveryRecipient').value.trim();
                if (!values.recipient_name) throw new Error('Enter the recipient name.');
            }
            if (file) {
                await this.validatePhoto(file);
            }
            const fingerprint = JSON.stringify(values);
            if (selected.fingerprint !== fingerprint || selected.file !== file) {
                selected.fingerprint = fingerprint;
                selected.file = file;
                const bytes = crypto.getRandomValues(new Uint8Array(16));
                bytes[6] = (bytes[6] & 15) | 64;
                bytes[8] = (bytes[8] & 63) | 128;
                const hex = Array.from(bytes, (value) => value.toString(16).padStart(2, '0')).join('');
                selected.requestKey = hex.replace(/^(........)(....)(....)(....)(............)$/, '$1-$2-$3-$4-$5');
            }
            const body = new FormData();
            Object.entries(values).forEach(([key, value]) => body.append(key, value));
            body.append('request_key', selected.requestKey);
            if (file) body.append('proof', file);
            const result = await this.request(`${this.base}/${Number(selected.dbId)}/action`, { method: 'POST', body });
            if (!result.data?.version) throw new Error('Update response is incomplete. Keep this dialog open and retry.');
            this.deliveries = this.deliveries.map((row) => row.dbId === result.data.dbId ? result.data : row);
            this.refresh();
            this.setBusy(false);
            this.manageModal.hide();
            try {
                await this.load();
                this.announce(action === 'payment'
                    ? `${result.data.orderId}: payment submitted for Sales & Payments confirmation.`
                    : `${result.data.id}: ${result.data.status}. ${result.message || 'Update saved.'}`);
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
        if (!value && this.el('deliveryPaymentMethod')) {
            this.el('deliveryAction').disabled = true;
            this.syncPaymentReference();
        }
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
    const feeSettings = document.querySelector('#deliveryFeeSettingsModal[data-reopen]');
    if (feeSettings) Modal.getOrCreateInstance(feeSettings).show();
    new DeliveryManagementUI(table);
}
if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', initializeDeliveryManagement, { once: true });
} else { initializeDeliveryManagement(); }

