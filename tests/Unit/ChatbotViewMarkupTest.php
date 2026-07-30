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
        $this->assertStringContainsString('toggleRecordedVoice()', $definition);
        $this->assertStringContainsString('observeMessages()', $definition);
        $this->assertStringContainsString('new MutationObserver(() => {', $definition);
        $this->assertStringContainsString('new ResizeObserver(() => {', $definition);
        $this->assertStringContainsString('messagesNearBottom(threshold = 96)', $definition);
        $this->assertStringContainsString('jumpToLatest(smooth = true)', $definition);
        $this->assertStringContainsString("scrollBehavior(smooth = true)", $definition);
        $this->assertStringContainsString("window.matchMedia('(prefers-reduced-motion: reduce)')", $definition);
        $this->assertStringContainsString('if (!force && !this.messagesPinned)', $definition);
        $this->assertStringContainsString('transcribeRecordedBlob(blob)', $definition);
        $this->assertStringContainsString("['whisper_local', 'vosk'].includes(this.speechInputProvider)", $definition);
        $this->assertStringContainsString('[40, 100, 250, 500, 1000].map((delay)', $definition);
        $this->assertStringContainsString('handleNewAssistantMessages(history)', $definition);
        $this->assertStringContainsString('this.queueTtsSentence(item.content, index)', $definition);
        $this->assertStringNotContainsString("this.\$watch('isLoading', (loading) => {\n                if (loading) {\n                    this.stopSpeaking();", $definition);
        $this->assertMatchesRegularExpression(
            '/speak\\(text, index = null\\).*?this\\.stopSpeaking\\(\\);.*?this\\.queueTtsSentence\\(cleanText, index\\);/s',
            $definition,
        );
        $this->assertStringContainsString('setWorkflowImprovements(improvements = [])', $definition);
        $this->assertStringContainsString('applyImprovementHighlights()', $definition);
        $this->assertStringContainsString('openWorkflowImprovement(improvement = {})', $definition);
        $this->assertStringContainsString("new CustomEvent('assistant-open-workflow-improvement'", $definition);
        $this->assertStringNotContainsString("this.ttsPlaying = true;\n            this.speaking = true;", $definition);
        $this->assertStringContainsString('this.ttsPreparing = true;', $definition);
        $this->assertStringContainsString("isDesktopDocked: window.matchMedia('(min-width: 1140px)').matches", $definition);
        $this->assertStringContainsString("this.isDesktopDocked = window.matchMedia('(min-width: 1140px)').matches", $definition);
        $this->assertStringContainsString("window.matchMedia('(min-width: 1140px)')", $definition);
        $this->assertStringContainsString(
            'this.showChat = this.isDesktopDocked && (storedChatOpen || this.studioPinned);',
            $definition,
        );
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
        $this->assertStringContainsString("speechOutputProvider === 'piper_local'", $source);
        $this->assertStringContainsString('window.MediaRecorder', $source);
        $this->assertStringContainsString('speechRate: @js($assistantSpeechRate)', $source);
        $this->assertStringContainsString('clampSpeechRate(value)', $definition);
        $this->assertStringContainsString('speed: this.clampSpeechRate(this.speechRate)', $definition);
        $this->assertStringContainsString('Math.min(2, Math.max(0.5, normalized))', $definition);
        $this->assertStringContainsString('assistant-improvement-error', $source);
        $this->assertStringContainsString('assistant-improvement-warning', $source);
        $this->assertStringContainsString('assistant-improvement-info', $source);
        $this->assertStringContainsString("\$item['improvements']", $source);
        $this->assertStringContainsString('workflow-copilot-session-activated', $source);
        $this->assertStringContainsString('assistant-open-workflow-run-preview', $source);
        $this->assertStringContainsString('normalizeEventDetail($event)', $source);
        $this->assertStringContainsString('wire:poll.2s="pollCopilotSession"', $source);
        $this->assertStringContainsString('System-Ausfuehrung', $source);
        $this->assertStringContainsString('Aktuelle Arbeitsschritte', $source);
        $this->assertStringContainsString('wire:click="pauseCopilotSession"', $source);
        $this->assertStringContainsString('wire:click="resumeCopilotSession"', $source);
        $this->assertStringContainsString('wire:click="stopCopilotSession"', $source);
        $this->assertStringContainsString('wire:click="restartCopilotSession"', $source);
        $this->assertStringContainsString('wire:click="openCopilotRunPreview"', $source);
        $this->assertStringContainsString('workflow-copilot-docked', $source);
        $this->assertStringContainsString('desktopDocked()', $definition);
        $this->assertStringContainsString('syncDockLayout()', $definition);
        $this->assertStringContainsString('observeAssistantStatusStream()', $definition);
        $this->assertStringContainsString('assistantActivityRunning()', $definition);
        $this->assertStringContainsString('copilotActivityRunning()', $definition);
        $this->assertStringContainsString('copilotActivityIsStale()', $definition);
        $this->assertStringContainsString('activityElapsed(value)', $definition);
        $this->assertStringContainsString('xl:hidden', $source);
        $this->assertStringContainsString('xl:w-[30rem]', $source);
        $this->assertStringContainsString('@media (min-width: 1140px)', $source);
        $this->assertStringContainsString('role="log"', $source);
        $this->assertStringContainsString('ff-copilot-panel', $source);
        $this->assertStringContainsString('ff-jump-latest', $source);
        $this->assertStringContainsString('Audio wird vorbereitet.', $source);
        $this->assertStringContainsString('x-trap.inert.noscroll="showChat && !isDesktopDocked"', $source);
        $this->assertStringContainsString('x-on:click.stop.prevent="setOpen(true, true)"', $source);
        $this->assertStringContainsString('class="workflow-copilot"', $source);
        $this->assertStringContainsString('data-workflow-copilot-completed-state', $source);
        $this->assertStringContainsString('data-workflow-copilot-vision-analysis', $source);
        $this->assertStringContainsString('Vorgeschlagene Workflow-Aktionen', $source);
        $this->assertStringContainsString('data-assistant-active-work', $source);
        $this->assertStringContainsString('data-assistant-activity-timer', $source);
        $this->assertStringContainsString('data-copilot-activity-timer', $source);
        $this->assertStringContainsString('Keine Statusaenderung seit', $source);
        $this->assertStringContainsString('<template x-if="assistantActivityRunning() || copilotActivityRunning()">', $source);
    }

    public function test_audio_lifecycle_guards_against_reindexing_cancellation_and_teardown_races(): void
    {
        $root = dirname(__DIR__, 2);
        $source = file_get_contents($root.'/resources/views/livewire/tools/chatbot.blade.php');
        $anchorDropdown = file_get_contents($root.'/resources/views/components/ui/dropdown/anchor-dropdown.blade.php');

        $this->assertStringContainsString('assistantMessageKey(item) {', $source);
        $this->assertStringNotContainsString('assistantMessageKey(item, index)', $source);
        $this->assertStringContainsString("`message:\${item?.time || ''}|\${item?.content || ''}`", $source);
        $this->assertStringContainsString('const duplicateIsActive = this.ttsActive()', $source);
        $this->assertStringContainsString('const duplicateIsQueued = this.ttsQueue.some((item) => (', $source);

        $this->assertStringContainsString('ttsPlaybackCancel: null', $source);
        $this->assertStringContainsString('const cancelPlayback = () => {', $source);
        $this->assertStringContainsString('audio.onpause = () => {', $source);
        $this->assertStringContainsString('cancelPlayback();', $source);
        $this->assertStringContainsString('if (item.generation !== this.ttsCurrentGeneration) return;', $source);
        $this->assertStringContainsString('cancelPlayback?.();', $source);

        $this->assertStringContainsString('this.cancelVoiceCapture();', $source);
        $this->assertStringContainsString('voiceCaptureGeneration: 0', $source);
        $this->assertStringContainsString('this.voiceCaptureGeneration++;', $source);
        $this->assertStringContainsString('recorder.onstop = null;', $source);
        $this->assertStringContainsString('recognition.abort();', $source);
        $this->assertStringContainsString('signal: abortController.signal', $source);

        $this->assertStringContainsString('panel-role="dialog"', $source);
        $this->assertStringContainsString('aria-haspopup="dialog"', $source);
        $this->assertStringContainsString('resizeHandler: null', $anchorDropdown);
        $this->assertStringContainsString("window.removeEventListener('resize', this.resizeHandler)", $anchorDropdown);
        $this->assertStringContainsString('overflow-y-auto', $anchorDropdown);
        $this->assertStringContainsString('max-height:calc(100dvh - 16px)', $anchorDropdown);
    }

    public function test_chatbot_is_mounted_on_the_standalone_workflow_studio_route(): void
    {
        $layout = file_get_contents(dirname(__DIR__, 2).'/resources/views/layouts/master.blade.php');

        $this->assertStringContainsString(
            "request()->routeIs('network.workflows', 'network.workflows.manage', 'network.workflows.studio')",
            $layout,
        );
    }
}
