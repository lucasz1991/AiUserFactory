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
        $this->assertStringContainsString('aria-controls="{{ $fieldIdPrefix }}-studio-task-catalog-panel"', $source);
        $this->assertStringContainsString('aria-controls="{{ $fieldIdPrefix }}-studio-task-canvas-panel"', $source);
        $this->assertStringContainsString('data-studio-editor-overview', $source);
        $this->assertStringContainsString('<x-workflows.minimap', $source);
        $this->assertStringContainsString(':workflow="$workflow"', $source);
        $this->assertStringContainsString(':zoomable="true"', $source);
        $this->assertStringContainsString('initial-zoom="overview"', $source);
        $this->assertStringContainsString("window.matchMedia('(pointer: fine)').matches", $source);
        $this->assertStringContainsString('x-on:workflow-preview-task-selected.stop', $source);
        $this->assertStringContainsString('const taskTarget = taskKey', $source);
        $this->assertStringContainsString('const target = taskTarget || stepTarget', $source);
        $this->assertStringContainsString('target?.focus({ preventScroll: true })', $source);
        $this->assertStringContainsString("@include('livewire.admin.network.partials.workflow-definition-editor'", $studioWrapper);
        $this->assertStringContainsString("@include('livewire.admin.network.partials.workflow-definition-editor'", $manager);
    }

    public function test_shared_left_library_searches_all_groups_without_changing_runtime_kind(): void
    {
        $root = dirname(__DIR__, 2);
        $source = file_get_contents($root.'/resources/views/livewire/admin/network/partials/workflow-definition-editor.blade.php');
        $styles = file_get_contents($root.'/resources/css/workflow-experience.css');
        $manager = file_get_contents($root.'/resources/views/livewire/admin/network/workflow-manager.blade.php');

        $this->assertStringContainsString('grid-cols-[minmax(0,1fr)]', $source);
        $this->assertStringContainsString('x-bind:data-library-expanded="libraryExpanded', $source);
        $this->assertStringContainsString('[data-studio-task-layout][data-library-expanded] {', $styles);
        $this->assertStringContainsString("[data-studio-task-layout][data-library-expanded='false']", $styles);
        $this->assertStringContainsString('grid-template-columns: 0 minmax(0, 1fr) !important;', $styles);
        $this->assertStringContainsString('visibility: hidden;', $styles);
        $this->assertStringContainsString('pointer-events: none;', $styles);
        $this->assertStringContainsString("window.localStorage.getItem('followflow.workflow-library-expanded')", $source);
        $this->assertStringContainsString('x-on:click="toggleLibrary()"', $source);
        $this->assertStringContainsString('Task-Bibliothek einklappen', $source);
        $this->assertStringContainsString('Bibliothek öffnen', $source);
        $this->assertStringContainsString('x-ref="libraryCollapseButton"', $source);
        $this->assertStringContainsString('x-ref="libraryOpenButton"', $source);
        $this->assertStringContainsString('target?.focus({ preventScroll: true });', $source);
        $this->assertStringContainsString('x-show.important="! libraryExpanded"', $source);
        $this->assertStringContainsString('this.setLibraryExpanded(true);', $source);
        $this->assertStringNotContainsString('xl:grid-cols-[72px_minmax(0,1fr)]', $source);
        $this->assertStringNotContainsString('[writing-mode:vertical-rl]', $source);
        $this->assertStringContainsString('id="{{ $fieldIdPrefix }}-studio-task-catalog-panel"', $source);
        $this->assertStringContainsString('w-full min-h-[480px] min-w-0 max-w-full', $source);
        $this->assertStringContainsString('w-full min-w-0 max-w-full shrink-0 flex-col', $source);
        $this->assertStringContainsString('wire:model.live.debounce.250ms="taskSearch"', $source);
        $this->assertStringContainsString('aria-label="Task-Suche leeren"', $source);
        $this->assertStringContainsString('wire:change="selectTaskGroup($event.target.value)"', $source);
        $this->assertStringContainsString('wire:click="selectTaskGroup(@js($taskGroup))"', $source);
        $this->assertStringContainsString('Gruppenübergreifende Suche', $source);
        $this->assertStringContainsString('min-h-[64px]', $source);
        $this->assertStringContainsString('wire:key="{{ $fieldIdPrefix }}-task-library-', $source);
        $this->assertStringContainsString('wire:target.except="taskSearch,selectTaskGroup,catalogTargetStepId"', $manager);
        $this->assertStringContainsString('data-task-library-group="{{ $taskDefinition[\'library_group\'] }}"', $source);
        $this->assertStringNotContainsString("collect(\$taskDefinitions)->where('kind', \$taskGroup)", $source);
        $this->assertStringContainsString("\$el.closest('.jetstream-modal')?.querySelector", $source);
        $this->assertStringContainsString(":id=\"\$routeMarkerId.'-edit-task-modal'\"", $source);
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

        foreach (['showAddStepModal', 'showEditStepModal', 'showAddTaskModal'] as $modal) {
            $markup = '<x-ui.dialog-modal wire:model="'.$modal.'"';

            $this->assertStringNotContainsString($markup, $manager);
            $this->assertSame(1, substr_count($source, $markup));
        }

        $this->assertStringNotContainsString('wire:model="showEditTaskModal"', $manager);
        $this->assertSame(1, substr_count($source, 'wire:model="showEditTaskModal"'));
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
