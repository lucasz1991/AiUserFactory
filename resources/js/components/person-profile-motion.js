import { gsap } from 'gsap';

// Livewire owns metrics and account cards. Animate only on navigation, never
// on DOM morphs, and never rewrite live numbers using a count-up tween.
const mounted = new WeakSet();
let media;

function initPersonProfileMotion() {
  const root = document.querySelector('[data-person-profile]');
  if (!root || mounted.has(root)) return;
  mounted.add(root);
  media?.revert();
  const mm = gsap.matchMedia();
  media = mm;
  mm.add('(prefers-reduced-motion: no-preference)', () => {
    const hero = root.querySelector('[data-profile-hero]');
    if (hero) {
      gsap.fromTo(hero, { y: 6 }, {
        y: 0, duration: 0.24, ease: 'power2.out', clearProps: 'transform',
      });
    }
  });
}

document.addEventListener('livewire:navigating', () => {
  media?.revert();
  media = undefined;
});
document.addEventListener('livewire:navigated', initPersonProfileMotion);
if (document.readyState === 'loading') {
  document.addEventListener('DOMContentLoaded', initPersonProfileMotion, { once: true });
} else {
  initPersonProfileMotion();
}
