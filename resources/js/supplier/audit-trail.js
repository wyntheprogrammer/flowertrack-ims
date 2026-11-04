import DataTable from 'datatables.net-bs5';
import { Modal } from 'bootstrap';
import { jsPDF } from 'jspdf';
import autoTable from 'jspdf-autotable';
import ExcelJS from 'exceljs';

const escapeHTML = (value) => String(value ?? '').replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#039;' }[c]));
const auditDefinition = {
    title: 'Audit Trail', note: 'Read-only sample system events. Backend-generated logging and Admin authorization are not yet connected.', columns: [
        { key: 'id', title: 'Audit ID' }, { key: 'date', title: 'Timestamp' }, { key: 'user', title: 'User' },
        { key: 'userId', title: 'User ID' }, { key: 'role', title: 'Role Snapshot' }, { key: 'module', title: 'Module' },
        { key: 'action', title: 'Action' }, { key: 'record', title: 'Record ID' }, { key: 'result', title: 'Result' },
        { key: 'description', title: 'Description' }, { key: 'ip', title: 'IP Address' },
    ]
};

/* Seeded UI examples only. No editable event creation controls. */
const eventSamples = [
    ['User Management', 'Account Created', 'USR-007', 'Success', 'Created Supplier/Staff account.', {}, { role: 'Supplier/Staff', status: 'Active' }],
    ['User Management', 'Role Changed', 'USR-002', 'Success', 'Updated role assignment.', { role: 'Supplier/Staff' }, { role: 'Admin' }],
    ['User Management', 'Password Reset', 'USR-004', 'Success', 'Issued temporary-password reset requirement.', { mustChangePassword: false }, { mustChangePassword: true }],
    ['Flower Management', 'Item Created', 'FLW-009', 'Success', 'Registered supplier-made bouquet.', {}, { name: 'Classic Rose Bouquet', itemType: 'Bouquet', baseUnit: 'Bouquet' }],
    ['Inventory Management', 'Stock-In', 'BAT-011', 'Success', 'Received finished bouquets.', { remaining: 0 }, { remaining: 12, unit: 'Bouquet' }],
    ['Order Management', 'Status Changed', 'ORD-007', 'Success', 'Confirmed actual customer collection.', { status: 'Ready for Pickup' }, { status: 'Completed' }],
    ['Inventory Management', 'Stock-Out', 'BAT-011', 'Success', 'Issued one bouquet after confirmed collection.', { remaining: 12 }, { remaining: 11, unit: 'Bouquet', order: 'ORD-007' }],
    ['Delivery Management', 'Delivered', 'DEL-003', 'Success', 'Recipient and delivery proof recorded.', { status: 'Out for Delivery' }, { status: 'Delivered' }],
    ['Sales & Payments', 'Payment Confirmed', 'PAY-005', 'Success', 'Verified full-order payment.', { status: 'Pending' }, { status: 'Confirmed', amountPHP: 1650 }],
    ['User Management', 'Deactivate Account', 'USR-001', 'Failed', 'Blocked attempt to deactivate last active Admin.', { status: 'Active' }, { status: 'Active' }],
    ['Authentication', 'Login Failed', '—', 'Failed', 'Unauthenticated login attempt. No credentials retained.', {}, {}],
    ['Batch Management', 'Metadata Updated', 'BAT-011', 'Success', 'Updated freshness estimate without changing quantities.', { freshness: 'Not set' }, { freshness: '2026-11-08' }],
    ['Client Management', 'Account Deactivated', 'CLI-005', 'Success', 'Deactivated client; history retained.', { status: 'Active' }, { status: 'Inactive' }],
    ['Spoilage Prediction', 'Prediction Generated', 'BAT-001', 'Success', 'Recorded advisory sample assessment only.', { risk: 'Medium' }, { risk: 'High' }],
];
const auditEvents = Object.freeze(eventSamples.map((row, index) => {
    const [module, action, record, result, description, before, after] = row;
    return Object.freeze({
        id: `AUD-${String(index + 1).padStart(3, '0')}`, date: new Date(Date.now() - index * 3600000).toISOString(),
        user: module === 'Authentication' ? 'Unauthenticated' : 'Administrator', userId: module === 'Authentication' ? '—' : 'USR-001',
        role: module === 'Authentication' ? '—' : 'Admin', module, action, record, result, description, ip: '127.0.0.1',
        before: Object.freeze(before), after: Object.freeze(after)
    });
}));

class AuditUI {
    constructor() {
        this.busy = false; this.viewModal = Modal.getOrCreateInstance(document.getElementById('auditViewModal'));
        document.getElementById('auditTableHost').innerHTML = '<table id="reportTable"><thead><tr><th scope="col">Date / Time</th><th scope="col">User</th><th scope="col">Module</th><th scope="col">Action</th><th scope="col">Record</th><th scope="col">Result</th><th scope="col">Details</th></tr></thead><tbody></tbody></table>';
        this.table = new DataTable('#reportTable', {
            data: auditEvents, autoWidth: false, pageLength: 5, order: [[0, 'desc']],
            layout: { topStart: null, topEnd: null, bottomStart: 'info', bottomEnd: 'paging' },
            language: { emptyTable: 'No activity available.', zeroRecords: 'No matching activity found.', info: 'Showing _START_ to _END_ of _TOTAL_ entries', infoEmpty: 'Showing 0 to 0 of 0 entries', infoFiltered: '(filtered from _MAX_ total entries)', paginate: { first: '«', previous: '‹', next: '›', last: '»' } },
            columns: [
                { data: 'date', render: (value, type) => type === 'display' ? escapeHTML(new Date(value).toLocaleString('en-PH')) : value },
                { data: 'user', render: (value, type, row) => type === 'display' ? `${escapeHTML(value)}<small style="display:block;font-size:10px;color:#968b9d">${escapeHTML(row.userId)}</small>` : `${value} ${row.userId}` },
                { data: 'module', render: (value, type) => type === 'display' ? escapeHTML(value) : value },
                { data: 'action', render: (value, type) => type === 'display' ? escapeHTML(value) : value },
                { data: 'record', render: (value, type) => type === 'display' ? escapeHTML(value) : value },
                { data: 'result', render: (value, type) => type === 'display' ? `<span class="audit-result ${value === 'Failed' ? 'failed' : ''}">${escapeHTML(value)}</span>` : value },
                { data: 'id', orderable: false, searchable: false, render: (value, type) => type === 'display' ? `<button type="button" class="audit-view-btn" data-audit-id="${escapeHTML(value)}" title="View activity" aria-label="View ${escapeHTML(value)}"><i class="bi bi-eye" aria-hidden="true"></i></button>` : value },
            ],
        });
        const table = document.getElementById('reportTable'); const wrapper = document.createElement('div'); wrapper.className = 'report-table-scroll'; wrapper.tabIndex = 0; wrapper.setAttribute('role', 'region'); wrapper.setAttribute('aria-label', 'Audit activities. Scroll horizontally for all columns.'); table.before(wrapper); wrapper.append(table);
        document.getElementById('auditSearch').addEventListener('input', (event) => this.table.search(event.target.value).draw());
        document.getElementById('auditLength').addEventListener('change', (event) => this.table.page.len(Number(event.target.value)).draw());
        table.addEventListener('click', (event) => { const button = event.target.closest('[data-audit-id]'); if (button) this.show(button.dataset.auditId); });
        document.querySelectorAll('[data-export]').forEach((button) => button.addEventListener('click', () => this.export(button.dataset.export)));
    }
    selectedRows() { return this.table.rows({ search: 'applied', order: 'applied' }).data().toArray(); }
    show(id) {
        const row = auditEvents.find((entry) => entry.id === id); if (!row) return;
        document.getElementById('auditViewTitle').textContent = `Activity Details — ${row.id}`;
        const fields = [['Timestamp', new Date(row.date).toLocaleString('en-PH')], ['Actor', `${row.user} (${row.userId})`], ['Role Snapshot', row.role], ['Module', row.module], ['Action', row.action], ['Affected Record', row.record], ['Result', row.result], ['Source IP', row.ip], ['Description', row.description]];
        const keys = [...new Set([...Object.keys(row.before), ...Object.keys(row.after)])];
        const value = (object, key) => Object.hasOwn(object, key) ? String(object[key]) : '—';
        document.getElementById('auditViewContent').innerHTML = `<dl class="audit-details">${fields.map(([label, text]) => `<div><dt>${escapeHTML(label)}</dt><dd>${escapeHTML(text)}</dd></div>`).join('')}</dl><h6>Previous and New Values</h6><div class="audit-changes"><table><thead><tr><th scope="col">Field</th><th scope="col">Previous</th><th scope="col">New</th></tr></thead><tbody>${keys.length ? keys.map((key) => `<tr><td>${escapeHTML(key)}</td><td>${escapeHTML(value(row.before, key))}</td><td>${escapeHTML(value(row.after, key))}</td></tr>`).join('') : '<tr><td colspan="3">No field changes recorded for this event.</td></tr>'}</tbody></table></div>`;
        this.viewModal.show();
    }
    async export(kind) {
        if (this.busy) return;
        this.busy = true; document.querySelectorAll('[data-export]').forEach((button) => button.disabled = true);
        const definition = auditDefinition; const rows = this.selectedRows();
        const timestamp = new Date().toISOString(); const filename = `flowertrack-audit-${timestamp.slice(0, 10)}`;
        const columns = definition.columns; const headings = columns.map((column) => column.title);
        const cells = rows.map((row) => columns.map((column) => column.money ? money(row[column.key]) : String(row[column.key])));
        try {
            if (kind === 'pdf') {
                const pdf = new jsPDF({ orientation: 'landscape' });
                pdf.setFontSize(16); pdf.text(`FlowerTrack IMS - ${definition.title}`, 14, 16);
                pdf.setFontSize(9); pdf.text('SAMPLE DATA - Search results across all pages', 14, 23);
                pdf.text(`Generated: ${timestamp}`, 14, 29);
                // Built-in PDF font lacks the peso symbol; label amounts as PHP.
                autoTable(pdf, { startY: 35, head: [headings], body: cells.map((row) => row.map((value) => value.replaceAll('₱', 'PHP '))), styles: { fontSize: 8, overflow: 'linebreak' }, headStyles: { fillColor: [23, 107, 58] }, margin: { left: 14, right: 14 } });
                pdf.save(`${filename}.pdf`);
            } else if (kind === 'excel') {
                const workbook = new ExcelJS.Workbook(); workbook.creator = 'FlowerTrack IMS';
                const sheet = workbook.addWorksheet('Report');
                sheet.addRow([`FlowerTrack IMS - ${definition.title}`]); sheet.addRow(['SAMPLE DATA - Search results across all pages']); sheet.addRow([`Generated: ${timestamp}`]); sheet.addRow([definition.note]); sheet.addRow([]); sheet.addRow(headings);
                rows.forEach((row) => sheet.addRow(columns.map((column) => {
                    const value = column.money ? row[column.key] / 100 : row[column.key];
                    // Text is written as a string, never as an Excel formula object.
                    return typeof value === 'number' ? value : String(value);
                })));
                columns.forEach((column, index) => { sheet.getColumn(index + 1).width = column.money ? 18 : 25; if (column.money) sheet.getColumn(index + 1).numFmt = '"PHP "#,##0.00'; });
                sheet.getRow(6).font = { bold: true, color: { argb: 'FFFFFFFF' } }; sheet.getRow(6).fill = { type: 'pattern', pattern: 'solid', fgColor: { argb: 'FF176B3A' } };
                sheet.views = [{ state: 'frozen', ySplit: 6 }]; sheet.autoFilter = { from: { row: 6, column: 1 }, to: { row: 6, column: columns.length } };
                const bytes = await workbook.xlsx.writeBuffer(); this.download(new Blob([bytes], { type: 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet' }), `${filename}.xlsx`);
            } else if (kind === 'print') {
                const frame = document.createElement('iframe'); frame.style.position = 'fixed'; frame.style.width = '0'; frame.style.height = '0'; frame.style.border = '0';
                const html = `<!doctype html><html><head><title>${escapeHTML(definition.title)}</title><style>@page{size:A4 landscape;margin:12mm}body{font-family:Arial,sans-serif;color:#344054}h1{font-size:20px}p{font-size:11px}table{width:100%;border-collapse:collapse;font-size:10px}th,td{padding:8px;border:1px solid #ddd;text-align:left}th{background:#eee7f3}thead{display:table-header-group}tr{break-inside:avoid}</style></head><body><h1>FlowerTrack IMS — ${escapeHTML(definition.title)}</h1><p>SAMPLE DATA · All matching rows · ${escapeHTML(timestamp)}</p><p>${escapeHTML(definition.note)}</p><table><thead><tr>${headings.map((value) => `<th>${escapeHTML(value)}</th>`).join('')}</tr></thead><tbody>${cells.map((row) => `<tr>${row.map((value) => `<td>${escapeHTML(value)}</td>`).join('')}</tr>`).join('')}</tbody></table></body></html>`;
                frame.onload = () => { frame.contentWindow.addEventListener('afterprint', () => frame.remove(), { once: true }); frame.contentWindow.focus(); frame.contentWindow.print(); }; frame.srcdoc = html; document.body.append(frame);
            }
            document.getElementById('auditStatus').textContent = `${definition.title}: ${rows.length} matching records prepared for ${kind}.`;
        } catch (error) { document.getElementById('auditStatus').textContent = `Export failed: ${error.message}`; }
        finally { this.busy = false; document.querySelectorAll('[data-export]').forEach((button) => button.disabled = false); }
    }

    download(blob, name) { const url = URL.createObjectURL(blob); const link = document.createElement('a'); link.href = url; link.download = name; document.body.append(link); link.click(); link.remove(); setTimeout(() => URL.revokeObjectURL(url), 60000); }
}
function initializeAudit() { if (document.getElementById('auditPage') && !DataTable.isDataTable('#reportTable')) new AuditUI(); }
if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', initializeAudit, { once: true }); else initializeAudit();
