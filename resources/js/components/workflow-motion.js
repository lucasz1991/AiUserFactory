/*
 * Workflow Experience Motion (GSAP)
 * Additive Bewegungsschicht fuer Workflow-Manager, Studio, Run-Preview und
 * Copilot-Chat. Bewusst defensiv gebaut:
 *  - animiert ausschliesslich transform/opacity/filter (GPU-sicher),
 *  - setzt Sichtbarkeits-Startzustaende erst unmittelbar vor der Animation
 *    (ohne JS bleibt alles sichtbar),
 *  - markiert Elemente per data-Flag, damit Livewire-Morphs keine
 *    Endlos-Reanimationen ausloesen,
 *  - respektiert prefers-reduced-motion vollstaendig via gsap.matchMedia().
 */

import { gsap } from 'gsap';

const ROOT_SELECTOR = [
  '.workflow-experience',
  '[data-workflow-manager-root]',
  '[data-workflow-studio-shell]',
  '[data-workflow-copilot-root]',
  '[data-workflow-run-preview]',
].join(', ');

const REVEAL_SELECTOR = [
  '.ff-command-surface',
  '.ff-step-column',
  '.ff-run-preview-card',
  '.ff-chat-intro',
  '.ff-quick-command',
  '.ff-browser-card',
].join(', ');

const MAGNET_SELECTOR = '.ff-action-trigger--primary, .ff-copilot-launcher';

const clampMagnet = gsap.utils.clamp(-5, 5);
const mapPointer = gsap.utils.mapRange(-1, 1, -7, 7);

function initWorkflowMotion() {
  if (!document.querySelector(ROOT_SELECTOR)) {
    return;
  }

  const mm = gsap.matchMedia();

  mm.add('(prefers-reduced-motion: no-preference)', () => {
    const revealQueue = new Set();
    let flushScheduled = false;

    const flushReveals = () => {
      flushScheduled = false;
      const batch = Array.from(revealQueue);
      revealQueue.clear();
      if (!batch.length) {
        return;
      }

      gsap.set(batch, { autoAlpha: 0, y: 22, filter: 'blur(6px)' });
      gsap.to(batch, {
        autoAlpha: 1,
        y: 0,
        filter: 'blur(0px)',
        duration: 0.85,
        ease: 'power3.out',
        stagger: gsap.utils.distribute({ base: 0, amount: 0.28, ease: 'power1.in' }),
        clearProps: 'opacity,visibility,transform,filter',
        overwrite: 'auto',
      });
    };

    const io = new IntersectionObserver((entries) => {
      for (const entry of entries) {
        if (!entry.isIntersecting) {
          continue;
        }
        io.unobserve(entry.target);
        revealQueue.add(entry.target);
      }
      if (revealQueue.size && !flushScheduled) {
        flushScheduled = true;
        requestAnimationFrame(flushReveals);
      }
    }, { rootMargin: '0px 0px -8% 0px', threshold: 0.05 });

    const countUp = (dd) => {
      const raw = (dd.textContent || '').trim();
      if (!/^\d{1,6}$/.test(raw)) {
        return;
      }
      const target = Number(raw);
      const proxy = { value: 0 };
      gsap.to(proxy, {
        value: target,
        duration: 0.9,
        ease: 'power2.out',
        snap: { value: 1 },
        onUpdate: () => {
          dd.textContent = String(proxy.value);
        },
        onComplete: () => {
          dd.textContent = raw;
        },
      });
    };

    const adopt = (scope) => {
      scope.querySelectorAll(REVEAL_SELECTOR).forEach((el) => {
        if (el.dataset.ffRevealed) {
          return;
        }
        el.dataset.ffRevealed = '1';
        io.observe(el);
      });

      scope.querySelectorAll('.ff-metric dd').forEach((dd) => {
        if (dd.dataset.ffCounted) {
          return;
        }
        dd.dataset.ffCounted = '1';
        countUp(dd);
      });
    };

    adopt(document);

    /* Livewire-Morphs: neue Karten/Flaechen nachtraeglich adoptieren —
       gebuendelt pro Frame, damit der Observer keine Last erzeugt. */
    let adoptScheduled = false;
    const mo = new MutationObserver((mutations) => {
      if (adoptScheduled) {
        return;
      }
      const relevant = mutations.some((m) => m.addedNodes.length > 0);
      if (!relevant) {
        return;
      }
      adoptScheduled = true;
      requestAnimationFrame(() => {
        adoptScheduled = false;
        adopt(document);
      });
    });
    mo.observe(document.body, { childList: true, subtree: true });

    /* Magnetische Primaeraktionen: Zeiger zieht den Button minimal mit,
       Loslassen federt elastisch zurueck (nur transform, delegiert). */
    const bindMagnet = (el) => {
      if (el.dataset.ffMagnet) {
        return;
      }
      el.dataset.ffMagnet = '1';

      const onMove = (event) => {
        const rect = el.getBoundingClientRect();
        const relX = (event.clientX - rect.left) / rect.width - 0.5;
        const relY = (event.clientY - rect.top) / rect.height - 0.5;
        gsap.to(el, {
          x: clampMagnet(mapPointer(relX * 2)),
          y: clampMagnet(mapPointer(relY * 2)),
          duration: 0.45,
          ease: 'power3.out',
          overwrite: 'auto',
        });
      };

      const onLeave = () => {
        gsap.to(el, {
          x: 0,
          y: 0,
          duration: 0.7,
          ease: 'elastic.out(1, 0.45)',
          overwrite: 'auto',
          clearProps: 'x,y',
        });
      };

      el.addEventListener('pointermove', onMove);
      el.addEventListener('pointerleave', onLeave);
      el.addEventListener('pointercancel', onLeave);
    };

    const onPointerOver = (event) => {
      const el = event.target instanceof Element
        ? event.target.closest(MAGNET_SELECTOR)
        : null;
      if (el) {
        bindMagnet(el);
      }
    };
    document.addEventListener('pointerover', onPointerOver, { passive: true });

    return () => {
      io.disconnect();
      mo.disconnect();
      document.removeEventListener('pointerover', onPointerOver);
    };
  });
}

if (document.readyState === 'loading') {
  document.addEventListener('DOMContentLoaded', initWorkflowMotion, { once: true });
} else {
  initWorkflowMotion();
}
