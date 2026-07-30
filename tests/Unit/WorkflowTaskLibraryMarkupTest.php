<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

class WorkflowTaskLibraryMarkupTest extends TestCase
{
    public function test_builder_has_mobile_panel_switch_group_selector_and_shared_overview(): void
    {
        $root = dirname(__DIR__, 2);
        $source = file_get_contents($root.'/resources/views/livewire/admin/network/workflow-studio-task-editor.blade.php');

        $this->assertStringContainsString("mobilePanel: 'canvas'", $source);
        $this->assertStringContainsString('data-studio-mobile-switch', $source);
        $this->assertStringContainsString('id="studio-task-group-mobile"', $source);
        $this->assertStringContainsString('data-studio-editor-overview', $source);
        $this->assertStringContainsString('<x-workflows.minimap', $source);
        $this->assertStringContainsString(':workflow="$workflow"', $source);
        $this->assertStringContainsString(':zoomable="true"', $source);
        $this->assertStringContainsString("initial-zoom=\"overview\"", $source);
        $this->assertStringContainsString("window.matchMedia('(pointer: fine)').matches", $source);
        $this->assertStringContainsString('x-on:workflow-preview-task-selected.stop', $source);
    }

    public function test_manager_searches_all_groups_without_changing_runtime_kind(): void
    {
        $root = dirname(__DIR__, 2);
        $source = file_get_contents($root.'/resources/views/livewire/admin/network/workflow-manager.blade.php');

        $this->assertStringContainsString("\$taskDefinition['library_group_label']", $source);
        $this->assertStringContainsString("\$taskDefinition['library_group_description']", $source);
        $this->assertStringContainsString("\$taskDefinition['library_group'] === \$activeTaskGroup", $source);
        $this->assertStringContainsString("\$taskDefinition['library_group_short_label']", $source);
        $this->assertStringNotContainsString("collect(\$taskDefinitions)->where('kind', \$taskGroup)", $source);
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
