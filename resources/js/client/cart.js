const form = document.querySelector('#cart-selection');

if (form) {
    const selectAll = document.querySelector('#cart-select-all');
    const checkboxes = [...document.querySelectorAll('[name="selected[]"]')];
    const total = document.querySelector('#cart-subtotal');
    const review = document.querySelector('#cart-review');
    const limit = BigInt(form.dataset.subtotalLimit);
    const limitMessage = document.querySelector('#cart-selection-limit');
    const saveButton = document.querySelector('#cart-save-selection');
    const error = document.querySelector('#cart-selection-error');
    const numberFormat = new Intl.NumberFormat('en-US');
    const confirmedSelection = new Set(checkboxes.filter(checkbox => checkbox.checked));
    const selectionIssues = new Map(checkboxes.map(checkbox => [
        checkbox, document.getElementById(checkbox.getAttribute('aria-describedby')),
    ]));
    let saveQueue = Promise.resolve();
    let selectionFailed = false;
    let selectionNeedsSave = form.dataset.selectionNeedsSave === 'true';
    let pendingSelectionSaves = 0;
    let waitingForSubmit = false;
    let submitting = false;

    function formatMoney(amount) {
        const cents = BigInt(amount);
        return '₱' + cents / 100n + '.' + String(cents % 100n).padStart(2, '0');
    }

    function eligibleCheckboxes() {
        return checkboxes.filter(checkbox => !checkbox.disabled);
    }

    function displaySelection() {
        const eligible = eligibleCheckboxes();
        const selected = eligible.filter(checkbox => checkbox.checked);
        const count = selected.length;
        const cents = selected.reduce((sum, checkbox) => sum + BigInt(checkbox.dataset.lineAmount), 0n);
        const overLimit = cents > limit;

        selectAll.checked = count > 0 && count === eligible.length;
        selectAll.indeterminate = count > 0 && count < eligible.length;
        selectAll.disabled = eligible.length === 0;
        limitMessage.hidden = !overLimit;
        total.value = overLimit ? '—' : (count === 0 ? '₱0' : formatMoney(cents));
        review.textContent = 'Review checkout (' + count + (count === 1 ? ' item)' : ' items)');
        review.disabled = count === 0 || overLimit;
    }

    // The quantity and selection endpoints both write the Laravel session.
    function enqueueSave(save) {
        saveQueue = saveQueue.then(save);
    }

    function saveSelection(changedCheckboxes) {
        displaySelection();
        pendingSelectionSaves++;
        enqueueSave(async () => {
            const selected = new Set(eligibleCheckboxes().filter(checkbox => checkbox.checked));
            try {
                const response = await fetch(form.action, {
                    method: 'POST',
                    headers: { Accept: 'application/json' },
                    body: new URLSearchParams(new FormData(form)),
                    keepalive: true,
                });
                if (response.status !== 204) {
                    throw new Error('Selection was not saved.');
                }
                confirmedSelection.clear();
                selected.forEach(checkbox => confirmedSelection.add(checkbox));
                selectionFailed = false;
                selectionNeedsSave = false;
                selectionIssues.forEach(issue => {
                    issue.hidden = true;
                    issue.textContent = '';
                });
            } catch {
                selectionFailed = true;
                const unsaved = checkboxes.filter(checkbox => selected.has(checkbox) !== confirmedSelection.has(checkbox));
                const affected = unsaved.length ? unsaved : changedCheckboxes;
                selectionIssues.forEach((issue, checkbox) => {
                    issue.hidden = !affected.includes(checkbox);
                    issue.textContent = issue.hidden ? '' : 'Could not save the selection for this flower.';
                });
            }
            error.hidden = !selectionFailed;
            saveButton.hidden = !selectionFailed;
            pendingSelectionSaves--;
        });
    }

    function setupQuantity(quantityForm) {
        const row = quantityForm.closest('.shopping-cart-item');
        const input = quantityForm.elements.quantity;
        const status = quantityForm.querySelector('[data-cart-quantity-status]');
        const [minus, plus] = quantityForm.querySelectorAll('[data-cart-step]');
        const checkbox = row.querySelector('[name="selected[]"]');
        const issue = document.getElementById(input.getAttribute('aria-describedby'));
        const stockIssue = issue.querySelector('[data-cart-stock-issue]');
        const saveError = issue.querySelector('[data-cart-save-error]');
        const equation = row.querySelector('[data-cart-equation]');
        let confirmed = Number(input.value);
        let revision = 0;
        let timer;
        let queued = false;
        let saving = false;
        let inFlightRevision;
        let failed = false;
        let needsConfirmation = false;

        function showError(message) {
            saveError.textContent = message;
            issue.hidden = !message && !stockIssue.firstElementChild;
            if (issue.hidden) {
                input.removeAttribute('aria-invalid');
            } else {
                input.setAttribute('aria-invalid', 'true');
            }
        }

        function syncControls() {
            minus.disabled = (input.valueAsNumber || confirmed) <= 1;
            status.textContent = queued || saving ? 'Saving…' : '';
        }

        function showConfirmed(result) {
            confirmed = result.quantity;
            input.max = result.maxQuantity;
            row.querySelector('[data-cart-unit-price]').textContent = formatMoney(result.price);
            row.querySelector('[data-cart-saved-quantity]').textContent = numberFormat.format(confirmed);
            row.querySelector('[data-cart-line-total]').textContent = formatMoney(result.amount);
            equation.hidden = false;
            stockIssue.replaceChildren();
            if (checkbox.disabled) {
                checkbox.checked = result.selected;
                checkbox.disabled = false;
                if (result.selected) {
                    confirmedSelection.add(checkbox);
                }
            }
            checkbox.dataset.lineAmount = result.amount;
            displaySelection();
        }

        function save({ confirm = false } = {}) {
            clearTimeout(timer);
            if (queued) {
                return;
            }
            if (saving && revision === inFlightRevision) {
                return;
            }
            if (!saving && input.value === String(confirmed) && !(confirm && needsConfirmation)) {
                return;
            }

            queued = true;
            syncControls();
            enqueueSave(async () => {
                queued = false;
                const quantity = input.valueAsNumber;
                const savingRevision = revision;
                if (input.value === '' && !input.validity.badInput) {
                    input.value = confirmed;
                    syncControls();
                    return;
                }
                if (!Number.isInteger(quantity) || quantity < 1) {
                    failed = true;
                    input.value = confirmed;
                    showError('Enter a whole number of 1 or more.');
                    syncControls();
                    return;
                }
                if (quantity === confirmed && !needsConfirmation) {
                    input.value = confirmed;
                    syncControls();
                    return;
                }

                saving = true;
                inFlightRevision = savingRevision;
                syncControls();
                let message = 'Could not save the quantity. Try again.';
                let rejected = false;
                try {
                    const body = new URLSearchParams(new FormData(quantityForm));
                    body.set('quantity', quantity);
                    const response = await fetch(quantityForm.action, {
                        method: 'POST',
                        headers: { Accept: 'application/json' },
                        body,
                    });
                    if (response.status === 422) {
                        const result = await response.json();
                        message = result.message || message;
                        rejected = true;
                    }
                    if (!response.ok) {
                        throw new Error(message);
                    }
                    const result = await response.json();
                    if (result.quantity !== quantity) {
                        throw new Error(message);
                    }
                    showConfirmed(result);
                    needsConfirmation = false;
                    if (revision === savingRevision) {
                        input.value = confirmed;
                        failed = false;
                        showError('');
                    }
                } catch {
                    needsConfirmation ||= !rejected;
                    if (revision === savingRevision) {
                        failed = true;
                        input.value = confirmed;
                        showError(message);
                    }
                } finally {
                    saving = false;
                    syncControls();
                }
            });
        }

        function edit() {
            revision++;
            failed = false;
            showError('');
            syncControls();
        }

        function flush(options) {
            if (input.value === '' && !input.validity.badInput && !queued && !saving) {
                input.value = confirmed;
                syncControls();
            }
            save(options);
        }

        input.addEventListener('input', () => {
            clearTimeout(timer);
            edit();
            if (input.value !== '' || input.validity.badInput) {
                timer = setTimeout(save, 500);
            }
        });
        input.addEventListener('blur', () => flush());
        input.addEventListener('keydown', event => {
            if (event.key === 'Enter') {
                event.preventDefault();
                flush();
            }
        });
        quantityForm.addEventListener('submit', event => {
            event.preventDefault();
            flush();
        });

        for (const button of [minus, plus]) {
            button.hidden = false;
            button.addEventListener('click', () => {
                input.value = Math.max(1, (input.valueAsNumber || confirmed) + Number(button.dataset.cartStep));
                edit();
                save();
            });
        }
        syncControls();

        return {
            checkbox,
            flush,
            hasFailed: () => failed || needsConfirmation,
            clearFailure: () => { failed = false; },
            focus: () => input.focus(),
        };
    }

    const quantities = [...document.querySelectorAll('.cart-quantity-form')].map(setupQuantity);

    selectAll.addEventListener('change', () => {
        const changed = eligibleCheckboxes().filter(checkbox => checkbox.checked !== selectAll.checked);
        changed.forEach(checkbox => {
            checkbox.checked = selectAll.checked;
        });
        saveSelection(changed);
    });
    checkboxes.forEach(checkbox => checkbox.addEventListener('change', () => saveSelection([checkbox])));

    document.querySelector('.shopping-cart').addEventListener('submit', async event => {
        const { target, submitter } = event;
        if (submitting || event.defaultPrevented) {
            return;
        }

        event.preventDefault();
        if (waitingForSubmit) {
            return;
        }

        waitingForSubmit = true;
        const reviewing = submitter === review;
        if (submitter === saveButton) {
            saveSelection(checkboxes.filter(checkbox => !selectionIssues.get(checkbox).hidden));
        } else if (reviewing && selectionNeedsSave && !selectionFailed && pendingSelectionSaves === 0) {
            saveSelection(checkboxes.filter(checkbox => checkbox.disabled));
        }
        quantities.forEach(item => item.flush({ confirm: reviewing && item.checkbox.checked }));
        let pending;
        do {
            pending = saveQueue;
            await pending;
            quantities.forEach(item => item.flush());
        } while (pending !== saveQueue);

        if (reviewing) {
            const failed = quantities.filter(item => item.checkbox.checked && item.hasFailed());
            waitingForSubmit = false;
            if (failed.length > 0) {
                failed.forEach(item => item.clearFailure());
                failed[0].focus();
                return;
            }
            if (selectionFailed) {
                const affected = checkboxes.find(checkbox => !selectionIssues.get(checkbox).hidden);
                (affected ?? saveButton).focus();
                return;
            }
            if (!review.disabled) {
                window.location.assign(review.dataset.checkoutUrl);
            }
            return;
        }

        if (submitter === saveButton) {
            waitingForSubmit = false;
            if (!selectionFailed) {
                (review.disabled ? selectAll : review).focus();
            }
            return;
        }

        submitting = true;
        target.requestSubmit(submitter);
        submitting = false;
        waitingForSubmit = false;
    });

    selectAll.closest('label').hidden = false;
    saveButton.hidden = true;
    displaySelection();
}
