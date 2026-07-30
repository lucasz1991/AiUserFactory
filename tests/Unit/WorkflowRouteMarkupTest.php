<?php

namespace Tests\Unit;

use DOMDocument;
use DOMXPath;
use PHPUnit\Framework\TestCase;

class WorkflowRouteMarkupTest extends TestCase
{
    public function test_standard_editor_routes_use_shared_surface_mobile_focus_and_livewire_refresh(): void
    {
        $surface = file_get_contents(dirname(__DIR__, 2).'/resources/js/components/workflow-route-surface.js');
        $editor = file_get_contents(dirname(__DIR__, 2).'/resources/views/livewire/admin/network/partials/workflow-definition-editor.blade.php');

        $this->assertStringContainsString('export function workflowRouteSurface', $surface);
        $this->assertStringContainsString("window.matchMedia('(max-width: 767px)')", $surface);
        $this->assertStringContainsString('this.showAllRoutes = !event.matches', $surface);
        $this->assertStringContainsString('line.sourceNode === focusNode || line.targetNode === focusNode', $surface);
        $this->assertStringContainsString("window.Livewire.hook('morph.updated'", $surface);
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
        $definition = $this->alpineDefinitionContaining($source, 'routeEvents:');

        $this->assertStringContainsString('activeRouteNode: @js($activeRouteNode)', $definition);
        $this->assertStringContainsString('setHoveredRouteNode(node = \'\')', $definition);
        $this->assertStringContainsString('const adjacentSteps', $definition);
        $this->assertStringContainsString('line.sourceNode === focusNode || line.targetNode === focusNode', $definition);
        $this->assertStringContainsString('data-minimap-step-column', $source);
        $this->assertStringNotContainsString('const laneY = Math.max(4', $definition);
        $this->assertStringEndsWith('}', trim($definition));
    }

    /**
     * Feature R3: In der Vorschau bestimmt zusaetzlich das Alter der Linie die
     * Deckkraft. Der Hover-Fokus daempft nur noch, statt fest auf 1 / 0.5 zu
     * setzen — und keine Linie darf dabei unsichtbar werden.
     */
    public function test_preview_route_opacity_encodes_age_and_never_reaches_zero(): void
    {
        $source = file_get_contents(dirname(__DIR__, 2).'/resources/views/components/workflows/minimap.blade.php');
        $definition = $this->alpineDefinitionContaining($source, 'routeEvents:');

        $this->assertStringContainsString('line.ageOpacity', $definition);
        $this->assertStringContainsString('Math.max(0.35', $definition, 'Untergrenze fuer die Alters-Deckkraft fehlt.');
        $this->assertStringContainsString('Math.max(0.28', $definition, 'Auch unfokussierte Linien bleiben sichtbar.');
        $this->assertStringNotContainsString('related ? 1 : 0.5', $definition, 'Die alte, altersblinde Deckkraft ist ersetzt.');
    }

    public function test_manager_cards_drive_hover_and_active_route_focus(): void
    {
        $source = file_get_contents(dirname(__DIR__, 2).'/resources/views/components/workflows/step-card.blade.php');

        $this->assertStringContainsString('x-on:mouseenter="setHoveredRouteNode(', $source);
        $this->assertStringContainsString('x-on:mouseleave="setHoveredRouteNode(\'\')"', $source);
        $this->assertStringContainsString('setActiveRouteNode(', $source);
        $this->assertStringContainsString("'opacity-50'", $source);
    }

    public function test_minimap_zoom_uses_semantic_density_and_recalculates_route_geometry(): void
    {
        $source = file_get_contents(dirname(__DIR__, 2).'/resources/views/components/workflows/minimap.blade.php');
        $definition = $this->alpineDefinitionContaining($source, 'routeEvents:');

        $this->assertStringContainsString("['overview', 'standard', 'detail']", $definition);
        $this->assertStringContainsString('setZoom(level)', $definition);
        $this->assertStringContainsString('this.$nextTick(() => this.refreshRouteLines())', $definition);
        $this->assertStringContainsString('new ResizeObserver(() => this.refreshRouteLines())', $definition);
        $this->assertStringContainsString('data-workflow-minimap-zoom-level="{{ $zoomKey }}"', $source);
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
        $this->assertStringContainsString('arrow-runtime', $source);
        $this->assertStringContainsString('data-minimap-node="terminal::end"', $source);
        $this->assertStringContainsString('data-minimap-node="terminal::fail"', $source);
        $this->assertStringContainsString('Str::slug($mapInstance)', $source);
        $this->assertStringContainsString('x-on:keydown.space.prevent.stop', $source);
        $this->assertStringContainsString('aria-pressed="{{ $isTaskSelected ? \'true\' : \'false\' }}"', $source);
        $this->assertStringNotContainsString('aria-selected=', $source);
        $this->assertStringContainsString('min-h-11 cursor-pointer touch-manipulation', $source);
        $this->assertStringContainsString("in_array(\$outcome, ['failed', 'timeout'], true) && is_array(\$task['on_error'] ?? null)", $source);
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
