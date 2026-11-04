import DataTable from 'datatables.net-bs5';
import { Modal } from 'bootstrap';

const escapeHTML = (value) => String(value ?? '').replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#039;' }[c]));
const roles = ['Admin', 'Supplier/Staff', 'Delivery Personnel'];
const rolePermissions = {
    Admin: ['All Supplier modules, including Reports, User Management and Audit Trail.', 'Create accounts, change roles/email/status, and issue temporary passwords.'],
    'Supplier/Staff': ['Operational Flower, Inventory, Batch, Prediction, Client, Order, Delivery and Sales/Payments access.', 'No Reports, User Management or Audit Trail access. Account administration remains Admin-only.'],
    'Delivery Personnel': ['Assigned deliveries only: view delivery details, update delivery status and submit proof.', 'No order, inventory, payment, fee or assignment changes.'],
};
const formatTime = (value) => new Intl.DateTimeFormat('en-PH', { dateStyle: 'medium', timeStyle: 'short' }).format(new Date(value));
const initials = (name) => name.trim().split(/\s+/).slice(0, 2).map((part) => Array.from(part)[0]).join('').toUpperCase();
const badge = (status) => `<span class="user-badge ${status.toLowerCase()}">${escapeHTML(status)}</span>`;

/* No passwords or password hashes are kept in this preview model. */
class UserModel {
    constructor() {
        const date = new Date().toISOString();
        this.users = [
            { id: 'USR-001', name: 'Administrator', email: 'admin@example.com', role: 'Admin', status: 'Active', mustChangePassword: false, created: date },
            { id: 'USR-002', name: 'Anna Santos', email: 'anna@example.com', role: 'Supplier/Staff', status: 'Active', mustChangePassword: false, created: date },
            { id: 'USR-003', name: 'Juan Dela Cruz', email: 'juan@example.com', role: 'Delivery Personnel', status: 'Active', mustChangePassword: false, created: date },
            { id: 'USR-004', name: 'Mark Reyes', email: 'mark@example.com', role: 'Delivery Personnel', status: 'Active', mustChangePassword: true, created: date },
            { id: 'USR-005', name: 'Leo Santos', email: 'leo@example.com', role: 'Delivery Personnel', status: 'Inactive', mustChangePassword: false, created: date },
            { id: 'USR-006', name: 'Carla Ramos', email: 'carla@example.com', role: 'Supplier/Staff', status: 'Active', mustChangePassword: true, created: date },
        ];
        this.history = [];
    }
    find(id) { return this.users.find((user) => user.id === id); }
    nextId() { return `USR-${String(Math.max(0, ...this.users.map((user) => Number(user.id.split('-')[1]))) + 1).padStart(3, '0')}`; }
    lastAdmin(user) { return user.role === 'Admin' && user.status === 'Active' && this.users.filter((item) => item.role === 'Admin' && item.status === 'Active').length === 1; }
    log(id, note) { this.history.push({ id, note, date: new Date().toISOString(), actor: 'Administrator (preview)' }); }
    save(data) {
        const existing = data.id ? this.find(data.id) : null;
        if (data.id && !existing) throw new Error('User not found.');
        const name = String(data.name ?? '').trim(); const email = String(data.email ?? '').trim().toLowerCase();
        if (!name || name.length > 120 || !email || email.length > 254 || !/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(email)) throw new Error('Enter a valid name and email address.');
        if (!roles.includes(data.role) || !['Active', 'Inactive'].includes(data.status)) throw new Error('Select a valid role and status.');
        if (this.users.some((user) => user.id !== existing?.id && user.email.toLowerCase() === email)) throw new Error('Email already belongs to another user.');
        if (existing && this.lastAdmin(existing) && (data.role !== 'Admin' || data.status !== 'Active')) throw new Error('The last active Admin cannot be deactivated or changed to another role.');
        const record = { id: existing?.id || this.nextId(), name, email, role: data.role, status: data.status, created: existing?.created || new Date().toISOString(), mustChangePassword: existing?.mustChangePassword ?? true };
        const changes = existing ? ['name', 'email', 'role', 'status'].filter((key) => existing[key] !== record[key]).map((key) => `${key}: ${existing[key]} → ${record[key]}`).join('; ') : 'Account created; next-login password change required.';
        if (existing) this.users = this.users.map((user) => user.id === existing.id ? record : user); else this.users.push(record);
        this.log(record.id, changes || 'Account reviewed; no values changed.'); return record;
    }
    toggle(id) { const user = this.find(id); if (!user) throw new Error('User not found.'); return this.save({ ...user, status: user.status === 'Active' ? 'Inactive' : 'Active' }); }
    reset(id) { const user = this.find(id); if (!user) throw new Error('User not found.'); user.mustChangePassword = true; this.log(id, 'Admin temporary-password reset requested; next-login password change required. No credential retained in preview.'); }
}

class UserManagementUI {
    constructor() {
        this.model = new UserModel(); this.pending = null;
        this.formModal = Modal.getOrCreateInstance(this.el('userFormModal')); this.actionModal = Modal.getOrCreateInstance(this.el('userActionModal')); this.viewModal = Modal.getOrCreateInstance(this.el('userViewModal'));
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
    }
    el(id) { return document.getElementById(id); }
    actions(user) {
        const button = (action, icon, label, disabled = false) => `<button type="button" class="user-icon-btn ${action}" data-user-action="${action}" data-user-id="${escapeHTML(user.id)}" title="${escapeHTML(label)}" aria-label="${escapeHTML(label)} ${escapeHTML(user.name)}" ${disabled ? 'disabled' : ''}><i class="bi bi-${icon}" aria-hidden="true"></i></button>`;
        const deactivate = user.status === 'Active';
        return `<div class="user-actions">${button('view', 'eye', 'View user')}${button('edit', 'pencil-square', 'Edit user')}${button('reset', 'key', 'Reset temporary password')}${button(deactivate ? 'deactivate' : 'activate', deactivate ? 'person-dash' : 'person-check', this.model.lastAdmin(user) ? 'Last active Admin is protected' : deactivate ? 'Deactivate' : 'Activate', this.model.lastAdmin(user))}</div>`;
    }
    bindEvents() {
        this.el('addUserBtn').addEventListener('click', () => this.openForm());
        this.el('userSearch').addEventListener('input', (event) => this.table.search(event.target.value).draw());
        this.el('userLength').addEventListener('change', (event) => this.table.page.len(Number(event.target.value)).draw());
        this.el('userTable').addEventListener('click', (event) => { const button = event.target.closest('[data-user-action]'); if (!button || button.disabled) return; const user = this.model.find(button.dataset.userId); if (!user) return; const action = button.dataset.userAction; if (action === 'view') this.view(user); else if (action === 'edit') this.openForm(user); else this.openAction(user, action); });
        this.el('userForm').addEventListener('submit', (event) => { event.preventDefault(); this.save(); });
        this.el('userActionForm').addEventListener('submit', (event) => { event.preventDefault(); this.performAction(); });
        this.el('userFormModal').addEventListener('hidden.bs.modal', () => { this.el('userForm').reset(); this.el('userPassword').value = ''; });
        this.el('userActionModal').addEventListener('hidden.bs.modal', () => { this.el('userResetPassword').value = ''; this.pending = null; });
    }
    error(id, message = '') { this.el(id).textContent = message; this.el(id).hidden = !message; }
    refresh(message) { this.table.clear(); this.table.rows.add(this.model.users); this.table.draw(false); this.el('userAnnouncement').textContent = message; }
    openForm(user = null) {
        this.el('userForm').reset(); this.error('userFormError');
        this.el('userFormTitle').textContent = user ? 'Edit User' : 'Add New User'; this.el('userEditingId').value = user?.id || ''; this.el('userCode').value = user?.id || this.model.nextId(); this.el('userName').value = user?.name || ''; this.el('userEmail').value = user?.email || ''; this.el('userRole').value = user?.role || 'Supplier/Staff'; this.el('userStatus').value = user?.status || 'Active';
        this.el('userPasswordField').hidden = Boolean(user); this.el('userPassword').disabled = Boolean(user); this.el('userPassword').required = !user; this.el('userPassword').value = ''; this.formModal.show();
    }
    save() {
        this.error('userFormError'); if (!this.el('userForm').reportValidity()) return;
        try {
            const creating = !this.el('userEditingId').value;
            if (creating && this.el('userPassword').value.length < 8) throw new Error('Use a dummy temporary password of at least 8 characters.');
            // Explicit allowlist: never send the password into model records/history.
            const user = this.model.save({ id: this.el('userEditingId').value, name: this.el('userName').value, email: this.el('userEmail').value, role: this.el('userRole').value, status: this.el('userStatus').value });
            this.el('userPassword').value = ''; this.formModal.hide(); this.refresh(`${user.id} saved in this preview. Actual account access is not connected.`);
        } catch (error) { this.error('userFormError', error.message); }
    }
    openAction(user, action) {
        this.pending = { id: user.id, action, expectedStatus: user.status }; this.error('userActionError');
        const reset = action === 'reset'; this.el('userActionTitle').textContent = reset ? 'Reset Temporary Password' : action === 'activate' ? 'Activate User' : 'Deactivate User';
        this.el('userActionMessage').textContent = reset ? `Issue a new temporary password for ${user.name}? The completed system will require a password change at next login. Enter a dummy value for this preview.` : `${action === 'activate' ? 'Activate' : 'Deactivate'} ${user.name}? Deactivation must block login and invalidate sessions in the backend. This preview changes the sample status only.`;
        this.el('userResetField').hidden = !reset; this.el('userResetPassword').required = reset; this.el('userResetPassword').disabled = !reset; this.el('userResetPassword').value = ''; this.actionModal.show();
    }
    performAction() {
        this.error('userActionError'); if (!this.pending || !this.el('userActionForm').reportValidity()) return;
        try {
            const user = this.model.find(this.pending.id); if (!user || user.status !== this.pending.expectedStatus) throw new Error('Account changed. Close and reopen the dialog.');
            if (this.pending.action === 'reset') {
                if (this.el('userResetPassword').value.length < 8) throw new Error('Enter at least 8 characters.');
                this.model.reset(user.id); this.el('userResetPassword').value = '';
            } else this.model.toggle(user.id);
            this.actionModal.hide(); this.refresh(`${user.id} updated in this preview. No credential was retained.`);
        } catch (error) { this.error('userActionError', error.message); }
    }
    view(user) {
        const history = this.model.history.filter((entry) => entry.id === user.id).slice().reverse();
        this.el('userViewTitle').textContent = `User Details — ${user.id}`;
        const fields = [['Name', user.name], ['Email', user.email], ['Role', user.role], ['Status', user.status], ['Registered', formatTime(user.created)], ['Password Change Required', user.mustChangePassword ? 'Yes — at next login' : 'No sample requirement']];
        this.el('userViewContent').innerHTML = `<dl class="user-detail-grid">${fields.map(([label, value]) => `<div><dt>${escapeHTML(label)}</dt><dd>${escapeHTML(value)}</dd></div>`).join('')}</dl><h6 class="user-section-title">Fixed Role Permissions</h6><ul class="user-permissions">${rolePermissions[user.role].map((value) => `<li>${escapeHTML(value)}</li>`).join('')}</ul><p class="user-help">Read-only permission reference. The backend must enforce these rules. No passwords are displayed or stored by this prototype.</p><h6 class="user-section-title">Session Activity</h6><ol class="user-history">${history.length ? history.map((entry) => `<li>${escapeHTML(entry.note)}<small>${escapeHTML(formatTime(entry.date))} · ${escapeHTML(entry.actor)}</small></li>`).join('') : '<li>No account changes in this preview session.</li>'}</ol>`;
        this.viewModal.show();
    }
}

function initializeUsers() { if (document.getElementById('userPage') && !DataTable.isDataTable('#userTable')) new UserManagementUI(); }
if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', initializeUsers, { once: true }); else initializeUsers();
