<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

class WorkflowTaskLibraryMarkupTest extends TestCase
{
    public function test_shared_definition_editor_has_mobile_panel_switch_group_selector_and_overview(): void
    {
        $root = dirname(__DIR__, 2);
        $source = file_get_contents($root.'/resources/views/livewire/admin/network/partials/workflow-definition-editor.blade.php');
        $studioWrapper = file_get_contents($root.'/resources/views/livewire/admin/network/workflow-studio-task-editor.blade.php');
        $manager = file_get_contents($root.'/resources/views/livewire/admin/network/workflow-manager.blade.php');

        $this->assertStringContainsString('data-workflow-definition-editor', $source);
        $this->assertStringContainsString("mobilePanel: 'canvas'", $source);
        $this->assertStringContainsString('data-studio-mobile-switch', $source);
        $this->assertStringContainsString('class="ff-task-group-rail', $source);
        $this->assertStringContainsString('aria-controls="studio-task-catalog-panel"', $source);
        $this->assertStringContainsString('aria-controls="studio-task-canvas-panel"', $source);
        $this->assertStringContainsString('data-studio-editor-overview', $source);
        $this->assertStringContainsString('<x-workflows.minimap', $source);
        $this->assertStringContainsString(':workflow="$workflow"', $source);
        $this->assertStringContainsString(':zoomable="true"', $source);
        $this->assertStringContainsString("initial-zoom=\"overview\"", $source);
        $this->assertStringContainsString("window.matchMedia('(pointer: fine)').matches", $source);
        $this->assertStringContainsString('x-on:workflow-preview-task-selected.stop', $source);
        $this->assertStringContainsString("@include('livewire.admin.network.partials.workflow-definition-editor'", $studioWrapper);
        $this->assertStringContainsString("@include('livewire.admin.network.partials.workflow-definition-editor'", $manager);
    }

    public function test_shared_left_library_searches_all_groups_without_changing_runtime_kind(): void
    {
        $root = dirname(__DIR__, 2);
        $source = file_get_contents($root.'/resources/views/livewire/admin/network/partials/workflow-definition-editor.blade.php');

        $this->assertStringContainsString("xl:grid-cols-[350px_minmax(0,1fr)]", $source);
        $this->assertStringContainsString('id="studio-task-catalog-panel"', $source);
        $this->assertStringContainsString('wire:model.live.debounce.250ms="taskSearch"', $source);
        $this->assertStringContainsString('Treffer in allen Gruppen', $source);
        $this->assertStringContainsString('data-task-library-group="{{ $taskDefinition[\'library_group\'] }}"', $source);
        $this->assertStringNotContainsString("collect(\$taskDefinitions)->where('kind', \$taskGroup)", $source);
    }

    public function test_manager_uses_shared_editor_without_legacy_right_drawer_or_duplicate_dialogs(): void
    {
        $root = dirname(__DIR__, 2);
        $manager = file_get_contents($root.'/resources/views/livewire/admin/network/workflow-manager.blade.php');
        $source = file_get_contents($root.'/resources/views/livewire/admin/network/partials/workflow-definition-editor.blade.php');

        $this->assertSame(1, substr_count($manager, "@include('livewire.admin.network.partials.workflow-definition-editor'"));
        $this->assertStringNotContainsString('@if(false)', $manager);
        $this->assertStringNotContainsString('ff-task-library-launcher', $manager);
        $this->assertStringNotContainsString('ff-task-drawer fixed inset-y-0 right-0', $manager);

        foreach (['showAddStepModal', 'showEditStepModal', 'showAddTaskModal', 'showEditTaskModal'] as $modal) {
            $markup = '<x-ui.dialog-modal wire:model="'.$modal.'"';

            $this->assertStringNotContainsString($markup, $manager);
            $this->assertSame(1, substr_count($source, $markup));
        }
    }

    public function test_mobile_css_uses_touch_targets_and_scroll_snap_without_transform_zoom(): void
    {
        $root = dirname(__DIR__, 2);
        $source = file_get_contents($root.'/resources/css/workflow-experience.css');

        $this->assertStringContainsString('[data-studio-task-editor] [data-studio-workflow-canvas]', $source);
        $this->assertStringContainsString('scroll-snap-type: inline proximity', $source);
        $this->assertStringContainsString('[data-studio-task-editor] [data-studio-editor-step]', $source);
        $this->assertStringContainsString('min-height: 44px', $source);
        $this->assertStringContainsString('overscroll-behavior: contain', $source);
    }
}
