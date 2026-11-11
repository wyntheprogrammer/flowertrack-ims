/* Shared Supplier navigation and notification-panel controls. */
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


}
if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', initializeSupplierShell, { once: true }); else initializeSupplierShell();