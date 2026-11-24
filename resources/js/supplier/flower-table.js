export function safeFlowerImageUrl(value) {
    if (!value) return null;

    try {
        const url = new URL(value, window.location.href);
        return ['http:', 'https:'].includes(url.protocol) ? url.href : null;
    } catch {
        return null;
    }
}

export function renderFlowerTableDetails(flower, escapeHtml) {
    const url = safeFlowerImageUrl(flower.imageUrl);
    const thumbnail = url
        ? `<img src="${escapeHtml(url)}" class="flower-table-image" alt="" loading="lazy">`
        : '<span class="flower-table-placeholder" aria-hidden="true"><i class="bi bi-flower1"></i></span>';

    return `<div class="flower-table-details">
        ${thumbnail}
        <div><strong>${escapeHtml(flower.name)}</strong><small>${escapeHtml(flower.code)}</small></div>
    </div>`;
}

export function renderOrderItemFlowers(items, escapeHtml) {
    return `<div class="flower-table-items">${items.map((item) => renderFlowerTableDetails({
        name: item.flowerName,
        code: item.flowerId,
        imageUrl: item.imageUrl,
    }, escapeHtml)).join('')}</div>`;
}
