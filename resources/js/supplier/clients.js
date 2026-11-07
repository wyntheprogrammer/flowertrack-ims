import DataTable from 'datatables.net-bs5';
import { Modal } from 'bootstrap';

/* ==================================================
   SHARED DISPLAY HELPERS
================================================== */

const escapeHtml = value => String(value ?? '').replace(/[&<>"']/g, character => ({
    '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#039;',
})[character]);

function formatDate(value) {
    if (!/^\d{4}-\d{2}-\d{2}$/.test(value || '')) return '—';
    const [year, month, day] = value.split('-').map(Number);
    return new Intl.DateTimeFormat('en-PH', {month: 'short', day: '2-digit', year: 'numeric'})
        .format(new Date(year, month - 1, day));
}

function initials(name) {
    return name.trim().split(/\s+/).slice(0, 2).map(part => Array.from(part)[0]).join('').toUpperCase();
}

function statusBadge(status) {
    return `<span class="client-badge ${status.toLowerCase()}">${escapeHtml(status)}</span>`;
}

/* ==================================================
   CLIENT MANAGEMENT — DATABASE-BACKED ACCOUNTS
================================================== */

class ClientManagementUI {
    /* ==================================================
       CONSTRUCTOR
    ================================================== */

    constructor(element) {
        this.element = element;
        this.clients = [];
        this.ready = false;
        this.loading = false;
        this.saving = false;
        this.profileLoading = false;
        this.committed = false;
        this.editingId = null;
        this.editingVersion = null;
        this.pendingStatusId = null;
        this.pendingStatusVersion = null;
        this.pendingDesiredStatus = null;
        this.form = this.el('clientForm');
        this.formModal = Modal.getOrCreateInstance(this.el('clientFormModal'));
        this.viewModal = Modal.getOrCreateInstance(this.el('clientViewModal'));
        this.statusModal = Modal.getOrCreateInstance(this.el('clientStatusModal'));
        this.initializeTable();
        this.mountScroller();
        this.bindEvents();
        this.loadClients();
    }

    /* ==================================================
       EL
    ================================================== */

    el(id) { return document.getElementById(id); }

    /* ==================================================
       INITIALIZE TABLE
    ================================================== */

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

    /* ==================================================
       MOUNT SCROLLER
    ================================================== */

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

    /* ==================================================
       BIND EVENTS
    ================================================== */

    bindEvents() {
        this.el('addClientBtn').addEventListener('click', () => this.openForm());
        ['input', 'search'].forEach(event => {
            this.el('clientSearch').addEventListener(event, () => this.applySearch());
        });
        this.el('clientPageLength').addEventListener('change', event => {
            const length = Number(event.target.value);
            if ([5, 10, 25, 50].includes(length)) this.table.page.len(length).draw();
        });
        this.table.on('length', (_event, _settings, length) => {
            this.el('clientPageLength').value = String(length);
        });
        this.element.addEventListener('click', event => {
            const button = event.target.closest('[data-client-action]');
            if (!button || button.disabled || !this.ready || this.saving || this.profileLoading) return;
            const client = this.clients.find(item => item.id === button.dataset.clientId);
            if (!client) return;
            if (button.dataset.clientAction === 'view') this.showProfile(client);
            if (button.dataset.clientAction === 'edit') this.openForm(client);
            if (button.dataset.clientAction === 'status') this.openStatusConfirmation(client);
        });
        this.form.addEventListener('submit', event => {
            event.preventDefault();
            this.saveClient();
        });
        this.el('confirmClientStatus').addEventListener('click', () => this.confirmStatus());
        ['clientFormModal', 'clientStatusModal'].forEach(id => {
            this.el(id).addEventListener('hide.bs.modal', event => {
                if (this.saving && !this.committed) event.preventDefault();
            });
        });
        this.el('clientFormModal').addEventListener('hidden.bs.modal', () => {
            this.form.reset();
            this.el('clientPassword').value = '';
            this.editingId = null;
            this.editingVersion = null;
            this.hideFormError();
        });
        this.el('clientStatusModal').addEventListener('hidden.bs.modal', () => {
            this.pendingStatusId = null;
            this.pendingStatusVersion = null;
            this.pendingDesiredStatus = null;
        });
    }

    /* ==================================================
       ACCEPT ONLY PUBLIC CLIENT FIELDS — NEVER PASSWORDS
    ================================================== */

    

    mapClient(row) {
        if (!row || !Number.isSafeInteger(row.dbId) || row.dbId < 1
            || typeof row.id !== 'string' || !row.id.startsWith('CLI-')
            || !['Active', 'Inactive'].includes(row.status)
            || !/^[a-f0-9]{64}$/.test(row.version)
            || !/^\d{4}-\d{2}-\d{2}$/.test(row.registered)) {
            throw new Error('Invalid client response. Refresh the list before retrying.');
        }
        return {
            dbId: row.dbId, id: row.id, name: row.name, email: row.email,
            phone: row.phone, address: row.address, registered: row.registered,
            status: row.status, version: row.version,
        };
    }

    /* ==================================================
       BUILD ENDPOINTS FROM LARAVEL NAMED ROUTES
    ================================================== */

    

    endpoint(name, id = null) {
        let url = this.element.dataset[name];
        if (!url) throw new Error('Missing client endpoint. Replace the Blade file too.');
        if (id !== null) {
            if (!url.includes('__CLIENT__')) throw new Error('Invalid client URL template.');
            url = url.replace('__CLIENT__', encodeURIComponent(String(id)));
        }
        return url;
    }

    /* ==================================================
       FETCH JSON WITH SESSION AND CSRF PROTECTION
    ================================================== */

    

    async request(url, payload = null) {
        const headers = {Accept: 'application/json'};
        if (payload !== null) {
            const token = this.form.querySelector('[name="_token"]')?.value;
            if (!token) throw new Error('Missing CSRF token. Add @csrf inside the client form.');
            headers['X-CSRF-TOKEN'] = token;
            headers['Content-Type'] = 'application/json';
        }
        const response = await fetch(url, {
            method: payload === null ? 'GET' : 'POST', headers,
            credentials: 'same-origin', redirect: 'error',
            ...(payload === null ? {} : {body: JSON.stringify(payload)}),
        });
        const isJson = (response.headers.get('content-type') || '').includes('application/json');
        const result = isJson ? await response.json() : {};
        if (!response.ok) {
            const messages = {
                401: 'Your session has expired. Please log in again.',
                403: 'Your account cannot perform this action.',
                404: 'The client or endpoint was not found. Refresh the page.',
                419: 'Your session token has expired. Refresh the page.',
            };
            const error = new Error(messages[response.status]
                || (response.status >= 500 ? 'The server could not complete the request.' : result.message)
                || 'Unable to complete the request.');
            error.status = response.status;
            error.errors = result.errors || {};
            throw error;
        }
        if (!isJson) throw new Error('Unexpected response. Refresh the page and check your login.');
        return result;
    }

    /* ==================================================
       LOAD ACTUAL CLIENT ACCOUNTS
    ================================================== */

    

    async loadClients() {
        if (this.loading || this.saving) return;
        this.loading = true;
        this.ready = false;
        this.updateControls();
        this.announce('Loading client records...');
        try {
            const result = await this.request(this.endpoint('url'));
            if (!Array.isArray(result.data)) throw new Error('Invalid client list response.');
            const records = result.data.map(row => this.mapClient(row));
            this.clients = records;
            this.refreshTable(false);
            this.ready = true;
            this.announce(records.length ? '' : 'No client accounts yet. Click Add New Client to begin.');
        } catch (error) {
            this.announce(error.message || 'Unable to load client records.', true);
        } finally {
            this.loading = false;
            this.updateControls();
        }
    }

    /* ==================================================
       PRESERVE APPROVED SEARCH, SORTING AND PAGINATION
    ================================================== */

    

    refreshTable(resetPaging = false) {
        this.table.clear();
        this.table.rows.add(this.clients);
        this.table.draw(resetPaging);
    }

    /* ==================================================
       APPLY SEARCH
    ================================================== */

    applySearch() {
        this.table.search(this.el('clientSearch').value.trim(), {
            regex: false, smart: true, caseInsensitive: true,
        }).draw();
    }

    /* ==================================================
       UPDATE ONE RECORD ONLY AFTER BACKEND SUCCESS
    ================================================== */

    

    upsertClient(data) {
        const client = this.mapClient(data);
        const index = this.clients.findIndex(item => item.dbId === client.dbId);
        if (index === -1) this.clients.unshift(client);
        else this.clients.splice(index, 1, client);
        this.refreshTable(false);
        this.updateControls();
        return client;
    }

    /* ==================================================
       LOCK WRITE ACTIONS AND FORMS DURING REQUESTS
    ================================================== */

    

    updateControls() {
        const unavailable = !this.ready || this.loading || this.saving || this.profileLoading;
        this.el('addClientBtn').disabled = unavailable;
        this.element.querySelectorAll('[data-client-action]').forEach(button => {button.disabled = unavailable;});
        for (const id of ['clientName', 'clientEmail', 'clientPhone', 'clientAddress', 'clientStatus']) {
            this.el(id).disabled = this.saving;
        }
        this.el('clientPassword').disabled = this.saving || this.editingId !== null;
        this.form.querySelector('[type="submit"]').disabled = unavailable;
        this.el('confirmClientStatus').disabled = unavailable;
        this.form.setAttribute('aria-busy', String(this.saving));
    }

    /* ==================================================
       OPEN CREATE / EDIT FORM
       Temporary password exists only in the creation form.
    ================================================== */

    

    openForm(client = null) {
        if (!this.ready || this.saving || this.profileLoading) return;
        this.form.reset();
        this.hideFormError();
        this.editingId = client?.dbId || null;
        this.editingVersion = client?.version || null;
        const editing = this.editingId !== null;
        this.el('clientFormTitle').textContent = editing ? 'Edit Client' : 'Add New Client';
        this.el('clientEditingId').value = client?.id || '';
        this.el('clientCode').value = client?.id || 'Generated after saving';
        this.el('clientName').value = client?.name || '';
        this.el('clientEmail').value = client?.email || '';
        this.el('clientPhone').value = client?.phone || '';
        this.el('clientAddress').value = client?.address || '';
        this.el('clientStatus').value = client?.status || 'Active';
        this.el('clientPasswordField').hidden = editing;
        this.el('clientPassword').required = !editing;
        this.el('clientPassword').value = '';
        this.el('clientAccountNote').hidden = editing;
        this.updateControls();
        this.formModal.show();
    }

    /* ==================================================
       SAVE CLIENT PROFILE AND FIXED-ROLE ACCOUNT
    ================================================== */

    

    async saveClient() {
        if (!this.ready || this.saving || !this.form.reportValidity()) return;
        this.hideFormError();
        const editing = this.editingId !== null;
        const payload = {
            name: this.el('clientName').value.trim(),
            email: this.el('clientEmail').value.trim().toLowerCase(),
            phone: this.el('clientPhone').value.trim(),
            address: this.el('clientAddress').value.trim(),
            status: this.el('clientStatus').value,
        };
        if (editing) payload.version = this.editingVersion;
        else payload.password = this.el('clientPassword').value;
        this.saving = true;
        this.committed = false;
        this.updateControls();
        try {
            const url = editing ? this.endpoint('updateUrl', this.editingId) : this.endpoint('storeUrl');
            const result = await this.request(url, payload);
            const client = this.upsertClient(result.data);
            this.el('clientPassword').value = '';
            this.committed = true;
            this.formModal.hide();
            this.announce(result.message || `${client.name} saved successfully.`);
        } catch (error) {
            this.showFormError(this.errorText(error));
        } finally {
            this.saving = false;
            this.committed = false;
            this.updateControls();
        }
    }

    /* ==================================================
       CONFIRM EXPLICIT ACCOUNT STATUS — NEVER BLIND TOGGLE
    ================================================== */

    

    openStatusConfirmation(client) {
        if (!this.ready || this.saving || this.profileLoading) return;
        this.pendingStatusId = client.dbId;
        this.pendingStatusVersion = client.version;
        this.pendingDesiredStatus = client.status === 'Active' ? 'Inactive' : 'Active';
        const deactivate = this.pendingDesiredStatus === 'Inactive';
        this.el('clientStatusError').hidden = true;
        this.el('clientStatusTitle').textContent = deactivate ? 'Deactivate Client' : 'Activate Client';
        this.el('clientStatusMessage').textContent = deactivate
            ? `Deactivate ${client.name}? The account will become inactive. Existing records will be retained.`
            : `Activate ${client.name}? The account will become active again.`;
        this.el('confirmClientStatus').textContent = deactivate ? 'Deactivate' : 'Activate';
        this.statusModal.show();
    }

    /* ==================================================
       CONFIRM STATUS
    ================================================== */

    async confirmStatus() {
        if (!this.ready || this.saving || this.pendingStatusId === null) return;
        this.el('clientStatusError').hidden = true;
        this.saving = true;
        this.committed = false;
        this.updateControls();
        try {
            const result = await this.request(this.endpoint('statusUrl', this.pendingStatusId), {
                status: this.pendingDesiredStatus,
                version: this.pendingStatusVersion,
            });
            this.upsertClient(result.data);
            this.committed = true;
            this.statusModal.hide();
            this.announce(result.message || 'Client account status updated.');
        } catch (error) {
            this.el('clientStatusError').textContent = this.errorText(error);
            this.el('clientStatusError').hidden = false;
        } finally {
            this.saving = false;
            this.committed = false;
            this.updateControls();
        }
    }

    /* ==================================================
       CLEAR AND DISPLAY SERVER VALIDATION ERRORS
    ================================================== */

    

    hideFormError() {
        this.el('clientFormError').hidden = true;
        this.el('clientFormError').textContent = '';
    }

    /* ==================================================
       SHOW FORM ERROR
    ================================================== */

    showFormError(message) {
        this.el('clientFormError').textContent = message;
        this.el('clientFormError').hidden = false;
    }

    /* ==================================================
       ERROR TEXT
    ================================================== */

    errorText(error) {
        const messages = Object.values(error.errors || {}).flat();
        let text = messages.length ? messages.join('\n') : error.message || 'Unable to complete saving.';
        if (error.errors?.version) text += ' Close the form, refresh the page, and reopen the client.';
        if (!messages.length && ![401, 403, 404, 419, 422].includes(error.status)) {
            text += ' Refresh to check whether saving succeeded before retrying.';
        }
        return text;
    }

    /* ==================================================
       ANNOUNCE
    ================================================== */

    announce(message, isError = false) {
        const element = this.el('clientAnnouncement');
        element.textContent = message;
        element.hidden = !message;
        element.classList.toggle('text-danger', isError);
    }

    /* ==================================================
       LOAD AUTHORITATIVE PROFILE
       Order history and gross completed-order totals come from the server.
    ================================================== */

    

    async showProfile(client) {
        if (!this.ready || this.saving || this.profileLoading) return;
        this.profileLoading = true;
        this.updateControls();
        this.announce('Loading client details...');
        try {
            const result = await this.request(this.endpoint('showUrl', client.dbId));
            const current = this.upsertClient(result.data);
            this.renderProfile(current, result);
            this.viewModal.show();
            this.announce('');
        } catch (error) {
            this.announce(error.message || 'Unable to load client details.', true);
        } finally {
            this.profileLoading = false;
            this.updateControls();
        }
    }

    /* ==================================================
       RENDER REAL PROFILE AND SERVER-DERIVED ORDER SUMMARY
    ================================================== */

    

    renderProfile(client, profile = null) {
        const available = Boolean(profile?.order_history_available && profile.summary && Array.isArray(profile.orders));
        const summary = available ? profile.summary : null;
        const currency = cents => new Intl.NumberFormat('en-PH', {style: 'currency', currency: 'PHP'}).format(Number(cents) / 100);
        const metric = key => summary ? Number(summary[key]).toLocaleString('en-PH') : '—';
        const rows = available && profile.orders.length ? profile.orders.map(order => `
            <tr>
                <td>${escapeHtml(order.id)}</td><td>${formatDate(order.date)}</td>
                <td><span class="client-badge order">${escapeHtml(order.status)}</span></td>
                <td>${currency(order.totalCents)}</td>
            </tr>`).join('') : `<tr><td colspan="4" class="client-history-empty">${available ? 'No orders recorded for this client.' : 'Order history is not yet available.'}</td></tr>`;
        this.el('clientViewTitle').textContent = `Client Details — ${client.id}`;
        this.el('clientViewContent').innerHTML = `
            <div class="client-profile-heading">
                <span class="client-avatar" aria-hidden="true">${escapeHtml(initials(client.name))}</span>
                <div><h3>${escapeHtml(client.name)}</h3><p>${escapeHtml(client.id)}</p></div>
                ${statusBadge(client.status)}
            </div>
            <dl class="client-profile-grid">
                <div><dt>Email Address</dt><dd>${escapeHtml(client.email)}</dd></div>
                <div><dt>Contact Number</dt><dd>${escapeHtml(client.phone)}</dd></div>
                <div><dt>Registration Date</dt><dd>${formatDate(client.registered)}</dd></div>
                <div><dt>Address</dt><dd>${escapeHtml(client.address)}</dd></div>
            </dl>
            <h4 class="client-section-title">Purchase Summary</h4>
            <div class="client-purchase-summary">
                <div><span>Total Orders</span><strong>${metric('total_orders')}</strong></div>
                <div><span>Completed Purchases</span><strong>${summary ? currency(summary.completed_purchases_cents) : '—'}</strong></div>
                <div><span>Completed Orders</span><strong>${metric('completed_orders')}</strong></div>
                <div><span>Pending / Ongoing</span><strong>${metric('ongoing_orders')}</strong></div>
            </div>
            <p class="client-form-note">${summary ? 'Completed Purchases totals completed order amounts, including delivery fees. It is not a payment balance or a refund-adjusted collection total.' : 'Order history and purchase totals are not yet available.'}</p>
            <h4 class="client-section-title">Order History</h4>
            <div class="client-history-scroll" tabindex="0" role="region" aria-label="Client order history">
                <table class="client-history">
                    <thead><tr><th scope="col">Order ID</th><th scope="col">Date</th><th scope="col">Status</th><th scope="col">Amount</th></tr></thead>
                    <tbody>${rows}</tbody>
                </table>
            </div>`;
    }
}

/* ==================================================
   START ONLY ON THE CLIENT MANAGEMENT PAGE
================================================== */

function initializeClientManagement() {
    const table = document.getElementById('clientTable');
    if (!table || DataTable.isDataTable(table)) return;
    new ClientManagementUI(table);
}

if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', initializeClientManagement, {once: true});
} else {
    initializeClientManagement();
}
