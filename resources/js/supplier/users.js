import DataTable from 'datatables.net-bs5';
import { Modal } from 'bootstrap';

const escapeHTML = (value) => String(value ?? '').replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#039;' }[c]));
const roles = ['Admin', 'Supplier/Staff', 'Delivery Personnel'];
const rolePermissions = {
    Admin: ['All Supplier modules, including Reports, User Management and Audit Trail.', 'Create accounts, change roles/email/status, and issue temporary passwords.'],
    'Supplier/Staff': ['Operational Flower, Inventory, Batch, Prediction, Client, Order, Delivery and Sales/Payments access.', 'No Reports, User Management or Audit Trail access. Account administration remains Admin-only.'],
    'Delivery Personnel': ['Assigned deliveries only: view details, record failed attempts and submit actual delivery proof.', 'No order, inventory, payment, fee or assignment changes.'],
};
const formatTime = (value) => new Intl.DateTimeFormat('en-PH', { timeZone: 'Asia/Manila', dateStyle: 'medium', timeStyle: 'short' }).format(new Date(value));
const initials = (name) => name.trim().split(/\s+/).slice(0, 2).map((part) => Array.from(part)[0]).join('').toUpperCase();
const badge = (status) => `<span class="user-badge ${status.toLowerCase()}">${escapeHTML(status)}</span>`;

/* Keep public account data only; credentials never enter table records. */
class UserModel {
    constructor() { this.users = []; }
    find(id) { return this.users.find((user) => user.id === id); }
}

class UserManagementUI {
    /* Preserve the approved table layout and initialize an empty database-backed list. */
    constructor() {
        const page = this.el('userPage');
        this.base = page.dataset.url.replace(/\/$/, '');
        this.csrf = page.dataset.csrf;
        this.model = new UserModel();
        this.pending = null;
        this.editingVersion = null;
        this.busy = false;
        this.loading = false;
        this.formModal = Modal.getOrCreateInstance(this.el('userFormModal'));
        this.actionModal = Modal.getOrCreateInstance(this.el('userActionModal'));
        this.viewModal = Modal.getOrCreateInstance(this.el('userViewModal'));
        this.table = new DataTable('#userTable', {
            data: this.model.users, autoWidth: false, pageLength: 5, order: [[0, 'asc']],
            layout: { topStart: null, topEnd: null, bottomStart: 'info', bottomEnd: 'paging' },
            language: { emptyTable: 'No users available.', zeroRecords: 'No matching users found.', info: 'Showing _START_ to _END_ of _TOTAL_ entries', infoEmpty: 'Showing 0 to 0 of 0 entries', infoFiltered: '(filtered from _MAX_ total entries)', paginate: { first: '«', previous: '‹', next: '›', last: '»' } },
            columns: [
                { data: 'name', width: '27%', render: (value, type, row) => type === 'display' ? `<div class="user-identity"><span class="user-avatar" aria-hidden="true">${escapeHTML(initials(value))}</span><div><strong>${escapeHTML(value)}</strong><small>${escapeHTML(row.id)}</small></div></div>` : `${value} ${row.id}` },
                { data: 'email', width: '25%', render: (value, type) => type === 'display' ? escapeHTML(value) : value },
                { data: 'role', width: '18%', render: (value, type) => type === 'display' ? escapeHTML(value) : value },
                { data: 'status', width: '12%', render: (value, type) => type === 'display' ? badge(value) : value },
                { data: null, width: '18%', orderable: false, searchable: false, render: (_value, type, row) => type === 'display' ? this.actions(row) : '' },
            ],
        });
        const table = this.el('userTable'); const wrapper = document.createElement('div'); wrapper.className = 'user-table-scroll'; wrapper.tabIndex = 0; wrapper.setAttribute('role', 'region'); wrapper.setAttribute('aria-label', 'Users table. Scroll horizontally for all columns.'); table.before(wrapper); wrapper.append(table);
        this.bindEvents();
        this.load().catch((error) => this.announce(error.message));
    }

    /* Find page and modal controls. */
    el(id) { return document.getElementById(id); }

    /* Announce a canonical result or recovery instruction. */
    announce(message) { this.el('userAnnouncement').textContent = message; }

    /* Use server permissions for protected Admin and current-account actions. */
    actions(user) {
        const button = (action, icon, label, disabled = false) => `<button type="button" class="user-icon-btn ${action}"
            data-user-action="${action}" data-user-id="${escapeHTML(user.id)}" title="${escapeHTML(label)}"
            aria-label="${escapeHTML(label)} ${escapeHTML(user.name)}" ${disabled ? 'disabled' : ''}>
            <i class="bi bi-${icon}" aria-hidden="true"></i></button>`;
        const active = user.status === 'Active';
        return `<div class="user-actions">${button('view', 'eye', 'View user')}
            ${button('edit', 'pencil-square', 'Edit user')}
            ${button('reset', 'key', user.isSelf ? 'Use Change Password for your own account' : 'Reset temporary password', !user.canReset)}
            ${button(active ? 'deactivate' : 'activate', active ? 'person-dash' : 'person-check',
                user.isSelf ? 'Your own account is protected' : user.isLastAdmin ? 'Last active Admin is protected'
                : active ? 'Deactivate' : 'Activate', !user.canChangeStatus)}</div>`;
    }

    /* Send explicit JSON inputs and surface Laravel validation/session responses. */
    async request(url, body = null) {
        const response = await fetch(url, {
            method: body === null ? 'GET' : 'POST', credentials: 'same-origin',
            headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest',
                'X-CSRF-TOKEN': this.csrf, ...(body === null ? {} : { 'Content-Type': 'application/json' }) },
            ...(body === null ? {} : { body: JSON.stringify(body) }),
        });
        const result = await response.json().catch(() => null);
        if (!response.ok || !result || response.redirected) {
            const messages = { 401: 'Please sign in again.', 403: 'Only an active Admin can manage accounts.',
                404: 'Operational account not found.', 419: 'Session expired. Refresh the page.' };
            const error = new Error(messages[response.status]
                || (result?.errors ? Object.values(result.errors).flat().join(' ') : null)
                || (response.status >= 500 ? 'Server error. Refresh the account before retrying.' : result?.message)
                || 'Could not verify the response. Refresh the list before retrying.');
            error.errors = result?.errors || {};
            throw error;
        }
        return result;
    }

    /* Load accounts without sample rows or stored passwords. */
    async load() {
        this.loading = true;
        try {
            const result = await this.request(`${this.base}/data`);
            if (!Array.isArray(result.data)) throw new Error('Unexpected account response. Refresh the page.');
            this.model.users = result.data;
            this.refresh();
        } finally { this.loading = false; }
    }

    /* Retain table search and pagination while replacing rows from the server. */
    refresh(message = '') {
        this.table.clear(); this.table.rows.add(this.model.users); this.table.draw(false);
        this.announce(message);
    }

    /* Bind forms and prevent dismissal or duplicate submissions during writes. */
    bindEvents() {
        this.el('addUserBtn').addEventListener('click', () => { if (!this.busy && !this.loading) this.openForm(); });
        this.el('userSearch').addEventListener('input', (event) => this.table.search(event.target.value).draw());
        this.el('userLength').addEventListener('change', (event) => this.table.page.len(Number(event.target.value)).draw());
        this.el('userTable').addEventListener('click', (event) => {
            const button = event.target.closest('[data-user-action]');
            if (!button || button.disabled || this.busy || this.loading) return;
            const user = this.model.find(button.dataset.userId);
            if (user) this.openRecord(user, button.dataset.userAction);
        });
        this.el('userForm').addEventListener('submit', (event) => { event.preventDefault(); this.save(); });
        this.el('userActionForm').addEventListener('submit', (event) => { event.preventDefault(); this.performAction(); });
        ['userFormModal', 'userActionModal'].forEach((id) => this.el(id).addEventListener('hide.bs.modal', (event) => {
            if (this.busy) event.preventDefault();
        }));
        this.el('userFormModal').addEventListener('hidden.bs.modal', () => {
            this.el('userForm').reset(); this.el('userPassword').value = ''; this.editingVersion = null;
        });
        this.el('userActionModal').addEventListener('hidden.bs.modal', () => {
            this.el('userResetPassword').value = ''; this.pending = null;
        });
    }

    /* Fetch a fresh version before displaying details, edit or confirmation. */
    async openRecord(user, action) {
        this.loading = true;
        try {
            const result = await this.request(`${this.base}/${user.dbId}`);
            if (!result.data?.version) throw new Error('Account details are unavailable.');
            const fresh = result.data;
            this.model.users = this.model.users.map((row) => row.dbId === fresh.dbId ? fresh : row);
            this.refresh();
            if (action === 'view') this.view(fresh);
            else if (action === 'edit') this.openForm(fresh);
            else this.openAction(fresh, action);
        } catch (error) { this.announce(error.message); }
        finally { this.loading = false; }
    }

    /* Display an error without copying credentials into account data. */
    error(id, message = '') { this.el(id).textContent = message; this.el(id).hidden = !message; }

    /* Populate the form; passwords are accepted only when creating an account. */
    openForm(user = null) {
        this.el('userForm').reset(); this.error('userFormError');
        this.editingVersion = user?.version || null;
        this.el('userFormTitle').textContent = user ? 'Edit User' : 'Add New User';
        this.el('userEditingId').value = user?.dbId || '';
        this.el('userCode').value = user?.id || 'Generated after saving';
        this.el('userName').value = user?.name || '';
        this.el('userEmail').value = user?.email || '';
        this.el('userRole').value = user?.roleValue || 'supplier';
        this.el('userStatus').value = user?.status || 'Active';
        this.el('userPasswordField').hidden = Boolean(user);
        this.el('userPassword').disabled = Boolean(user);
        this.el('userPassword').required = !user;
        this.el('userPassword').value = '';
        this.formModal.show();
    }

    /* Check bcrypt's byte limit before sending a temporary password. */
    passwordValid(value) {
        if (Array.from(value).length < 12 || new TextEncoder().encode(value).length > 72 || value.includes('\0')) {
            throw new Error('Use at least 12 characters, at most 72 UTF-8 bytes, with no null characters.');
        }
    }

    /* Create or update using an allowlist; never merge credentials into the table model. */
    async save() {
        if (this.busy) return;
        this.error('userFormError');
        if (!this.el('userForm').reportValidity()) return;
        const id = this.el('userEditingId').value;
        const values = { name: this.el('userName').value.trim(), email: this.el('userEmail').value.trim().toLowerCase(),
            role: this.el('userRole').value, status: this.el('userStatus').value };
        try {
            if (id) values.version = this.editingVersion;
            else { values.password = this.el('userPassword').value; this.passwordValid(values.password); }
            this.setBusy(true);
            const result = await this.request(id ? `${this.base}/${Number(id)}/update` : this.base, values);
            this.el('userPassword').value = '';
            this.setBusy(false); this.formModal.hide();
            await this.reloadAfterWrite(result);
        } catch (error) { this.error('userFormError', error.message || 'Could not verify the update. Refresh before retrying.'); }
        finally { delete values.password; this.setBusy(false); }
    }

    /* Confirm an explicit status or a separate temporary-password reset. */
    openAction(user, action) {
        if ((action === 'reset' && !user.canReset) || (action !== 'reset' && !user.canChangeStatus)) return;
        const reset = action === 'reset';
        this.pending = { dbId: user.dbId, action, version: user.version };
        this.error('userActionError');
        this.el('userActionTitle').textContent = reset ? 'Reset Temporary Password' : action === 'activate' ? 'Activate User' : 'Deactivate User';
        this.el('userActionMessage').textContent = reset
            ? `Issue a new temporary password for ${user.name}? Previous sessions will be revoked and a password change will be required.`
            : `${action === 'activate' ? 'Activate' : 'Deactivate'} ${user.name}? Deactivation blocks login and revokes existing access.`;
        this.el('userResetField').hidden = !reset;
        this.el('userResetPassword').required = reset;
        this.el('userResetPassword').disabled = !reset;
        this.el('userResetPassword').value = '';
        this.actionModal.show();
    }

    /* Apply status/reset with the selected version; there is no unsafe status toggle. */
    async performAction() {
        if (this.busy || !this.pending) return;
        this.error('userActionError');
        if (!this.el('userActionForm').reportValidity()) return;
        const pending = { ...this.pending };
        const values = { version: pending.version };
        try {
            const reset = pending.action === 'reset';
            if (reset) { values.password = this.el('userResetPassword').value; this.passwordValid(values.password); }
            else values.status = pending.action === 'activate' ? 'Active' : 'Inactive';
            this.setBusy(true);
            const result = await this.request(`${this.base}/${pending.dbId}/${reset ? 'reset-password' : 'status'}`, values);
            this.el('userResetPassword').value = '';
            this.setBusy(false); this.actionModal.hide();
            await this.reloadAfterWrite(result);
        } catch (error) { this.error('userActionError', error.message || 'Could not verify the update. Refresh before retrying.'); }
        finally { delete values.password; this.setBusy(false); }
    }

    /* Preserve the returned saved row even if the subsequent list refresh fails. */
    async reloadAfterWrite(result) {
        if (!result.data?.version) throw new Error('Update response is incomplete. Refresh the page to verify.');
        const index = this.model.users.findIndex((row) => row.dbId === result.data.dbId);
        if (index < 0) this.model.users.push(result.data); else this.model.users[index] = result.data;
        this.refresh(result.message || 'Account saved.');
        try { await this.load(); this.announce(result.message || 'Account saved.'); }
        catch { this.announce('Account saved. Refresh the page to reload the complete list.'); }
    }

    /* Lock controls while writing; restore password-field states afterward. */
    setBusy(value) {
        this.busy = value;
        ['userFormModal', 'userActionModal'].forEach((id) => this.el(id)
            .querySelectorAll('input, select, button').forEach((control) => { control.disabled = value; }));
        this.el('addUserBtn').disabled = value;
        if (!value) {
            this.el('userPassword').disabled = Boolean(this.el('userEditingId').value);
            this.el('userResetPassword').disabled = this.pending?.action !== 'reset';
        }
    }

    /* Display saved account/security facts without fabricated session history or secrets. */
    view(user) {
        this.el('userViewTitle').textContent = `User Details — ${user.id}`;
        const fields = [['Name', user.name], ['Email', user.email], ['Role', user.role], ['Status', user.status],
            ['Registered', user.created ? formatTime(user.created) : '—'],
            ['Password Change Required', user.mustChangePassword ? 'Yes — at next login' : 'No']];
        this.el('userViewContent').innerHTML = `<dl class="user-detail-grid">${fields.map(([label, value]) =>
            `<div><dt>${escapeHTML(label)}</dt><dd>${escapeHTML(value)}</dd></div>`).join('')}</dl>
            <h6 class="user-section-title">Fixed Role Permissions</h6>
            <ul class="user-permissions">${(rolePermissions[user.role] || []).map((value) => `<li>${escapeHTML(value)}</li>`).join('')}</ul>
            <p class="user-help">Passwords are never displayed. Account administration is restricted to active Admin accounts.</p>`;
        this.viewModal.show();
    }
}

/* Initialize only on the User Management page. */
function initializeUsers() {
    if (document.getElementById('userPage') && !DataTable.isDataTable('#userTable')) new UserManagementUI();
}
if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', initializeUsers, { once: true });
else initializeUsers();
