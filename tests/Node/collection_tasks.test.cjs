'use strict';

const test = require('node:test');
const assert = require('node:assert/strict');
const { spawnSync } = require('node:child_process');
const fs = require('node:fs');
const os = require('node:os');
const path = require('node:path');

const loopTask = require('../../node/workflows/tasks/loop/for_each_element.cjs');
const loopEndTask = require('../../node/workflows/tasks/loop/end.cjs');
const readFieldsTask = require('../../node/workflows/tasks/browser/read_element_fields.cjs');
const readSearchResultTask = require('../../node/workflows/tasks/browser/read_searchengine_result.cjs');
const appendTask = require('../../node/workflows/tasks/data/append_to_array.cjs');
const arrayLengthTask = require('../../node/workflows/tasks/decision/array_length.cjs');
const scrollTask = require('../../node/workflows/tasks/browser/scroll.cjs');
const workflowReturnTask = require('../../node/workflows/tasks/data/workflow_return.cjs');

class FakeHandle {
  constructor(data = {}, selectors = {}, visible = true) {
    this.data = data;
    this.selectors = selectors;
    this.visible = visible;
  }

  async $$(selector) {
    return this.selectors[selector] || [];
  }

  async evaluate(_callback, payload) {
    if (Number.isInteger(payload)) return this.data.identity || `element-${payload}`;
    if (!payload || !payload.type) return this.visible;
    switch (payload.type) {
      case 'href': return this.data.href || '';
      case 'html': return this.data.html || '';
      case 'inner_text': return this.data.innerText || this.data.text || '';
      case 'attribute': return this.data.attributes?.[payload.attributeName] || '';
      case 'exists': return true;
      default: return this.data.text || '';
    }
  }
}

function searchResult(title, url, description) {
  const titleHandle = new FakeHandle({ text: `  ${title}  ` });
  const linkHandle = new FakeHandle({ href: url });
  const descriptionHandle = new FakeHandle({ text: description });
  return new FakeHandle({}, {
    h3: [titleHandle],
    a: [linkHandle],
    '.description': [descriptionHandle],
  });
}

test('generic element loop reads fields and appends a deduplicated result array', async () => {
  const handles = [
    searchResult('First result', '/first', 'First description'),
    searchResult('Second result', '/second', 'Second description'),
  ];
  const page = {
    $$: async (selector) => (selector === '.result' ? handles : []),
    url: () => 'https://search.example/query?q=test',
  };
  const context = {
    page,
    workflow_variables: {},
    input: {
      key: 'result-loop',
      selector: '.result',
      limit: 2,
      only_visible: true,
      store_current_element_as: 'current_result',
      store_index_as: 'result_index',
      success_target: 'read-result',
      empty_target: 'return-results',
    },
  };

  const firstLoop = await loopTask.run(context);
  assert.equal(firstLoop.current_index, 0);
  assert.equal(firstLoop.matched_count, 2);
  assert.equal(firstLoop.route_target_key, 'read-result');

  context.input = {
    scope_variable: 'current_result',
    output_variable: 'current_result',
    fields: [
      { name: 'titel', selector: 'h3', type: 'text', required: true },
      { name: 'url', selector: 'a', type: 'href', required: true },
      { name: 'description', selector: '.description', type: 'text' },
    ],
  };
  const firstRead = await readFieldsTask.run(context);
  assert.equal(firstRead.ok, true);
  assert.deepEqual(firstRead.result, {
    titel: 'First result',
    url: 'https://search.example/first',
    description: 'First description',
  });

  context.lastResult = firstRead;
  context.input = { array_name: 'top_results', value_from_variable: 'current_result', dedupe_by: 'url' };
  const firstAppend = await appendTask.run(context);
  assert.equal(firstAppend.new_length, 1);
  assert.equal(firstAppend.appended, true);

  context.input = {
    key: 'result-loop',
    selector: '.result',
    limit: 2,
    only_visible: true,
    store_current_element_as: 'current_result',
    store_index_as: 'result_index',
    success_target: 'read-result',
    empty_target: 'return-results',
  };
  const secondLoop = await loopTask.run(context);
  assert.equal(secondLoop.current_index, 1);
  assert.equal(secondLoop.processed_count, 1);

  context.input = {
    scope_variable: 'current_result',
    fields: [
      { name: 'titel', selector: 'h3', type: 'text', required: true },
      { name: 'url', selector: 'a', type: 'href', required: true },
    ],
  };
  const secondRead = await readFieldsTask.run(context);
  context.lastResult = secondRead;
  context.input = { array_name: 'top_results', value_from_variable: 'current_result', dedupe_by: 'url' };
  await appendTask.run(context);

  context.input = {
    key: 'result-loop',
    selector: '.result',
    store_current_element_as: 'current_result',
    store_index_as: 'result_index',
    empty_target: 'return-results',
  };
  const completed = await loopTask.run(context);
  assert.equal(completed.loop_complete, true);
  assert.equal(completed.processed_count, 2);
  assert.equal(completed.route_target_key, 'return-results');
  assert.equal(context.workflow_variables.top_results.length, 2);
});

test('loop end routes back to start while the loop is active and continues after completion', async () => {
  const handles = [
    searchResult('Only result', '/only', 'Only description'),
  ];
  const context = {
    page: {
      $$: async (selector) => (selector === '.result' ? handles : []),
    },
    workflow_variables: {},
    input: {
      key: 'result-loop',
      selector: '.result',
      store_current_element_as: 'current_result',
      store_index_as: 'result_index',
    },
  };

  const firstLoop = await loopTask.run(context);
  assert.equal(firstLoop.loop_complete, false);

  context.input = { loop_start_key: 'result-loop' };
  const firstEnd = await loopEndTask.run(context);
  assert.equal(firstEnd.route_target_key, 'result-loop');
  assert.equal(firstEnd.loop_complete, false);

  context.input = {
    key: 'result-loop',
    selector: '.result',
    store_current_element_as: 'current_result',
    store_index_as: 'result_index',
  };
  const completed = await loopTask.run(context);
  assert.equal(completed.loop_complete, true);

  context.input = { loop_start_key: 'result-loop' };
  const finalEnd = await loopEndTask.run(context);
  assert.equal(finalEnd.loop_complete, true);
  assert.equal(finalEnd.route_target_key, undefined);
});

test('element loop collects every reader result into a persistent workflow array', async () => {
  const handles = [
    searchResult('First result', '/first', 'First description'),
    searchResult('Second result', '/second', 'Second description'),
  ];
  handles[0].data.identity = 'result:first';
  handles[1].data.identity = 'result:second';
  const context = {
    page: {
      $$: async (selector) => (selector === '.result' ? handles : []),
      url: () => 'https://search.example/',
    },
    workflow_variables: {},
  };
  const loopInput = {
    key: 'collect-loop',
    selector: '.result',
    store_current_element_as: 'current_result',
    store_index_as: 'result_index',
    collect_to_array: 'top_results',
    collect_from_variable: 'current_result',
    collect_dedupe_by: 'url',
    success_target: 'read-result',
    completion_target: 'return-results',
  };

  for (let index = 0; index < handles.length; index += 1) {
    context.input = loopInput;
    const active = await loopTask.run(context);
    assert.equal(active.current_index, index);

    context.input = {
      scope_variable: 'current_result',
      output_variable: 'current_result',
      fields: [
        { name: 'title', selector: 'h3', type: 'text', required: true },
        { name: 'url', selector: 'a', type: 'href', required: true },
      ],
    };
    const read = await readFieldsTask.run(context);
    assert.equal(read.ok, true);
  }

  context.input = loopInput;
  const completed = await loopTask.run(context);

  assert.equal(completed.route_target_key, 'return-results');
  assert.equal(completed.route_outcome, 'complete');
  assert.equal(completed.collected_count, 2);
  assert.deepEqual(context.workflow_variables.top_results, [
    { title: 'First result', url: 'https://search.example/first' },
    { title: 'Second result', url: 'https://search.example/second' },
  ]);
});

test('element loop separates empty and completed routes and defaults to its paired end', async () => {
  const emptyContext = {
    page: { $$: async () => [] },
    workflow_variables: {},
    input: {
      key: 'empty-loop',
      selector: '.result',
      empty_target: 'handle-empty',
      completion_target: 'handle-complete',
      loop_end_key: 'empty-loop-end',
    },
  };
  const empty = await loopTask.run(emptyContext);
  assert.equal(empty.status, 'loop_empty');
  assert.equal(empty.route_target_key, 'handle-empty');
  assert.equal(empty.route_outcome, 'empty');

  const handle = searchResult('Only result', '/only', 'Only description');
  handle.data.identity = 'result:only';
  const completedContext = {
    page: { $$: async () => [handle] },
    workflow_variables: {},
    input: {
      key: 'default-exit-loop',
      selector: '.result',
      loop_end_key: 'default-exit-loop-end',
    },
  };
  await loopTask.run(completedContext);
  const completed = await loopTask.run(completedContext);
  assert.equal(completed.route_target_key, 'default-exit-loop-end');
  assert.equal(completed.route_outcome, 'complete');
});

test('element loop refreshes the DOM handle before the next iteration', async () => {
  const firstSnapshot = [
    new FakeHandle({ identity: 'item:1', text: 'Old first' }),
    new FakeHandle({ identity: 'item:2', text: 'Old second' }),
  ];
  const refreshedSnapshot = [
    new FakeHandle({ identity: 'item:1', text: 'New first' }),
    new FakeHandle({ identity: 'item:2', text: 'New second' }),
  ];
  let queryCount = 0;
  const context = {
    page: {
      $$: async () => {
        queryCount += 1;
        return queryCount === 1 ? firstSnapshot : refreshedSnapshot;
      },
      url: () => 'https://example.test/',
    },
    workflow_variables: {},
    input: {
      key: 'refresh-loop',
      selector: '.item',
      store_current_element_as: 'current_item',
    },
  };

  await loopTask.run(context);
  context.input = {
    key: 'refresh-loop',
    selector: '.item',
    store_current_element_as: 'current_item',
  };
  const second = await loopTask.run(context);
  assert.equal(second.current_index, 1);

  context.input = {
    scope_variable: 'current_item',
    output_variable: 'current_item_data',
    fields: [{ name: 'text', scope_self: true, type: 'text' }],
  };
  const read = await readFieldsTask.run(context);
  assert.deepEqual(read.result, { text: 'New second' });
});

test('append to array reports invalid arrays and missing source values explicitly', async () => {
  const invalidArray = await appendTask.run({
    workflow_variables: { results: 'not-an-array', current_result: { id: 1 } },
    input: { array_name: 'results', value_from_variable: 'current_result' },
  });
  assert.equal(invalidArray.ok, false);
  assert.equal(invalidArray.reason_code, 'array_not_array');

  const missingValue = await appendTask.run({
    workflow_variables: {},
    input: { array_name: 'results', value_from_variable: 'missing_result' },
  });
  assert.equal(missingValue.ok, false);
  assert.equal(missingValue.reason_code, 'value_missing');
});

test('search result wrapper supports fallback selectors and optional fields', async () => {
  const scope = new FakeHandle({}, {
    '[role=heading]': [new FakeHandle({ text: 'Fallback title' })],
    'a[href]': [new FakeHandle({ href: 'https://target.example/path' })],
    '.snippet': [new FakeHandle({ text: 'Fallback description' })],
  });
  const context = {
    page: { url: () => 'https://search.example/' },
    workflow_variables: {},
    input: {
      scope_variable: 'current_result',
      title_selector: '.missing-title',
      link_selector: '.missing-link',
      description_selector: '.missing-description',
      fallbacks: {
        title: ['[role=heading]'],
        link: ['a[href]'],
        description: ['.snippet'],
      },
    },
  };
  Object.defineProperty(context, '__workflowElementScopes', { value: { current_result: scope }, enumerable: false });

  const result = await readSearchResultTask.run(context);
  assert.equal(result.ok, true);
  assert.deepEqual(result.result, {
    titel: 'Fallback title',
    url: 'https://target.example/path',
    description: 'Fallback description',
    site_name: '',
    breadcrumb: '',
  });
});

test('search result wrapper reads the href from an anchor loop scope after process resume', async () => {
  const resultAnchor = new FakeHandle({ href: '/profile' }, {
    h3: [new FakeHandle({ text: 'Profile result' })],
  });
  const page = {
    $$: async (selector) => (selector === '#search a:has(h3)' ? [resultAnchor] : []),
    url: () => 'https://search.example/query',
  };
  const loopContext = {
    page,
    workflow_variables: {},
    input: {
      key: 'result-loop',
      selector: '#search a:has(h3)',
      limit: 1,
      store_current_element_as: 'current_result',
      store_index_as: 'result_index',
    },
  };

  const active = await loopTask.run(loopContext);
  assert.equal(active.current_index, 0);

  const resumedContext = {
    page,
    workflow_variables: structuredClone(loopContext.workflow_variables),
    input: {
      scope_variable: 'current_result',
      output_variable: 'current_result',
      title_selector: 'h3',
      link_selector: 'a',
    },
  };
  const result = await readSearchResultTask.run(resumedContext);

  assert.equal(result.ok, true);
  assert.equal(result.result.titel, 'Profile result');
  assert.equal(result.result.url, 'https://search.example/profile');
  assert.equal(result.selectors_used.url, ':scope');
});

test('loop cursor survives isolated single-task runner processes', async () => {
  const handles = [
    new FakeHandle({ identity: 'item:one', text: 'One' }),
    new FakeHandle({ identity: 'item:two', text: 'Two' }),
  ];
  const page = { $$: async () => handles };
  const input = {
    key: 'isolated-loop',
    selector: '.item',
    store_current_element_as: 'current_item',
    store_index_as: 'item_index',
  };
  const firstContext = { page, workflow_variables: {}, input };
  const first = await loopTask.run(firstContext);
  assert.equal(first.current_index, 0);

  const endContext = {
    workflow_variables: structuredClone(firstContext.workflow_variables),
    input: { loop_start_key: 'isolated-loop' },
  };
  const end = await loopEndTask.run(endContext);
  assert.equal(end.route_target_key, 'isolated-loop');

  const secondContext = {
    page,
    workflow_variables: structuredClone(endContext.workflow_variables),
    input,
  };
  const second = await loopTask.run(secondContext);
  assert.equal(second.current_index, 1);
  assert.equal(second.processed_count, 1);
});

test('array length branches and workflow return expose the requested status payload', async () => {
  const context = { workflow_variables: { top_results: [{ url: 'a' }, { url: 'b' }, { url: 'c' }] } };
  context.input = {
    array_name: 'top_results',
    operator: '>=',
    compare_value: 3,
    success_target: 'return-results',
    error_target: 'scroll-more',
  };
  const decision = await arrayLengthTask.run(context);
  assert.equal(decision.array_length, 3);
  assert.equal(decision.condition_met, true);
  assert.equal(decision.route_target_key, 'return-results');

  context.input = { selector: 'top_results', value: '' };
  const returned = await workflowReturnTask.run(context);
  assert.equal(returned.workflow_return_ok, true);
  assert.deepEqual(returned.workflow_return_preview, context.workflow_variables.top_results.slice(0, 3));
});

test('scroll task stops on an until selector and reports scroll status', async () => {
  let scrollY = 0;
  const untilHandle = new FakeHandle({ text: 'loaded' });
  const page = {
    $$: async (selector) => (selector === '.loaded' && scrollY >= 400 ? [untilHandle] : []),
    evaluate: async (_callback, amount) => {
      if (typeof amount === 'number') {
        scrollY += amount;
        return undefined;
      }
      return { scrollY, scrollHeight: 2000, contentLength: 100 + scrollY };
    },
  };
  const result = await scrollTask.run({
    page,
    input: {
      pixels: 400,
      steps: 5,
      max_rounds: 10,
      delay_ms_between_steps: 0,
      until_selector: '.loaded',
    },
  });

  assert.equal(result.ok, true);
  assert.equal(result.scroll_rounds, 1);
  assert.equal(result.final_scroll_y, 400);
  assert.equal(result.until_selector_found, true);
});

test('workflow runner follows a dynamic target returned by a collection decision task', () => {
  const directory = fs.mkdtempSync(path.join(os.tmpdir(), 'collection-route-'));
  const runtimePath = path.join(directory, 'runtime.json');
  const resultPath = path.join(directory, 'result.json');
  const statusPath = path.join(directory, 'status.json');
  fs.writeFileSync(runtimePath, JSON.stringify({
    resultPath,
    statusPath,
    runDirectory: directory,
    livePreviewEnabled: false,
    workflow: { workflow_variables: { top_results: [] } },
    tasks: [
      {
        key: 'enough-results',
        task_key: 'decision.array_length',
        title: 'Enough results',
        kind: 'data',
        runner: 'node',
        node_script: 'node/workflows/tasks/decision/array_length.cjs',
        array_name: 'top_results',
        operator: '>=',
        compare_value: 1,
        success_target: 'must-be-skipped',
        error_target: 'fallback-return',
      },
      {
        key: 'must-be-skipped',
        task_key: 'data.workflow_return',
        title: 'Must be skipped',
        kind: 'data',
        runner: 'node',
        node_script: 'node/workflows/tasks/data/workflow_return.cjs',
        value: false,
      },
      {
        key: 'fallback-return',
        task_key: 'data.workflow_return',
        title: 'Fallback return',
        kind: 'data',
        runner: 'node',
        node_script: 'node/workflows/tasks/data/workflow_return.cjs',
        value: true,
      },
    ],
  }));

  const processResult = spawnSync(process.execPath, [path.resolve(__dirname, '../../node/workflows/run_step.cjs'), runtimePath], {
    cwd: path.resolve(__dirname, '../..'),
    encoding: 'utf8',
    timeout: 15000,
  });

  try {
    assert.equal(processResult.status, 0, processResult.stderr || processResult.stdout);
    const result = JSON.parse(fs.readFileSync(resultPath, 'utf8'));
    assert.equal(result.ok, true);
    assert.deepEqual(result.tasks.map((task) => task.key), ['enough-results', 'fallback-return']);
  } finally {
    fs.rmSync(directory, { recursive: true, force: true });
  }
});
