import DataTable from 'datatables.net-bs5';
import { Modal } from 'bootstrap';
import { jsPDF } from 'jspdf';
import autoTable from 'jspdf-autotable';
import ExcelJS from 'exceljs';

/* ========================================
   DISPLAY HELPERS
======================================== */

const escapeHTML = (value) => String(value ?? '').replace(
    /[&<>"']/g,
    (character) => ({
        '&': '&amp;',
        '<': '&lt;',
        '>': '&gt;',
        '"': '&quot;',
        "'": '&#039;',
    }[character])
);

const formatDate = (value) => {
    if (!value) return '—';

    const date = new Date(value);

    return Number.isNaN(date.getTime())
        ? '—'
        : date.toLocaleString('en-PH', {
            timeZone: 'Asia/Manila',
        });
};

const exportColumns = [
    ['id', 'Audit ID'],
    ['date', 'Timestamp'],
    ['user', 'User'],
    ['userId', 'User ID'],
    ['role', 'Role'],
    ['module', 'Module'],
    ['action', 'Action'],
    ['record', 'Record'],
    ['result', 'Result'],
    ['description', 'Description'],
    ['ip', 'IP Address'],
    ['before', 'Previous Values'],
    ['after', 'New Values'],
];

const exportValue = (row, key) => {
    if (key === 'date') return formatDate(row[key]);

    if (key === 'before' || key === 'after') {
        return JSON.stringify(row[key] ?? {});
    }

    return String(row[key] ?? '');
};

/* ========================================
   AUDIT TRAIL DATABASE INTERFACE
======================================== */

class AuditUI {
    constructor(page) {
        this.page = page;
        this.endpoint = page.dataset.url;
        this.status = document.getElementById('auditStatus');
        this.events = new Map();
        this.sequence = 0;
        this.exporting = false;

        this.modal = Modal.getOrCreateInstance(
            document.getElementById('auditViewModal')
        );

        this.createFilters();

        document.getElementById('auditTableHost').innerHTML = `
            <table id="reportTable" class="table w-100">
                <thead>
                    <tr>
                        <th>Date / Time</th>
                        <th>User</th>
                        <th>Module</th>
                        <th>Action</th>
                        <th>Record</th>
                        <th>Result</th>
                        <th>Details</th>
                    </tr>
                </thead>
                <tbody></tbody>
            </table>
        `;

        this.table = new DataTable('#reportTable', {
            processing: true,
            serverSide: true,
            searchDelay: 350,
            autoWidth: false,
            pageLength: 5,
            order: [[0, 'desc']],
            layout: {
                topStart: null,
                topEnd: null,
                bottomStart: 'info',
                bottomEnd: 'paging',
            },
            ajax: (request, callback) => {
                this.load(request, callback);
            },
            language: {
                emptyTable: 'No audit records yet.',
                zeroRecords: 'No matching activity found.',
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
                    data: 'date',
                    render: (value, type) => type === 'display'
                        ? escapeHTML(formatDate(value))
                        : value,
                },
                {
                    data: 'user',
                    render: (value, type, row) => type === 'display'
                        ? `${escapeHTML(value)}
                           <small class="d-block text-muted">
                               ${escapeHTML(row.userId)}
                           </small>`
                        : value,
                },
                this.textColumn('module'),
                this.textColumn('action'),
                this.textColumn('record'),
                {
                    data: 'result',
                    render: (value, type) => type === 'display'
                        ? `<span class="audit-result ${
                            value === 'Failed' ? 'failed' : ''
                        }">${escapeHTML(value)}</span>`
                        : value,
                },
                {
                    data: 'id',
                    orderable: false,
                    searchable: false,
                    render: (value, type) => type === 'display'
                        ? `<button type="button"
                                   class="audit-view-btn"
                                   data-audit-id="${escapeHTML(value)}"
                                   aria-label="View ${escapeHTML(value)}">
                               <i class="bi bi-eye" aria-hidden="true"></i>
                           </button>`
                        : value,
                },
            ],
        });

        this.wrapTable();
        this.bindEvents();
    }

    /* ========================================
       SAFE TEXT COLUMNS
    ======================================== */

    textColumn(key) {
        return {
            data: key,
            render: (value, type) => type === 'display'
                ? escapeHTML(value)
                : value,
        };
    }

    /* ========================================
       AUDIT FILTERS
    ======================================== */

    createFilters() {
        const filters = document.createElement('div');

        filters.className = 'row g-3 mb-3';
        filters.id = 'auditFilters';

        filters.innerHTML = `
            <div class="col-6 col-lg-2">
                <label class="form-label" for="auditFrom">From</label>
                <input class="form-control" type="date" id="auditFrom">
            </div>

            <div class="col-6 col-lg-2">
                <label class="form-label" for="auditTo">To</label>
                <input class="form-control" type="date" id="auditTo">
            </div>

            <div class="col-6 col-lg-2">
                <label class="form-label" for="auditModule">Module</label>
                <select class="form-select" id="auditModule">
                    <option value="">All modules</option>
                </select>
            </div>

            <div class="col-6 col-lg-2">
                <label class="form-label" for="auditActor">User</label>
                <select class="form-select" id="auditActor">
                    <option value="">All users</option>
                </select>
            </div>

            <div class="col-6 col-lg-2">
                <label class="form-label" for="auditAction">Action</label>
                <select class="form-select" id="auditAction">
                    <option value="">All actions</option>
                </select>
            </div>

            <div class="col-6 col-lg-2">
                <label class="form-label" for="auditResult">Result</label>
                <select class="form-select" id="auditResult">
                    <option value="">All results</option>
                    <option value="Success">Success</option>
                    <option value="Failed">Failed</option>
                </select>
            </div>

            <div class="col-12">
                <button type="button"
                        class="btn btn-outline-secondary btn-sm"
                        id="auditReset">
                    Reset filters
                </button>
            </div>
        `;

        this.page.querySelector('.report-toolbar').before(filters);
    }

    updateOptions(options = {}) {
        const fill = (id, entries, label) => {
            const select = document.getElementById(id);
            const selected = select.value;

            select.replaceChildren(new Option(label, ''));

            entries.forEach((entry) => {
                const value = typeof entry === 'object'
                    ? entry.id
                    : entry;

                const text = typeof entry === 'object'
                    ? entry.name
                    : entry;

                select.add(new Option(text, String(value)));
            });

            select.value = selected;
        };

        fill('auditModule', options.modules ?? [], 'All modules');
        fill('auditAction', options.actions ?? [], 'All actions');
        fill('auditActor', options.actors ?? [], 'All users');
    }

    /* ========================================
       DATABASE QUERY PARAMETERS
    ======================================== */

    parameters(request) {
        const params = new URLSearchParams();

        const fields = {
            from: 'auditFrom',
            to: 'auditTo',
            module: 'auditModule',
            actor: 'auditActor',
            action: 'auditAction',
            result: 'auditResult',
        };

        Object.entries(fields).forEach(([key, id]) => {
            const value = document.getElementById(id).value;

            if (value) params.set(key, value);
        });

        const sortColumns = [
            'created_at',
            'actor_name',
            'module',
            'action',
            'record_code',
            'result',
        ];

        const order = request.order?.[0] ?? {
            column: 0,
            dir: 'desc',
        };

        params.set(
            'sort',
            sortColumns[Number(order.column)] ?? 'created_at'
        );

        params.set('direction', order.dir === 'asc' ? 'asc' : 'desc');

        const search = String(request.search?.value ?? '').trim();

        if (search) params.set('search', search);

        params.set(
            'page',
            String(Math.floor(request.start / request.length) + 1)
        );

        params.set('per_page', String(request.length));

        return params;
    }

    /* ========================================
       FETCH DATABASE RESPONSE
    ======================================== */

    async request(params, signal) {
        const url = new URL(this.endpoint, window.location.origin);

        url.search = params.toString();

        const response = await fetch(url, {
            credentials: 'same-origin',
            headers: {
                Accept: 'application/json',
                'X-Requested-With': 'XMLHttpRequest',
            },
            signal,
        });

        const payload = await response.json().catch(() => null);

        if (!response.ok) {
            const messages = Object.values(payload?.errors ?? {}).flat();

            throw new Error(
                messages[0]
                ?? payload?.message
                ?? `Unable to load audit records (${response.status}).`
            );
        }

        if (!Array.isArray(payload?.data)) {
            throw new Error('Invalid audit response. Refresh and sign in again.');
        }

        return payload;
    }

    async load(request, callback) {
        const sequence = ++this.sequence;

        this.abortController?.abort();
        this.abortController = new AbortController();

        this.lastRequest = request;
        this.status.textContent = 'Loading audit records...';

        try {
            const payload = await this.request(
                this.parameters(request),
                this.abortController.signal
            );

            if (sequence !== this.sequence) return;

            this.events = new Map(
                payload.data.map((event) => [event.id, event])
            );

            this.updateOptions(payload.options);

            callback({
                draw: request.draw,
                recordsTotal: payload.total,
                recordsFiltered: payload.filtered,
                data: payload.data,
            });

            this.status.textContent = payload.total === 0
                ? 'Database connected. No audit records yet.'
                : `Updated ${formatDate(payload.generatedAt)}.`;
        } catch (error) {
            if (error.name === 'AbortError' || sequence !== this.sequence) {
                return;
            }

            callback({
                draw: request.draw,
                recordsTotal: 0,
                recordsFiltered: 0,
                data: [],
            });

            this.events.clear();
            this.status.textContent = error.message;
        }
    }

    /* ========================================
       TABLE SCROLL AND CONTROL EVENTS
    ======================================== */

    wrapTable() {
        const table = document.getElementById('reportTable');
        const wrapper = document.createElement('div');

        wrapper.className = 'report-table-scroll';
        wrapper.tabIndex = 0;
        wrapper.setAttribute('role', 'region');
        wrapper.setAttribute('aria-label', 'Audit activity table');

        table.before(wrapper);
        wrapper.append(table);
    }

    bindEvents() {
        let searchTimer;

        document.getElementById('auditSearch')
            .addEventListener('input', (event) => {
                clearTimeout(searchTimer);

                const value = event.target.value;

                searchTimer = setTimeout(() => {
                    this.table.search(value).draw();
                }, 350);
            });

        document.getElementById('auditLength')
            .addEventListener('change', (event) => {
                this.table.page.len(Number(event.target.value)).draw();
            });

        document.getElementById('auditFilters')
            .addEventListener('change', () => {
                this.table.ajax.reload();
            });

        document.getElementById('auditReset')
            .addEventListener('click', () => {
                document.querySelectorAll(
                    '#auditFilters input, #auditFilters select'
                ).forEach((field) => {
                    field.value = '';
                });

                document.getElementById('auditSearch').value = '';

                this.table.search('').draw();
            });

        document.getElementById('auditRefresh')
            .addEventListener('click', () => {
                this.table.ajax.reload(null, false);
            });

        document.getElementById('auditTableHost')
            .addEventListener('click', (event) => {
                const button = event.target.closest('[data-audit-id]');

                if (button) this.show(button.dataset.auditId);
            });

        this.page.querySelectorAll('[data-export]')
            .forEach((button) => {
                button.addEventListener('click', () => {
                    this.export(button.dataset.export);
                });
            });
    }

    /* ========================================
       READ-ONLY ACTIVITY DETAILS
    ======================================== */

    show(id) {
        const event = this.events.get(id);

        if (!event) return;

        const details = [
            ['Audit ID', event.id],
            ['Date / Time', formatDate(event.date)],
            ['User', event.user],
            ['User ID', event.userId],
            ['Role', event.role],
            ['Module', event.module],
            ['Action', event.action],
            ['Record', event.record],
            ['Result', event.result],
            ['IP Address', event.ip],
            ['Description', event.description],
        ];

        document.getElementById('auditViewContent').innerHTML = `
            <dl class="row">
                ${details.map(([label, value]) => `
                    <dt class="col-sm-3">${escapeHTML(label)}</dt>
                    <dd class="col-sm-9">${escapeHTML(value)}</dd>
                `).join('')}
            </dl>

            <div class="row g-3">
                <div class="col-md-6">
                    <h6>Previous Values</h6>
                    <pre class="border rounded p-3 text-wrap">${escapeHTML(
                        JSON.stringify(event.before ?? {}, null, 2)
                    )}</pre>
                </div>

                <div class="col-md-6">
                    <h6>New Values</h6>
                    <pre class="border rounded p-3 text-wrap">${escapeHTML(
                        JSON.stringify(event.after ?? {}, null, 2)
                    )}</pre>
                </div>
            </div>
        `;

        this.modal.show();
    }

    /* ========================================
       EXPORT ALL MATCHING RECORDS
    ======================================== */

    async export(kind) {
        if (this.exporting || !this.lastRequest) return;

        this.exporting = true;

        const controls = [
            ...this.page.querySelectorAll('button, input, select'),
        ];

        const states = controls.map((control) => control.disabled);

        controls.forEach((control) => {
            control.disabled = true;
        });

        // Open immediately so browsers do not block the print window.
        const printWindow = kind === 'print'
            ? window.open('', '_blank')
            : null;

        try {
            if (kind === 'print' && !printWindow) {
                throw new Error('Allow pop-ups to print this report.');
            }

            const params = this.parameters(this.lastRequest);

            // Use the latest visible search value for the export.
            const search = document.getElementById('auditSearch').value.trim();

            if (search) params.set('search', search);
            else params.delete('search');

            params.set('export', '1');

            const payload = await this.request(params);

            if (!payload.data.length) {
                throw new Error('No matching audit records to export.');
            }

            const headers = exportColumns.map(([, title]) => title);

            const rows = payload.data.map((row) => (
                exportColumns.map(([key]) => exportValue(row, key))
            ));

            const timestamp = formatDate(payload.generatedAt);

            const filename = `FlowerTrack-Audit-${new Date()
                .toISOString()
                .replace(/[:.]/g, '-')}`;

            if (kind === 'pdf') {
                const document = new jsPDF({
                    orientation: 'landscape',
                    format: 'a3',
                });

                document.setFontSize(16);
                document.text('FlowerTrack IMS — Audit Trail', 14, 16);

                document.setFontSize(9);
                document.text(`Generated: ${timestamp}`, 14, 23);

                autoTable(document, {
                    head: [headers],
                    body: rows,
                    startY: 29,
                    styles: {
                        fontSize: 6,
                        cellPadding: 2,
                        overflow: 'linebreak',
                    },
                    headStyles: {
                        fillColor: [23, 107, 58],
                    },
                    margin: 14,
                });

                document.save(`${filename}.pdf`);
            } else if (kind === 'excel') {
                const workbook = new ExcelJS.Workbook();
                const sheet = workbook.addWorksheet('Audit Trail');

                sheet.addRow(['FlowerTrack IMS — Audit Trail']);
                sheet.addRow([`Generated: ${timestamp}`]);
                sheet.addRow([]);
                sheet.addRow(headers);

                rows.forEach((row) => {
                    // Plain string cells; no formula objects.
                    sheet.addRow(row.map((value) => String(value)));
                });

                sheet.getRow(4).font = {
                    bold: true,
                    color: { argb: 'FFFFFFFF' },
                };

                sheet.getRow(4).eachCell((cell) => {
                    cell.fill = {
                        type: 'pattern',
                        pattern: 'solid',
                        fgColor: { argb: 'FF176B3A' },
                    };
                });

                sheet.columns.forEach((column, index) => {
                    column.width = index >= 9 ? 45 : 23;
                    column.alignment = {
                        vertical: 'top',
                        wrapText: true,
                    };
                });

                sheet.views = [{ state: 'frozen', ySplit: 4 }];

                const buffer = await workbook.xlsx.writeBuffer();

                this.download(
                    new Blob([buffer], {
                        type: 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
                    }),
                    `${filename}.xlsx`
                );
            } else if (kind === 'print') {
                printWindow.document.open();

                printWindow.document.write(`
                    <!DOCTYPE html>
                    <html lang="en">
                    <head>
                        <meta charset="utf-8">
                        <title>FlowerTrack Audit Trail</title>
                        <style>
                            @page {
                                size: A3 landscape;
                                margin: 12mm;
                            }

                            body {
                                font-family: Arial, sans-serif;
                                font-size: 9px;
                            }

                            table {
                                width: 100%;
                                border-collapse: collapse;
                                table-layout: fixed;
                            }

                            th, td {
                                border: 1px solid #ddd;
                                padding: 5px;
                                text-align: left;
                                vertical-align: top;
                                overflow-wrap: anywhere;
                            }

                            th {
                                background: #e4f2e4;
                            }

                            thead {
                                display: table-header-group;
                            }
                        </style>
                    </head>
                    <body>
                        <h1>FlowerTrack IMS — Audit Trail</h1>
                        <p>Generated: ${escapeHTML(timestamp)}</p>

                        <table>
                            <thead>
                                <tr>
                                    ${headers.map((header) => (
                                        `<th>${escapeHTML(header)}</th>`
                                    )).join('')}
                                </tr>
                            </thead>

                            <tbody>
                                ${rows.map((row) => `
                                    <tr>
                                        ${row.map((value) => (
                                            `<td>${escapeHTML(value)}</td>`
                                        )).join('')}
                                    </tr>
                                `).join('')}
                            </tbody>
                        </table>
                    </body>
                    </html>
                `);

                printWindow.document.close();
                printWindow.focus();

                setTimeout(() => printWindow.print(), 250);
            }

            this.status.textContent =
                `Prepared ${rows.length} matching audit records.`;
        } catch (error) {
            if (printWindow && !printWindow.closed) {
                printWindow.close();
            }

            this.status.textContent = error.message;
        } finally {
            controls.forEach((control, index) => {
                control.disabled = states[index];
            });

            this.exporting = false;
        }
    }

    /* ========================================
       DOWNLOAD GENERATED FILE
    ======================================== */

    download(blob, filename) {
        const url = URL.createObjectURL(blob);
        const link = document.createElement('a');

        link.href = url;
        link.download = filename;

        document.body.append(link);
        link.click();
        link.remove();

        setTimeout(() => URL.revokeObjectURL(url), 1000);
    }
}

/* ========================================
   INITIALIZE ONLY ON THE AUDIT PAGE
======================================== */

const auditPage = document.getElementById('auditPage');

if (auditPage) {
    new AuditUI(auditPage);
}