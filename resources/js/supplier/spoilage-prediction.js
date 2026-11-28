import DataTable from 'datatables.net-bs5';
import { Modal } from 'bootstrap';

// Saved data loads first; changed inputs update through one automatic request.
const DATA_URL = '/supplier/spoilage-prediction/data';
const REFRESH_URL = '/supplier/spoilage-prediction/refresh';
const riskPriority = { Low: 1, Medium: 2, High: 3, Critical: 4 };

function escapeHtml(value) {
    return String(value ?? '').replace(/[&<>"']/g, (character) => ({
        '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#039;',
    })[character]);
}

function formatDate(value) {
    if (!value) return 'Not available';
    const [year, month, day] = value.split('-').map(Number);
    return new Intl.DateTimeFormat('en-PH', {
        month: 'short', day: '2-digit', year: 'numeric',
    }).format(new Date(year, month - 1, day));
}

function formatDateWindow(start, end) {
    return start === end ? formatDate(start) : `${formatDate(start)} – ${formatDate(end)}`;
}

function number(value, digits = 0) {
    return value == null ? 'Not available' : Number(value).toLocaleString('en-PH', {
        maximumFractionDigits: digits,
    });
}

function calculationPercent(stock, fraction, quantity) {
    const percent = fraction * 100;
    const [whole, decimal = ''] = quantity.replaceAll(',', '').split('.');
    const cents = BigInt(whole + decimal.padEnd(2, '0'));
    const twiceStock = 2n * BigInt(stock);
    for (let digits = 2; ; digits++) {
        const scale = 10n ** BigInt(digits);
        let rate = BigInt(percent.toFixed(digits).replace('.', ''));
        // Stock times percent gives hundredths of a stock unit. Check the displayed decimal exactly.
        if (digits === 20) {
            // At a rounding boundary, use the closest decimal that rounds to the displayed quantity.
            const minimum = ((2n * cents - 1n) * scale + twiceStock - 1n) / twiceStock;
            const maximum = ((2n * cents + 1n) * scale - 1n) / twiceStock;
            if (rate < minimum) rate = minimum;
            if (rate > maximum) rate = maximum;
        } else if ((rate === 0n && percent > 0) || (twiceStock * rate + scale) / (2n * scale) !== cents) {
            continue;
        }
        const decimals = String(rate % scale).padStart(digits, '0').replace(/0+$/, '');
        return `${rate / scale}${decimals ? `.${decimals}` : ''}%`;
    }
}

// Null and non-finite values are unavailable; a zero forecast remains valid.
function validFraction(value) {
    return typeof value === 'number' && Number.isFinite(value) && value >= 0 && value <= 1;
}

function usablePrediction(item) {
    return item.predictionStatus === 'Predicted' && !item.needsRefresh
        && Object.hasOwn(riskPriority, item.risk);
}

function riskText(item) {
    if (item.needsRefresh) return 'Updating required';
    if (item.predictionStatus === 'InsufficientData') return 'Insufficient data';
    return usablePrediction(item) ? item.risk : 'Unavailable';
}

function riskBadge(item) {
    if (!usablePrediction(item)) {
        return `<span class="text-muted">${escapeHtml(riskText(item))}</span>`;
    }
    return `<span class="prediction-risk prediction-risk-${item.risk.toLowerCase()}">${escapeHtml(item.risk)}</span>`;
}

function recommendation(item) {
    if (item.needsRefresh) return 'A current forecast is required before using the saved risk.';
    if (!usablePrediction(item)) return 'Review completed daily observations for this flower.';
    return {
        Low: 'Maintain storage conditions and routine inspection.',
        Medium: 'Monitor closely and plan the use of usable stock.',
        High: 'Inspect promptly and prioritize usable stock.',
        Critical: 'Inspect immediately and confirm actual condition.',
    }[item.risk];
}

function csrfHeaders() {
    const token = document.querySelector('meta[name="csrf-token"]')?.content;
    if (token) return { 'X-CSRF-TOKEN': token };
    const cookie = document.cookie.split('; ').find((item) => item.startsWith('XSRF-TOKEN='));
    if (cookie) return { 'X-XSRF-TOKEN': decodeURIComponent(cookie.substring(11)) };
    throw new Error('Security token missing. Reload and log in again.');
}

async function readResponse(response) {
    if (response.redirected || [401, 403, 419].includes(response.status)) {
        throw new Error('Session expired or access denied. Log in again.');
    }
    if (response.status === 429) {
        throw new Error('Too many page refreshes. Wait one minute, then refresh the browser.');
    }
    if (response.status >= 500) {
        throw new Error('Automatic update failed. Check storage/logs/laravel.log.');
    }
    const result = await response.json().catch(() => {
        throw new Error('The server returned invalid JSON.');
    });
    if (!response.ok) {
        throw new Error(Object.values(result.errors ?? {}).flat().join(' ') || result.message || 'Request failed.');
    }
    return result;
}

class SpoilagePredictionUI {
    constructor(tableElement) {
        this.tableElement = tableElement;
        this.assessments = [];
        this.forecasts = [];
        this.forecastFlowerId = '';
        this.forecastPeriod = 'weekly';
        this.running = false;
        this.modal = Modal.getOrCreateInstance(document.getElementById('predictionViewModal'));
        this.preparePage();
        this.initializeTable();
        this.mountTableScroller();
        this.bindEvents();
        this.bindForecastEvents();
        this.start();
    }

    // Keep the approved layout and remove the old manual update label/button.
    preparePage() {
        document.querySelector('.prediction-page .prediction-demo-label')?.remove();
        const notice = document.querySelector('.prediction-notice p');
        if (notice) {
            notice.textContent = 'Saved records appear first. Missing or outdated predictions update automatically while this page is open. Predictions are advisory and do not deduct stock.';
        }
        this.status = document.createElement('p');
        this.status.className = 'prediction-detail-note';
        this.status.setAttribute('role', 'status');
        document.querySelector('.prediction-table-container').after(this.status);
    }

    initializeTable() {
        this.table = new DataTable(this.tableElement, {
            data: [], autoWidth: false, pageLength: 5, lengthMenu: [5, 10, 25, 50],
            order: [[3, 'desc'], [0, 'asc']],
            layout: { topStart: null, topEnd: null, bottomStart: 'info', bottomEnd: 'paging' },
            language: {
                emptyTable: 'No batch records loaded.', zeroRecords: 'No matching batches found.',
                info: 'Showing _START_ to _END_ of _TOTAL_ entries',
                infoEmpty: 'Showing 0 to 0 of 0 entries',
                infoFiltered: '(filtered from _MAX_ total entries)',
            },
            columns: [
                {
                    data: 'batch', render: (value, type) => type === 'display'
                        ? `<span class="prediction-batch-code">${escapeHtml(value)}</span>` : value,
                },
                {
                    data: 'flowerName', render: (value, type, row) => type !== 'display'
                        ? `${value ?? ''} ${row.flowerCode ?? ''} ${row.category ?? ''}`
                        : `<div class="prediction-flower"><span class="prediction-flower-icon"><i class="bi bi-flower1" aria-hidden="true"></i></span><div><strong>${escapeHtml(value)}</strong><small>${escapeHtml(row.flowerCode)} &middot; ${escapeHtml(row.category)}</small></div></div>`,
                },
                {
                    data: 'remaining', render: (value, type, row) => type === 'display'
                        ? `${number(value)} ${escapeHtml(row.baseUnit)}` : value,
                },
                {
                    data: null, render: (_value, type, row) => {
                        if (type === 'sort' || type === 'type') return usablePrediction(row) ? riskPriority[row.risk] : 0;
                        return type === 'display' ? riskBadge(row) : riskText(row);
                    },
                },
                {
                    data: null, render: (_value, type, row) => type === 'display'
                        ? `<p class="prediction-recommendation">${escapeHtml(recommendation(row))}</p>` : recommendation(row),
                },
                {
                    data: null, orderable: false, searchable: false,
                    render: (_value, type, row) => type === 'display'
                        ? `<button type="button" class="prediction-view-btn" data-view-prediction="${Number(row.id)}" title="View assessment" aria-label="View ${escapeHtml(row.batch)}"><i class="bi bi-eye" aria-hidden="true"></i></button>` : '',
                },
            ],
        });
    }

    mountTableScroller() {
        const scroller = document.createElement('div');
        scroller.className = 'prediction-table-scroll';
        scroller.tabIndex = 0;
        scroller.setAttribute('role', 'region');
        scroller.setAttribute('aria-label', 'Batch prediction table');
        this.tableElement.before(scroller);
        scroller.append(this.tableElement);
    }

    bindEvents() {
        document.getElementById('predictionSearch').addEventListener('input', (event) => {
            this.table.search(event.target.value).draw();
        });
        document.getElementById('predictionPageLength').addEventListener('change', (event) => {
            this.table.page.len(Number(event.target.value)).draw();
        });
        this.tableElement.addEventListener('click', (event) => {
            const button = event.target.closest('[data-view-prediction]');
            if (button) this.showAssessment(Number(button.dataset.viewPrediction));
        });
    }

    // Period switches reuse the same flower snapshot and never fit another model.
    bindForecastEvents() {
        document.getElementById('forecastFlower').addEventListener('change', (event) => {
            this.forecastFlowerId = event.target.value;
            this.renderForecastPeriod();
        });
        document.querySelectorAll('[data-forecast-period]').forEach((button) => {
            button.addEventListener('click', () => {
                this.forecastPeriod = button.dataset.forecastPeriod;
                this.renderForecastPeriod();
            });
        });
    }

    // Keep a user's selected flower when automatic refresh updates the snapshot.
    updateForecastFlowers(forecasts) {
        this.forecasts = Array.isArray(forecasts) ? forecasts : [];
        const select = document.getElementById('forecastFlower');
        if (!this.forecasts.some((flower) => String(flower.flowerId) === this.forecastFlowerId)) {
            this.forecastFlowerId = this.forecasts.length ? String(this.forecasts[0].flowerId) : '';
        }
        select.innerHTML = this.forecasts.length ? this.forecasts.map((flower) => `<option value="${escapeHtml(flower.flowerId)}">${escapeHtml(flower.flowerName)} · ${escapeHtml(flower.flowerCode)}</option>`).join('') : '<option value="">No flowers available</option>';
        select.value = this.forecastFlowerId;
        select.disabled = this.forecasts.length === 0;
        this.renderForecastPeriod();
    }

    // Render true zero values, distinguish unavailable forecasts, and name the risk basis.
    renderForecastPeriod() {
        const key = this.forecastPeriod;
        document.querySelectorAll('[data-forecast-period]').forEach((button) => {
            button.setAttribute('aria-pressed', String(button.dataset.forecastPeriod === key));
        });
        const flower = this.forecasts.find((item) => String(item.flowerId) === this.forecastFlowerId);
        const host = document.getElementById('forecastPeriodContent');
        if (!flower) {
            host.innerHTML = '<p class="forecast-unavailable">No flower forecasts are available. Eligible completed observations are required before a forecast can be generated.</p>';
            return;
        }
        const period = flower.periods?.[key];
        const title = {daily:'Daily',weekly:'Weekly',monthly:'Monthly',yearly:'Yearly'}[key];
        const dates = period?.startDate && period?.endDate
            ? `${key === 'daily' ? 'Forecast for ' : ''}${formatDateWindow(period.startDate, period.endDate)}`
            : 'Forecast dates unavailable';
        const heading = `<div class="forecast-period-heading"><div><h3>${escapeHtml(flower.flowerName)} · ${title} forecast</h3><p class="forecast-window">${escapeHtml(dates)} · Asia/Manila</p></div></div>`;
        if (flower.needsRefresh || period?.status !== 'Predicted' || !validFraction(period.meanDailyFraction) || !validFraction(period.cumulativeFraction)) {
            host.innerHTML = `${heading}<div class="forecast-unavailable"><strong>${flower.needsRefresh ? 'Forecast update required' : period?.status === 'InsufficientData' ? 'Insufficient history' : 'Forecast unavailable'}</strong><p class="forecast-method-note">${escapeHtml(flower.needsRefresh ? 'Inputs changed. The current forecast is being prepared; outdated rates are withheld.' : period?.message || 'A current forecast is required. Saved batch advisories remain available below.')}</p></div>`;
            return;
        }
        const percent = (value) => validFraction(value) ? `${number(value * 100, 2)}%` : 'Not available';
        const quantity = number(period.predictedQuantity, 2);
        const unit = flower.baseUnit === 'Stem' ? 'stems' : 'bouquets';
        const stock = number(flower.remaining);
        const calculationBasis = key === 'daily' ? 'daily rate' : 'cumulative exposure';
        const metrics = [
            ['Average daily spoilage rate', percent(period.meanDailyFraction), 'Average of the future daily forecast rates.'],
            ['Period cumulative exposure', percent(period.cumulativeFraction), 'Compounded exposure across this forecast window; not a risk classification.'],
            key === 'yearly'
                ? ['Planning horizon', '12 months', 'Long-range rate outlook; affected stock quantities are not projected.']
                : ['Expected spoiled quantity', `${quantity} ${unit}`, `Stock basis: ${stock} ${unit}`,
                    `Calculation (higher-precision ${calculationBasis}): ${stock} × ${calculationPercent(flower.remaining, period.cumulativeFraction, quantity)} = ${quantity} ${unit}`],
        ];
        const risk = Object.hasOwn(riskPriority, period.risk) ? `<span class="prediction-risk prediction-risk-${period.risk.toLowerCase()}">${escapeHtml(period.risk)} · 7-day reference risk</span>` : '<span class="text-muted">Reference risk unavailable</span>';
        const buckets = Array.isArray(period.buckets) ? period.buckets : [];
        const maximum = Math.max(0, ...buckets.map((bucket) => validFraction(bucket.meanDailyFraction) ? bucket.meanDailyFraction : 0));
        const breakdown = buckets.length ? `<div class="forecast-breakdown" tabindex="0" role="region" aria-label="${title} forecast breakdown; scroll for all columns"><table><caption>${key === 'yearly' ? 'Month-by-month outlook' : key === 'monthly' ? 'Weekly progression' : 'Daily progression'} · future predictions</caption><thead><tr><th scope="col">Period</th><th scope="col" class="forecast-value">Avg. daily rate</th><th scope="col">Relative trend</th><th scope="col" class="forecast-value">Cumulative exposure</th></tr></thead><tbody>${buckets.map((bucket) => {
            const value = validFraction(bucket.meanDailyFraction) ? bucket.meanDailyFraction : 0;
            const date = escapeHtml(formatDateWindow(bucket.startDate, bucket.endDate));
            const label = bucket.startDate === bucket.endDate ? date : `${escapeHtml(bucket.label)}<small class="forecast-window d-block">${date}</small>`;
            return `<tr><th scope="row">${label}</th><td class="forecast-value">${percent(bucket.meanDailyFraction)}</td><td><span class="forecast-trend" aria-hidden="true"><span style="width:${maximum > 0 ? Math.max(0, Math.min(100, value / maximum * 100)) : 0}%"></span></span></td><td class="forecast-value">${percent(bucket.cumulativeFraction)}</td></tr>`;
        }).join('')}</tbody></table></div>` : '';
        host.innerHTML = `${heading}${risk}<div class="forecast-metrics mt-3">${metrics.map(([label, value, ...notes]) => `<div class="forecast-metric"><span>${escapeHtml(label)}</span><strong>${escapeHtml(value)}</strong>${notes.map((note) => `<small>${escapeHtml(note)}</small>`).join('')}</div>`).join('')}</div>${breakdown}<p class="forecast-method-note">Method: ${escapeHtml(period.method || 'Unavailable')} · ${number(period.historyDays)} completed history days through ${escapeHtml(formatDate(flower.historyEndDate))}. Risk always uses the same 7-day reference window. Bars compare average daily rates within this view; each row’s exposure applies only to that row’s dates. ${escapeHtml(period.message || '')}</p>`;
    }

    // GET reads saved results; only the automatic POST may write predictions.
    async loadData() {
        const response = await fetch(DATA_URL, {
            headers: { Accept: 'application/json' }, credentials: 'same-origin', cache: 'no-store',
        });
        const result = await readResponse(response);
        if (!Array.isArray(result.data)) throw new Error('Invalid batch dataset.');
        this.assessments = result.data;
        this.updateForecastFlowers(result.forecasts);
        this.table.clear().rows.add(this.assessments).draw(false);
        this.renderSummary();
        return this.assessments.filter((item) => item.needsRefresh).length;
    }

    // Update asynchronously after the first table render. No per-batch HTTP loop.
    async start() {
        if (this.running) return;
        this.running = true;
        this.status.textContent = 'Loading saved predictions...';
        try {
            let pending = await this.loadData();
            if (pending === 0) {
                this.showReady();
                return;
            }
            // Bound retries when another tab is updating or inventory is changing.
            let busyWaits = 0;
            for (let pass = 0; pass < 3; pass++) {
                this.status.textContent = `${pending} batches need an update. Saved records are visible; calculating predictions...`;
                const response = await fetch(REFRESH_URL, {
                    method: 'POST', credentials: 'same-origin', cache: 'no-store',
                    headers: { Accept: 'application/json', ...csrfHeaders() },
                });
                const result = await readResponse(response);
                if (result.busy) {
                    if (++busyWaits > 30) throw new Error('Another update is still running. Refresh the browser shortly.');
                    this.status.textContent = 'Predictions are updating in another request. Waiting...';
                    await new Promise((resolve) => window.setTimeout(resolve, 5000));
                    pending = await this.loadData();
                    if (pending === 0) { this.showReady(); return; }
                    pass--;
                    continue;
                }
                pending = await this.loadData();
                if (Array.isArray(result.failed) && result.failed.length) {
                    this.status.textContent = `Update finished with failures: ${result.failed.join(', ')}. Other saved results are displayed. Check the Laravel log.`;
                    return;
                }
                if (pending === 0) { this.showReady(); return; }
            }
            this.status.textContent = `${pending} batches changed during processing or remain pending. Refresh the browser to retry. Saved risks marked outdated are not counted.`;
        } catch (error) {
            this.renderForecastPeriod();
            this.status.textContent = `${error.message} Saved records remain visible. Refresh the browser to retry.`;
        } finally {
            this.running = false;
        }
    }

    showReady() {
        const predicted = this.assessments.filter(usablePrediction).length;
        const insufficient = this.assessments.filter((item) => !item.needsRefresh && item.predictionStatus === 'InsufficientData').length;
        this.status.textContent = `Up to date: ${this.assessments.length} batches, ${predicted} predicted, ${insufficient} insufficient data. Unchanged results are reused.`;
    }

    renderSummary() {
        const current = this.assessments.filter(usablePrediction);
        const count = (risk) => current.filter((item) => item.risk === risk).length;
        document.getElementById('predictionTotal').textContent = this.assessments.length;
        document.getElementById('predictionLow').textContent = count('Low');
        document.getElementById('predictionMedium').textContent = count('Medium');
        document.getElementById('predictionUrgent').textContent = count('High') + count('Critical');
    }

    showAssessment(id) {
        const item = this.assessments.find((row) => Number(row.id) === id);
        if (!item) return;
        const details = [
            ['Current remaining', `${number(item.remaining)} ${item.baseUnit ?? ''}`],
            ['Storage condition', item.storageCondition ?? 'Not recorded'],
            ['Received date', formatDate(item.receivedDate)],
            ['Prediction status', item.predictionStatus],
            ['Saved risk', item.risk ?? 'Not available'], ['Forecast start', formatDate(item.asOfDate)],
            ['Forecast end', formatDate(item.forecastEndDate)], ['Opening stock used', number(item.openingQuantity)],
            ['Predicted spoiled quantity', `${number(item.predictedQuantity, 2)} ${item.baseUnit ?? ''}`],
            ['Predicted spoiled percentage', item.predictedFraction == null ? 'Not available' : `${number(item.predictedFraction * 100, 2)}%`],
            ['Forecast method', item.method ?? 'Not available'], ['Flower history days', number(item.historyDays)],
        ];
        document.getElementById('predictionViewModalLabel').textContent = `Risk Assessment - ${item.batch}`;
        document.getElementById('predictionViewContent').innerHTML = `
            <div class="prediction-detail-heading"><div><h3>${escapeHtml(item.flowerName)}</h3><p>${escapeHtml(item.batch)} &middot; ${escapeHtml(item.flowerCode)}</p></div>${riskBadge(item)}</div>
            <dl class="prediction-detail-grid">${details.map(([label, value]) => `<div><dt>${escapeHtml(label)}</dt><dd>${escapeHtml(value)}</dd></div>`).join('')}</dl>
            <div class="prediction-detail-section"><h4>Recommended Action</h4><p>${escapeHtml(recommendation(item))}</p></div>
            ${item.message ? `<div class="prediction-detail-section"><h4>Model Note</h4><p>${escapeHtml(item.message)}</p></div>` : ''}
            <p class="prediction-detail-note">Predictions describe their saved forecast window. They do not deduct stock or confirm physical spoilage.</p>`;
        this.modal.show();
    }
}

function initializeSpoilagePrediction() {
    const table = document.getElementById('predictionTable');
    if (!table || DataTable.isDataTable(table)) return;
    new SpoilagePredictionUI(table);
}

if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', initializeSpoilagePrediction, { once: true });
} else {
    initializeSpoilagePrediction();
}
