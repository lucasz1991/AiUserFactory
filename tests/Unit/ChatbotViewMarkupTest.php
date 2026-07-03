<?php

namespace Tests\Unit;

use DOMDocument;
use DOMXPath;
use PHPUnit\Framework\TestCase;

class ChatbotViewMarkupTest extends TestCase
{
    public function test_alpine_component_definition_is_not_truncated_by_html_attribute_quotes(): void
    {
        $source = file_get_contents(dirname(__DIR__, 2).'/resources/views/livewire/tools/chatbot.blade.php');
        $document = new DOMDocument;

        libxml_use_internal_errors(true);
        $document->loadHTML($source);
        libxml_clear_errors();

        $root = (new DOMXPath($document))->query('//*[@data-workflow-copilot-root]')->item(0);

        $this->assertNotNull($root);

        $definition = $root->getAttribute('x-data');

        $this->assertStringContainsString('highlightElement(action = {})', $definition);
        $this->assertStringContainsString('refreshWorkflowPage()', $definition);
        $this->assertStringContainsString('audio.onplaying = () => {', $definition);
        $this->assertStringNotContainsString("this.ttsPlaying = true;\n            this.speaking = true;", $definition);
        $this->assertStringEndsWith('}', trim($definition));

        $activeSpeechLabels = (new DOMXPath($document))->query(
            '//*[@x-show="speaking" and contains(normalize-space(.), "Wird gerade vorgelesen.")]',
        );

        $this->assertCount(1, $activeSpeechLabels);
        $this->assertStringNotContainsString('Automatisch vorlesen', $source);
    }
}
