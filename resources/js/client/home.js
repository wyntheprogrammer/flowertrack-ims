const hero = document.querySelector('.floral-hero');

if (hero) {
    const motion = window.matchMedia('(min-width: 701px) and (hover: hover) and (pointer: fine) and (prefers-reduced-motion: no-preference)');
    let frame = 0;
    let pointerX = 0;
    let pointerY = 0;

    function paintDepth() {
        const bounds = hero.getBoundingClientRect();
        const x = (pointerX - bounds.left) / bounds.width - 0.5;
        const y = (pointerY - bounds.top) / bounds.height - 0.5;
        hero.style.setProperty('--scene-x', `${x * 10}px`);
        hero.style.setProperty('--scene-y', `${y * 8}px`);
        frame = 0;
    }

    function followPointer(event) {
        pointerX = event.clientX;
        pointerY = event.clientY;
        if (!frame) {
            frame = requestAnimationFrame(paintDepth);
        }
    }

    function resetDepth() {
        cancelAnimationFrame(frame);
        frame = 0;
        hero.style.removeProperty('--scene-x');
        hero.style.removeProperty('--scene-y');
    }

    function syncMotion() {
        if (motion.matches) {
            hero.addEventListener('pointermove', followPointer);
            hero.addEventListener('pointerleave', resetDepth);
        } else {
            hero.removeEventListener('pointermove', followPointer);
            hero.removeEventListener('pointerleave', resetDepth);
            resetDepth();
        }
    }

    syncMotion();
    motion.addEventListener('change', syncMotion);
}
