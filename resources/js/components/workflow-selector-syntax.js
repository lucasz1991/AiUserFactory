const HTML_TAG_NAMES = new Set([
    'a', 'article', 'aside', 'button', 'details', 'dialog', 'div', 'fieldset', 'footer',
    'form', 'h1', 'h2', 'h3', 'h4', 'h5', 'h6', 'header', 'iframe', 'img', 'input',
    'label', 'legend', 'li', 'main', 'nav', 'option', 'p', 'section', 'select', 'slot',
    'span', 'summary', 'table', 'tbody', 'td', 'textarea', 'tfoot', 'th', 'thead', 'tr',
    'ul', 'video', 'svg', 'path', 'g', 'rect', 'circle', 'use', 'webmailer-mail-list',
]);

export const SELECTOR_MODES = Object.freeze({
    elementCandidates: 'element_candidates',
    cssSelector: 'css_selector',
    fieldDefinitions: 'selector_field_definitions',
    fallbackMap: 'selector_fallback_map',
    actionSteps: 'selector_action_steps',
    variablePath: 'variable_path',
});

export function splitTopLevelSelectorList(value, { allowUnclosedQuote = false } = {}) {
    const input = String(value ?? '').trim();

    if (input === '') {
        return { entries: [], error: null };
    }

    const entries = [];
    let current = '';
    let quote = '';
    let escaped = false;
    let parentheses = 0;
    let brackets = 0;

    for (const character of input) {
        if (escaped) {
            current += character;
            escaped = false;
            continue;
        }

        if (character === '\\') {
            current += character;
            escaped = true;
            continue;
        }

        if (quote !== '') {
            current += character;

            if (character === quote) {
                quote = '';
            }

            continue;
        }

        if (character === '"' || character === "'") {
            current += character;
            quote = character;
            continue;
        }

        if (character === '(') {
            parentheses += 1;
        } else if (character === ')') {
            if (parentheses === 0) {
                return { entries: [], error: 'Eine schließende runde Klammer besitzt keine öffnende Klammer.' };
            }

            parentheses -= 1;
        } else if (character === '[') {
            brackets += 1;
        } else if (character === ']') {
            if (brackets === 0) {
                return { entries: [], error: 'Eine schließende eckige Klammer besitzt keine öffnende Klammer.' };
            }

            brackets -= 1;
        }

        if (character === ',' && parentheses === 0 && brackets === 0) {
            if (current.trim() === '') {
                return { entries: [], error: 'Zwischen zwei Kommas fehlt ein Selector oder Textziel.' };
            }

            entries.push(current.trim());
            current = '';
            continue;
        }

        current += character;
    }

    if (escaped) {
        return { entries: [], error: 'Ein Escape-Zeichen am Ende ist unvollständig.' };
    }

    if (quote !== '' && !allowUnclosedQuote) {
        return { entries: [], error: 'Ein Textwert besitzt kein schließendes Anführungszeichen.' };
    }

    if (parentheses > 0) {
        return { entries: [], error: 'Eine runde Klammer wurde nicht geschlossen.' };
    }

    if (brackets > 0) {
        return { entries: [], error: 'Eine eckige Klammer wurde nicht geschlossen.' };
    }

    if (current.trim() === '') {
        return { entries: [], error: 'Nach dem letzten Komma fehlt ein Selector oder Textziel.' };
    }

    entries.push(current.trim());

    return { entries, error: null };
}

function looksLikeCssSelector(value) {
    const candidate = String(value ?? '').trim();

    if (/^(css|selector)\s*=/i.test(candidate)) {
        return true;
    }

    if (/^[#.\[*:>+~]/.test(candidate) || /[\[\]#>:~+]/.test(candidate)) {
        return true;
    }

    const firstToken = candidate.match(/^([a-z][a-z0-9-]*)/i)?.[1]?.toLowerCase() || '';

    return HTML_TAG_NAMES.has(firstToken);
}

function querySelectorSyntaxError(selector) {
    if (!globalThis.document?.createDocumentFragment) {
        return null;
    }

    try {
        globalThis.document.createDocumentFragment().querySelector(selector);
        return null;
    } catch (error) {
        return String(error?.message || 'Ungültiger CSS-Selector.');
    }
}

function containsTopLevelToken(value, tokens) {
    let quote = '';
    let escaped = false;
    let parentheses = 0;
    let brackets = 0;

    for (const character of String(value ?? '')) {
        if (escaped) {
            escaped = false;
            continue;
        }

        if (character === '\\') {
            escaped = true;
            continue;
        }

        if (quote !== '') {
            if (character === quote) {
                quote = '';
            }

            continue;
        }

        if (character === '"' || character === "'") {
            quote = character;
            continue;
        }

        if (character === '(') {
            parentheses += 1;
            continue;
        }

        if (character === ')') {
            parentheses = Math.max(0, parentheses - 1);
            continue;
        }

        if (character === '[') {
            brackets += 1;
            continue;
        }

        if (character === ']') {
            brackets = Math.max(0, brackets - 1);
            continue;
        }

        if (parentheses === 0 && brackets === 0 && tokens.includes(character)) {
            return true;
        }
    }

    return false;
}

function validateNativeCssShape(value) {
    const selector = String(value ?? '').trim();

    if (selector === '') {
        return 'Der CSS-Selector ist leer.';
    }

    if (/^[>+~]|[>+~]\s*$/.test(selector)) {
        return 'Der CSS-Selector besitzt einen unvollständigen Kombinator.';
    }

    if (/(?:^|[\s>+~,])(?:[.#])(?:$|[\s>+~,])/.test(selector)) {
        return 'Nach Punkt oder Raute fehlt ein Klassen- bzw. ID-Name.';
    }

    if (/\[\s*\]/.test(selector)) {
        return 'Ein Attribut-Selector darf nicht leer sein.';
    }

    if (/:\s*$/.test(selector)) {
        return 'Nach dem Doppelpunkt fehlt eine CSS-Pseudoklasse.';
    }

    if (containsTopLevelToken(selector, ['{', '}', ';', '='])) {
        return 'Der CSS-Selector enthält ein ungültiges Zeichen außerhalb eines Attributs oder Textwerts.';
    }

    const browserError = querySelectorSyntaxError(selector);

    return browserError ? `Chromium meldet ungültiges CSS: ${browserError}` : null;
}

function validateCssSelector(value) {
    const selector = String(value ?? '').trim();

    if (/^(?:text|has-text|text-is|css|selector)\s*=/i.test(selector)) {
        return 'Dieses Feld erwartet reines CSS. Präfixe wie text= oder css= sind hier nicht erlaubt.';
    }

    if (/:(?:has-text|text-is)\s*\(/i.test(selector)) {
        return 'Dieses Feld erwartet reines CSS. :has-text() und :text-is() sind hier nicht erlaubt.';
    }

    const balanced = splitTopLevelSelectorList(selector);

    return balanced.error || validateNativeCssShape(selector);
}

function validateExtendedOrNativeCss(value) {
    const selector = String(value ?? '').trim();
    const nested = selector.match(/^(.*?):has\(\s*(.*?):(has-text|text-is)\(\s*(["'])(.*?)\4\s*\)\s*\)$/i);

    if (nested) {
        return validateCssSelector(nested[1].trim() || '*')
            || validateCssSelector(nested[2].trim() || '*');
    }

    const simple = selector.match(/^(.*?):(has-text|text-is)\(\s*(["'])(.*?)\3\s*\)$/i);

    if (simple) {
        return validateCssSelector(simple[1].trim() || '*');
    }

    if (/:(?:has-text|text-is)\s*\(/i.test(selector)) {
        return 'Text-Pseudos müssen am Ende stehen und Text in Anführungszeichen enthalten, z. B. button:has-text("Weiter").';
    }

    return validateCssSelector(selector);
}

function validateElementCandidates(value) {
    // Keep parity with normalizeElementCandidates(): an unmatched quote stays
    // part of a plain text candidate, e.g. text=What's new. Any CSS candidate
    // is validated strictly after it has been classified.
    const { entries, error } = splitTopLevelSelectorList(value, { allowUnclosedQuote: true });

    if (error) {
        return error;
    }

    if (entries.length === 0) {
        return 'Bitte mindestens einen Selector oder Text angeben.';
    }

    for (const candidate of entries) {
        const explicitText = candidate.match(/^(text|has-text|text-is)\s*=\s*(.*)$/i);

        if (explicitText) {
            if (explicitText[2].trim().replace(/^("|')|("|')$/g, '').trim() === '') {
                return `Nach ${explicitText[1]}= fehlt der Suchtext.`;
            }

            continue;
        }

        const explicitSelector = candidate.match(/^(css|selector)\s*=\s*(.*)$/i);

        if (explicitSelector) {
            if (explicitSelector[2].trim() === '') {
                return `Nach ${explicitSelector[1]}= fehlt der CSS-Selector.`;
            }

            const selectorError = validateExtendedOrNativeCss(explicitSelector[2]);

            if (selectorError) {
                return selectorError;
            }

            continue;
        }

        if (looksLikeCssSelector(candidate)) {
            const selectorError = validateExtendedOrNativeCss(candidate);

            if (selectorError) {
                return selectorError;
            }
        }
    }

    return null;
}

function validateVariablePath(value) {
    if (String(value).length > 120) {
        return 'Der Variablenname darf höchstens 120 Zeichen lang sein.';
    }

    return /^[A-Za-z0-9_.-]+$/.test(value)
        ? null
        : 'Der Variablenname darf nur Buchstaben, Zahlen, Punkt, Unterstrich und Bindestrich enthalten.';
}

function parseJson(value, label) {
    try {
        return { value: JSON.parse(value), error: null };
    } catch (error) {
        return { value: null, error: `${label} sind kein gültiges JSON: ${error.message}` };
    }
}

function validateFieldDefinitions(value) {
    const parsed = parseJson(value, 'Die Felddefinitionen');

    if (parsed.error) {
        return parsed.error;
    }

    if (!Array.isArray(parsed.value) || parsed.value.length === 0) {
        return 'Die Felddefinitionen müssen ein nichtleeres JSON-Array sein.';
    }

    const allowedTypes = ['text', 'inner_text', 'href', 'html', 'attribute', 'exists'];
    const names = new Set();

    for (const [index, field] of parsed.value.entries()) {
        const position = index + 1;

        if (!field || Array.isArray(field) || typeof field !== 'object') {
            return `Feld ${position} muss ein JSON-Objekt sein.`;
        }

        const name = String(field.name ?? '').trim();

        if (!name) {
            return `Feld ${position} benötigt einen Namen.`;
        }

        if (names.has(name)) {
            return `Der Feldname "${name}" ist doppelt vorhanden.`;
        }

        names.add(name);
        const type = String(field.type ?? 'text').trim().toLowerCase();

        if (!allowedTypes.includes(type)) {
            return `Feld ${position} verwendet den unbekannten Typ "${type}".`;
        }

        if (field.selector !== undefined && field.selector !== null && typeof field.selector !== 'string') {
            return `Feld ${position}: selector muss Text sein.`;
        }

        if (String(field.selector ?? '').trim()) {
            const selectorError = validateCssSelector(field.selector);

            if (selectorError) {
                return `Feld ${position}: ${selectorError}`;
            }
        }

        let fallbacks = field.fallback_selectors ?? field.fallbackSelectors ?? [];

        if (typeof fallbacks === 'string') {
            try {
                const decoded = JSON.parse(fallbacks);
                fallbacks = Array.isArray(decoded) ? decoded : fallbacks.split(/\r?\n|\|\|/);
            } catch {
                fallbacks = fallbacks.split(/\r?\n|\|\|/);
            }
        }

        if (!Array.isArray(fallbacks)) {
            return `Feld ${position}: fallback_selectors muss eine String-Liste sein.`;
        }

        for (const fallback of fallbacks) {
            if (typeof fallback !== 'string') {
                return `Feld ${position}: Jeder Fallback-Selector muss Text sein.`;
            }

            if (fallback.trim()) {
                const fallbackError = validateCssSelector(fallback);

                if (fallbackError) {
                    return `Feld ${position}: ${fallbackError}`;
                }
            }
        }
    }

    return null;
}

function validateFallbackMap(value) {
    const parsed = parseJson(value, 'Die Fallbacks');

    if (parsed.error) {
        return parsed.error;
    }

    if (!parsed.value || Array.isArray(parsed.value) || typeof parsed.value !== 'object') {
        return 'Fallbacks müssen ein JSON-Objekt sein.';
    }

    const allowedKeys = ['title', 'link', 'description', 'site_name', 'breadcrumb'];

    for (const [key, configuredSelectors] of Object.entries(parsed.value)) {
        if (!allowedKeys.includes(key)) {
            return `Der Fallback-Schlüssel "${key}" ist nicht erlaubt.`;
        }

        const selectors = typeof configuredSelectors === 'string'
            ? configuredSelectors.split(/\r?\n|\|\|/)
            : configuredSelectors;

        if (!Array.isArray(selectors)) {
            return `Fallback "${key}" muss eine String-Liste sein.`;
        }

        for (const selector of selectors) {
            if (typeof selector !== 'string' || selector.trim() === '') {
                return `Fallback "${key}" enthält einen leeren oder ungültigen Selector.`;
            }

            const selectorError = validateCssSelector(selector);

            if (selectorError) {
                return `Fallback "${key}": ${selectorError}`;
            }
        }
    }

    return null;
}

function validateActionSteps(value) {
    let steps;

    try {
        steps = JSON.parse(String(value).replace(/,\s*([}\]])/g, '$1'));
    } catch {
        steps = String(value).split(/\r?\n|;/)
            .map((selector) => selector.trim())
            .filter(Boolean)
            .map((selector) => ({ selector }));
    }

    if (!Array.isArray(steps) || steps.length === 0) {
        return 'Aktionsschritte müssen ein JSON-Array oder eine Liste mit einem Selector pro Zeile sein.';
    }

    for (const [index, configuredStep] of steps.entries()) {
        const position = index + 1;
        const step = typeof configuredStep === 'string' ? { selector: configuredStep } : configuredStep;

        if (!step || Array.isArray(step) || typeof step !== 'object') {
            return `Aktionsschritt ${position} muss ein Selector oder JSON-Objekt sein.`;
        }

        const configuredSelector = step.selector ?? step.text ?? step.value ?? '';

        if (configuredSelector !== null && !['string', 'number', 'boolean'].includes(typeof configuredSelector)) {
            return `Aktionsschritt ${position}: Selector oder Text muss eine Zeichenfolge sein.`;
        }

        const selector = String(configuredSelector).trim();

        if (!selector) {
            return `Aktionsschritt ${position} benötigt einen Selector oder Text.`;
        }

        const selectorError = validateElementCandidates(selector);

        if (selectorError) {
            return `Aktionsschritt ${position}: ${selectorError}`;
        }

        for (const numberField of ['wait_ms', 'timeout_ms']) {
            if (Object.hasOwn(step, numberField)
                && (!Number.isFinite(Number(step[numberField])) || Number(step[numberField]) < 0)) {
                return `Aktionsschritt ${position}: ${numberField} muss eine nichtnegative Zahl sein.`;
            }
        }

        if (Object.hasOwn(step, 'required') && typeof step.required !== 'boolean') {
            return `Aktionsschritt ${position}: required muss true oder false sein.`;
        }
    }

    return null;
}

export function validateWorkflowSelector(value, mode) {
    const input = String(value ?? '').trim();

    if (input === '') {
        return { state: 'empty', message: 'Noch keine Eingabe.' };
    }

    if (/[\u0000-\u0008\u000B\u000C\u000E-\u001F\u007F]/.test(input)) {
        return { state: 'invalid', message: 'Die Eingabe enthält ein ungültiges Steuerzeichen.' };
    }

    const error = {
        [SELECTOR_MODES.elementCandidates]: validateElementCandidates,
        [SELECTOR_MODES.cssSelector]: validateCssSelector,
        [SELECTOR_MODES.fieldDefinitions]: validateFieldDefinitions,
        [SELECTOR_MODES.fallbackMap]: validateFallbackMap,
        [SELECTOR_MODES.actionSteps]: validateActionSteps,
        [SELECTOR_MODES.variablePath]: validateVariablePath,
    }[mode]?.(input) ?? null;

    if (error) {
        return { state: 'invalid', message: error };
    }

    const validMessage = {
        [SELECTOR_MODES.elementCandidates]: 'Syntax gültig – der Resolver kann dieses Ziel verarbeiten.',
        [SELECTOR_MODES.cssSelector]: 'Gültiger CSS-Selector für Chromium.',
        [SELECTOR_MODES.fieldDefinitions]: 'Felddefinitionen und enthaltene CSS-Selectoren sind gültig.',
        [SELECTOR_MODES.fallbackMap]: 'Fallback-JSON und enthaltene CSS-Selectoren sind gültig.',
        [SELECTOR_MODES.actionSteps]: 'Aktionsschritte und enthaltene Ziele sind gültig.',
        [SELECTOR_MODES.variablePath]: 'Gültiger Workflow-Variablenname.',
    }[mode] || 'Syntax gültig.';

    return { state: 'valid', message: validMessage };
}

export function workflowSelectorField(config = {}) {
    return {
        helpOpen: false,
        message: '',
        mode: String(config.mode || SELECTOR_MODES.cssSelector),
        required: config.required === true,
        serverError: String(config.serverError || ''),
        state: 'empty',
        touched: false,

        check(value, markTouched = false) {
            if (markTouched) {
                this.touched = true;
                this.serverError = '';
            }

            if (this.serverError) {
                this.state = 'invalid';
                this.message = this.serverError;
                return;
            }

            const result = validateWorkflowSelector(value, this.mode);
            this.state = result.state;
            this.message = result.message;

            if (result.state === 'empty') {
                this.message = this.required
                    ? 'Pflichtfeld – bitte einen Wert angeben.'
                    : 'Optional – leer verwendet die Fallbacks des Tasks.';

                if (this.required && this.touched) {
                    this.state = 'invalid';
                }
            }
        },

        closeHelp() {
            this.helpOpen = false;
        },

        toggleHelp() {
            this.helpOpen = !this.helpOpen;
        },
    };
}
