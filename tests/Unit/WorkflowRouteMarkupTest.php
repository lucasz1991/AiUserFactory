<?php

namespace Tests\Unit;

use DOMDocument;
use DOMXPath;
use PHPUnit\Framework\TestCase;

class WorkflowRouteMarkupTest extends TestCase
{
    public function test_manager_routes_use_local_corridors_and_card_focus(): void
    {
        $source = file_get_contents(dirname(__DIR__, 2).'/resources/views/livewire/admin/network/workflow-manager.blade.php');
        $definition = $this->alpineDefinitionContaining($source, 'focusedTask:');

        $this->assertStringContainsString('renderRouteLines()', $definition);
        $this->assertStringContainsString('const adjacentSteps', $definition);
        $this->assertStringContainsString('const corridorY = lowerCost < upperCost ? lowerY : upperY', $definition);
        $this->assertStringContainsString('line.sourceNode === focusNode || line.targetNode === focusNode', $definition);
        $this->assertStringContainsString('related ? 1 : 0.5', $definition);
        $this->assertStringNotContainsString('const topLaneY = 18', $definition);
        $this->assertStringEndsWith('}', trim($definition));
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
