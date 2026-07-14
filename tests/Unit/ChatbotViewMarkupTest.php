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
        $this->assertStringContainsString('voiceProviderSupported()', $definition);
        $this->assertStringContainsString('toggleVoskVoice()', $definition);
        $this->assertStringContainsString('transcribeVoskBlob(blob)', $definition);
        $this->assertStringContainsString('setWorkflowImprovements(improvements = [])', $definition);
        $this->assertStringContainsString('applyImprovementHighlights()', $definition);
        $this->assertStringContainsString('openWorkflowImprovement(improvement = {})', $definition);
        $this->assertStringContainsString("new CustomEvent('assistant-open-workflow-improvement'", $definition);
        $this->assertStringNotContainsString("this.ttsPlaying = true;\n            this.speaking = true;", $definition);
        $this->assertStringEndsWith('}', trim($definition));

        $activeSpeechLabels = (new DOMXPath($document))->query(
            '//template[@x-if="speaking"]/*[contains(normalize-space(.), "Wird gerade vorgelesen.")]',
        );

        $this->assertCount(1, $activeSpeechLabels);
        $this->assertStringNotContainsString('Automatisch vorlesen', $source);
        $this->assertStringNotContainsString('x-show="speaking"', $source);
        $this->assertStringNotContainsString('workflow-assistant-speech-rate', $source);
        $this->assertStringContainsString("route('assistant.audio-input.transcribe'", $source);
        $this->assertStringContainsString('speechInputProvider: @js($assistantSpeechInputProvider)', $source);
        $this->assertStringContainsString('speechOutputProvider: @js($assistantSpeechOutputProvider)', $source);
        $this->assertStringContainsString('window.MediaRecorder', $source);
        $this->assertStringContainsString('speechRate: @js($assistantSpeechRate)', $source);
        $this->assertStringContainsString('speed: Number(this.speechRate || 1)', $definition);
        $this->assertStringContainsString('assistant-improvement-error', $source);
        $this->assertStringContainsString('assistant-improvement-warning', $source);
        $this->assertStringContainsString('assistant-improvement-info', $source);
        $this->assertStringContainsString("\$item['improvements']", $source);
        $this->assertStringContainsString('workflow-copilot-session-activated', $source);
        $this->assertStringContainsString('normalizeEventDetail($event)', $source);
        $this->assertStringContainsString('wire:poll.2s="pollCopilotSession"', $source);
        $this->assertStringContainsString('System-Ausfuehrung', $source);
        $this->assertStringContainsString('Aktuelle Arbeitsschritte', $source);
        $this->assertStringContainsString('wire:click="pauseCopilotSession"', $source);
        $this->assertStringContainsString('wire:click="resumeCopilotSession"', $source);
        $this->assertStringContainsString('wire:click="stopCopilotSession"', $source);
    }
}
