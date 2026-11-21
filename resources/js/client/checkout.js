const form = document.querySelector('[data-checkout-order]');

if (form) {
    const button = form.querySelector('button[type="submit"]');
    const status = form.querySelector('[data-order-status]');
    let submitting = false;

    form.addEventListener('submit', event => {
        if (submitting) {
            event.preventDefault();
            return;
        }

        submitting = true;
        button.disabled = true;
        status.textContent = 'Placing your order…';
    });

    window.addEventListener('pageshow', () => {
        submitting = false;
        button.disabled = false;
        status.textContent = '';
    });
}
