const BODY_EXCLUDED_TAGS = new Set(['html', 'head']);
const SEMANTIC_ATTRIBUTES = [
    ['data-testid', 100],
    ['data-test', 98],
    ['data-cy', 98],
    ['data-qa', 98],
    ['aria-label', 92],
    ['name', 88],
    ['placeholder', 82],
    ['title', 78],
];
const ACTIONABLE_TAGS = new Set(['a', 'button', 'input', 'select', 'textarea']);
const KNOWN_HTML_TAGS = new Set([
    'a', 'article', 'aside', 'body', 'button', 'canvas', 'div', 'fieldset', 'footer',
    'form', 'h1', 'h2', 'h3', 'h4', 'h5', 'h6', 'header', 'iframe', 'img', 'input',
    'label', 'li', 'main', 'nav', 'ol', 'option', 'p', 'section', 'select', 'span',
    'strong', 'table', 'tbody', 'td', 'textarea', 'th', 'thead', 'tr', 'ul',
]);

function normalizedString(value) {
    return String(value ?? '').trim();
}

function normalizedTag(value) {
    const tag = normalizedString(value).toLowerCase();

    return /^[a-z][a-z0-9-]*$/.test(tag) ? tag : 'div';
}

function normalizedClasses(value) {
    const classes = Array.isArray(value)
        ? value
        : normalizedString(value).split(/\s+/);

    return classes
        .map((className) => normalizedString(className))
        .filter(Boolean)
        .slice(0, 8);
}

function normalizedRect(node = {}) {
    const source = node.rect && typeof node.rect === 'object'
        ? node.rect
        : node;

    return {
        x: Number(source.x || 0),
        y: Number(source.y || 0),
        width: Math.max(0, Number(source.width || 0)),
        height: Math.max(0, Number(source.height || 0)),
    };
}

function cssIdentifier(value) {
    if (window.CSS?.escape) {
        return window.CSS.escape(normalizedString(value));
    }

    return normalizedString(value).replace(/[^a-zA-Z0-9_-]/g, (character) => `\\${character}`);
}

function cssAttributeValue(value) {
    return normalizedString(value)
        .replace(/\\/g, '\\\\')
        .replace(/"/g, '\\"')
        .replace(/\r?\n/g, '\\a ');
}

function selectorLooksStructured(query) {
    const normalized = normalizedString(query);

    return /^[#.[*:]/.test(normalized)
        || /[>+~,[\]#.:]/.test(normalized)
        || KNOWN_HTML_TAGS.has(normalized.toLowerCase());
}

function nodeSearchText(node = {}) {
    return [
        node.text,
        node.label,
        node.ariaLabel,
        node.placeholder,
        node.title,
        node.name,
        node.role,
        node.type,
        node.id,
        ...(Array.isArray(node.classes) ? node.classes : []),
    ]
        .map((value) => normalizedString(value).toLocaleLowerCase('de'))
        .filter(Boolean)
        .join(' ');
}

function bodyOnlyNodes(nodes = []) {
    const groups = new Map();

    for (const node of nodes) {
        const frameRef = normalizedString(node.frameRef) || 'main';
        const group = groups.get(frameRef) || [];
        group.push(node);
        groups.set(frameRef, group);
    }

    const included = [];

    for (const frameNodes of groups.values()) {
        const frameIndex = Object.fromEntries(frameNodes.map((node) => [node.nodeRef, node]));
        const bodyNode = frameNodes.find((node) => normalizedTag(node.tag) === 'body');

        for (const node of frameNodes) {
            const tag = normalizedTag(node.tag);

            if (BODY_EXCLUDED_TAGS.has(tag)) {
                continue;
            }

            if (bodyNode) {
                let current = node;
                let insideBody = node.nodeRef === bodyNode.nodeRef;
                let guard = 0;

                while (!insideBody && current?.parentRef && guard < 80) {
                    current = frameIndex[current.parentRef] || null;
                    insideBody = current?.nodeRef === bodyNode.nodeRef;
                    guard += 1;
                }

                if (!insideBody) {
                    continue;
                }
            } else {
                let current = node;
                let insideHead = false;
                let guard = 0;

                while (current && guard < 80) {
                    if (normalizedTag(current.tag) === 'head') {
                        insideHead = true;
                        break;
                    }

                    current = current.parentRef ? (frameIndex[current.parentRef] || null) : null;
                    guard += 1;
                }

                if (insideHead) {
                    continue;
                }
            }

            included.push(node);
        }
    }

    const includedRefs = new Set(included.map((node) => node.nodeRef));

    return included.map((node) => ({
        ...node,
        parentRef: includedRefs.has(node.parentRef) ? node.parentRef : null,
    }));
}

function selectorTextFilter(query) {
    const normalized = normalizedString(query);
    const explicitText = normalized.match(/^text\s*=\s*(.+)$/i);

    if (explicitText) {
        return {
            css: '*',
            exact: false,
            text: normalizedString(explicitText[1]).replace(/^(['"])(.*)\1$/, '$2'),
        };
    }

    const pseudo = normalized.match(/:(has-text|text-is)\(\s*(['"])(.*?)\2\s*\)/i);
    if (!pseudo) {
        return null;
    }

    return {
        css: normalized.replace(pseudo[0], '').trim() || '*',
        exact: pseudo[1].toLowerCase() === 'text-is',
        text: pseudo[3],
    };
}

export function workflowDomInspector(config = {}) {
    return {
        nodes: [],
        nodeIndex: {},
        childrenByParent: {},
        searchFrames: [],
        searchElementByRef: {},
        selectedRef: null,
        selectedSuggestions: [],
        collapsed: {},
        viewport: null,
        cursor: null,
        cursorPoint: null,
        cursorClicked: false,
        windowKey: 'main',
        query: '',
        matchedRefs: [],
        matchedLookup: {},
        matchOrder: {},
        searchError: '',
        copiedSelector: '',
        selectionNotice: '',
        interactive: config.interactive === true,
        canProbe: config.canProbe === true,
        storageKey: normalizedString(config.storageKey),

        init() {
            let payload = {};

            try {
                payload = JSON.parse(this.$refs.payload.textContent || '{}');
            } catch {
                payload = {};
            }

            const rawNodes = Array.isArray(payload.nodes) ? payload.nodes : [];
            const normalizedNodes = rawNodes.map((node, index) => {
                const frameRef = normalizedString(node.frameRef) || 'main';
                const nodeRef = normalizedString(node.nodeRef) || `${frameRef}:snapshot:${index}`;
                const attributes = node.attributes && typeof node.attributes === 'object' && !Array.isArray(node.attributes)
                    ? node.attributes
                    : {};

                return {
                    ...node,
                    nodeRef,
                    frameRef,
                    parentRef: normalizedString(node.parentRef) || null,
                    tag: normalizedTag(node.tag),
                    classes: normalizedClasses(node.classes ?? node.className),
                    attributes,
                    role: normalizedString(node.role || attributes.role),
                    type: normalizedString(node.type || attributes.type),
                    name: normalizedString(node.name || attributes.name),
                    ariaLabel: normalizedString(node.ariaLabel || attributes['aria-label']),
                    placeholder: normalizedString(node.placeholder || attributes.placeholder),
                    title: normalizedString(node.title || attributes.title),
                    href: normalizedString(node.href || attributes.href),
                    rect: normalizedRect(node),
                    depth: Math.max(0, Number(node.depth || 0)),
                    visible: node.visible === true,
                    enabled: node.enabled !== false,
                    focused: node.focused === true,
                    editable: node.editable === true,
                    actionable: node.actionable === true,
                    inShadowDom: node.inShadowDom === true,
                    selectorCandidates: Array.isArray(node.selectorCandidates) ? node.selectorCandidates : [],
                };
            });

            this.nodes = bodyOnlyNodes(normalizedNodes);
            this.nodeIndex = Object.fromEntries(this.nodes.map((node) => [node.nodeRef, node]));
            this.childrenByParent = {};
            for (const node of this.nodes) {
                if (!node.parentRef) {
                    continue;
                }

                this.childrenByParent[node.parentRef] ||= [];
                this.childrenByParent[node.parentRef].push(node.nodeRef);
            }
            this.viewport = payload.viewport || null;
            this.cursor = payload.cursor || null;
            this.windowKey = normalizedString(payload.windowKey) || 'main';
            this.buildSearchFrames();

            const remembered = this.readState();
            this.query = normalizedString(remembered.query);
            this.selectedRef = remembered.selectedRef && this.nodeIndex[remembered.selectedRef]
                ? remembered.selectedRef
                : null;

            if (this.query !== '') {
                this.search();
            }

            if (!this.selectedRef && this.query === '') {
                const initialNode = this.nodes.find((node) => node.focused === true && this.isControl(node))
                    || this.nodes.find((node) => node.visible === true && this.isControl(node))
                    || this.nodes.find((node) => node.visible === true)
                    || this.nodes[0]
                    || null;

                if (initialNode) {
                    this.select(initialNode, false);
                }
            } else {
                this.revealAncestors(this.selectedRef);
                this.refreshSuggestions();
            }

            this.animateCursor();
            this.$nextTick(() => this.scrollSelectedIntoView('auto'));
        },

        readState() {
            if (!this.storageKey) {
                return {};
            }

            try {
                const stored = window.sessionStorage.getItem(this.storageKey);
                if (!stored) {
                    return {};
                }

                if (!stored.trim().startsWith('{')) {
                    return { selectedRef: stored };
                }

                const parsed = JSON.parse(stored);

                return parsed && typeof parsed === 'object' ? parsed : {};
            } catch {
                return {};
            }
        },

        persistState() {
            if (!this.storageKey) {
                return;
            }

            try {
                window.sessionStorage.setItem(this.storageKey, JSON.stringify({
                    query: this.query,
                    selectedRef: this.selectedRef,
                }));
            } catch {
                // Session storage can be unavailable in hardened browser modes.
            }
        },

        buildSearchFrames() {
            const frameGroups = new Map();
            this.searchElementByRef = {};

            for (const node of this.nodes) {
                const group = frameGroups.get(node.frameRef) || [];
                group.push(node);
                frameGroups.set(node.frameRef, group);
            }

            this.searchFrames = Array.from(frameGroups.entries()).map(([frameRef, frameNodes]) => {
                const documentNode = document.implementation.createHTMLDocument('');
                const elements = {};

                for (const node of [...frameNodes].sort((left, right) => left.depth - right.depth)) {
                    const isBody = node.tag === 'body';
                    const element = isBody ? documentNode.body : documentNode.createElement(node.tag);

                    element.setAttribute('data-workflow-node-ref', node.nodeRef);

                    if (node.id) {
                        element.id = normalizedString(node.id);
                    }
                    if (node.classes.length > 0) {
                        element.className = node.classes.join(' ');
                    }

                    const safeAttributes = {
                        ...(node.attributes || {}),
                        ...Object.fromEntries([
                            ['aria-label', node.ariaLabel],
                            ['href', node.href],
                            ['name', node.name],
                            ['placeholder', node.placeholder],
                            ['role', node.role],
                            ['title', node.title],
                            ['type', node.type],
                        ].filter(([, value]) => normalizedString(value) !== '')),
                    };

                    for (const [name, value] of Object.entries(safeAttributes)) {
                        if (/^(?:id|class|style|value|on|srcdoc|nonce)/i.test(name)) {
                            continue;
                        }

                        try {
                            element.setAttribute(name, normalizedString(value));
                        } catch {
                            // Ignore malformed legacy snapshot attribute names.
                        }
                    }

                    if (!isBody) {
                        const parent = elements[node.parentRef] || documentNode.body;
                        parent.appendChild(element);
                    }

                    element.appendChild(documentNode.createTextNode(
                        normalizedString(node.text || node.label || node.ariaLabel),
                    ));
                    elements[node.nodeRef] = element;
                    this.searchElementByRef[node.nodeRef] = element;
                }

                return { documentNode, elements, frameRef };
            });
        },

        querySelectorRefs(selector) {
            const refs = [];

            for (const frame of this.searchFrames) {
                const elements = frame.documentNode.querySelectorAll(selector);

                for (const element of elements) {
                    const ref = element.getAttribute('data-workflow-node-ref');
                    if (ref && this.nodeIndex[ref]) {
                        refs.push(ref);
                    }
                }
            }

            return [...new Set(refs)];
        },

        textMatchRefs(text, exact = false, candidateRefs = null) {
            const expected = normalizedString(text).toLocaleLowerCase('de');
            if (!expected) {
                return [];
            }

            const candidates = Array.isArray(candidateRefs)
                ? candidateRefs.map((ref) => this.nodeIndex[ref]).filter(Boolean)
                : this.nodes;

            return candidates
                .filter((node) => {
                    const descendantText = normalizedString(this.searchElementByRef[node.nodeRef]?.textContent)
                        .toLocaleLowerCase('de');
                    const actual = `${nodeSearchText(node)} ${descendantText}`.trim();

                    return exact ? actual === expected : actual.includes(expected);
                })
                .map((node) => node.nodeRef);
        },

        matchQuery(query) {
            const normalized = normalizedString(query);
            if (!normalized) {
                return { refs: [], error: '' };
            }

            const textFilter = selectorTextFilter(normalized);
            if (textFilter) {
                try {
                    const candidateRefs = this.querySelectorRefs(textFilter.css);

                    return {
                        refs: this.textMatchRefs(textFilter.text, textFilter.exact, candidateRefs),
                        error: '',
                    };
                } catch (error) {
                    return { refs: [], error: `Ungültiger CSS-Teil: ${error.message}` };
                }
            }

            try {
                const refs = this.querySelectorRefs(normalized);

                if (refs.length > 0 || selectorLooksStructured(normalized)) {
                    return { refs, error: '' };
                }

                return { refs: this.textMatchRefs(normalized), error: '' };
            } catch (error) {
                if (!selectorLooksStructured(normalized)) {
                    return { refs: this.textMatchRefs(normalized), error: '' };
                }

                return { refs: [], error: `Ungültiger Selektor: ${error.message}` };
            }
        },

        search(selectFirst = true) {
            const result = this.matchQuery(this.query);
            this.matchedRefs = result.refs;
            this.updateMatchIndex();
            this.searchError = result.error;

            if (
                selectFirst
                && this.matchedRefs.length > 0
                && !this.matchedRefs.includes(this.selectedRef)
            ) {
                this.select(this.nodeIndex[this.matchedRefs[0]]);
            } else if (
                selectFirst
                && this.query !== ''
                && !this.searchError
                && this.matchedRefs.length === 0
            ) {
                this.selectedRef = null;
                this.selectedSuggestions = [];
            }

            this.persistState();
        },

        updateMatchIndex() {
            this.matchedLookup = Object.fromEntries(this.matchedRefs.map((ref) => [ref, true]));
            this.matchOrder = Object.fromEntries(this.matchedRefs.map((ref, index) => [ref, index + 1]));
        },

        runQuickQuery(query) {
            this.query = query;
            this.search();
        },

        clearSearch() {
            this.query = '';
            this.matchedRefs = [];
            this.updateMatchIndex();
            this.searchError = '';
            this.persistState();
        },

        selectNextMatch(direction = 1) {
            if (this.matchedRefs.length === 0) {
                return;
            }

            const currentIndex = this.matchedRefs.indexOf(this.selectedRef);
            const baseIndex = currentIndex >= 0 ? currentIndex : (direction > 0 ? -1 : 0);
            const nextIndex = (baseIndex + direction + this.matchedRefs.length) % this.matchedRefs.length;

            this.select(this.nodeIndex[this.matchedRefs[nextIndex]]);
        },

        visibleNodes() {
            if (this.query !== '') {
                const visibleRefs = new Set(this.matchedRefs);

                for (const ref of this.matchedRefs) {
                    let parentRef = this.nodeIndex[ref]?.parentRef || null;
                    let guard = 0;

                    while (parentRef && guard < 80) {
                        visibleRefs.add(parentRef);
                        parentRef = this.nodeIndex[parentRef]?.parentRef || null;
                        guard += 1;
                    }
                }

                return this.nodes.filter((node) => visibleRefs.has(node.nodeRef));
            }

            return this.nodes.filter((node) => {
                let parentRef = node.parentRef;
                let guard = 0;

                while (parentRef && guard < 80) {
                    if (this.collapsed[parentRef]) {
                        return false;
                    }

                    parentRef = this.nodeIndex[parentRef]?.parentRef || null;
                    guard += 1;
                }

                return true;
            });
        },

        hasChildren(node) {
            return (this.childrenByParent[node.nodeRef] || []).length > 0;
        },

        toggle(node) {
            this.collapsed[node.nodeRef] = !this.collapsed[node.nodeRef];
        },

        revealAncestors(ref) {
            let parentRef = this.nodeIndex[ref]?.parentRef || null;
            let guard = 0;

            while (parentRef && guard < 80) {
                this.collapsed[parentRef] = false;
                parentRef = this.nodeIndex[parentRef]?.parentRef || null;
                guard += 1;
            }
        },

        select(node, shouldScroll = true) {
            if (!node?.nodeRef) {
                return;
            }

            this.selectedRef = node.nodeRef;
            this.selectionNotice = '';
            this.revealAncestors(node.nodeRef);
            this.refreshSuggestions();
            this.persistState();

            if (shouldScroll) {
                this.$nextTick(() => this.scrollSelectedIntoView());
            }
        },

        scrollSelectedIntoView(behavior = 'smooth') {
            const row = Array.from(this.$root.querySelectorAll('[data-workflow-dom-row]'))
                .find((candidate) => candidate.dataset.nodeRef === this.selectedRef);

            row?.scrollIntoView({ block: 'center', inline: 'nearest', behavior });
        },

        selectedNode() {
            return this.selectedRef ? (this.nodeIndex[this.selectedRef] || null) : null;
        },

        isControl(node) {
            if (!node) {
                return false;
            }

            const type = normalizedString(node.type).toLowerCase();
            const role = normalizedString(node.role).toLowerCase();

            return node.actionable === true
                || node.editable === true
                || (ACTIONABLE_TAGS.has(node.tag) && !(node.tag === 'input' && type === 'hidden'))
                || ['button', 'link', 'textbox', 'checkbox', 'radio', 'switch', 'combobox'].includes(role);
        },

        selectedNodeActionable() {
            const node = this.selectedNode();

            return Boolean(
                this.interactive
                && this.selectedSuggestions.length > 0
                && node?.frameRef === 'main'
                && node?.inShadowDom !== true
            );
        },

        selectedNodeProbeable() {
            return this.selectedNodeActionable() && this.canProbe;
        },

        nodeLabel(node) {
            if (!node) {
                return '<element>';
            }

            const id = node.id ? `#${node.id}` : '';
            const classes = Array.isArray(node.classes) && node.classes.length
                ? `.${node.classes.slice(0, 3).join('.')}`
                : '';

            return `<${node.tag || 'element'}${id}${classes}>`;
        },

        nodeSummary(node) {
            return normalizedString(
                node?.text
                || node?.label
                || node?.ariaLabel
                || node?.placeholder
                || node?.role,
            );
        },

        isMatched(node) {
            return this.matchedLookup[node?.nodeRef] === true;
        },

        matchNumber(node) {
            return this.matchOrder[node?.nodeRef] || '';
        },

        overlayNodes() {
            const viewportWidth = Number(this.viewport?.width || 0);
            const viewportHeight = Number(this.viewport?.height || 0);

            return this.matchedRefs
                .filter((ref) => ref !== this.selectedRef)
                .map((ref) => this.nodeIndex[ref])
                .filter((node) => (
                    node?.visible === true
                    && node.rect?.width > 0
                    && node.rect?.height > 0
                    && node.rect.x < viewportWidth
                    && node.rect.y < viewportHeight
                    && node.rect.x + node.rect.width > 0
                    && node.rect.y + node.rect.height > 0
                ))
                .slice(0, 250);
        },

        overlayStyle(rect, viewport = null) {
            const sourceViewport = viewport || this.viewport || {};
            const width = Number(sourceViewport.width || 0);
            const height = Number(sourceViewport.height || 0);

            if (!rect || width <= 0 || height <= 0) {
                return 'display:none';
            }

            const sourceLeft = Number(rect.x || 0);
            const sourceTop = Number(rect.y || 0);
            const sourceRight = sourceLeft + Number(rect.width || 0);
            const sourceBottom = sourceTop + Number(rect.height || 0);
            const left = Math.max(0, Math.min(width, sourceLeft));
            const top = Math.max(0, Math.min(height, sourceTop));
            const right = Math.max(left, Math.min(width, sourceRight));
            const bottom = Math.max(top, Math.min(height, sourceBottom));

            if (right <= left || bottom <= top) {
                return 'display:none';
            }

            return [
                `left:${(left / width) * 100}%`,
                `top:${(top / height) * 100}%`,
                `width:${((right - left) / width) * 100}%`,
                `height:${((bottom - top) / height) * 100}%`,
            ].join(';');
        },

        selectFromScreenshot(event) {
            const image = this.$refs.image;
            const viewportWidth = Number(this.viewport?.width || 0);
            const viewportHeight = Number(this.viewport?.height || 0);

            if (!image || viewportWidth <= 0 || viewportHeight <= 0) {
                return;
            }

            const imageRect = image.getBoundingClientRect();
            const x = ((event.clientX - imageRect.left) / imageRect.width) * viewportWidth;
            const y = ((event.clientY - imageRect.top) / imageRect.height) * viewportHeight;
            const candidates = this.nodes
                .filter((node) => {
                    const rect = node.rect || {};

                    return node.visible === true
                        && rect.width > 0
                        && rect.height > 0
                        && x >= rect.x
                        && x <= rect.x + rect.width
                        && y >= rect.y
                        && y <= rect.y + rect.height;
                })
                .sort((left, right) => (
                    Number(right.depth || 0) - Number(left.depth || 0)
                    || Number(right.actionable === true) - Number(left.actionable === true)
                    || (left.rect.width * left.rect.height) - (right.rect.width * right.rect.height)
                ));

            if (candidates[0]) {
                this.select(candidates[0]);
                return;
            }

            this.selectionNotice = 'An dieser Stelle ist im Snapshot kein sichtbarer DOM-Knoten erfasst.';
        },

        candidateScore(selector, fallback = 0) {
            if (/\[(?:data-testid|data-test|data-cy|data-qa)=/i.test(selector)) {
                return 100;
            }
            if (/\[(?:aria-label|role)=/i.test(selector)) {
                return 92;
            }
            if (/\[name=/i.test(selector)) {
                return 88;
            }
            if (/\[(?:placeholder|title)=/i.test(selector)) {
                return 80;
            }
            if (/^#[^\s>+~]+$/.test(selector)) {
                return /(?:^|[-_])(?:ember|react|vue|radix|headlessui)[-_]|\d{5,}|[a-f0-9]{12,}/i.test(selector)
                    ? 35
                    : 76;
            }
            if (/^[a-z][a-z0-9-]*(?:\.[a-zA-Z0-9_-]+)+$/.test(selector)) {
                return 55;
            }
            if (selector.includes('>')) {
                return 20;
            }

            return Number(fallback || 40);
        },

        structuralSelector(node) {
            if (!node) {
                return '';
            }

            const segments = [];
            let current = node;
            let guard = 0;

            while (current && guard < 32) {
                const tag = normalizedTag(current.tag);
                let segment = tag;

                if (current.parentRef) {
                    const sameTagSiblings = this.nodes.filter((candidate) => (
                        candidate.parentRef === current.parentRef
                        && normalizedTag(candidate.tag) === tag
                    ));

                    if (sameTagSiblings.length > 1) {
                        const position = sameTagSiblings.findIndex((candidate) => candidate.nodeRef === current.nodeRef);
                        if (position >= 0) {
                            segment += `:nth-of-type(${position + 1})`;
                        }
                    }
                }

                segments.unshift(segment);

                if (tag === 'body' || !current.parentRef) {
                    break;
                }

                current = this.nodeIndex[current.parentRef] || null;
                guard += 1;
            }

            return segments.join(' > ');
        },

        buildSelectorSuggestions(node) {
            if (!node) {
                return [];
            }

            const candidates = [];
            const seen = new Set();
            const tag = node.tag || '*';
            const add = (selector, kind = 'css', score = 0) => {
                const normalized = normalizedString(selector);
                if (!normalized || seen.has(normalized)) {
                    return;
                }

                seen.add(normalized);
                candidates.push({ selector: normalized, kind, score: this.candidateScore(normalized, score) });
            };

            for (const [attribute, score] of SEMANTIC_ATTRIBUTES) {
                const value = normalizedString(node.attributes?.[attribute] ?? node[attribute.replace(/-([a-z])/g, (_, character) => character.toUpperCase())]);
                if (value) {
                    add(`${tag}[${attribute}="${cssAttributeValue(value)}"]`, 'attribute', score);
                }
            }

            if (node.role && node.ariaLabel) {
                add(
                    `[role="${cssAttributeValue(node.role)}"][aria-label="${cssAttributeValue(node.ariaLabel)}"]`,
                    'aria',
                    94,
                );
            } else if (node.role) {
                add(`[role="${cssAttributeValue(node.role)}"]`, 'role', 74);
            }

            for (const candidate of Array.isArray(node.selectorCandidates) ? node.selectorCandidates : []) {
                add(candidate?.selector, candidate?.kind || 'css', candidate?.score || 0);
            }

            if (node.id) {
                add(`#${cssIdentifier(node.id)}`, 'id', 76);
            }
            if (node.classes.length > 0) {
                add(`${tag}${node.classes.slice(0, 2).map((className) => `.${cssIdentifier(className)}`).join('')}`, 'class', 55);
            }
            add(node.selector, 'snapshot', 35);
            add(this.structuralSelector(node), 'path', 20);

            return candidates
                .map((candidate) => {
                    let count = null;

                    if (!candidate.selector.startsWith('text=')) {
                        try {
                            count = this.querySelectorRefs(candidate.selector).length;
                        } catch {
                            count = null;
                        }
                    }

                    return {
                        ...candidate,
                        count,
                        unique: count === 1,
                    };
                })
                .sort((left, right) => (
                    right.score - left.score
                    || Number(right.unique) - Number(left.unique)
                    || Number(left.count ?? Number.MAX_SAFE_INTEGER) - Number(right.count ?? Number.MAX_SAFE_INTEGER)
                ))
                .slice(0, 8);
        },

        refreshSuggestions() {
            this.selectedSuggestions = this.buildSelectorSuggestions(this.selectedNode());
        },

        useSelector(selector) {
            if (!this.selectedNodeProbeable()) {
                return;
            }

            this.$dispatch('workflow-dom-node-selected', {
                browserWindow: this.windowKey,
                selector,
            });
        },

        async copySelector(selector) {
            const normalized = normalizedString(selector);
            if (!normalized) {
                return;
            }

            let copied = false;

            try {
                await navigator.clipboard.writeText(normalized);
                copied = true;
            } catch {
                const textarea = document.createElement('textarea');
                textarea.value = normalized;
                textarea.style.position = 'fixed';
                textarea.style.opacity = '0';
                document.body.appendChild(textarea);
                textarea.select();
                copied = document.execCommand('copy') === true;
                textarea.remove();
            }

            if (!copied) {
                this.selectionNotice = 'Der Selektor konnte nicht in die Zwischenablage kopiert werden.';

                return;
            }

            this.copiedSelector = normalized;
            window.setTimeout(() => {
                if (this.copiedSelector === normalized) {
                    this.copiedSelector = '';
                }
            }, 1600);
        },

        animateCursor() {
            if (!this.cursor) {
                return;
            }

            this.cursorPoint = {
                x: Number(this.cursor.fromX || 0),
                y: Number(this.cursor.fromY || 0),
            };
            this.$nextTick(() => {
                window.requestAnimationFrame(() => {
                    this.cursorPoint = {
                        x: Number(this.cursor.toX || 0),
                        y: Number(this.cursor.toY || 0),
                    };
                    this.cursorClicked = this.cursor.clicked === true;
                });
            });
        },

        cursorStyle() {
            const viewport = this.cursor?.viewport || this.viewport || {};
            const width = Number(viewport.width || 0);
            const height = Number(viewport.height || 0);

            if (!this.cursorPoint || width <= 0 || height <= 0) {
                return 'display:none';
            }

            const x = Math.max(0, Math.min(width, Number(this.cursorPoint.x || 0)));
            const y = Math.max(0, Math.min(height, Number(this.cursorPoint.y || 0)));

            return `left:${(x / width) * 100}%;top:${(y / height) * 100}%`;
        },
    };
}
