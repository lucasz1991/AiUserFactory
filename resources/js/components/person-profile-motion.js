/*
 * Personen-Profil Motion (GSAP)
 *
 * Additive Bewegungsschicht fuer `[data-person-profile]`. Baugleich defensiv zu
 * `workflow-motion.js`:
 *  - animiert ausschliesslich transform/opacity/filter (GPU-sicher),
 *  - setzt Startzustaende erst unmittelbar vor der Animation, damit ohne
 *    JavaScript nichts unsichtbar bleibt,
 *  - markiert Elemente per data-Flag, damit Livewire-Morphs keine
 *    Endlos-Reanimationen ausloesen,
 *  - respektiert `prefers-reduced-motion` vollstaendig ueber gsap.matchMedia().
 */

import { gsap } from 'gsap';

const ROOT_SELECTOR = '[data-person-profile]';

const clampMagnet = gsap.utils.clamp(-4, 4);
const mapPointer = gsap.utils.mapRange(-1, 1, -6, 6);

function animateHero(root) {
  const hero = root.querySelector('[data-profile-hero]');

  if (!hero || hero.dataset.ffHero) {
    return;
  }

  hero.dataset.ffHero = '1';

  const avatar = hero.querySelector('[data-hero-avatar]');
  const lines = hero.querySelectorAll('[data-hero-line]');
  const tl = gsap.timeline({ defaults: { ease: 'power3.out' } });

  if (avatar) {
    gsap.set(avatar, { autoAlpha: 0, scale: 0.88, rotate: -4 });
    tl.to(avatar, { autoAlpha: 1, scale: 1, rotate: 0, duration: 0.7, clearProps: 'transform,opacity,visibility' });
  }

  if (lines.length) {
    gsap.set(lines, { autoAlpha: 0, y: 16 });
    tl.to(lines, {
      autoAlpha: 1,
      y: 0,
      duration: 0.6,
      stagger: 0.07,
      clearProps: 'transform,opacity,visibility',
    }, avatar ? '-=0.45' : 0);
  }
}

function animateMetrics(root) {
  const metrics = Array.from(root.querySelectorAll('.ff-metric')).filter((el) => !el.dataset.ffMetric);

  if (!metrics.length) {
    return;
  }

  metrics.forEach((el) => {
    el.dataset.ffMetric = '1';
  });

  gsap.set(metrics, { autoAlpha: 0, y: 18, filter: 'blur(5px)' });
  gsap.to(metrics, {
    autoAlpha: 1,
    y: 0,
    filter: 'blur(0px)',
    duration: 0.65,
    ease: 'power3.out',
    stagger: 0.06,
    clearProps: 'transform,opacity,visibility,filter',
    overwrite: 'auto',
  });

  metrics.forEach((el) => countUp(el.querySelector('[data-metric-value]')));
}

/* Zaehlt nur reine Ganzzahlen hoch. Der Suffix (`/ 12`, ` Cookies`) steht in
   einem eigenen Kindelement und bleibt dabei unangetastet. */
function countUp(node) {
  if (!node || node.dataset.ffCounted) {
    return;
  }

  node.dataset.ffCounted = '1';

  const suffix = node.querySelector('.ff-metric__suffix');
  const textNode = Array.from(node.childNodes).find((child) => child.nodeType === Node.TEXT_NODE);
  const raw = (textNode?.textContent || '').trim();

  if (!/^\d{1,6}$/.test(raw)) {
    return;
  }

  const target = Number(raw);

  if (target === 0) {
    return;
  }

  const proxy = { value: 0 };
  gsap.to(proxy, {
    value: target,
    duration: 0.8,
    ease: 'power2.out',
    snap: { value: 1 },
    onUpdate: () => {
      textNode.textContent = String(proxy.value);
    },
    onComplete: () => {
      textNode.textContent = raw;
      if (suffix) {
        // Der Suffix haengt am selben dd; ein Morph darf ihn nicht verlieren.
        node.appendChild(suffix);
      }
    },
  });
}

function animatePanelSwitch(root) {
  const panels = Array.from(root.querySelectorAll('[data-profile-panel]'));

  if (!panels.length) {
    return null;
  }

  const isVisible = (el) => el.offsetParent !== null || getComputedStyle(el).display !== 'none';
  const state = new WeakMap();

  panels.forEach((panel) => state.set(panel, isVisible(panel)));

  const observer = new MutationObserver((mutations) => {
    for (const mutation of mutations) {
      const panel = mutation.target;

      if (!(panel instanceof HTMLElement) || !panels.includes(panel)) {
        continue;
      }

      const visible = isVisible(panel);

      if (visible === state.get(panel)) {
        continue;
      }

      state.set(panel, visible);

      if (!visible) {
        continue;
      }

      /* Bewusst NUR transform, kein opacity/visibility: dieser Tween haengt an
         einem Klick des Nutzers. Laeuft der GSAP-Ticker gerade nicht (Tab im
         Hintergrund, gedrosseltes Rendering), bliebe ein Startzustand mit
         opacity 0 stehen und der gerade geoeffnete Tab waere leer. Ein
         steckengebliebener 12px-Versatz faellt dagegen nicht auf. */
      gsap.fromTo(
        panel,
        { y: 12 },
        {
          y: 0,
          duration: 0.45,
          ease: 'power2.out',
          clearProps: 'transform',
          overwrite: 'auto',
        },
      );
    }
  });

  panels.forEach((panel) => observer.observe(panel, { attributes: true, attributeFilter: ['style', 'class'] }));

  return () => observer.disconnect();
}

function revealAccounts(root, io) {
  root.querySelectorAll('.ff-account-chip, .ff-account-card, .ff-account-paths, .ff-mediacard').forEach((el) => {
    if (el.dataset.ffRevealed) {
      return;
    }
    el.dataset.ffRevealed = '1';
    io.observe(el);
  });
}

function initPersonProfileMotion() {
  const root = document.querySelector(ROOT_SELECTOR);

  if (!root) {
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

      gsap.set(batch, { autoAlpha: 0, y: 14 });
      gsap.to(batch, {
        autoAlpha: 1,
        y: 0,
        duration: 0.55,
        ease: 'power3.out',
        stagger: gsap.utils.distribute({ base: 0, amount: 0.2, ease: 'power1.in' }),
        clearProps: 'transform,opacity,visibility',
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
    }, { rootMargin: '0px 0px -6% 0px', threshold: 0.05 });

    const adopt = () => {
      const scope = document.querySelector(ROOT_SELECTOR);

      if (!scope) {
        return;
      }

      animateHero(scope);
      animateMetrics(scope);
      revealAccounts(scope, io);
    };

    adopt();

    const disposePanels = animatePanelSwitch(root);

    /* Livewire ersetzt beim Tabwechsel und nach jedem Speichern ganze Teilbaeume;
       neue Karten werden gebuendelt pro Frame nachtraeglich adoptiert. */
    let adoptScheduled = false;
    const mo = new MutationObserver((mutations) => {
      if (adoptScheduled || !mutations.some((m) => m.addedNodes.length > 0)) {
        return;
      }

      adoptScheduled = true;
      requestAnimationFrame(() => {
        adoptScheduled = false;
        adopt();
      });
    });
    mo.observe(document.body, { childList: true, subtree: true });

    /* Magnetische Primaeraktion: der Zeiger zieht den Knopf minimal mit,
       Loslassen federt elastisch zurueck. Delegiert, damit neu gerenderte
       Knoepfe ohne erneutes Binden mitmachen. */
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
          duration: 0.4,
          ease: 'power3.out',
          overwrite: 'auto',
        });
      };

      const onLeave = () => {
        gsap.to(el, {
          x: 0,
          y: 0,
          duration: 0.65,
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
      /* Eigener Haken statt einer geliehenen Workflow-Klasse: `.ff-action-trigger--primary`
         bringt dort ein komplettes Knopf-Design per `!important` mit. */
      const el = event.target instanceof Element
        ? event.target.closest('[data-person-profile] [data-magnetic]')
        : null;

      if (el) {
        bindMagnet(el);
      }
    };

    document.addEventListener('pointerover', onPointerOver, { passive: true });

    return () => {
      io.disconnect();
      mo.disconnect();
      disposePanels?.();
      document.removeEventListener('pointerover', onPointerOver);
    };
  });
}

if (document.readyState === 'loading') {
  document.addEventListener('DOMContentLoaded', initPersonProfileMotion, { once: true });
} else {
  initPersonProfileMotion();
}

/* Nach einer Livewire-Navigation existiert die Wurzel erst nach dem Austausch. */
document.addEventListener('livewire:navigated', initPersonProfileMotion);
