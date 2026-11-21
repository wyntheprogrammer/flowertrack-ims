const form = document.querySelector('.product-buy');

if (form) {
    const quantity = form.querySelector('[name="quantity"]');
    const total = form.querySelector('output');
    const priceInCents = BigInt(form.dataset.priceCents);

    function updateTotal() {
        if (!quantity.validity.valid) {
            total.value = '—';
            return;
        }

        const cents = priceInCents * BigInt(quantity.valueAsNumber);
        const pesos = cents / 100n;
        const fraction = String(cents % 100n).padStart(2, '0');
        total.value = '₱' + pesos + '.' + fraction;
    }

    quantity.addEventListener('input', updateTotal);
    window.addEventListener('pageshow', updateTotal);
}
