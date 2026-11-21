const header = document.querySelector('.shop-header');
const toggle = header.querySelector('.shop-menu-toggle');
const links = header.querySelector('nav');
const mobile = matchMedia('(max-width: 800px)');

function setMenuOpen(isOpen) {
    links.hidden = mobile.matches && !isOpen;
    toggle.hidden = !mobile.matches;
    toggle.setAttribute('aria-expanded', String(isOpen));
}

setMenuOpen(false);
mobile.addEventListener('change', () => setMenuOpen(false));
toggle.addEventListener('click', () => setMenuOpen(links.hidden));

document.addEventListener('click', event => {
    if (mobile.matches && !links.hidden && (!header.contains(event.target) || event.target.closest('a'))) {
        setMenuOpen(false);
    }
});
header.addEventListener('focusout', event => {
    if (!header.contains(event.relatedTarget)) {
        setMenuOpen(false);
    }
});
header.addEventListener('keydown', event => {
    if (event.key === 'Escape' && mobile.matches && !links.hidden) {
        setMenuOpen(false);
        toggle.focus();
    }
});

const topButton = document.querySelector('.shop-back-top');
const topThreshold = document.querySelector('[data-shop-back-top-threshold]');

if (topButton && topThreshold && 'IntersectionObserver' in window) {
    const topObserver = new IntersectionObserver(([entry]) => {
        topButton.hidden = entry.isIntersecting || entry.boundingClientRect.bottom > 0;
    });
    topObserver.observe(topThreshold);

    const reduceMotion = matchMedia('(prefers-reduced-motion: reduce)');
    topButton.addEventListener('click', () => {
        topThreshold.querySelector('h1').focus({ preventScroll: true });
        window.scrollTo({ top: 0, behavior: reduceMotion.matches ? 'instant' : 'smooth' });
    });
}

if ('IntersectionObserver' in window && matchMedia('(prefers-reduced-motion: no-preference)').matches) {
    const reveal = new IntersectionObserver((entries, observer) => {
        for (const entry of entries) {
            if (entry.isIntersecting) {
                entry.target.classList.add('shop-revealed');
                observer.unobserve(entry.target);
            }
        }
    }, { rootMargin: '0px 0px -24px' });

    document.querySelectorAll('[data-shop-reveal]').forEach(section => reveal.observe(section));
}
