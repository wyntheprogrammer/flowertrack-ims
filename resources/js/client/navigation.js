const header = document.querySelector('.shop-header');
const toggle = header.querySelector('.shop-menu-toggle');
const links = header.querySelector('nav');
const collections = links.querySelector('details');
const mobile = matchMedia('(max-width: 800px)');

function closeMenu() {
    collections.open = false;
    links.hidden = mobile.matches;
    toggle.hidden = !mobile.matches;
    toggle.setAttribute('aria-expanded', 'false');
}

closeMenu();
mobile.addEventListener('change', closeMenu);
toggle.addEventListener('click', () => {
    links.hidden = !links.hidden;
    toggle.setAttribute('aria-expanded', String(!links.hidden));
    if (links.hidden) collections.open = false;
});
document.addEventListener('click', event => {
    if (!header.contains(event.target) || event.target.closest('a')) closeMenu();
    else if (!collections.contains(event.target)) collections.open = false;
});
header.addEventListener('focusout', event => {
    if (!header.contains(event.relatedTarget)) closeMenu();
    else if (!collections.contains(event.relatedTarget)) collections.open = false;
});
header.addEventListener('keydown', event => {
    if (event.key !== 'Escape') return;
    if (collections.open) {
        collections.open = false;
        collections.querySelector('summary').focus();
    } else if (mobile.matches && !links.hidden) {
        closeMenu();
        toggle.focus();
    }
});
