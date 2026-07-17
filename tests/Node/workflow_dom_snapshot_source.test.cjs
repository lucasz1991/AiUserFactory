'use strict';

const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const test = require('node:test');

const source = fs.readFileSync(
  path.resolve(__dirname, '../../node/workflows/run_step.cjs'),
  'utf8',
);

test('workflow DOM snapshot prefers semantic input attributes before generated ids', () => {
  const semanticAttributes = source.indexOf("['title', 'aria-label', 'placeholder', 'data-testid'");
  const idFallback = source.indexOf('if (element.id) return `#${escapeCss(element.id)}`;');

  assert.notEqual(semanticAttributes, -1);
  assert.notEqual(idFallback, -1);
  assert.ok(semanticAttributes < idFallback);
  assert.match(source, /title:\s*element\.getAttribute\('title'\)/);
});

test('workflow DOM snapshot recognizes search results and emits a collection selector', () => {
  const resultState = source.indexOf("if (hasVisibleSearchResults) return 'search_results';");
  const inputState = source.indexOf("if (hasVisibleSearchInput) return 'search_input';");

  assert.notEqual(resultState, -1);
  assert.notEqual(inputState, -1);
  assert.ok(resultState < inputState);
  assert.match(source, /#search a:has\(h3\), #search a:has\(h2\)/);
});
