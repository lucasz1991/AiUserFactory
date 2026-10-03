import { gsap } from 'gsap';

// Entry motion belongs to page navigation, never to polling or DOM mutations.
// Editor, inspector and live run surfaces must keep their coordinates stable.
const ROOT_SELECTOR = '.workflow-experience, [data-workflow-copilot-root]';
const STABLE_SURFACE = '[data-workflow-manager-root], [data-workflow-studio-shell], [data-workflow-definition-editor], [data-workflow-run-preview], .jetstream-modal';
const REVEAL_SELECTOR = '.ff-command-surface, .ff-chat-intro, .ff-quick-command';
let dispose = () => {};

export function initWorkflowMotion() {
    dispose();
    const roots = [...document.querySelectorAll(ROOT_SELECTOR)];
    if (!roots.length) return;

    const media = gsap.matchMedia();
    media.add('(prefers-reduced-motion: no-preference)', () => {
        const targets = roots.flatMap((root) => [...root.querySelectorAll(REVEAL_SELECTOR)])
            .filter((element) => !element.closest(STABLE_SURFACE) && element.getClientRects().length);
        const uniqueTargets = [...new Set(targets)];
        if (!uniqueTargets.length) return;

        gsap.fromTo(uniqueTargets, { opacity: 0, y: 8 }, {
            opacity: 1, y: 0, duration: 0.3, stagger: 0.035,
            ease: 'power2.out', clearProps: 'opacity,transform',
        });
    });
    dispose = () => media.revert();
}

document.addEventListener('livewire:navigating', () => dispose());
document.addEventListener('livewire:navigated', initWorkflowMotion);
if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', initWorkflowMotion, { once: true });
} else {
    initWorkflowMotion();
}
