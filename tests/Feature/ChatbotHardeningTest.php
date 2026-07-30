<?php

namespace Tests\Feature;

use App\Livewire\Tools\Chatbot;
use ReflectionMethod;
use Tests\TestCase;

/**
 * Pinnt die Chatbot-Korrekturen vom 2026-07-30 (Spur C).
 *
 * Die geprueften Stellen sind fuer sich genommen unauffaellig und wuerden bei
 * einem Umbau still zurueckfallen — jeder Test haelt deshalb genau das
 * Fehlverhalten fest, das er verhindert.
 */
class ChatbotHardeningTest extends TestCase
{
    private function callPrivate(string $method, array $arguments): mixed
    {
        $reflection = new ReflectionMethod(Chatbot::class, $method);
        $reflection->setAccessible(true);

        return $reflection->invokeArgs(new Chatbot, $arguments);
    }

    /**
     * `take(-36)` schneidet rein positional. Bleibt eine `role=tool`-Antwort
     * ohne die `assistant`-Nachricht stehen, die sie per `tool_calls`
     * angefordert hat, lehnen OpenAI-kompatible Anbieter das gesamte
     * Transcript ab. Da es in der Session persistiert wird, waere der Chat
     * danach dauerhaft unbenutzbar.
     */
    public function test_trim_transcript_removes_tool_answers_that_lost_their_assistant_call(): void
    {
        $messages = [
            ['role' => 'tool', 'tool_call_id' => 'call-1', 'content' => 'Ergebnis A'],
            ['role' => 'tool', 'tool_call_id' => 'call-2', 'content' => 'Ergebnis B'],
            ['role' => 'user', 'content' => 'Wie geht es weiter?'],
            ['role' => 'assistant', 'content' => 'Ich pruefe das.'],
        ];

        $trimmed = $this->callPrivate('trimTranscript', [$messages]);

        $this->assertSame('user', $trimmed[0]['role'] ?? null, 'Verwaiste Tool-Antworten muessen am Anfang entfallen.');
        $this->assertCount(2, $trimmed);
    }

    public function test_trim_transcript_keeps_complete_tool_rounds_intact(): void
    {
        $messages = [
            ['role' => 'user', 'content' => 'Analysiere den Lauf.'],
            ['role' => 'assistant', 'content' => '', 'tool_calls' => [['id' => 'call-1']]],
            ['role' => 'tool', 'tool_call_id' => 'call-1', 'content' => 'Ergebnis'],
            ['role' => 'assistant', 'content' => 'Fertig.'],
        ];

        $trimmed = $this->callPrivate('trimTranscript', [$messages]);

        $this->assertCount(4, $trimmed, 'Eine vollstaendige Toolrunde darf nicht angetastet werden.');
        $this->assertSame('tool', $trimmed[2]['role']);
    }

    /**
     * Der Streaming-Parser legt jeden Tool-Call mit `'id' => ''` an. Der
     * frueher genutzte `??`-Operator greift bei Leerstring nicht, sodass die
     * Tool-Antwort keiner Anfrage mehr zuzuordnen war.
     */
    public function test_empty_streamed_tool_call_id_falls_back_to_a_stable_id(): void
    {
        $normalized = $this->callPrivate('normalizeToolCalls', [[
            ['id' => '', 'function' => ['name' => 'get_workflow_context', 'arguments' => '{}']],
        ]]);

        $this->assertNotSame('', $normalized[0]['id']);
        $this->assertSame('tool-call-0', $normalized[0]['id']);
        $this->assertSame('tool-call-0', $normalized[0]['raw']['id']);
    }

    /**
     * Die Steuermuster suchen ihren Treffer irgendwo im Text. Ohne Wortgrenze
     * verwirft eine ausformulierte Anweisung sich selbst und pausiert die
     * Sitzung sogar ohne Rueckfrage.
     */
    public function test_long_instructions_are_not_mistaken_for_control_commands(): void
    {
        $freitext = [
            'Warte auf das Modal und lass den Browser kurz anhalten',
            'Klicke im Dialog bitte auf den Knopf Abbrechen',
            'Nach dem Login den Lauf fortsetzen und dann das Suchfeld fuellen',
        ];

        foreach ($freitext as $message) {
            $this->assertNull(
                $this->callPrivate('copilotControlIntent', [$message]),
                "Freitext darf kein Steuerbefehl sein: {$message}",
            );
        }
    }

    public function test_short_control_commands_still_work(): void
    {
        $this->assertSame('pause', $this->callPrivate('copilotControlIntent', ['Bitte pausieren'])['action'] ?? null);
        $this->assertSame('resume', $this->callPrivate('copilotControlIntent', ['Bitte fortsetzen'])['action'] ?? null);
        $this->assertSame('stop', $this->callPrivate('copilotControlIntent', ['Stoppen'])['action'] ?? null);
    }

    public function test_internal_confirmation_tokens_bypass_the_word_limit(): void
    {
        $token = '3f1a2b4c-5d6e-7f80-9012-3456789abcde';

        $intent = $this->callPrivate('copilotControlIntent', ['__copilot_confirm_stop:'.$token]);

        $this->assertSame('stop', $intent['action'] ?? null);
        $this->assertTrue($intent['confirmed'] ?? false);
    }

    /**
     * Tool-Ereignisse liegen als public Property im Livewire-Snapshot und
     * werden per @entangle zusaetzlich nach Alpine gespiegelt. Roh-Argumente
     * und vollstaendige Tool-Ergebnisse haben dort nichts verloren — angezeigt
     * werden sie nie, redigiert stehen sie im Auditlog.
     */
    public function test_tool_events_carry_no_raw_payload_into_the_livewire_snapshot(): void
    {
        $chatbot = new Chatbot;
        $method = new ReflectionMethod(Chatbot::class, 'appendToolEvent');
        $method->setAccessible(true);

        $method->invokeArgs($chatbot, [
            'get_workflow_context',
            ['workflow_id' => 7, 'secret_hint' => 'nicht anzeigen'],
            ['ok' => true, 'message' => 'Kontext geladen.', 'steps' => range(1, 200)],
        ]);

        $event = $chatbot->toolEvents[0];

        $this->assertArrayNotHasKey('result', $event);
        $this->assertArrayNotHasKey('arguments', $event);
        $this->assertSame('get_workflow_context', $event['tool']);
        $this->assertSame('success', $event['status']);
        $this->assertSame('Kontext geladen.', $event['message']);
    }

    /**
     * Livewire schreibt gestreamte Inhalte per `innerHTML` in die Seite. Der
     * Toolname stammt unkontrolliert aus der Modellantwort — ohne Escaping
     * genuegt ein per Prompt-Injection gesetzter Name, um beliebiges JavaScript
     * in der Admin-Sitzung auszufuehren.
     */
    public function test_tool_status_stream_is_escaped_and_drops_the_model_supplied_name(): void
    {
        $source = file_get_contents(app_path('Livewire/Tools/Chatbot.php'));

        $this->assertStringContainsString(
            "e(\$this->assistantToolStatus(\$toolCall['name']))",
            $source,
            'Der Status-Stream muss escaped werden.',
        );
        $this->assertStringNotContainsString(
            "'Werkzeug '.\$toolName.' wird ausgefuehrt.'",
            $source,
            'Der unbekannte Toolname darf nicht in die Statuszeile interpoliert werden.',
        );
    }

    /**
     * Die Screenshot-URL stammt aus dem Node-Ergebnis und wird als `href`
     * gerendert. `javascript:` darf dort nie ankommen.
     */
    public function test_screenshot_url_rejects_non_http_schemes(): void
    {
        $reflection = new ReflectionMethod(Chatbot::class, 'copilotScreenshotUrl');
        $reflection->setAccessible(true);
        $chatbot = new Chatbot;

        $boesartig = [
            'javascript:fetch("/livewire/update")',
            'JaVaScRiPt:alert(1)',
            'data:text/html;base64,PHNjcmlwdD4=',
            '//evil.example.com/shot.png',
        ];

        foreach ($boesartig as $url) {
            $this->assertNull(
                $reflection->invokeArgs($chatbot, [['latest_screenshot_url' => $url]]),
                "Unsicheres Schema muss verworfen werden: {$url}",
            );
        }

        $this->assertSame(
            '/storage/screenshots/1.png',
            $reflection->invokeArgs($chatbot, [['latest_screenshot_url' => '/storage/screenshots/1.png']]),
        );
        $this->assertSame(
            'https://example.test/shot.png',
            $reflection->invokeArgs($chatbot, [['latest_screenshot_url' => 'https://example.test/shot.png']]),
        );
    }

    /**
     * `tailwind.config.js` setzt `important: true`. Ein nacktes `x-show` auf
     * einem Element mit Display-Utility laesst sich dadurch nicht ausblenden —
     * sichtbar etwa als leeres rotes Audio-Fehlerbanner.
     */
    public function test_hideable_elements_use_the_important_modifier(): void
    {
        $blade = file_get_contents(resource_path('views/livewire/tools/chatbot.blade.php'));

        $this->assertStringContainsString('x-show.important="ttsError"', $blade);
        $this->assertStringContainsString('x-show.important="speechSupported"', $blade);
        $this->assertStringNotContainsString('x-show="ttsError"', $blade);
        $this->assertStringNotContainsString('x-show="speechSupported"', $blade);
    }

    /**
     * Der Verlauf liegt in `<template x-if="showChat">`. Ohne Neubindung beim
     * Oeffnen bleibt der MutationObserver ungebunden und der Chat folgt keiner
     * Streaming-Antwort mehr.
     */
    public function test_message_observer_is_rebound_when_the_panel_opens(): void
    {
        $blade = file_get_contents(resource_path('views/livewire/tools/chatbot.blade.php'));

        $this->assertMatchesRegularExpression(
            "/\\\$watch\('showChat'.*?observeMessages\(\)/s",
            $blade,
            'Der showChat-Watcher muss observeMessages() erneut aufrufen.',
        );
    }
}
