const hero = document.querySelector('.floral-hero');
const photo = hero.querySelector('.home-hero-photo');
const desktopMotion = matchMedia('(min-width: 761px) and (hover: hover) and (pointer: fine) and (prefers-reduced-motion: no-preference)');
const reduceMotion = matchMedia('(prefers-reduced-motion: reduce)');
let scrollFrame = 0;

function updateScrollEffects() {
    const { bottom, height } = hero.getBoundingClientRect();

    if (desktopMotion.matches) {
        const progress = Math.min(1, Math.max(0, 1 - bottom / height));
        photo.style.setProperty('--photo-progress', progress);
    } else {
        photo.style.removeProperty('--photo-progress');
    }

    scrollFrame = 0;
}

function queueScrollUpdate() {
    if (!scrollFrame) {
        scrollFrame = requestAnimationFrame(updateScrollEffects);
    }
}

window.addEventListener('scroll', queueScrollUpdate, { passive: true });
window.addEventListener('resize', queueScrollUpdate);
window.addEventListener('pageshow', queueScrollUpdate);
desktopMotion.addEventListener('change', queueScrollUpdate);
updateScrollEffects();

if ('IntersectionObserver' in window && !reduceMotion.matches) {
    const revealCards = new IntersectionObserver((entries, observer) => {
        const visibleCards = entries.filter(entry => entry.isIntersecting);
        visibleCards.forEach(({ target }, index) => {
            target.style.setProperty('--home-card-delay', (index * 45) + 'ms');
            target.classList.add('home-card-entered');
            observer.unobserve(target);
        });
    }, { threshold: 0.1 });
    document.querySelectorAll('.home-flowers .catalog-item')
        .forEach(card => revealCards.observe(card));
}
