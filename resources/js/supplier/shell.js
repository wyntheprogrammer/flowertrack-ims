/* Shared Supplier navigation, sample alerts and dashboard chart controls. */
function initializeSupplierShell() {
    const layout = document.querySelector('.supplier-layout');
    if (!layout || layout.dataset.shellReady) return;
    layout.dataset.shellReady = 'true';
    const menu = document.getElementById('supplierMenuToggle');
    const sidebar = document.getElementById('supplierSidebar');
    const mobile = window.matchMedia('(max-width: 768px)');
    const notificationButton = document.getElementById('supplierNotificationToggle');
    const notificationPanel = document.getElementById('supplierNotificationPanel');

    function syncMenu() {
        const expanded = mobile.matches ? layout.classList.contains('sidebar-expanded') : !layout.classList.contains('sidebar-collapsed');
        menu?.setAttribute('aria-expanded', String(expanded));
    }
    menu?.addEventListener('click', () => {
        layout.classList.toggle(mobile.matches ? 'sidebar-expanded' : 'sidebar-collapsed');
        syncMenu();
    });
    mobile.addEventListener('change', () => {
        layout.classList.remove('sidebar-expanded', 'sidebar-collapsed');
        syncMenu();
    });
    syncMenu();

    function closeNotifications() {
        if (notificationPanel) notificationPanel.hidden = true;
        notificationButton?.setAttribute('aria-expanded', 'false');
    }
    notificationButton?.addEventListener('click', () => {
        if (!notificationPanel) return;
        notificationPanel.hidden = !notificationPanel.hidden;
        notificationButton.setAttribute('aria-expanded', String(!notificationPanel.hidden));
    });
    document.addEventListener('click', (event) => {
        if (!event.target.closest('.supplier-notifications')) closeNotifications();
        if (mobile.matches && !sidebar?.contains(event.target) && !menu?.contains(event.target)) {
            layout.classList.remove('sidebar-expanded'); syncMenu();
        }
    });
    document.addEventListener('keydown', (event) => {
        if (event.key !== 'Escape') return;
        if (notificationPanel && !notificationPanel.hidden) { closeNotifications(); notificationButton?.focus(); }
        if (mobile.matches && layout.classList.contains('sidebar-expanded')) {
            layout.classList.remove('sidebar-expanded'); syncMenu(); menu?.focus();
        }
    });

    const period = document.getElementById('dashboardPeriod');
    const chart = document.getElementById('dashboardChartContent');
    if (!period || !chart) return;
    const samples = {
        month: { labels: ['Week 1', 'Week 2', 'Week 3', 'Week 4'], values: [1450, 1900, 2250, 2480] },
        last: { labels: ['Week 1', 'Week 2', 'Week 3', 'Week 4'], values: [1200, 1650, 1800, 2350] },
        year: { labels: ['Q1', 'Q2', 'Q3', 'Q4'], values: [1300, 1800, 2100, 2480] },
    };
    function renderChart() {
        const sample = samples[period.value] || samples.month;
        const maximum = Math.ceil(Math.max(...sample.values) / 500) * 500;
        const points = sample.values.map((value, index) => [55 + index * 200, 175 - value / maximum * 145]);
        const line = points.map(([x, y]) => `${x},${y}`).join(' ');
        const grids = Array.from({ length: 5 }, (_, index) => {
            const y = 175 - index * 145 / 4;
            return `<line x1="45" y1="${y}" x2="680" y2="${y}" stroke="#e8eee7"/><text x="4" y="${y + 4}" fill="#7b867d" font-size="10">${maximum * index / 4}</text>`;
        }).join('');
        chart.innerHTML = `${grids}<polygon points="55,175 ${line} 655,175" fill="#198754" fill-opacity=".1"/><polyline points="${line}" fill="none" stroke="#168047" stroke-width="3"/>${points.map(([x, y], index) => `<circle cx="${x}" cy="${y}" r="5" fill="#168047" stroke="#fff" stroke-width="2"/><text x="${x}" y="198" text-anchor="middle" fill="#7b867d" font-size="11">${sample.labels[index]}</text>`).join('')}`;
        document.getElementById('dashboardChartDescription').textContent =
            `Sample loose-stem stock, ${period.selectedOptions[0].textContent}: `
            + sample.labels.map((label, index) => `${label}: ${sample.values[index]} stems`).join('; ');
    }
    period.addEventListener('change', renderChart);
    renderChart();
}
if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', initializeSupplierShell, { once: true }); else initializeSupplierShell();
