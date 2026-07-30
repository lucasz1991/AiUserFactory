<?php

namespace App\Services\Workflows;

use JsonException;

class WorkflowSelectorSyntaxService
{
    public const MODE_ELEMENT_CANDIDATES = 'element_candidates';

    public const MODE_CSS_SELECTOR = 'css_selector';

    public const MODE_SELECTOR_FIELD_DEFINITIONS = 'selector_field_definitions';

    public const MODE_SELECTOR_FALLBACK_MAP = 'selector_fallback_map';

    public const MODE_SELECTOR_ACTION_STEPS = 'selector_action_steps';

    public const MODE_VARIABLE_PATH = 'variable_path';

    /**
     * The field names intentionally mirror the payload keys read by the Node
     * task scripts. A field is absent when it is not a selector contract.
     *
     * @var array<string, array<string, string>>
     */
    private const FIELD_MODES = [
        'mail.inbox_list_scan' => [
            'selector' => self::MODE_CSS_SELECTOR,
            'value' => self::MODE_CSS_SELECTOR,
            'subject_selector' => self::MODE_CSS_SELECTOR,
            'title_selector' => self::MODE_CSS_SELECTOR,
            'search_input_selector' => self::MODE_ELEMENT_CANDIDATES,
            'search_button_selector' => self::MODE_ELEMENT_CANDIDATES,
            'date_selector' => self::MODE_CSS_SELECTOR,
        ],
        'mail.list_search_loop' => [
            'selector' => self::MODE_CSS_SELECTOR,
            'subject_selector' => self::MODE_CSS_SELECTOR,
            'title_selector' => self::MODE_CSS_SELECTOR,
        ],
        'mail.list_action_loop' => [
            'selector' => self::MODE_ELEMENT_CANDIDATES,
            'open_selector' => self::MODE_CSS_SELECTOR,
            'confirm_selector' => self::MODE_ELEMENT_CANDIDATES,
            'action_steps' => self::MODE_SELECTOR_ACTION_STEPS,
            'return_selector' => self::MODE_ELEMENT_CANDIDATES,
        ],
        'mail.extract_value' => [
            'selector' => self::MODE_CSS_SELECTOR,
        ],
        'browser.scroll' => [
            'selector' => self::MODE_CSS_SELECTOR,
            'until_selector' => self::MODE_CSS_SELECTOR,
        ],
        'browser.read_element_fields' => [
            'fields' => self::MODE_SELECTOR_FIELD_DEFINITIONS,
        ],
        'browser.read_searchengine_result' => [
            'list_container_selector' => self::MODE_CSS_SELECTOR,
            'list_item_selector' => self::MODE_CSS_SELECTOR,
            'title_selector' => self::MODE_CSS_SELECTOR,
            'link_selector' => self::MODE_CSS_SELECTOR,
            'description_selector' => self::MODE_CSS_SELECTOR,
            'site_name_selector' => self::MODE_CSS_SELECTOR,
            'breadcrumb_selector' => self::MODE_CSS_SELECTOR,
            'fallbacks' => self::MODE_SELECTOR_FALLBACK_MAP,
            'exclude_item_selector' => self::MODE_CSS_SELECTOR,
        ],
        'browser.find_element' => ['selector' => self::MODE_ELEMENT_CANDIDATES],
        'browser.hover' => ['selector' => self::MODE_ELEMENT_CANDIDATES],
        'decision.element_exists' => ['selector' => self::MODE_ELEMENT_CANDIDATES],
        'browser.click' => ['selector' => self::MODE_ELEMENT_CANDIDATES],
        'browser.highlight' => ['selector' => self::MODE_ELEMENT_CANDIDATES],
        'input.fill_field' => ['selector' => self::MODE_ELEMENT_CANDIDATES],
        'input.submit' => ['selector' => self::MODE_ELEMENT_CANDIDATES],
        'wait.selector' => ['selector' => self::MODE_ELEMENT_CANDIDATES],
        'mail.generate_address' => ['selector' => self::MODE_ELEMENT_CANDIDATES],
        'mail.fill_address' => ['selector' => self::MODE_ELEMENT_CANDIDATES],
        'mail.check_address_availability' => ['selector' => self::MODE_ELEMENT_CANDIDATES],
        'mail.generate_password' => ['selector' => self::MODE_ELEMENT_CANDIDATES],
        'data.workflow_return' => ['selector' => self::MODE_VARIABLE_PATH],
    ];

    /**
     * @return array<string, array<string, string>>
     */
    public function fieldModes(): array
    {
        return self::FIELD_MODES;
    }

    public function modeFor(string $taskKey, string $fieldName): ?string
    {
        return self::FIELD_MODES[$taskKey][$fieldName] ?? null;
    }

    public function errorFor(string $taskKey, string $fieldName, mixed $value): ?string
    {
        $mode = $this->modeFor($taskKey, $fieldName);

        if ($mode === null) {
            return null;
        }

        return $this->validate((string) $value, $mode);
    }

    public function validate(string $value, string $mode): ?string
    {
        $value = trim($value);

        if ($value === '') {
            return null;
        }

        if (preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', $value) === 1) {
            return 'Die Eingabe enthaelt ein ungueltiges Steuerzeichen.';
        }

        return match ($mode) {
            self::MODE_ELEMENT_CANDIDATES => $this->validateElementCandidates($value),
            self::MODE_CSS_SELECTOR => $this->validateCssSelector($value),
            self::MODE_SELECTOR_FIELD_DEFINITIONS => $this->validateFieldDefinitions($value),
            self::MODE_SELECTOR_FALLBACK_MAP => $this->validateFallbackMap($value),
            self::MODE_SELECTOR_ACTION_STEPS => $this->validateActionSteps($value),
            self::MODE_VARIABLE_PATH => $this->validateVariablePath($value),
            default => null,
        };
    }

    protected function validateElementCandidates(string $value): ?string
    {
        // The Node resolver keeps an unmatched quote as ordinary text instead
        // of failing. This matters for unquoted contractions such as
        // text=What's new. CSS candidates still pass through the strict CSS
        // validation below.
        [$candidates, $splitError] = $this->splitTopLevelSelectorList($value, true);

        if ($splitError !== null) {
            return $splitError;
        }

        if ($candidates === []) {
            return 'Bitte mindestens einen Selector oder Text angeben.';
        }

        foreach ($candidates as $candidate) {
            $candidate = trim($candidate);
            $textMatch = [];
            $selectorMatch = [];

            if (preg_match('/^(text|has-text|text-is)\s*=\s*(.*)$/iu', $candidate, $textMatch) === 1) {
                if (trim((string) ($textMatch[2] ?? ''), " \t\n\r\0\x0B\"'") === '') {
                    return 'Nach '.$textMatch[1].'= fehlt der Suchtext.';
                }

                continue;
            }

            if (preg_match('/^(css|selector)\s*=\s*(.*)$/iu', $candidate, $selectorMatch) === 1) {
                $css = trim((string) ($selectorMatch[2] ?? ''));

                if ($css === '') {
                    return 'Nach '.$selectorMatch[1].'= fehlt der CSS-Selector.';
                }

                if ($error = $this->validateExtendedOrNativeCss($css)) {
                    return $error;
                }

                continue;
            }

            if ($this->looksLikeCssSelector($candidate)) {
                if ($error = $this->validateExtendedOrNativeCss($candidate)) {
                    return $error;
                }
            }
        }

        return null;
    }

    protected function validateCssSelector(string $value): ?string
    {
        if (preg_match('/^(?:text|has-text|text-is|css|selector)\s*=/iu', $value) === 1) {
            return 'Dieses Feld erwartet reines CSS. Praefixe wie text= oder css= sind hier nicht erlaubt.';
        }

        if (preg_match('/:(?:has-text|text-is)\s*\(/iu', $value) === 1) {
            return 'Dieses Feld erwartet reines CSS. :has-text() und :text-is() sind hier nicht erlaubt.';
        }

        if ($error = $this->balancedSyntaxError($value)) {
            return $error;
        }

        return $this->validateNativeCssShape($value);
    }

    protected function validateExtendedOrNativeCss(string $value): ?string
    {
        $nested = [];
        $simple = [];

        if (preg_match('/^(.*?):has\(\s*(.*?):(has-text|text-is)\(\s*(["\'])(.*?)\4\s*\)\s*\)$/iu', $value, $nested) === 1) {
            foreach ([trim((string) $nested[1]) ?: '*', trim((string) $nested[2]) ?: '*'] as $css) {
                if ($error = $this->validateCssSelector($css)) {
                    return $error;
                }
            }

            return null;
        }

        if (preg_match('/^(.*?):(has-text|text-is)\(\s*(["\'])(.*?)\3\s*\)$/iu', $value, $simple) === 1) {
            return $this->validateCssSelector(trim((string) $simple[1]) ?: '*');
        }

        if (preg_match('/:(?:has-text|text-is)\s*\(/iu', $value) === 1) {
            return 'Text-Pseudos muessen am Ende stehen und einen Text in Anfuehrungszeichen enthalten, z. B. button:has-text("Weiter").';
        }

        return $this->validateCssSelector($value);
    }

    protected function validateNativeCssShape(string $value): ?string
    {
        if ($value === '') {
            return 'Der CSS-Selector ist leer.';
        }

        if (preg_match('/^[>+~]|[>+~]\s*$/u', $value) === 1) {
            return 'Der CSS-Selector besitzt einen unvollstaendigen Kombinator.';
        }

        if (preg_match('/(?:^|[\s>+~,])(?:[.#])(?:$|[\s>+~,])/u', $value) === 1) {
            return 'Nach Punkt oder Raute fehlt ein Klassen- bzw. ID-Name.';
        }

        if (preg_match('/\[\s*\]/u', $value) === 1) {
            return 'Ein Attribut-Selector darf nicht leer sein.';
        }

        if (preg_match('/:\s*$/u', $value) === 1) {
            return 'Nach dem Doppelpunkt fehlt eine CSS-Pseudoklasse.';
        }

        if ($this->containsTopLevelToken($value, ['{', '}', ';', '='])) {
            return 'Der CSS-Selector enthaelt ein ungueltiges Zeichen ausserhalb eines Attributs oder Textwerts.';
        }

        return null;
    }

    protected function validateVariablePath(string $value): ?string
    {
        if (mb_strlen($value) > 120) {
            return 'Der Variablenname darf hoechstens 120 Zeichen lang sein.';
        }

        if (preg_match('/^[A-Za-z0-9_.-]+$/', $value) !== 1) {
            return 'Der Variablenname darf nur Buchstaben, Zahlen, Punkt, Unterstrich und Bindestrich enthalten.';
        }

        return null;
    }

    protected function validateFieldDefinitions(string $value): ?string
    {
        try {
            $fields = json_decode($value, true, 64, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            return 'Die Felddefinitionen sind kein gueltiges JSON: '.$exception->getMessage();
        }

        if (! is_array($fields) || ! array_is_list($fields) || $fields === []) {
            return 'Die Felddefinitionen muessen ein nichtleeres JSON-Array sein.';
        }

        $allowedTypes = ['text', 'inner_text', 'href', 'html', 'attribute', 'exists'];
        $names = [];

        foreach ($fields as $index => $field) {
            $position = $index + 1;

            if (! is_array($field) || array_is_list($field)) {
                return 'Feld '.$position.' muss ein JSON-Objekt sein.';
            }

            $name = trim((string) ($field['name'] ?? ''));

            if ($name === '') {
                return 'Feld '.$position.' benoetigt einen Namen.';
            }

            if (isset($names[$name])) {
                return 'Der Feldname "'.$name.'" ist doppelt vorhanden.';
            }

            $names[$name] = true;
            $type = strtolower(trim((string) ($field['type'] ?? 'text')));

            if (! in_array($type, $allowedTypes, true)) {
                return 'Feld '.$position.' verwendet den unbekannten Typ "'.$type.'".';
            }

            $configuredSelector = $field['selector'] ?? '';

            if ($configuredSelector !== null && ! is_string($configuredSelector)) {
                return 'Feld '.$position.': selector muss Text sein.';
            }

            if (($selector = trim((string) $configuredSelector)) !== '') {
                if ($error = $this->validateCssSelector($selector)) {
                    return 'Feld '.$position.': '.$error;
                }
            }

            $fallbacks = $field['fallback_selectors'] ?? $field['fallbackSelectors'] ?? [];

            if (is_string($fallbacks)) {
                $decodedFallbacks = json_decode($fallbacks, true);
                $fallbacks = is_array($decodedFallbacks)
                    ? $decodedFallbacks
                    : preg_split('/\r?\n|\|\|/', $fallbacks);
            }

            if (! is_array($fallbacks)) {
                return 'Feld '.$position.': fallback_selectors muss eine String-Liste sein.';
            }

            foreach ($fallbacks as $fallback) {
                if (! is_string($fallback)) {
                    return 'Feld '.$position.': Jeder Fallback-Selector muss Text sein.';
                }

                if (trim($fallback) !== '' && ($error = $this->validateCssSelector(trim($fallback)))) {
                    return 'Feld '.$position.': '.$error;
                }
            }
        }

        return null;
    }

    protected function validateFallbackMap(string $value): ?string
    {
        try {
            $fallbacks = json_decode($value, true, 64, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            return 'Die Fallbacks sind kein gueltiges JSON: '.$exception->getMessage();
        }

        if (! is_array($fallbacks) || ! str_starts_with(ltrim($value), '{')) {
            return 'Fallbacks muessen ein JSON-Objekt sein.';
        }

        $allowedKeys = ['title', 'link', 'description', 'site_name', 'breadcrumb'];

        foreach ($fallbacks as $key => $selectors) {
            if (! in_array((string) $key, $allowedKeys, true)) {
                return 'Der Fallback-Schluessel "'.$key.'" ist nicht erlaubt.';
            }

            if (is_string($selectors)) {
                $selectors = preg_split('/\r?\n|\|\|/', $selectors);
            }

            if (! is_array($selectors) || ! array_is_list($selectors)) {
                return 'Fallback "'.$key.'" muss eine String-Liste sein.';
            }

            foreach ($selectors as $selector) {
                if (! is_string($selector) || trim($selector) === '') {
                    return 'Fallback "'.$key.'" enthaelt einen leeren oder ungueltigen Selector.';
                }

                if ($error = $this->validateCssSelector(trim($selector))) {
                    return 'Fallback "'.$key.'": '.$error;
                }
            }
        }

        return null;
    }

    protected function validateActionSteps(string $value): ?string
    {
        $normalizedJson = preg_replace('/,\s*([}\]])/u', '$1', $value) ?? $value;

        try {
            $steps = json_decode($normalizedJson, true, 64, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            $steps = collect(preg_split('/\r?\n|;/', $value))
                ->map(fn (mixed $selector): string => trim((string) $selector))
                ->filter()
                ->map(fn (string $selector): array => ['selector' => $selector])
                ->values()
                ->all();
        }

        if (! is_array($steps) || ! array_is_list($steps) || $steps === []) {
            return 'Aktionsschritte muessen ein JSON-Array oder eine Liste mit einem Selector pro Zeile sein.';
        }

        foreach ($steps as $index => $step) {
            $position = $index + 1;
            $step = is_string($step) ? ['selector' => $step] : $step;

            if (! is_array($step) || array_is_list($step)) {
                return 'Aktionsschritt '.$position.' muss ein Selector oder JSON-Objekt sein.';
            }

            $configuredSelector = $step['selector'] ?? $step['text'] ?? $step['value'] ?? '';

            if (! is_scalar($configuredSelector) && $configuredSelector !== null) {
                return 'Aktionsschritt '.$position.': Selector oder Text muss eine Zeichenfolge sein.';
            }

            $selector = trim((string) $configuredSelector);

            if ($selector === '') {
                return 'Aktionsschritt '.$position.' benoetigt einen Selector oder Text.';
            }

            if ($error = $this->validateElementCandidates($selector)) {
                return 'Aktionsschritt '.$position.': '.$error;
            }

            foreach (['wait_ms', 'timeout_ms'] as $numberField) {
                if (array_key_exists($numberField, $step) && (! is_numeric($step[$numberField]) || (float) $step[$numberField] < 0)) {
                    return 'Aktionsschritt '.$position.': '.$numberField.' muss eine nichtnegative Zahl sein.';
                }
            }

            if (array_key_exists('required', $step) && ! is_bool($step['required'])) {
                return 'Aktionsschritt '.$position.': required muss true oder false sein.';
            }
        }

        return null;
    }

    /**
     * @return array{0: list<string>, 1: ?string}
     */
    protected function splitTopLevelSelectorList(string $value, bool $allowUnclosedQuote = false): array
    {
        $entries = [];
        $current = '';
        $quote = '';
        $escaped = false;
        $parentheses = 0;
        $brackets = 0;
        $characters = preg_split('//u', trim($value), -1, PREG_SPLIT_NO_EMPTY) ?: [];

        foreach ($characters as $character) {
            if ($escaped) {
                $current .= $character;
                $escaped = false;

                continue;
            }

            if ($character === '\\') {
                $current .= $character;
                $escaped = true;

                continue;
            }

            if ($quote !== '') {
                $current .= $character;

                if ($character === $quote) {
                    $quote = '';
                }

                continue;
            }

            if ($character === '"' || $character === "'") {
                $current .= $character;
                $quote = $character;

                continue;
            }

            if ($character === '(') {
                $parentheses++;
            } elseif ($character === ')') {
                if ($parentheses === 0) {
                    return [[], 'Eine schliessende runde Klammer besitzt keine oeffnende Klammer.'];
                }

                $parentheses--;
            } elseif ($character === '[') {
                $brackets++;
            } elseif ($character === ']') {
                if ($brackets === 0) {
                    return [[], 'Eine schliessende eckige Klammer besitzt keine oeffnende Klammer.'];
                }

                $brackets--;
            }

            if ($character === ',' && $parentheses === 0 && $brackets === 0) {
                if (trim($current) === '') {
                    return [[], 'Zwischen zwei Kommas fehlt ein Selector oder Textziel.'];
                }

                $entries[] = trim($current);
                $current = '';

                continue;
            }

            $current .= $character;
        }

        if ($escaped) {
            return [[], 'Ein Escape-Zeichen am Ende ist unvollstaendig.'];
        }

        if ($quote !== '' && ! $allowUnclosedQuote) {
            return [[], 'Ein Textwert besitzt kein schliessendes Anfuehrungszeichen.'];
        }

        if ($parentheses > 0) {
            return [[], 'Eine runde Klammer wurde nicht geschlossen.'];
        }

        if ($brackets > 0) {
            return [[], 'Eine eckige Klammer wurde nicht geschlossen.'];
        }

        if (trim($current) === '') {
            return [[], 'Nach dem letzten Komma fehlt ein Selector oder Textziel.'];
        }

        $entries[] = trim($current);

        return [$entries, null];
    }

    protected function balancedSyntaxError(string $value): ?string
    {
        [, $error] = $this->splitTopLevelSelectorList($value);

        return $error;
    }

    protected function containsTopLevelToken(string $value, array $tokens): bool
    {
        $quote = '';
        $escaped = false;
        $parentheses = 0;
        $brackets = 0;
        $characters = preg_split('//u', $value, -1, PREG_SPLIT_NO_EMPTY) ?: [];

        foreach ($characters as $character) {
            if ($escaped) {
                $escaped = false;

                continue;
            }

            if ($character === '\\') {
                $escaped = true;

                continue;
            }

            if ($quote !== '') {
                if ($character === $quote) {
                    $quote = '';
                }

                continue;
            }

            if ($character === '"' || $character === "'") {
                $quote = $character;

                continue;
            }

            if ($character === '(') {
                $parentheses++;

                continue;
            }

            if ($character === ')') {
                $parentheses = max(0, $parentheses - 1);

                continue;
            }

            if ($character === '[') {
                $brackets++;

                continue;
            }

            if ($character === ']') {
                $brackets = max(0, $brackets - 1);

                continue;
            }

            if ($parentheses === 0 && $brackets === 0 && in_array($character, $tokens, true)) {
                return true;
            }
        }

        return false;
    }

    protected function looksLikeCssSelector(string $value): bool
    {
        if (preg_match('/^(css|selector)\s*=/iu', $value) === 1) {
            return true;
        }

        if (preg_match('/^[#.\[*:>+~]|[\[\]#>:~+]/u', $value) === 1) {
            return true;
        }

        preg_match('/^([a-z][a-z0-9-]*)/iu', $value, $match);

        return in_array(strtolower((string) ($match[1] ?? '')), [
            'a', 'article', 'aside', 'button', 'details', 'dialog', 'div', 'fieldset', 'footer',
            'form', 'h1', 'h2', 'h3', 'h4', 'h5', 'h6', 'header', 'iframe', 'img', 'input',
            'label', 'legend', 'li', 'main', 'nav', 'option', 'p', 'section', 'select', 'slot',
            'span', 'summary', 'table', 'tbody', 'td', 'textarea', 'tfoot', 'th', 'thead', 'tr',
            'ul', 'video', 'svg', 'path', 'g', 'rect', 'circle', 'use', 'webmailer-mail-list',
        ], true);
    }
}
