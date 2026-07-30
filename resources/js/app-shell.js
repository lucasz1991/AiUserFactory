const DESKTOP_SIDEBAR_BREAKPOINT = 1024;
const DESKTOP_SIDEBAR_EXPAND_DELAY = 750;
const DESKTOP_SIDEBAR_COLLAPSE_DELAY = 1500;
const SIDEBAR_TRANSITION_DURATION = 540;

let sidebarExpandTimer = null;
let sidebarCollapseTimer = null;
let layoutEventTimer = null;
let swipeStart = null;
let desktopSidebarExpandedState = null;
let mobileSidebarTrigger = null;

function appBody() {
    return document.body;
}

function appSidebar() {
    return document.querySelector('[data-ff-shell-sidebar]');
}

function sidebarToggleButton() {
    return document.querySelector('.vertical-menu-btn');
}

function isDesktopSidebar() {
    return window.innerWidth >= 1024;
}

function syncSidebarAccessibility() {
    const sidebar = appSidebar();
    const body = appBody();

    if (!sidebar || !body) {
        return;
    }

    const hidden = !isDesktopSidebar() && !body.classList.contains('sidebar-enable');
    sidebar.toggleAttribute('inert', hidden);
    sidebar.setAttribute('aria-hidden', hidden ? 'true' : 'false');
}

function focusFirstSidebarControl() {
    const target = appSidebar()?.querySelector(
        '[data-ff-sidebar-link], [data-ff-sidebar-group]',
    );

    target?.focus({ preventScroll: true });
}

function captureDesktopSidebarState() {
    const body = appBody();

    if (!body || !isDesktopSidebar()) {
        return;
    }

    desktopSidebarExpandedState = body.dataset.sidebarExpanded === 'true';
}

function clearSidebarTimers() {
    window.clearTimeout(sidebarExpandTimer);
    window.clearTimeout(sidebarCollapseTimer);
    sidebarExpandTimer = null;
    sidebarCollapseTimer = null;
}

function emitLayoutChanged() {
    const body = appBody();

    window.dispatchEvent(new CustomEvent('app-shell-layout-changed', {
        detail: {
            desktop: isDesktopSidebar(),
            expanded: body?.dataset.sidebarExpanded === 'true',
            mobileOpen: body?.classList.contains('sidebar-enable') === true,
        },
    }));
}

function queueLayoutChanged() {
    window.clearTimeout(layoutEventTimer);
    window.requestAnimationFrame(emitLayoutChanged);
    layoutEventTimer = window.setTimeout(() => {
        emitLayoutChanged();
        window.dispatchEvent(new Event('resize'));
    }, SIDEBAR_TRANSITION_DURATION);
}

function syncToggleState() {
    const body = appBody();
    const expanded = isDesktopSidebar()
        ? body?.dataset.sidebarExpanded === 'true'
        : body?.classList.contains('sidebar-enable') === true;

    document.querySelectorAll('.vertical-menu-btn').forEach((button) => {
        button.setAttribute('aria-expanded', expanded ? 'true' : 'false');
        button.setAttribute(
            'aria-label',
            expanded ? 'Hauptnavigation schliessen' : 'Hauptnavigation oeffnen',
        );
    });
}

function setDesktopSidebarExpanded(expanded) {
    const body = appBody();

    if (!body || !isDesktopSidebar()) {
        return;
    }

    const next = Boolean(expanded);
    const current = body.dataset.sidebarExpanded === 'true';
    desktopSidebarExpandedState = next;
    body.dataset.sidebarExpanded = next ? 'true' : 'false';
    body.dataset.sidebarSize = next ? 'lg' : 'sm';
    syncToggleState();

    if (current !== next) {
        queueLayoutChanged();
    }
}

function setMobileSidebarOpen(open, {
    focusNavigation = false,
    restoreFocus = false,
    trigger = null,
} = {}) {
    const body = appBody();

    if (!body) {
        return;
    }

    const next = Boolean(open);
    const current = body.classList.contains('sidebar-enable');

    if (next) {
        mobileSidebarTrigger = trigger ?? mobileSidebarTrigger ?? sidebarToggleButton();
    }

    body.classList.toggle('sidebar-enable', next);
    syncSidebarAccessibility();
    syncToggleState();

    if (current !== next) {
        queueLayoutChanged();
    }

    if (next && focusNavigation) {
        window.requestAnimationFrame(() => {
            if (!isDesktopSidebar() && appBody()?.classList.contains('sidebar-enable')) {
                focusFirstSidebarControl();
            }
        });
    } else if (!next && restoreFocus) {
        const focusTarget = trigger ?? mobileSidebarTrigger ?? sidebarToggleButton();

        window.requestAnimationFrame(() => {
            if (!isDesktopSidebar() && !appBody()?.classList.contains('sidebar-enable')) {
                focusTarget?.focus({ preventScroll: true });
            }
        });
    }
}

function sidebarHoveredOrFocused() {
    const sidebar = appSidebar();
    const brand = document.querySelector('.topbar-brand');
    const activeElement = document.activeElement;

    return Boolean(
        sidebar?.matches(':hover')
        || brand?.matches(':hover')
        || sidebar?.contains(activeElement)
        || brand?.contains(activeElement),
    );
}

function scheduleDesktopExpand() {
    window.clearTimeout(sidebarCollapseTimer);
    window.clearTimeout(sidebarExpandTimer);

    if (!isDesktopSidebar() || appBody()?.dataset.sidebarExpanded === 'true') {
        return;
    }

    sidebarExpandTimer = window.setTimeout(() => {
        sidebarExpandTimer = null;

        if (sidebarHoveredOrFocused()) {
            setDesktopSidebarExpanded(true);
        }
    }, DESKTOP_SIDEBAR_EXPAND_DELAY);
}

function scheduleDesktopCollapse() {
    window.clearTimeout(sidebarExpandTimer);
    window.clearTimeout(sidebarCollapseTimer);

    if (!isDesktopSidebar()) {
        return;
    }

    sidebarCollapseTimer = window.setTimeout(() => {
        sidebarCollapseTimer = null;

        if (!sidebarHoveredOrFocused()) {
            setDesktopSidebarExpanded(false);
        }
    }, DESKTOP_SIDEBAR_COLLAPSE_DELAY);
}

function normalizedUrl(value) {
    try {
        const url = new URL(value, window.location.origin);

        return `${url.origin}${url.pathname.replace(/\/+$/, '') || '/'}`;
    } catch {
        return '';
    }
}

function setGroupAria(sideMenu) {
    sideMenu.querySelectorAll('[data-ff-sidebar-group]').forEach((group) => {
        group.setAttribute(
            'aria-expanded',
            group.closest('[data-ff-sidebar-group-item]')?.classList.contains('mm-active')
                ? 'true'
                : 'false',
        );
    });
}

function initActiveMenu() {
    const sideMenu = document.getElementById('side-menu');

    if (!sideMenu) {
        return;
    }

    const pageUrl = normalizedUrl(window.location.href);
    const links = Array.from(sideMenu.querySelectorAll('[data-ff-sidebar-link]'));

    links.forEach((link) => {
        link.classList.remove('active');
        link.removeAttribute('aria-current');
    });
    sideMenu.querySelectorAll('[data-ff-sidebar-group-item]').forEach((item) => {
        item.classList.remove('mm-active');
    });
    sideMenu.querySelectorAll('[data-ff-sidebar-group-item] > ul').forEach((list) => {
        list.classList.remove('mm-show');
    });

    const exact = links.filter((link) => normalizedUrl(link.href) === pageUrl);
    const prefix = links
        .filter((link) => {
            const href = normalizedUrl(link.href);

            return href !== `${window.location.origin}/`
                && pageUrl.startsWith(`${href}/`);
        })
        .sort((left, right) => normalizedUrl(right.href).length - normalizedUrl(left.href).length);
    const activeLinks = exact.length > 0
        ? exact
        : (prefix.length > 0 ? [prefix[0]] : []);

    activeLinks.forEach((link) => {
        link.classList.add('active');
        link.setAttribute('aria-current', 'page');

        let item = link.closest('li');
        while (item && sideMenu.contains(item)) {
            item.classList.add('mm-active');

            const parentList = item.parentElement;
            if (parentList?.tagName === 'UL' && parentList.id !== 'side-menu') {
                parentList.classList.add('mm-show');
            }

            item = parentList?.closest('li') ?? null;
        }
    });

    setGroupAria(sideMenu);
}

function initMetisMenu() {
    const sideMenu = document.getElementById('side-menu');

    if (!sideMenu || sideMenu.dataset.ffMetisInitialized === 'true') {
        return;
    }

    sideMenu.dataset.ffMetisInitialized = 'true';

    sideMenu.querySelectorAll('[data-ff-sidebar-group]').forEach((trigger) => {
        trigger.addEventListener('click', (event) => {
            event.preventDefault();
            const item = trigger.closest('[data-ff-sidebar-group-item]');
            const open = !item?.classList.contains('mm-active');
            const list = item?.querySelector(':scope > ul');

            sideMenu.querySelectorAll('[data-ff-sidebar-group-item]').forEach((candidate) => {
                if (candidate === item) {
                    return;
                }

                candidate.classList.remove('mm-active');
                const candidateList = candidate.querySelector(':scope > ul');
                candidateList?.classList.remove('mm-show', 'mm-collapsing');
                candidateList?.style.removeProperty('height');
            });

            item?.classList.toggle('mm-active', open);
            list?.classList.remove('mm-collapsing');
            list?.classList.toggle('mm-show', open);
            list?.style.removeProperty('height');
            setGroupAria(sideMenu);
        });
    });
}

function scrollActiveMenuIntoView() {
    window.setTimeout(() => {
        const active = document.querySelector('#side-menu [aria-current="page"]');
        const scroller = appSidebar()?.querySelector('.simplebar-content-wrapper');

        if (!active || !scroller) {
            return;
        }

        const scrollerRect = scroller.getBoundingClientRect();
        const activeRect = active.getBoundingClientRect();

        if (activeRect.top >= scrollerRect.top && activeRect.bottom <= scrollerRect.bottom) {
            return;
        }

        active.scrollIntoView({
            block: 'center',
            behavior: window.matchMedia('(prefers-reduced-motion: reduce)').matches ? 'auto' : 'smooth',
        });
    }, 160);
}

function initFeatherIcons() {
    if (window.feather && typeof window.feather.replace === 'function') {
        window.feather.replace();
    }
}

function bindShellElements() {
    document.querySelectorAll('.vertical-menu-btn').forEach((button) => {
        if (button.dataset.ffShellBound === 'true') {
            return;
        }

        button.dataset.ffShellBound = 'true';
        button.addEventListener('click', (event) => {
            event.preventDefault();
            clearSidebarTimers();

            if (isDesktopSidebar()) {
                setDesktopSidebarExpanded(appBody()?.dataset.sidebarExpanded !== 'true');
            } else {
                const next = !appBody()?.classList.contains('sidebar-enable');
                setMobileSidebarOpen(next, {
                    focusNavigation: next,
                    restoreFocus: !next,
                    trigger: button,
                });
            }
        });
    });

    document.querySelectorAll('[data-ff-sidebar-backdrop]').forEach((backdrop) => {
        if (backdrop.dataset.ffShellBound === 'true') {
            return;
        }

        backdrop.dataset.ffShellBound = 'true';
        backdrop.addEventListener('click', () => {
            setMobileSidebarOpen(false, { restoreFocus: true });
        });
    });

    document.querySelectorAll('[data-ff-shell-sidebar], .topbar-brand').forEach((element) => {
        if (element.dataset.ffShellHoverBound === 'true') {
            return;
        }

        element.dataset.ffShellHoverBound = 'true';
        element.addEventListener('mouseenter', scheduleDesktopExpand);
        element.addEventListener('mouseleave', scheduleDesktopCollapse);
        element.addEventListener('focusin', () => {
            if (isDesktopSidebar()) {
                clearSidebarTimers();
                setDesktopSidebarExpanded(true);
            }
        });
        element.addEventListener('focusout', scheduleDesktopCollapse);
    });

    document.querySelectorAll('[data-ff-sidebar-link]').forEach((link) => {
        if (link.dataset.ffShellBound === 'true') {
            return;
        }

        link.dataset.ffShellBound = 'true';
        link.addEventListener('click', () => {
            if (!isDesktopSidebar()) {
                setMobileSidebarOpen(false);
            }
        });
    });
}

function syncResponsiveMode() {
    const body = appBody();

    if (!body || !appSidebar()) {
        return;
    }

    const desktop = window.innerWidth >= 1024;
    body.dataset.sidebarCollapsible = desktop ? 'true' : 'false';

    if (desktop) {
        if (body.classList.contains('sidebar-enable')) {
            setMobileSidebarOpen(false);
        }

        const expanded = desktopSidebarExpandedState
            ?? body.dataset.sidebarExpanded === 'true';
        desktopSidebarExpandedState = expanded;
        body.dataset.sidebarExpanded = expanded ? 'true' : 'false';
        body.dataset.sidebarSize = expanded ? 'lg' : 'sm';
    } else {
        clearSidebarTimers();

        if (desktopSidebarExpandedState === null) {
            desktopSidebarExpandedState = body.dataset.sidebarExpanded === 'true';
        }

        body.dataset.sidebarExpanded = 'false';
        body.dataset.sidebarSize = 'lg';
    }

    syncSidebarAccessibility();
    syncToggleState();
}

function initAppShell() {
    if (!appSidebar()) {
        return;
    }

    syncResponsiveMode();
    bindShellElements();
    initMetisMenu();
    initActiveMenu();
    scrollActiveMenuIntoView();
    initFeatherIcons();
}

function interactiveTouchTarget(target) {
    return target instanceof Element
        && Boolean(target.closest('input, textarea, select, button, [role="dialog"], [contenteditable="true"]'));
}

function bindGlobalShellInteractions() {
    if (window.__ffShellInteractionsBound === true) {
        return;
    }

    window.__ffShellInteractionsBound = true;

    document.addEventListener('pointerdown', (event) => {
        const target = event.target instanceof Element ? event.target : null;

        if (isDesktopSidebar()) {
            if (!target?.closest('[data-ff-shell-sidebar], .topbar-brand')) {
                clearSidebarTimers();
                setDesktopSidebarExpanded(false);
            }
            return;
        }

        if (
            appBody()?.classList.contains('sidebar-enable')
            && !target?.closest('[data-ff-shell-sidebar], .vertical-menu-btn')
        ) {
            setMobileSidebarOpen(false);
        }
    }, true);

    document.addEventListener('keydown', (event) => {
        if (event.key !== 'Escape' || event.defaultPrevented) {
            return;
        }

        if (document.querySelector('[data-ff-dropdown-root][data-open="true"]')) {
            return;
        }

        const mobileOpen = appBody()?.classList.contains('sidebar-enable') === true;
        clearSidebarTimers();
        setDesktopSidebarExpanded(false);
        setMobileSidebarOpen(false, { restoreFocus: mobileOpen });
    });

    document.addEventListener('touchstart', (event) => {
        if (isDesktopSidebar() || event.touches.length !== 1 || interactiveTouchTarget(event.target)) {
            swipeStart = null;
            return;
        }

        const touch = event.touches[0];
        const sidebarOpen = appBody()?.classList.contains('sidebar-enable') === true;
        const insideSidebar = event.target instanceof Element
            && Boolean(event.target.closest('[data-ff-shell-sidebar]'));

        if ((!sidebarOpen && touch.clientX <= 26) || (sidebarOpen && insideSidebar)) {
            swipeStart = {
                x: touch.clientX,
                y: touch.clientY,
                sidebarOpen,
            };
        }
    }, { passive: true });

    document.addEventListener('touchend', (event) => {
        if (!swipeStart || event.changedTouches.length !== 1) {
            swipeStart = null;
            return;
        }

        const touch = event.changedTouches[0];
        const deltaX = touch.clientX - swipeStart.x;
        const deltaY = touch.clientY - swipeStart.y;
        const threshold = Math.max(64, Math.min(110, window.innerWidth * 0.2));
        const horizontal = Math.abs(deltaX) >= threshold
            && Math.abs(deltaX) > Math.abs(deltaY) * 1.25;

        if (horizontal && !swipeStart.sidebarOpen && deltaX > 0) {
            setMobileSidebarOpen(true, { focusNavigation: true });
        } else if (horizontal && swipeStart.sidebarOpen && deltaX < 0) {
            setMobileSidebarOpen(false, { restoreFocus: true });
        }

        swipeStart = null;
    }, { passive: true });

    document.addEventListener('touchcancel', () => {
        swipeStart = null;
    }, { passive: true });

    window.addEventListener('resize', syncResponsiveMode);
    document.addEventListener('livewire:navigate', () => {
        captureDesktopSidebarState();
        clearSidebarTimers();
    });
    document.addEventListener('livewire:navigating', () => {
        captureDesktopSidebarState();
        setMobileSidebarOpen(false);
    });
    document.addEventListener('livewire:navigated', initAppShell);
}

bindGlobalShellInteractions();

if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', initAppShell, { once: true });
} else {
    initAppShell();
}
