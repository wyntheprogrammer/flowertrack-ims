import DataTable from 'datatables.net-bs5';
import { jsPDF } from 'jspdf';
import autoTable from 'jspdf-autotable';
import ExcelJS from 'exceljs';

const escapeHTML = (value) => String(value ?? '').replace(/[&<>"']/g, (c) => ({
    '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#039;',
})[c]);
const money = (value) => new Intl.NumberFormat('en-PH', {
    style: 'currency', currency: 'PHP',
}).format(value / 100);
const sum = (rows, key) => rows.reduce((total, row) => total + row[key], 0);
const textColumn = (key, title) => ({ key, title });
const numericColumn = (key, title) => ({ key, title, number: true });
const moneyColumn = (key, title) => ({ key, title, money: true });

/* Read-only independent samples. Not synchronized with other modules. */
const reportData = {
    sales: [
        { id: 'SAL-001', order: 'ORD-001', client: 'Maria Santos', date: '2026-11-04', gross: 135000, refund: 0 },
        { id: 'SAL-002', order: 'ORD-002', client: 'Carlo Reyes', date: '2026-11-03', gross: 65000, refund: 10000 },
        { id: 'SAL-003', order: 'ORD-007', client: 'Maria Santos', date: '2026-11-04', gross: 165000, refund: 0 },
    ].map((row) => ({ ...row, net: row.gross - row.refund })),
    payments: [
        { id: 'PAY-001', order: 'ORD-001', client: 'Maria Santos', date: '2026-11-04', method: 'Cash', amount: 135000, status: 'Confirmed' },
        { id: 'PAY-002', order: 'ORD-002', client: 'Carlo Reyes', date: '2026-11-03', method: 'Bank Transfer', amount: 65000, status: 'Confirmed' },
        { id: 'PAY-003', order: 'ORD-003', client: 'Daniel Garcia', date: '2026-11-04', method: 'E-wallet', amount: 100000, status: 'Confirmed' },
        { id: 'PAY-004', order: 'ORD-004', client: 'Isabel Ramos', date: '2026-11-04', method: 'Bank Transfer', amount: 95000, status: 'Pending' },
        { id: 'PAY-005', order: 'ORD-007', client: 'Maria Santos', date: '2026-11-04', method: 'Cash', amount: 165000, status: 'Confirmed' },
    ],
    inventory: [
        { code: 'FLW-001', item: 'Red Rose', unit: 'Stem', onHand: 230, reserved: 20, minimum: 100 },
        { code: 'FLW-002', item: 'White Rose', unit: 'Stem', onHand: 50, reserved: 15, minimum: 100 },
        { code: 'FLW-003', item: 'Sunflower', unit: 'Stem', onHand: 100, reserved: 20, minimum: 50 },
        { code: 'FLW-009', item: 'Classic Rose Bouquet', unit: 'Bouquet', onHand: 10, reserved: 2, minimum: 5 },
        { code: 'FLW-010', item: 'Mixed Flower Bouquet', unit: 'Bouquet', onHand: 8, reserved: 1, minimum: 3 },
    ].map((row) => {
        const available = row.onHand - row.reserved;
        return { ...row, available, status: available <= 0 ? 'Out of Stock' : available < row.minimum ? 'Low Stock' : 'Normal' };
    }),
    orders: [
        { id: 'ORD-001', client: 'Maria Santos', date: '2026-11-04', method: 'Delivery', status: 'Completed', total: 135000 },
        { id: 'ORD-002', client: 'Carlo Reyes', date: '2026-11-03', method: 'Customer Pickup', status: 'Completed', total: 65000 },
        { id: 'ORD-003', client: 'Daniel Garcia', date: '2026-11-04', method: 'Delivery', status: 'Confirmed', total: 100000 },
        { id: 'ORD-004', client: 'Isabel Ramos', date: '2026-11-04', method: 'Customer Pickup', status: 'Processing', total: 95000 },
        { id: 'ORD-005', client: 'Paolo Mendoza', date: '2026-11-02', method: 'Customer Pickup', status: 'Cancelled', total: 55000 },
        { id: 'ORD-006', client: 'Maria Santos', date: '2026-11-04', method: 'Customer Pickup', status: 'Pending', total: 90000 },
        { id: 'ORD-007', client: 'Maria Santos', date: '2026-11-04', method: 'Delivery', status: 'Completed', total: 165000 },
    ],
    delivery: [
        { id: 'DEL-001', order: 'ORD-001', client: 'Maria Santos', date: '2026-11-04', person: 'Juan Dela Cruz', status: 'Delivered' },
        { id: 'DEL-002', order: 'ORD-003', client: 'Daniel Garcia', date: '2026-11-05', person: 'Mark Reyes', status: 'Scheduled' },
        { id: 'DEL-003', order: 'ORD-007', client: 'Maria Santos', date: '2026-11-04', person: 'Juan Dela Cruz', status: 'Delivered' },
    ],
    clients: [
        { id: 'CLI-001', name: 'Maria Santos', email: 'maria@example.com', registered: '2026-10-01', status: 'Active' },
        { id: 'CLI-002', name: 'Carlo Reyes', email: 'carlo@example.com', registered: '2026-10-05', status: 'Active' },
        { id: 'CLI-003', name: 'Daniel Garcia', email: 'daniel@example.com', registered: '2026-10-12', status: 'Active' },
        { id: 'CLI-004', name: 'Isabel Ramos', email: 'isabel@example.com', registered: '2026-10-18', status: 'Active' },
        { id: 'CLI-005', name: 'Paolo Mendoza', email: 'paolo@example.com', registered: '2026-10-25', status: 'Inactive' },
    ],
    spoilage: [
        { id: 'SPL-001', date: '2026-11-03', item: 'Red Rose', batch: 'BAT-001', unit: 'Stem', quantity: 5, reason: 'Damaged petals; inspected and recorded.' },
        { id: 'SPL-002', date: '2026-11-04', item: 'Classic Rose Bouquet', batch: 'BAT-011', unit: 'Bouquet', quantity: 1, reason: 'Wilted arrangement; confirmed by staff.' },
    ],
};

const definitions = {
    sales: { title: 'Sales Report', note: 'Completed-order sales less recorded refunds. Sample refunds are attributed to the original sale date; final reporting policy remains for review.', columns: [textColumn('id','Sale ID'), textColumn('order','Order ID'), textColumn('client','Client'), textColumn('date','Completed'), moneyColumn('gross','Gross Sales'), moneyColumn('refund','Refunds'), moneyColumn('net','Net Sales')] },
    payments: { title: 'Payments Report', note: 'Each sample payment is for the full order total. Pending payments are excluded from confirmed collections; refunds are reported separately.', columns: [textColumn('id','Payment ID'),textColumn('order','Order ID'),textColumn('client','Client'),textColumn('date','Recorded'),textColumn('method','Method'),moneyColumn('amount','Amount'),textColumn('status','Status')] },
    inventory: { title: 'Inventory Report', note: 'Physical stock and reservations are distinct. Stem and Bouquet quantities must never be combined into one stock total.', columns: [textColumn('code','Item Code'),textColumn('item','Item'),textColumn('unit','Unit'),numericColumn('onHand','On-Hand'),numericColumn('reserved','Reserved'),numericColumn('available','Available'),numericColumn('minimum','Minimum'),textColumn('status','Status')] },
    orders: { title: 'Orders Report', note: 'Order status and payment status are independent. Cancelled order amounts are not completed sales.', columns: [textColumn('id','Order ID'),textColumn('client','Client'),textColumn('date','Order Date'),textColumn('method','Fulfillment'),textColumn('status','Status'),moneyColumn('total','Order Total')] },
    delivery: { title: 'Delivery Report', note: 'Delivery records only; Pickup orders are excluded. This summary counts current delivery statuses, not historical attempt performance.', columns: [textColumn('id','Delivery ID'),textColumn('order','Order ID'),textColumn('client','Client'),textColumn('date','Scheduled Date'),textColumn('person','Personnel'),textColumn('status','Status')] },
    clients: { title: 'Clients Report', note: 'Client activity is derived from sample order records. Completed purchase totals include refunds recorded against those completed sales.', columns: [textColumn('id','Client ID'),textColumn('name','Client'),textColumn('email','Email'),textColumn('registered','Registered'),textColumn('status','Status'),numericColumn('orders','Orders'),moneyColumn('purchases','Net Completed Purchases')] },
    spoilage: { title: 'Spoilage Report', note: 'Actual recorded sample Spoilage Events only; prediction risk is not counted as spoilage. Quantities remain separate by unit.', columns: [textColumn('id','Event ID'),textColumn('date','Recorded'),textColumn('item','Item'),textColumn('batch','Batch'),textColumn('unit','Unit'),numericColumn('quantity','Spoiled Quantity'),textColumn('reason','Remarks')] },
};

function rowsFor(key) {
    if (key !== 'clients') return reportData[key];
    return reportData.clients.map((client) => ({ ...client,
        orders: reportData.orders.filter((order) => order.client === client.name).length,
        purchases: sum(reportData.sales.filter((sale) => sale.client === client.name), 'net'),
    }));
}

function metricsFor(key, rows) {
    const count = (status) => rows.filter((row) => row.status === status).length;
    const byUnit = (unit, field) => sum(rows.filter((row) => row.unit === unit), field);
    if (key === 'sales') return [['Sales',rows.length],['Gross Sales',money(sum(rows,'gross'))],['Refunds',money(sum(rows,'refund'))],['Net Sales',money(sum(rows,'net'))]];
    if (key === 'payments') return [['Payments',rows.length],['Confirmed Collections',money(sum(rows.filter((row) => row.status === 'Confirmed'),'amount'))],['Pending Verification',money(sum(rows.filter((row) => row.status === 'Pending'),'amount'))],['Confirmed Records',count('Confirmed')]];
    if (key === 'inventory') return [['Stem On-Hand',byUnit('Stem','onHand')],['Bouquet On-Hand',byUnit('Bouquet','onHand')],['Stem Reserved',byUnit('Stem','reserved')],['Bouquet Reserved',byUnit('Bouquet','reserved')]];
    if (key === 'orders') return [['Orders',rows.length],['Completed',count('Completed')],['Cancelled',count('Cancelled')],['Ongoing',rows.length-count('Completed')-count('Cancelled')]];
    if (key === 'delivery') return [['Deliveries',rows.length],['Delivered',count('Delivered')],['Failed',count('Failed')],['Awaiting Completion',rows.length-count('Delivered')-count('Cancelled')]];
    if (key === 'clients') return [['Clients',rows.length],['Active',count('Active')],['Inactive',count('Inactive')],['Net Completed Purchases',money(sum(rows,'purchases'))]];
    return [['Recorded Events',rows.length],['Spoiled Stems',byUnit('Stem','quantity')],['Spoiled Bouquets',byUnit('Bouquet','quantity')],['Affected Batches',new Set(rows.map((row) => row.batch)).size]];
}

class ReportsUI {
    constructor() {
        this.key = 'sales';
        this.table = null;
        this.busy = false;
        this.render();
        document.getElementById('reportType').addEventListener('change',(event) => { this.key=event.target.value; this.render(); });
        document.getElementById('reportSearch').addEventListener('input',(event) => this.table.search(event.target.value).draw());
        document.getElementById('reportLength').addEventListener('change',(event) => this.table.page.len(Number(event.target.value)).draw());
        document.querySelectorAll('[data-export]').forEach((button) => button.addEventListener('click',() => this.export(button.dataset.export)));
    }

    render() {
        if (this.table) this.table.destroy();
        const definition=definitions[this.key];
        document.getElementById('reportDefinition').textContent=definition.note;
        document.getElementById('reportSearch').value='';
        document.getElementById('reportStatus').textContent='';
        const host=document.getElementById('reportTableHost');
        host.innerHTML='<table id="reportTable"><thead><tr>'+definition.columns.map((column) => `<th scope="col">${escapeHTML(column.title)}</th>`).join('')+'</tr></thead><tbody></tbody></table>';
        this.table=new DataTable('#reportTable',{
            data:rowsFor(this.key),autoWidth:false,pageLength:Number(document.getElementById('reportLength').value),order:[[0,'asc']],
            layout:{topStart:null,topEnd:null,bottomStart:'info',bottomEnd:'paging'},
            language:{emptyTable:'No report records available.',zeroRecords:'No matching records found.',info:'Showing _START_ to _END_ of _TOTAL_ entries',infoEmpty:'Showing 0 to 0 of 0 entries',infoFiltered:'(filtered from _MAX_ total entries)',paginate:{first:'«',previous:'‹',next:'›',last:'»'}},
            columns:definition.columns.map((column) => ({data:column.key,className:column.number||column.money?'report-number':'',render:(value,type) => type==='display'?escapeHTML(column.money?money(value):value):value})),
        });
        const table=document.getElementById('reportTable');const wrapper=document.createElement('div');
        wrapper.className='report-table-scroll';wrapper.tabIndex=0;wrapper.setAttribute('role','region');wrapper.setAttribute('aria-label',`${definition.title}. Scroll horizontally for all columns.`);table.before(wrapper);wrapper.append(table);
        this.table.on('draw',() => this.updateSummary());
        this.updateSummary();
    }

    selectedRows() { return this.table.rows({search:'applied',order:'applied'}).data().toArray(); }

    updateSummary() {
        const rows=this.selectedRows();
        document.getElementById('reportSummary').innerHTML=metricsFor(this.key,rows).map(([label,value]) => `<div><span>${escapeHTML(label)}</span><strong>${escapeHTML(value)}</strong></div>`).join('');
        const definition=definitions[this.key];const groups=new Map();
        rows.forEach((row) => {
            let label;let value;
            if(this.key==='sales'){label=row.date;value=row.net;}
            else if(this.key==='inventory'){label=row.status;value=1;}
            else if(this.key==='spoilage'){label=`${row.unit} events`;value=1;}
            else {label=row.status;value=1;}
            groups.set(label,(groups.get(label)||0)+value);
        });
        const values=[...groups].sort((a,b) => a[0].localeCompare(b[0]));const maximum=Math.max(1,...values.map(([,value])=>value));
        document.getElementById('reportChart').innerHTML=`<h2>${this.key==='sales'?'Net Sales by Completion Date':'Record Count by Status / Type'}</h2>`+(values.length?values.map(([label,value])=>`<div class="report-bar"><div class="report-bar-label"><span>${escapeHTML(label)}</span><strong>${escapeHTML(this.key==='sales'?money(value):value)}</strong></div><div class="report-track" aria-hidden="true"><div class="report-fill" style="width:${Math.max(0,value/maximum*100)}%"></div></div></div>`).join(''):'<p class="report-definition">No matching records.</p>');
    }

    async export(kind) {
        if(this.busy)return;
        this.busy=true;document.querySelectorAll('[data-export]').forEach((button)=>button.disabled=true);
        const definition=definitions[this.key];const rows=this.selectedRows();
        const timestamp=new Date().toISOString();const filename=`flowertrack-${this.key}-${timestamp.slice(0,10)}`;
        const columns=definition.columns;const headings=columns.map((column)=>column.title);
        const cells=rows.map((row)=>columns.map((column)=>column.money?money(row[column.key]):String(row[column.key])));
        try {
            if(kind==='pdf'){
                const pdf=new jsPDF({orientation:'landscape'});
                pdf.setFontSize(16);pdf.text(`FlowerTrack IMS - ${definition.title}`,14,16);
                pdf.setFontSize(9);pdf.text('SAMPLE DATA - Search results across all pages',14,23);
                pdf.text(`Generated: ${timestamp}`,14,29);
                // Built-in PDF font lacks the peso symbol; label amounts as PHP.
                autoTable(pdf,{startY:35,head:[headings],body:cells.map((row)=>row.map((value)=>value.replaceAll('₱','PHP '))),styles:{fontSize:8,overflow:'linebreak'},headStyles:{fillColor:[23,107,58]},margin:{left:14,right:14}});
                pdf.save(`${filename}.pdf`);
            } else if(kind==='excel'){
                const workbook=new ExcelJS.Workbook();workbook.creator='FlowerTrack IMS';
                const sheet=workbook.addWorksheet('Report');
                sheet.addRow([`FlowerTrack IMS - ${definition.title}`]);sheet.addRow(['SAMPLE DATA - Search results across all pages']);sheet.addRow([`Generated: ${timestamp}`]);sheet.addRow([definition.note]);sheet.addRow([]);sheet.addRow(headings);
                rows.forEach((row)=>sheet.addRow(columns.map((column)=>{
                    const value=column.money?row[column.key]/100:row[column.key];
                    // Text is written as a string, never as an Excel formula object.
                    return typeof value==='number'?value:String(value);
                })));
                columns.forEach((column,index)=>{sheet.getColumn(index+1).width=column.money?18:25;if(column.money)sheet.getColumn(index+1).numFmt='"PHP "#,##0.00';});
                sheet.getRow(6).font={bold:true,color:{argb:'FFFFFFFF'}};sheet.getRow(6).fill={type:'pattern',pattern:'solid',fgColor:{argb:'FF176B3A'}};
                sheet.views=[{state:'frozen',ySplit:6}];sheet.autoFilter={from:{row:6,column:1},to:{row:6,column:columns.length}};
                const bytes=await workbook.xlsx.writeBuffer();this.download(new Blob([bytes],{type:'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'}),`${filename}.xlsx`);
            } else if(kind==='print'){
                const frame=document.createElement('iframe');frame.style.position='fixed';frame.style.width='0';frame.style.height='0';frame.style.border='0';
                const html=`<!doctype html><html><head><title>${escapeHTML(definition.title)}</title><style>@page{size:A4 landscape;margin:12mm}body{font-family:Arial,sans-serif;color:#344054}h1{font-size:20px}p{font-size:11px}table{width:100%;border-collapse:collapse;font-size:10px}th,td{padding:8px;border:1px solid #ddd;text-align:left}th{background:#eee7f3}thead{display:table-header-group}tr{break-inside:avoid}</style></head><body><h1>FlowerTrack IMS — ${escapeHTML(definition.title)}</h1><p>SAMPLE DATA · All matching rows · ${escapeHTML(timestamp)}</p><p>${escapeHTML(definition.note)}</p><table><thead><tr>${headings.map((value)=>`<th>${escapeHTML(value)}</th>`).join('')}</tr></thead><tbody>${cells.map((row)=>`<tr>${row.map((value)=>`<td>${escapeHTML(value)}</td>`).join('')}</tr>`).join('')}</tbody></table></body></html>`;
                frame.onload=()=>{frame.contentWindow.addEventListener('afterprint',()=>frame.remove(),{once:true});frame.contentWindow.focus();frame.contentWindow.print();};frame.srcdoc=html;document.body.append(frame);
            }
            document.getElementById('reportStatus').textContent=`${definition.title}: ${rows.length} matching records prepared for ${kind}.`;
        }catch(error){document.getElementById('reportStatus').textContent=`Export failed: ${error.message}`;}
        finally{this.busy=false;document.querySelectorAll('[data-export]').forEach((button)=>button.disabled=false);}
    }

    download(blob,name){const url=URL.createObjectURL(blob);const link=document.createElement('a');link.href=url;link.download=name;document.body.append(link);link.click();link.remove();setTimeout(()=>URL.revokeObjectURL(url),60000);}
}

function initializeReports(){if(document.getElementById('reportPage')&&!DataTable.isDataTable('#reportTable'))new ReportsUI();}
if(document.readyState==='loading')document.addEventListener('DOMContentLoaded',initializeReports,{once:true});else initializeReports();
