<?php

namespace Tests\Unit;

use App\Services\Workflows\WorkflowTaskCatalog;
use Tests\TestCase;

class WorkflowTaskLibraryPresentationTest extends TestCase
{
    public function test_every_visible_catalog_task_has_one_known_library_group_and_explicit_order(): void
    {
        $catalog = app(WorkflowTaskCatalog::class);
        $groups = $catalog->libraryGroups();
        $options = collect($catalog->arrangeLibraryOptions($catalog->options()));

        $this->assertCount(51, $catalog->all());
        $this->assertCount(44, $options);
        $this->assertCount(44, $options->pluck('key')->unique());
        $this->assertSame(
            [],
            $options
                ->reject(fn (array $option): bool => array_key_exists($option['library_group'], $groups))
                ->pluck('key')
                ->values()
                ->all(),
            'Jeder sichtbare Task muss einer bekannten fachlichen Gruppe angehoeren.',
        );
        $this->assertSame(
            [],
            $options
                ->where('library_order', 500)
                ->pluck('key')
                ->values()
                ->all(),
            'Neue sichtbare Tasks duerfen nicht still in die alphabetische Fallback-Sortierung fallen.',
        );

        $this->assertSame([
            'navigation' => 6,
            'discovery' => 6,
            'interaction' => 7,
            'decisions' => 8,
            'loops' => 4,
            'accounts' => 8,
            'data' => 5,
        ], $options->countBy('library_group')->all());

        $this->assertSame([
            'browser.open',
            'browser.open_url',
            'browser.navigate_back',
            'browser.navigate_forward',
            'browser.reload',
            'browser.close',
        ], $options->where('library_group', 'navigation')->pluck('key')->values()->all());
    }

    public function test_internal_legacy_and_probe_tasks_stay_registered_but_out_of_the_library(): void
    {
        $catalog = app(WorkflowTaskCatalog::class);
        $allKeys = array_keys($catalog->all());
        $visibleKeys = collect($catalog->options())->pluck('key')->all();
        $hiddenKeys = [
            'browser.open_browser_session',
            'loop.end',
            'browser.highlight',
            'browser.assistance_click_coordinates',
            'browser.assistance_type_text',
            'data.save_workflow_data',
            'data.persist_browser_session',
        ];

        foreach ($hiddenKeys as $taskKey) {
            $this->assertContains($taskKey, $allKeys);
            $this->assertNotContains($taskKey, $visibleKeys);
            $this->assertTrue((bool) data_get($catalog->task($taskKey), 'hidden_from_library'));
        }
    }

    public function test_library_arrangement_does_not_change_runtime_kind_runner_or_script(): void
    {
        $catalog = app(WorkflowTaskCatalog::class);
        $arranged = collect($catalog->arrangeLibraryOptions($catalog->options()))->keyBy('key');

        foreach ($arranged as $taskKey => $option) {
            $definition = $catalog->task($taskKey);

            $this->assertSame($definition['kind'], $option['kind'], $taskKey.' kind');
            $this->assertSame($definition['runner'], $option['runner'], $taskKey.' runner');
            $this->assertSame(
                $definition['node_script'],
                data_get($catalog->resolveRuntimeTask(['task_key' => $taskKey]), 'node_script'),
                $taskKey.' node_script',
            );
        }
    }

    public function test_embedded_workflows_are_sorted_into_their_own_group(): void
    {
        $catalog = app(WorkflowTaskCatalog::class);
        $arranged = $catalog->arrangeLibraryOptions([[
            'key' => 'workflow.include.42',
            'label' => 'Workflow: Registrierung',
            'kind' => 'workflow',
            'runner' => 'workflow',
            'description' => '',
        ]]);

        $this->assertSame('workflows', $arranged[0]['library_group']);
        $this->assertSame('Unter-Workflows', $arranged[0]['library_group_label']);
    }
}
