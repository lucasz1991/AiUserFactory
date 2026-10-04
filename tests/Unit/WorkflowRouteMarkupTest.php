<?php

namespace Tests\Unit;

use DOMDocument;
use DOMXPath;
use PHPUnit\Framework\TestCase;

class WorkflowRouteMarkupTest extends TestCase
{
    public function test_tasks_have_error_feedback_but_no_route_disclosures_or_hover_reflow(): void
    {
        $card = file_get_contents(dirname(__DIR__, 2).'/resources/views/components/workflows/step-card.blade.php');
        $this->assertSame(1, preg_match('/x-on:focus\.self="([^"]*)"/', $card, $focus));
        $this->assertStringNotContainsString('setActiveRouteNode', $focus[1]);
        $this->assertStringContainsString('x-on:keydown.enter.self.prevent.stop="setActiveRouteNode', $card);
        $this->assertStringContainsString('x-on:keydown.space.self.prevent.stop="setActiveRouteNode', $card);
        $this->assertStringContainsString('x-bind:aria-pressed="activeRouteNode ===', $card);
        $feedback = file_get_contents(dirname(__DIR__, 2).'/resources/views/components/workflows/task-feedback.blade.php');
        $this->assertStringNotContainsString('<details', $feedback);
        $this->assertStringNotContainsString('data-task-routes', $feedback);
        $this->assertStringContainsString('data-task-error', $feedback);
        $this->assertStringNotContainsString('routeFocusNode()', $feedback);
        $this->assertStringNotContainsString('hover:h-', $card);
        $this->assertStringNotContainsString('x-show.important="!routeFocusNode()', $card);
        $this->assertStringContainsString('data-workflow-task-gap', $card);
    }

    public function test_standard_editor_routes_use_shared_surface_mobile_focus_and_livewire_refresh(): void
    {
        $surface = file_get_contents(dirname(__DIR__, 2).'/resources/js/components/workflow-route-surface.js');
        $editor = file_get_contents(dirname(__DIR__, 2).'/resources/views/livewire/admin/network/partials/workflow-definition-editor.blade.php');

        $this->assertStringContainsString('export function workflowRouteSurface', $surface);
        $this->assertStringContainsString("window.matchMedia('(max-width: 767px)')", $surface);
        $this->assertStringContainsString('this.showAllRoutes = false;', $surface);
        $this->assertStringContainsString('line.sourceNode === focusNode', $surface);
        $this->assertStringContainsString("window.Livewire.hook('morphed'", $surface);
        $this->assertStringNotContainsString("window.Livewire.hook('morph.updated'", $surface);
        $this->assertStringContainsString('new ResizeObserver(() => this.queueRouteRefresh())', $surface);
        $this->assertStringContainsString('workflowRouteSurface({', $editor);
        $this->assertStringContainsString('data-workflow-route-surface', $editor);
        $this->assertStringContainsString('x-ref="routeMap"', $editor);
        $this->assertStringContainsString('Alle Verbindungen', $editor);
        $this->assertStringContainsString('data-workflow-route-node="terminal::end"', $editor);
        $this->assertStringContainsString('data-workflow-route-node="terminal::fail"', $editor);
    }

    public function test_preview_routes_use_the_same_focus_and_corridor_behavior(): void
    {
        $source = file_get_contents(dirname(__DIR__, 2).'/resources/views/components/workflows/minimap.blade.php');
        $definition = $this->alpineDefinitionContaining($source, 'workflowRouteSurface');

        $this->assertStringContainsString('initialNode: @js($activeRouteNode)', $definition);
        $this->assertStringContainsString('...workflowRouteSurface({', $definition);
        $this->assertStringNotContainsString('refreshRouteLines() {', $definition, 'Keine zweite Geometrie-Implementierung.');
        $this->assertStringContainsString('x-ref="routeMap"', $source);
        $this->assertStringContainsString('data-workflow-task-node="{{ $taskNode }}"', $source);
        $this->assertStringContainsString('data-workflow-column-gap', $source);
        $this->assertStringNotContainsString('x-show.important="!routeFocusNode()', $source);
        $this->assertStringNotContainsString('hover:-translate', $source);
        $this->assertStringNotContainsString('taskRouteBadge', $source);
        $this->assertStringContainsString('data-minimap-step-column', $source);
        $this->assertStringNotContainsString('const laneY = Math.max(4', $definition);
        $this->assertStringEndsWith('}', trim($definition));
    }

    /** Ohne Auswahl bleiben auch aeltere Linien sichtbar; Fokus filtert nur Quellen. */
    public function test_preview_route_opacity_encodes_age_and_never_reaches_zero(): void
    {
        $source = file_get_contents(dirname(__DIR__, 2).'/resources/views/components/workflows/minimap.blade.php');
        $surface = file_get_contents(dirname(__DIR__, 2).'/resources/js/components/workflow-route-surface.js');

        $this->assertStringContainsString("\$routeEvent['ageOpacity']", $source);
        $this->assertStringContainsString('line.ageOpacity', $surface);
        $this->assertStringContainsString('Math.max(0.35', $surface);
        $this->assertStringContainsString('if (focusNode && !related) return', $surface);
    }

    public function test_manager_cards_drive_hover_and_active_route_focus(): void
    {
        $source = file_get_contents(dirname(__DIR__, 2).'/resources/views/components/workflows/step-card.blade.php');

        $this->assertStringContainsString('x-on:mouseenter="setHoveredRouteNode(', $source);
        $this->assertStringContainsString('x-on:mouseleave="setHoveredRouteNode(\'\')"', $source);
        $this->assertStringContainsString('setActiveRouteNode(', $source);
        $this->assertStringContainsString('data-workflow-task-gap', $source);
    }

    public function test_minimap_zoom_uses_semantic_density_and_recalculates_route_geometry(): void
    {
        $source = file_get_contents(dirname(__DIR__, 2).'/resources/views/components/workflows/minimap.blade.php');
        $definition = $this->alpineDefinitionContaining($source, 'workflowRouteSurface');

        $this->assertStringContainsString("['overview', 'standard', 'detail']", $definition);
        $this->assertStringContainsString('setZoom(level)', $definition);
        $this->assertStringContainsString('this.$nextTick(() => this.queueRouteRefresh())', $definition);
        $this->assertStringContainsString('data-workflow-minimap-zoom-level="{{ $zoomKey }}"', $source);
        $this->assertStringContainsString('x-on:click.stop="setZoom(@js($zoomKey))"', $source);
        $this->assertStringContainsString('data-workflow-minimap-instance="{{ $mapInstance }}"', $source);
        $this->assertStringContainsString('data-workflow-minimap-source="{{ $mapSource }}"', $source);
        $this->assertStringContainsString('instance: this.instance', $definition);
        $this->assertStringContainsString('source: this.source', $definition);
        $this->assertStringContainsString('refreshForEvent(detail = {})', $definition);
        $this->assertStringContainsString("requestedInstance !== '' && requestedInstance === this.instance", $definition);
        $this->assertStringContainsString("requestedSource !== '' && requestedSource === this.source", $definition);
        $this->assertStringContainsString('! this.isRenderable()', $definition);
        $this->assertStringContainsString('x-on:workflow-minimap-refresh-requested.window="refreshForEvent($event.detail)"', $source);
        $this->assertStringContainsString("zoomLevel === 'overview' ? 'w-36'", $source);
        $this->assertStringContainsString("'w-48' : 'w-56'", $source);
        $this->assertStringNotContainsString('transform: scale(', $source);
        $this->assertStringNotContainsString('zoomist', strtolower($source));
    }

    public function test_minimap_supports_static_workflow_routes_and_unique_instances(): void
    {
        $source = file_get_contents(dirname(__DIR__, 2).'/resources/views/components/workflows/minimap.blade.php');

        $this->assertStringContainsString("'workflowRun' => null", $source);
        $this->assertStringContainsString("'workflow' => null", $source);
        $this->assertStringContainsString('$workflow = $workflow ?: $workflowRun?->workflow', $source);
        $this->assertStringContainsString('$configuredRouteEvents', $source);
        $this->assertStringContainsString("'configured' => true", $source);
        $this->assertStringContainsString('WorkflowRouteMapPresenter::class', $source);
        $this->assertStringContainsString('WorkflowRouteMapPresenter::MODE_COMBINED', $source);
        $markers = file_get_contents(dirname(__DIR__, 2).'/resources/views/components/workflows/route-markers.blade.php');
        $this->assertStringContainsString('<x-workflows.route-markers', $source);
        $this->assertStringContainsString("'partial' => '#3b82f6'", $markers);
        $this->assertStringContainsString("'timeout' => '#8b5cf6'", $markers);
        $this->assertStringContainsString('markerUnits="userSpaceOnUse"', $markers);
        $this->assertStringContainsString('data-minimap-node="terminal::end"', $source);
        $this->assertStringContainsString('data-minimap-node="terminal::fail"', $source);
        $this->assertStringContainsString('Str::slug($mapInstance)', $source);
        $this->assertStringContainsString("'source' => null", $source);
        $this->assertStringContainsString('$minimapEventSource = $source;', $source);
        $this->assertStringContainsString('$mapSource = trim((string) ($minimapEventSource ?: $mapInstance));', $source);
        $this->assertStringContainsString('taskKey: @js($taskKey), instance, source', $source);
        $this->assertStringContainsString('x-on:keydown.space.prevent.stop', $source);
        $this->assertStringContainsString('aria-pressed="{{ $isTaskSelected ? \'true\' : \'false\' }}"', $source);
        $this->assertStringNotContainsString('aria-selected=', $source);
        $this->assertStringContainsString('min-h-11 cursor-pointer touch-manipulation', $source);
        $this->assertStringContainsString("in_array(\$outcome, ['failed', 'timeout'], true) && is_array(\$task['on_error'] ?? null)", $source);
    }

    public function test_test_window_shows_selectable_definition_map_before_first_run(): void
    {
        $source = file_get_contents(dirname(__DIR__, 2).'/resources/views/livewire/admin/network/workflow-studio/browser.blade.php');

        $this->assertStringContainsString('@if(! $historicalRunView)', $source);
        $this->assertStringContainsString('<livewire:admin.network.workflow-studio-task-editor', $source);
        $this->assertStringContainsString(':workflow="$workflow"', $source);
        $this->assertStringContainsString(':selectable-tasks="! $autonomousMode"', $source);
        $this->assertStringContainsString('initial-zoom="overview"', $source);
        $this->assertStringContainsString('Doppelklick öffnet direkt die gemeinsamen Task-Einstellungen.', $source);
    }

    private function alpineDefinitionContaining(string $source, string $needle): string
    {
        $document = new DOMDocument;

        libxml_use_internal_errors(true);
        $document->loadHTML($source);
        libxml_clear_errors();

        foreach ((new DOMXPath($document))->query('//*[@x-data]') as $node) {
            $definition = $node->getAttribute('x-data');

            if (str_contains($definition, $needle)) {
                return $definition;
            }
        }

        $this->fail('Passende Alpine-Komponente wurde nicht gefunden.');
    }
}
