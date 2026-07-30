import assert from 'node:assert/strict';
import test from 'node:test';

import {
    SELECTOR_MODES,
    splitTopLevelSelectorList,
    validateWorkflowSelector,
} from '../../resources/js/components/workflow-selector-syntax.js';

test('top-level candidates preserve commas in brackets, quotes and parentheses', () => {
    assert.deepEqual(
        splitTopLevelSelectorList('button[data-label="A,B"], article:has(h3, h2), text=Weiter').entries,
        ['button[data-label="A,B"]', 'article:has(h3, h2)', 'text=Weiter'],
    );
});

test('element candidate syntax accepts resolver extensions and rejects malformed input', () => {
    for (const value of [
        'button[type=submit], text=Weiter',
        "text=What's new",
        'text-is="Jetzt anmelden"',
        'css=body',
        'button:has-text("Weiter")',
        'button:has(span:has-text("Login"))',
    ]) {
        assert.equal(validateWorkflowSelector(value, SELECTOR_MODES.elementCandidates).state, 'valid', value);
    }

    for (const value of ['css=', 'button[type=submit', 'button:has-text(Weiter)', 'button,,text=Weiter']) {
        assert.equal(validateWorkflowSelector(value, SELECTOR_MODES.elementCandidates).state, 'invalid', value);
    }
});

test('raw CSS mode rejects resolver-only syntax', () => {
    assert.equal(validateWorkflowSelector('article:has(h3), [data-result]', SELECTOR_MODES.cssSelector).state, 'valid');
    assert.equal(validateWorkflowSelector('text=Weiter', SELECTOR_MODES.cssSelector).state, 'invalid');
    assert.equal(validateWorkflowSelector('button:has-text("Weiter")', SELECTOR_MODES.cssSelector).state, 'invalid');
});

test('nested JSON selector formats validate embedded selectors', () => {
    assert.equal(validateWorkflowSelector(
        '[{"name":"title","selector":"h3","type":"text"}]',
        SELECTOR_MODES.fieldDefinitions,
    ).state, 'valid');
    assert.equal(validateWorkflowSelector(
        '[{"name":"title","selector":"h3[","type":"text"}]',
        SELECTOR_MODES.fieldDefinitions,
    ).state, 'invalid');
    assert.equal(validateWorkflowSelector(
        '[{"name":"title","selector":[],"type":"text"}]',
        SELECTOR_MODES.fieldDefinitions,
    ).state, 'invalid');
    assert.equal(validateWorkflowSelector(
        '{}',
        SELECTOR_MODES.fallbackMap,
    ).state, 'valid');
    assert.equal(validateWorkflowSelector(
        '{"title":["h3","[role=heading]"]}',
        SELECTOR_MODES.fallbackMap,
    ).state, 'valid');
    assert.equal(validateWorkflowSelector(
        '[{"selector":"text=Loeschen","required":false}]',
        SELECTOR_MODES.actionSteps,
    ).state, 'valid');
    assert.equal(validateWorkflowSelector(
        '[{"selector":"text=Loeschen","required":false,},]',
        SELECTOR_MODES.actionSteps,
    ).state, 'valid');
});
