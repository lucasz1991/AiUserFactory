<?php

namespace Tests\Unit;

use App\Services\Workflows\WorkflowTaskCatalog;
use PHPUnit\Framework\TestCase;

class WorkflowCollectionTaskCatalogTest extends TestCase
{
    public function test_generic_collection_tasks_are_registered_with_existing_node_scripts(): void
    {
        $catalog = new WorkflowTaskCatalog;
        $expected = [
            'browser.scroll' => 'node/workflows/tasks/browser/scroll.cjs',
            'browser.open_browser_session' => 'node/workflows/tasks/browser/open_browser_session.cjs',
            'loop.for_each_element' => 'node/workflows/tasks/loop/for_each_element.cjs',
            'loop.end' => 'node/workflows/tasks/loop/end.cjs',
            'browser.read_element_fields' => 'node/workflows/tasks/browser/read_element_fields.cjs',
            'data.append_to_array' => 'node/workflows/tasks/data/append_to_array.cjs',
            'decision.array_length' => 'node/workflows/tasks/decision/array_length.cjs',
            'browser.read_searchengine_result' => 'node/workflows/tasks/browser/read_searchengine_result.cjs',
        ];

        foreach ($expected as $taskKey => $script) {
            $definition = $catalog->task($taskKey);

            $this->assertNotNull($definition, $taskKey);
            $this->assertSame('node', $definition['runner']);
            $this->assertSame($script, $definition['node_script']);
            $this->assertFileExists(dirname(__DIR__, 2).DIRECTORY_SEPARATOR.str_replace('/', DIRECTORY_SEPARATOR, $script));
        }
    }

    public function test_catalog_cards_include_task_specific_defaults(): void
    {
        $catalog = new WorkflowTaskCatalog;

        $loop = $catalog->cardFromDefinition('loop.for_each_element');
        $reader = $catalog->cardFromDefinition('browser.read_element_fields');
        $append = $catalog->cardFromDefinition('data.append_to_array');

        $this->assertSame('current_result', $loop['store_current_element_as']);
        $this->assertSame('result_index', $loop['store_index_as']);
        $this->assertSame('current_result', $reader['scope_variable']);
        $this->assertSame('top_results', $append['array_name']);
        $this->assertSame('current_result', $append['value_from_variable']);
    }
}
