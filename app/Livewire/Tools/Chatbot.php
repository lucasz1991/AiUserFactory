<?php

namespace App\Livewire\Tools;

use App\Jobs\WorkflowCopilotSupervisorJob;
use App\Models\Setting;
use App\Models\WorkflowCopilotSession;
use App\Services\Ai\AiConnectionService;
use App\Services\Ai\WorkflowAssistantToolService;
use App\Services\Workflows\WorkflowCopilotSessionService;
use App\Services\Workflows\WorkflowExecutionService;
use App\Services\Workflows\WorkflowTransferService;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Session;
use Illuminate\Support\Str;
use Livewire\Component;
use Livewire\WithFileUploads;

class Chatbot extends Component
{
    use WithFileUploads;

    private const DISPLAY_HISTORY_KEY = 'ai_user_factory_chatbot_display_history';

    private const TRANSCRIPT_KEY = 'ai_user_factory_chatbot_transcript';

    private const COPILOT_SESSION_KEY = 'ai_user_factory_active_workflow_copilot_session';

    private const COPILOT_CLEARED_SEQUENCES_KEY = 'ai_user_factory_workflow_copilot_cleared_sequences';

    public string $message = '';

    public array $chatHistory = [];

    public array $toolEvents = [];

    public array $pageContext = [];

    public ?int $activeCopilotSessionId = null;

    public int $copilotLastEventSequence = 0;

    public array $copilotStatus = [];

    public array $copilotEventFeed = [];

    public bool $isLoading = false;

    public bool $assistantEnabled = true;

    public string $assistantName = 'Workflow Copilot';

    public bool $assistantAutoReadDefault = false;

    public float $assistantSpeechRate = 1.0;

    public string $assistantSpeechInputProvider = 'browser';

    public string $assistantSpeechOutputProvider = 'ai';

    public mixed $workflowImportFile = null;

    protected $listeners = [
        'sendMessage' => 'sendMessage',
        'assistantQuickAction' => 'quickAction',
    ];

    public function mount(): void
    {
        $settings = $this->assistantSettings();

        $this->assistantEnabled = (bool) ($settings['enabled'] ?? true);
        $this->assistantName = trim((string) ($settings['name'] ?? 'Workflow Copilot')) ?: 'Workflow Copilot';
        $this->assistantAutoReadDefault = (bool) ($settings['auto_read_default'] ?? false);
        $this->assistantSpeechRate = max(0.5, min(2.0, (float) ($settings['speech_rate'] ?? 1.0)));
        $this->assistantSpeechInputProvider = $this->normalizeSpeechInputProvider($settings['speech_input_provider'] ?? 'browser');
        $this->assistantSpeechOutputProvider = $this->normalizeSpeechOutputProvider($settings['speech_output_provider'] ?? 'ai');
        $this->chatHistory = Session::get(self::DISPLAY_HISTORY_KEY, []);
        $this->toolEvents = [];
        $this->pageContext = $this->initialPageContext();
        $this->restoreCopilotSession();
    }

    public function render()
    {
        return view('livewire.tools.chatbot');
    }

    public function updatePageContext(array $context): void
    {
        $this->pageContext = $this->normalizePageContext([
            ...$this->pageContext,
            ...$context,
        ]);

        if ($this->pageContext['workflow_id']) {
            $this->attachLatestCopilotSession((int) $this->pageContext['workflow_id']);
        }
    }

    public function quickAction(string $prompt): void
    {
        $this->message = $prompt;
        $this->sendMessage();
    }

    public function sendChatOption(int $messageIndex, int $optionIndex): void
    {
        $message = $this->chatHistory[$messageIndex] ?? null;
        $option = is_array($message) ? data_get($message, "options.{$optionIndex}") : null;

        if (
            ! is_array($message)
            || ($message['role'] ?? null) !== 'assistant'
            || (array_key_exists('selected_option_index', $message) && $message['selected_option_index'] !== null)
            || ! is_array($option)
            || blank($option['prompt'] ?? null)
        ) {
            return;
        }

        $this->chatHistory[$messageIndex]['selected_option_index'] = $optionIndex;
        Session::put(self::DISPLAY_HISTORY_KEY, $this->chatHistory);
        $this->sendMessage((string) $option['prompt']);
    }

    public function sendMessage(?string $clientMessage = null): void
    {
        if ($clientMessage !== null) {
            $this->message = $clientMessage;
        }

        $userMessage = trim($this->message);

        if ($userMessage === '') {
            return;
        }

        $this->message = '';
        $this->isLoading = true;
        $this->appendDisplayMessage('user', $this->displayMessageForUserPrompt($userMessage));

        try {
            $copilotSession = $this->activeCopilotSession();

            if ($copilotSession && $this->isCopilotSessionActive($copilotSession)) {
                $sessionService = app(WorkflowCopilotSessionService::class);
                $sessionService->appendEvent(
                    $copilotSession,
                    'chat.user',
                    'Benutzer hat eine Nachricht im Workflow-Copilot-Chat gesendet.',
                    ['content' => $userMessage, 'user_id' => Auth::id(), 'source' => 'workflow-copilot-chat'],
                    'conversation',
                );
                $sessionService->instruction($copilotSession, $userMessage, [
                    'user_id' => Auth::id(),
                    'source' => 'workflow-copilot-chat',
                ]);
                WorkflowCopilotSupervisorJob::dispatch((int) $copilotSession->getKey());
                $acknowledgement = 'Die Anweisung wurde gespeichert und wird am naechsten sicheren Checkpoint beruecksichtigt.';
                $sessionService->appendEvent(
                    $copilotSession,
                    'chat.assistant',
                    'Workflow-Copilot hat die Benutzeranweisung bestaetigt.',
                    ['content' => $acknowledgement],
                    'conversation',
                );
                $this->appendDisplayMessage(
                    'assistant',
                    $acknowledgement,
                    'success',
                );
                $this->pollCopilotSession();

                return;
            }

            if (! $this->assistantEnabled) {
                $this->appendDisplayMessage('assistant', 'Der AI Workflow Copilot ist in den Einstellungen deaktiviert.', 'error');

                return;
            }

            $response = $this->runAssistantConversation($userMessage);

            $this->appendDisplayMessage(
                'assistant',
                trim((string) ($response['message'] ?? '')) ?: 'Ich habe dazu gerade keine belastbare Antwort erhalten.',
                'neutral',
                $response['chat_options'] ?? null,
                $response['improvements'] ?? null,
            );

            if (is_array($response['ui_action'] ?? null)) {
                $this->dispatch('assistant-ui-action', action: $response['ui_action']);
            }

            if ((bool) ($response['refresh_page'] ?? false)) {
                $this->dispatch('assistant-workflow-page-refresh');
            }
        } catch (\Throwable $exception) {
            Log::warning('AI User Factory Chatbot fehlgeschlagen.', [
                'error' => $exception->getMessage(),
            ]);

            $this->appendDisplayMessage(
                'assistant',
                'Der AI Workflow Copilot konnte die Anfrage nicht abschliessen: '.$exception->getMessage(),
                'error',
            );
        } finally {
            $this->isLoading = false;
        }
    }

    public function importWorkflowUpdate(WorkflowTransferService $transferService): void
    {
        $this->validate([
            'workflowImportFile' => ['required', 'file', 'max:10240'],
        ]);

        try {
            $result = $transferService->importFile(
                $this->workflowImportFile->getRealPath(),
                $this->workflowImportFile->getClientOriginalName(),
            );

            $this->workflowImportFile = null;

            $this->appendDisplayMessage(
                'assistant',
                'Workflow-Import abgeschlossen: '.$result['total'].' verarbeitet, '.$result['created'].' neu, '.$result['updated'].' aktualisiert.',
                'success',
            );
            $this->dispatch('assistant-workflow-page-refresh');
        } catch (\Throwable $exception) {
            $this->addError('workflowImportFile', $exception->getMessage());
            $this->appendDisplayMessage('assistant', 'Workflow-Import fehlgeschlagen: '.$exception->getMessage(), 'error');
        }
    }

    public function clearChat(): void
    {
        Session::forget([self::DISPLAY_HISTORY_KEY, self::TRANSCRIPT_KEY]);

        if ($session = $this->activeCopilotSession()) {
            $clearedSequences = Session::get(self::COPILOT_CLEARED_SEQUENCES_KEY, []);
            $clearedSequences = is_array($clearedSequences) ? $clearedSequences : [];
            $clearedSequences[(string) $session->getKey()] = max(
                $this->copilotLastEventSequence,
                (int) ($session->last_event_sequence ?? 0),
            );
            Session::put(self::COPILOT_CLEARED_SEQUENCES_KEY, $clearedSequences);
            $this->copilotLastEventSequence = $clearedSequences[(string) $session->getKey()];
        }

        $this->chatHistory = [];
        $this->toolEvents = [];
        $this->copilotEventFeed = [];
        $this->message = '';
        $this->workflowImportFile = null;
        $this->dispatch('assistant-ui-action', action: [
            'type' => 'highlight_workflow_improvements',
            'improvements' => [],
        ]);
    }

    public function attachCopilotSession(int $sessionId): void
    {
        $session = WorkflowCopilotSession::query()->with('workflow')->find($sessionId);

        if (! $session) {
            return;
        }

        $this->activeCopilotSessionId = (int) $session->getKey();
        Session::put(self::COPILOT_SESSION_KEY, $this->activeCopilotSessionId);
        $this->restoreCopilotProjection($session);

        if ($this->isCopilotSessionActive($session)) {
            $this->pollCopilotSession();
        }
    }

    public function pollCopilotSession(): void
    {
        $session = $this->activeCopilotSession();

        if (! $session) {
            $this->activeCopilotSessionId = null;
            $this->copilotStatus = [];
            $this->copilotEventFeed = [];
            Session::forget(self::COPILOT_SESSION_KEY);

            return;
        }

        $session->loadMissing('workflow');
        $events = app(WorkflowCopilotSessionService::class)
            ->eventsAfter($session, $this->copilotLastEventSequence, 100);

        foreach ($events as $event) {
            $sequence = (int) ($event->sequence ?? 0);
            $this->copilotLastEventSequence = max($this->copilotLastEventSequence, $sequence);

            if (! $this->isVisibleCopilotEvent((string) ($event->event_type ?? ''))) {
                continue;
            }

            $message = $this->sanitizeAssistantText((string) ($event->message ?? ''));

            if ($message === '') {
                continue;
            }

            $feedItem = [
                'id' => (int) $event->getKey(),
                'sequence' => $sequence,
                'event_type' => (string) ($event->event_type ?? 'status'),
                'phase' => (string) ($event->phase ?? $session->phase ?? ''),
                'tone' => $this->copilotTone((string) ($event->level ?? 'info')),
                'message' => $message,
                'time' => optional($event->occurred_at ?? $event->created_at)->format('H:i:s') ?? now()->format('H:i:s'),
            ];
            $this->copilotEventFeed[] = $feedItem;

            if ((bool) ($event->is_milestone ?? false) && ! $this->hasCopilotMilestone($event->getKey())) {
                $this->appendDisplayMessage(
                    'assistant',
                    $message,
                    $feedItem['tone'],
                    null,
                    null,
                    [
                        'copilot_event_id' => (int) $event->getKey(),
                        'copilot_session_id' => (int) $session->getKey(),
                        'copilot_event_sequence' => $sequence,
                    ],
                );
            }
        }

        $this->copilotEventFeed = array_slice($this->copilotEventFeed, -20);
        $this->copilotStatus = $this->copilotStatusPayload($session->fresh(['workflow']) ?? $session);
    }

    public function pauseCopilotSession(): void
    {
        if ($session = $this->activeCopilotSession()) {
            app(WorkflowCopilotSessionService::class)->pause($session, 'Im Workflow-Copilot-Chat pausiert.');
            $this->pollCopilotSession();
        }
    }

    public function resumeCopilotSession(): void
    {
        if ($session = $this->activeCopilotSession()) {
            $session = app(WorkflowCopilotSessionService::class)->resume($session);
            WorkflowCopilotSupervisorJob::dispatch((int) $session->getKey());
            $this->pollCopilotSession();
        }
    }

    public function stopCopilotSession(): void
    {
        if ($session = $this->activeCopilotSession()) {
            $session->loadMissing('activeRun');

            if ($session->activeRun) {
                app(WorkflowExecutionService::class)->cancel(
                    $session->activeRun,
                    'Workflow-Test wurde mit der Copilot-Sitzung gestoppt.',
                );
            }

            app(WorkflowCopilotSessionService::class)->stop($session, 'Im Workflow-Copilot-Chat gestoppt.');
            $this->pollCopilotSession();
        }
    }

    public function restartCopilotSession(): void
    {
        $session = $this->activeCopilotSession();

        if (! $session) {
            return;
        }

        try {
            $restarted = app(WorkflowCopilotSessionService::class)->restart(
                $session,
                'Vollstaendiger Neustart wurde in der Copilot-Sidebar angefordert.',
            );

            WorkflowCopilotSupervisorJob::dispatch((int) $restarted->getKey());
            $this->activeCopilotSessionId = (int) $restarted->getKey();
            $this->copilotLastEventSequence = 0;
            Session::put(self::COPILOT_SESSION_KEY, $this->activeCopilotSessionId);
            $this->restoreCopilotProjection($restarted);
            $this->dispatch('workflow-copilot-session-activated', sessionId: (int) $restarted->getKey());
            $this->dispatch(
                'assistant-open-workflow-run-preview',
                workflow_id: (int) $restarted->workflow_id,
                run_id: 0,
                session_id: (int) $restarted->getKey(),
            );
        } catch (\Throwable $exception) {
            $this->appendDisplayMessage(
                'assistant',
                'Der Copilot-Neustart ist fehlgeschlagen: '.$exception->getMessage(),
                'error',
            );
        }
    }

    public function openCopilotRunPreview(): void
    {
        if (! $session = $this->activeCopilotSession()) {
            return;
        }

        $this->dispatch(
            'assistant-open-workflow-run-preview',
            workflow_id: (int) $session->workflow_id,
            run_id: (int) ($session->active_workflow_run_id ?? 0),
            session_id: (int) $session->getKey(),
        );
    }

    public function dismissToolEvent(string $eventId): void
    {
        $this->toolEvents = collect($this->toolEvents)
            ->reject(fn (array $event): bool => ($event['id'] ?? null) === $eventId)
            ->values()
            ->all();
    }

    private function runAssistantConversation(string $userMessage): array
    {
        $ai = app(AiConnectionService::class);
        $toolService = app(WorkflowAssistantToolService::class);
        $settings = $this->assistantSettings();
        $toolRounds = max(1, min(8, (int) ($settings['max_tool_rounds'] ?? 5)));
        $context = $toolService->conversationContext(Auth::user(), $this->pageContext);
        $messages = [
            [
                'role' => 'system',
                'content' => $toolService->systemPrompt((string) ($settings['instructions'] ?? '')),
            ],
            [
                'role' => 'system',
                'content' => 'Aktueller App-Kontext als JSON: '.$this->encodeJson($context),
            ],
            ...$this->baseTranscript(),
            [
                'role' => 'user',
                'content' => $userMessage,
            ],
        ];
        $chatOptions = null;
        $improvements = null;
        $finalMessage = '';
        $uiAction = null;
        $refreshPage = false;
        $analyzedRunContext = null;
        $forceImprovementTool = false;
        $improvementRetryUsed = false;
        $roundLimit = $toolRounds;
        $auditSession = null;
        $auditTrail = [[
            'event_type' => 'chat.user',
            'message' => 'Benutzer hat die autonome Workflow-Anfrage gesendet.',
            'payload' => ['content' => $userMessage, 'user_id' => Auth::id(), 'page_context' => $this->pageContext],
        ]];

        $this->stream('assistant-response-stream', '', true);
        $this->stream('assistant-status-stream', 'Kontext wird geprueft und die Anfrage vorbereitet.', true);

        for ($round = 0; $round < $roundLimit; $round++) {
            if ($round > 0) {
                $this->stream('assistant-status-stream', 'Werkzeugergebnisse werden ausgewertet.', true);
            }

            $response = $ai->requestStreamed([
                'messages' => $messages,
                'tools' => $toolService->tools(),
                'tool_choice' => $forceImprovementTool
                    ? [
                        'type' => 'function',
                        'function' => ['name' => 'present_workflow_improvements'],
                    ]
                    : 'auto',
            ], 'text', function (string $chunk): void {
                $chunk = $this->sanitizeAssistantChunk($chunk);

                if ($chunk !== '') {
                    $this->stream('assistant-response-stream', e($chunk));
                }
            });
            $assistantMessage = data_get($response, 'choices.0.message', []);
            $content = trim((string) data_get($assistantMessage, 'content', ''));
            $toolCalls = $this->normalizeToolCalls(data_get($assistantMessage, 'tool_calls', data_get($assistantMessage, 'toolCalls', [])));

            if ($content !== '') {
                $auditTrail[] = [
                    'event_type' => 'assistant.response',
                    'message' => 'Workflow-Copilot hat eine Antwort fuer Toolrunde '.($round + 1).' erzeugt.',
                    'payload' => ['round' => $round + 1, 'content' => $content],
                ];
            }

            if ($toolCalls === []) {
                if ($analyzedRunContext && $improvements === null && ! $improvementRetryUsed) {
                    $improvementRetryUsed = true;
                    $forceImprovementTool = true;
                    $roundLimit++;
                    $messages[] = [
                        'role' => 'assistant',
                        'content' => $content !== '' ? $content : null,
                    ];
                    $messages[] = [
                        'role' => 'user',
                        'content' => 'Strukturiere jetzt die belegten Verbesserungen fuer Workflow #'
                            .$analyzedRunContext['workflow_id'].' und Run #'.$analyzedRunContext['run_id']
                            .' mit present_workflow_improvements. Nutze ein leeres improvements-Array, wenn keine Verbesserung belegt ist.',
                    ];
                    $this->stream('assistant-status-stream', 'Verbesserungen werden den Workflow-Elementen zugeordnet.', true);

                    continue;
                }

                $this->stream('assistant-status-stream', 'Antwort wird fertiggestellt.', true);
                $finalMessage = $content;
                $messages[] = [
                    'role' => 'assistant',
                    'content' => $finalMessage,
                ];

                break;
            }

            $messages[] = [
                'role' => 'assistant',
                'content' => $content !== '' ? $content : null,
                'tool_calls' => array_map(fn (array $toolCall): array => $toolCall['raw'], $toolCalls),
            ];

            foreach ($toolCalls as $toolCall) {
                $this->stream('assistant-status-stream', $this->assistantToolStatus($toolCall['name']), true);
                $auditTrail[] = [
                    'event_type' => 'tool.called',
                    'message' => 'Assistant-Tool `'.$toolCall['name'].'` wurde aufgerufen.',
                    'payload' => [
                        'round' => $round + 1,
                        'tool_call_id' => $toolCall['id'],
                        'tool' => $toolCall['name'],
                        'arguments' => $this->sanitizeAuditPayload($toolCall['arguments']),
                    ],
                ];

                $result = $toolService->execute($toolCall['name'], $toolCall['arguments'], Auth::user());
                $auditTrail[] = [
                    'event_type' => 'tool.completed',
                    'message' => 'Assistant-Tool `'.$toolCall['name'].'` wurde '.(($result['ok'] ?? false) ? 'erfolgreich' : 'mit Fehler').' abgeschlossen.',
                    'payload' => [
                        'round' => $round + 1,
                        'tool_call_id' => $toolCall['id'],
                        'tool' => $toolCall['name'],
                        'result' => $this->sanitizeAuditPayload($result),
                    ],
                    'level' => ($result['ok'] ?? false) ? 'success' : 'error',
                ];

                if ($toolCall['name'] === 'workflow_optimize_start' && ($result['ok'] ?? false)) {
                    $auditSession = WorkflowCopilotSession::query()->find((int) data_get($result, 'session.id'));
                }

                $this->flushCopilotAuditTrail($auditSession, $auditTrail);

                $this->appendToolEvent($toolCall['name'], $toolCall['arguments'], $result);
                $refreshPage = true;
                $this->dispatch('assistant-workflow-page-refresh');

                if (is_array($result['chat_options'] ?? null)) {
                    $chatOptions = $result['chat_options'];
                }

                if ($toolCall['name'] === 'analyze_last_workflow_run' && ($result['ok'] ?? false)) {
                    $analyzedRunContext = [
                        'workflow_id' => (int) data_get($result, 'run.workflow_id'),
                        'run_id' => (int) data_get($result, 'run.id'),
                    ];
                }

                if ($toolCall['name'] === 'present_workflow_improvements' && is_array($result['improvements'] ?? null)) {
                    $improvements = $result['improvements'];
                    $forceImprovementTool = false;
                }

                if (($result['ok'] ?? false) && is_array($result['ui_action'] ?? null)) {
                    $candidateUiAction = $result['ui_action'];
                    $currentType = (string) ($uiAction['type'] ?? '');
                    $candidateType = (string) ($candidateUiAction['type'] ?? '');
                    $previewTypes = ['workflow_copilot_session', 'open_workflow_run_preview'];

                    if ($uiAction === null
                        || in_array($candidateType, $previewTypes, true)
                        || ! in_array($currentType, $previewTypes, true)) {
                        $uiAction = $candidateUiAction;
                    }
                }

                $messages[] = [
                    'role' => 'tool',
                    'tool_call_id' => $toolCall['id'],
                    'name' => $toolCall['name'],
                    'content' => $this->encodeJson($result),
                ];
            }
        }

        if ($finalMessage === '') {
            $this->stream('assistant-status-stream', 'Tool-Ergebnisse werden zusammengefasst.', true);
            $response = $ai->requestStreamed([
                'messages' => [
                    ...$messages,
                    [
                        'role' => 'user',
                        'content' => 'Fasse die ausgefuehrten Workflow-Tool-Ergebnisse fuer den Nutzer knapp zusammen und nenne den naechsten sinnvollen Schritt.',
                    ],
                ],
                'tools' => $toolService->tools(),
                'tool_choice' => 'none',
            ], 'text', function (string $chunk): void {
                $chunk = $this->sanitizeAssistantChunk($chunk);

                if ($chunk !== '') {
                    $this->stream('assistant-response-stream', e($chunk));
                }
            });
            $finalMessage = trim((string) data_get($response, 'choices.0.message.content', ''));
            $messages[] = [
                'role' => 'assistant',
                'content' => $finalMessage,
            ];
        }

        if ($finalMessage !== '') {
            $auditTrail[] = [
                'event_type' => 'chat.assistant',
                'message' => 'Workflow-Copilot hat die sichtbare Abschlussantwort gesendet.',
                'payload' => ['content' => $this->sanitizeAssistantText($finalMessage)],
            ];
        }

        $this->flushCopilotAuditTrail($auditSession, $auditTrail);

        Session::put(self::TRANSCRIPT_KEY, $this->trimTranscript($messages));

        return [
            'message' => $this->sanitizeAssistantText($finalMessage),
            'chat_options' => $this->normalizeChatOptions($chatOptions),
            'improvements' => $improvements === null ? null : ($this->normalizeImprovements($improvements) ?? []),
            'ui_action' => $uiAction,
            'refresh_page' => $refreshPage,
        ];
    }

    private function baseTranscript(): array
    {
        $transcript = Session::get(self::TRANSCRIPT_KEY, []);

        if (! is_array($transcript)) {
            return [];
        }

        return collect($transcript)
            ->filter(fn (mixed $message): bool => is_array($message) && in_array($message['role'] ?? null, ['user', 'assistant', 'tool'], true))
            ->values()
            ->all();
    }

    private function trimTranscript(array $messages): array
    {
        return collect($messages)
            ->filter(fn (array $message): bool => ($message['role'] ?? null) !== 'system')
            ->take(-36)
            ->values()
            ->all();
    }

    private function normalizeToolCalls(mixed $toolCalls): array
    {
        return collect(is_array($toolCalls) ? $toolCalls : [])
            ->map(function (array $toolCall, int $index): ?array {
                $id = trim((string) ($toolCall['id'] ?? 'tool-call-'.$index));
                $name = trim((string) data_get($toolCall, 'function.name', $toolCall['name'] ?? ''));
                $arguments = data_get($toolCall, 'function.arguments', $toolCall['arguments'] ?? '{}');

                if ($name === '') {
                    return null;
                }

                if (is_array($arguments)) {
                    $argumentString = $this->encodeJson($arguments);
                    $decodedArguments = $arguments;
                } else {
                    $argumentString = (string) $arguments;
                    $decodedArguments = $this->decodeToolArguments($argumentString);
                }

                return [
                    'id' => $id,
                    'name' => $name,
                    'arguments' => $decodedArguments,
                    'raw' => [
                        'id' => $id,
                        'type' => 'function',
                        'function' => [
                            'name' => $name,
                            'arguments' => $argumentString,
                        ],
                    ],
                ];
            })
            ->filter()
            ->values()
            ->all();
    }

    private function decodeToolArguments(string $arguments): array
    {
        $decoded = json_decode($arguments, true);

        return is_array($decoded) ? $decoded : [];
    }

    private function appendDisplayMessage(
        string $role,
        string $content,
        string $tone = 'neutral',
        ?array $options = null,
        ?array $improvements = null,
        array $metadata = [],
    ): void {
        $message = [
            'role' => $role,
            'content' => $role === 'assistant' ? $this->sanitizeAssistantText($content) : $content,
            'tone' => $tone,
            'time' => now()->format('H:i'),
            'options' => $options,
            'improvements' => $improvements === null ? null : ($this->normalizeImprovements($improvements) ?? []),
            'selected_option_index' => null,
            ...$metadata,
        ];

        $this->chatHistory[] = $message;
        $this->chatHistory = array_slice($this->chatHistory, -80);

        Session::put(self::DISPLAY_HISTORY_KEY, $this->chatHistory);
    }

    private function appendToolEvent(string $toolName, array $arguments, array $result): void
    {
        $this->toolEvents[] = [
            'id' => (string) Str::uuid(),
            'tool' => $toolName,
            'status' => ($result['ok'] ?? false) ? 'success' : 'error',
            'message' => (string) ($result['message'] ?? $result['error'] ?? 'Tool ausgefuehrt.'),
            'arguments' => $arguments,
            'result' => $result,
            'time' => now()->format('H:i:s'),
        ];

        $this->toolEvents = array_slice($this->toolEvents, -24);
    }

    private function flushCopilotAuditTrail(?WorkflowCopilotSession $session, array &$events): void
    {
        if (! $session || $events === []) {
            return;
        }

        $service = app(WorkflowCopilotSessionService::class);

        foreach ($events as $event) {
            try {
                $service->appendEvent(
                    $session,
                    (string) ($event['event_type'] ?? 'assistant.event'),
                    (string) ($event['message'] ?? 'Workflow-Copilot-Ereignis.'),
                    is_array($event['payload'] ?? null) ? $event['payload'] : [],
                    'conversation',
                    (string) ($event['level'] ?? 'info'),
                );
            } catch (\Throwable $exception) {
                Log::warning('Workflow-Copilot-Auditereignis konnte nicht gespeichert werden.', [
                    'session_id' => $session->id,
                    'event_type' => $event['event_type'] ?? null,
                    'error' => $exception->getMessage(),
                ]);
            }
        }

        $events = [];
    }

    private function sanitizeAuditPayload(mixed $payload): mixed
    {
        if (! is_array($payload)) {
            return $payload;
        }

        $sanitized = [];

        foreach ($payload as $key => $value) {
            $normalizedKey = Str::lower(str_replace(['-', '_', ' '], '', (string) $key));
            $sensitive = str_contains($normalizedKey, 'password')
                || str_contains($normalizedKey, 'secret')
                || str_contains($normalizedKey, 'token')
                || str_contains($normalizedKey, 'cookie')
                || str_contains($normalizedKey, 'sessionpayload')
                || str_contains($normalizedKey, 'payloadencrypted')
                || str_contains($normalizedKey, 'browserwsendpoint')
                || str_contains($normalizedKey, 'websocketendpoint')
                || $normalizedKey === 'wsendpoint';

            $sanitized[$key] = $sensitive ? '[redacted]' : $this->sanitizeAuditPayload($value);
        }

        return $sanitized;
    }

    private function assistantToolStatus(string $toolName): string
    {
        return match ($toolName) {
            'list_workflows' => 'Workflows werden gesucht.',
            'get_workflow_context' => 'Workflow-Kontext wird geladen.',
            'analyze_last_workflow_run' => 'Letzter Workflow-Lauf wird analysiert.',
            'get_workflow_variables' => 'Variablen und Werte werden gelesen.',
            'search_workflow_tasks' => 'Workflow-Tasks werden durchsucht.',
            'get_nodescript_content_debugg' => 'Node-Skript wird geladen.',
            'create_workflow' => 'Workflow wird erstellt.',
            'duplicate_workflow' => 'Workflow wird dupliziert.',
            'update_workflow' => 'Workflow wird aktualisiert.',
            'create_workflow_list' => 'Workflow-Liste wird angelegt.',
            'add_workflow_task' => 'Task wird hinzugefuegt.',
            'update_workflow_task' => 'Task wird aktualisiert.',
            'set_workflow_task_routes' => 'Task-Routen werden gesetzt.',
            'apply_workflow_definition' => 'Workflow-Definition wird angewendet.',
            'update_list_import' => 'Listen werden importiert.',
            'update_task_import' => 'Tasks werden importiert.',
            'workflow_test_run' => 'Workflow-Testlauf wird gestartet.',
            'workflow_optimize_start' => 'Autonome System-Optimierung wird gestartet.',
            'workflow_optimize_status' => 'Aktueller Optimierungsstatus wird geladen.',
            'workflow_optimize_instruction' => 'Anweisung wird fuer den naechsten sicheren Checkpoint gespeichert.',
            'workflow_optimize_pause' => 'Workflow-Optimierung wird pausiert.',
            'workflow_optimize_resume' => 'Workflow-Optimierung wird fortgesetzt.',
            'workflow_optimize_rewind' => 'Workflow wird zum ausgewaehlten Checkpoint zurueckgespult.',
            'workflow_optimize_stop' => 'Workflow-Optimierung und aktiver Testlauf werden gestoppt.',
            'navigate' => 'Ansicht wird vorbereitet.',
            'highlight_workflow_element' => 'Workflow-Element wird markiert.',
            'present_workflow_improvements' => 'Verbesserungen werden im Workflow zugeordnet.',
            default => 'Werkzeug '.$toolName.' wird ausgefuehrt.',
        };
    }

    private function assistantSettings(): array
    {
        $settings = Setting::getValue('ai_assistant', 'workflow_copilot');

        return is_array($settings) ? $settings : [];
    }

    private function restoreCopilotSession(): void
    {
        $workflowId = (int) ($this->pageContext['workflow_id'] ?? 0);

        if ($workflowId > 0) {
            $this->attachLatestCopilotSession($workflowId);

            return;
        }

        $sessionId = (int) Session::get(self::COPILOT_SESSION_KEY, 0);

        if ($sessionId > 0) {
            $this->attachCopilotSession($sessionId);
        }
    }

    private function attachLatestCopilotSession(int $workflowId): void
    {
        $session = $this->preferredCopilotSessionForWorkflow($workflowId);

        if ($session) {
            if ((int) $this->activeCopilotSessionId !== (int) $session->getKey()) {
                $this->attachCopilotSession((int) $session->getKey());
            }

            return;
        }

        $current = $this->activeCopilotSession();

        if ($current && (int) $current->workflow_id !== $workflowId) {
            $this->activeCopilotSessionId = null;
            $this->copilotLastEventSequence = 0;
            $this->copilotStatus = [];
            $this->copilotEventFeed = [];
            Session::forget(self::COPILOT_SESSION_KEY);
        }
    }

    private function preferredCopilotSessionForWorkflow(int $workflowId): ?WorkflowCopilotSession
    {
        $query = WorkflowCopilotSession::query()->where('workflow_id', $workflowId);

        return (clone $query)
            ->whereIn('status', WorkflowCopilotSession::ACTIVE_STATUSES)
            ->latest('id')
            ->first()
            ?? (clone $query)
                ->whereIn('status', WorkflowCopilotSession::TERMINAL_STATUSES)
                ->latest('id')
                ->first();
    }

    private function restoreCopilotProjection(WorkflowCopilotSession $session): void
    {
        $clearedSequence = $this->copilotClearedSequence($session);
        $events = $session->events()
            ->where('sequence', '>', $clearedSequence)
            ->latest('sequence')
            ->limit(100)
            ->get()
            ->sortBy('sequence')
            ->values();

        $this->copilotEventFeed = $events
            ->filter(fn ($event): bool => $this->isVisibleCopilotEvent((string) ($event->event_type ?? '')))
            ->map(fn ($event): array => $this->copilotFeedItem($event, $session))
            ->take(-20)
            ->values()
            ->all();

        $milestones = $session->events()
            ->where('is_milestone', true)
            ->where('sequence', '>', $clearedSequence)
            ->latest('sequence')
            ->limit(80)
            ->get()
            ->sortBy('sequence');

        foreach ($milestones as $event) {
            if (! $this->isVisibleCopilotEvent((string) ($event->event_type ?? ''))
                || $this->hasCopilotMilestone($event->getKey())) {
                continue;
            }

            $message = $this->sanitizeAssistantText((string) ($event->message ?? ''));

            if ($message === '') {
                continue;
            }

            $this->appendDisplayMessage(
                'assistant',
                $message,
                $this->copilotTone((string) ($event->level ?? 'info')),
                null,
                null,
                [
                    'copilot_event_id' => (int) $event->getKey(),
                    'copilot_session_id' => (int) $session->getKey(),
                    'copilot_event_sequence' => (int) ($event->sequence ?? 0),
                ],
            );
        }

        $latestSequence = max(
            (int) $events->max('sequence'),
            (int) $milestones->max('sequence'),
        );
        $this->copilotLastEventSequence = max($clearedSequence, $latestSequence);
        $this->copilotStatus = $this->copilotStatusPayload($session);
    }

    private function copilotClearedSequence(WorkflowCopilotSession $session): int
    {
        $clearedSequences = Session::get(self::COPILOT_CLEARED_SEQUENCES_KEY, []);

        return max(0, (int) data_get(
            is_array($clearedSequences) ? $clearedSequences : [],
            (string) $session->getKey(),
            0,
        ));
    }

    private function copilotFeedItem(mixed $event, WorkflowCopilotSession $session): array
    {
        return [
            'id' => (int) $event->getKey(),
            'sequence' => (int) ($event->sequence ?? 0),
            'event_type' => (string) ($event->event_type ?? 'status'),
            'phase' => (string) ($event->phase ?? $session->phase ?? ''),
            'tone' => $this->copilotTone((string) ($event->level ?? 'info')),
            'message' => $this->sanitizeAssistantText((string) ($event->message ?? '')),
            'time' => optional($event->occurred_at ?? $event->created_at)->format('H:i:s') ?? now()->format('H:i:s'),
        ];
    }

    private function activeCopilotSession(): ?WorkflowCopilotSession
    {
        if (! $this->activeCopilotSessionId) {
            return null;
        }

        return WorkflowCopilotSession::query()->find($this->activeCopilotSessionId);
    }

    private function isCopilotSessionActive(WorkflowCopilotSession $session): bool
    {
        return in_array((string) $session->status, WorkflowCopilotSession::ACTIVE_STATUSES, true);
    }

    private function isVisibleCopilotEvent(string $eventType): bool
    {
        $eventType = Str::lower(trim($eventType));

        return ! Str::contains($eventType, [
            'reasoning',
            'internal_analysis',
            'chain_of_thought',
            'chain-of-thought',
            'thought',
        ]);
    }

    private function hasCopilotMilestone(mixed $eventId): bool
    {
        return collect($this->chatHistory)->contains(
            fn (mixed $message): bool => is_array($message)
                && (string) ($message['copilot_event_id'] ?? '') === (string) $eventId,
        );
    }

    private function copilotTone(string $level): string
    {
        return match (Str::lower($level)) {
            'success', 'completed' => 'success',
            'error', 'failed', 'critical' => 'error',
            default => 'neutral',
        };
    }

    private function copilotStatusPayload(WorkflowCopilotSession $session): array
    {
        $state = is_array($session->state_json) ? $session->state_json : [];
        $budget = is_array($session->budget_json) ? $session->budget_json : [];
        $usage = is_array($session->usage_json) ? $session->usage_json : [];
        $maxMinutes = max(1, (int) ($budget['max_minutes'] ?? 90));
        $elapsedMinutes = $session->started_at
            ? max(0, (int) $session->started_at->diffInMinutes(now()))
            : 0;

        return [
            'id' => (int) $session->getKey(),
            'workflow_id' => (int) $session->workflow_id,
            'workflow_name' => (string) ($session->workflow?->name ?? 'Workflow'),
            'status' => (string) $session->status,
            'active' => $this->isCopilotSessionActive($session),
            'paused' => (string) $session->status === WorkflowCopilotSession::STATUS_PAUSED,
            'phase' => (string) ($session->phase ?? data_get($state, 'phase', 'vorbereiten')),
            'current_step_name' => (string) data_get($state, 'current_step_name', data_get($state, 'cursor.step_name', '')),
            'current_task_key' => (string) data_get($state, 'current_task_key', data_get($state, 'cursor.task_key', '')),
            'current_task_title' => (string) data_get($state, 'current_task_title', ''),
            'latest_screenshot_url' => $this->copilotScreenshotUrl($state),
            'page_state' => $this->copilotDisplayValue(data_get($state, 'page_state', data_get($state, 'observation.page_state'))),
            'last_action' => $this->copilotDisplayValue(data_get($state, 'last_action')),
            'current_result' => $this->copilotDisplayValue(data_get($state, 'last_result', data_get($state, 'current_result'))),
            'next_action' => $this->copilotDisplayValue(data_get($state, 'next_action')),
            'repair_iteration' => (int) ($session->repair_round ?? 0),
            'max_repair_iterations' => max(1, (int) ($budget['max_repair_iterations'] ?? 15)),
            'probe_actions' => (int) ($usage['probe_actions'] ?? 0),
            'max_probe_actions' => max(1, (int) ($budget['max_probe_actions'] ?? 60)),
            'ai_requests' => max(0, (int) ($usage['ai_requests'] ?? 0)),
            'total_tokens' => max(0, (int) ($usage['total_tokens'] ?? 0)),
            'cost_usd' => max(0, (float) ($usage['cost_usd'] ?? 0)),
            'max_cost_usd' => max(0, (float) ($budget['max_cost_usd'] ?? 0)),
            'elapsed_minutes' => $elapsedMinutes,
            'remaining_minutes' => max(0, $maxMinutes - $elapsedMinutes),
            'started_at' => optional($session->started_at)->format('d.m.Y H:i:s'),
            'finished_at' => optional($session->finished_at)->format('d.m.Y H:i:s'),
            'verification_report' => $this->copilotVerificationReport($session, $state),
        ];
    }

    private function copilotVerificationReport(WorkflowCopilotSession $session, array $state): ?array
    {
        $event = $session->events()
            ->whereIn('event_type', ['verification.passed', 'verification.failed'])
            ->latest('sequence')
            ->first();
        $stateVerification = data_get($state, 'verification');

        if (! $event && ! is_array($stateVerification)) {
            return null;
        }

        $payload = is_array($event?->payload_json) ? $event->payload_json : [];
        $criteria = data_get($payload, 'criteria_evaluation', data_get($state, 'verification.criteria', []));
        $criteria = is_array($criteria) ? $criteria : [];
        $vision = data_get($state, 'verification.vision', data_get($payload, 'vision', []));
        $vision = is_array($vision) ? $vision : [];
        $pass = $event
            ? (string) $event->event_type === 'verification.passed'
            : (bool) data_get($state, 'verification.pass', false);

        return [
            'final' => in_array((string) $session->status, WorkflowCopilotSession::TERMINAL_STATUSES, true),
            'pass' => $pass,
            'message' => $this->sanitizeAssistantText((string) ($event?->message ?? ($pass
                ? 'Workflow vollstaendig erfolgreich und automatisch verifiziert.'
                : 'Die letzte Endpruefung wurde nicht bestanden.'))),
            'workflow_run_id' => (int) ($payload['workflow_run_id'] ?? data_get($state, 'verification_run_id', 0)),
            'revision' => (int) ($payload['revision'] ?? $session->current_revision ?? 0),
            'technical_status' => (string) ($payload['technical_status'] ?? ''),
            'business_status' => (string) ($payload['business_status'] ?? ''),
            'criteria_pass' => (bool) ($criteria['pass'] ?? false),
            'criteria_passed' => (int) ($criteria['passed'] ?? 0),
            'criteria_total' => (int) ($criteria['total'] ?? 0),
            'vision_verdict' => (string) ($payload['vision_verdict'] ?? $vision['verdict'] ?? ''),
            'vision_confidence' => is_numeric($payload['vision_confidence'] ?? $vision['confidence'] ?? null)
                ? (float) ($payload['vision_confidence'] ?? $vision['confidence'])
                : null,
            'time' => optional($event?->occurred_at ?? $event?->created_at)->format('d.m.Y H:i:s'),
        ];
    }

    private function copilotScreenshotUrl(array $state): ?string
    {
        $directUrl = trim((string) data_get($state, 'latest_screenshot_url', data_get($state, 'observation.screenshot_url', '')));

        if ($directUrl !== '') {
            return $directUrl;
        }

        $artifactId = (int) data_get($state, 'last_screenshot_artifact_id', 0);

        if ($artifactId < 1) {
            return null;
        }

        $artifact = \App\Models\WorkflowRunArtifact::query()->find($artifactId);

        if (! $artifact || ! $artifact->workflow_run_id) {
            return null;
        }

        return route('workflow-run-artifacts.show', [
            'run' => $artifact->workflow_run_id,
            'artifact' => $artifact->getKey(),
        ], false);
    }

    private function copilotDisplayValue(mixed $value): string
    {
        if ($value === null || $value === '') {
            return '';
        }

        if (is_scalar($value)) {
            return Str::limit((string) $value, 1000, '');
        }

        return Str::limit(json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: '', 1000, '');
    }

    private function initialPageContext(): array
    {
        return $this->normalizePageContext([
            'route_name' => request()->route()?->getName(),
            'path' => request()->path(),
            'page_title' => config('app.name'),
            'workflow_id' => request()->route('workflow')?->id ?? request()->route('workflow'),
        ]);
    }

    private function normalizePageContext(array $context): array
    {
        $stringValue = static function (mixed $value, int $limit = 255): ?string {
            $text = trim((string) ($value ?? ''));

            return $text !== '' ? Str::limit($text, $limit, '') : null;
        };
        $positiveInteger = static function (mixed $value): ?int {
            $number = (int) $value;

            return $number > 0 ? $number : null;
        };

        return [
            'route_name' => $stringValue($context['route_name'] ?? null),
            'path' => $stringValue($context['path'] ?? null, 2048),
            'page_title' => $stringValue($context['page_title'] ?? null),
            'workflow_id' => $positiveInteger($context['workflow_id'] ?? null),
            'workflow_slug' => $stringValue($context['workflow_slug'] ?? null),
            'highlighted_workflow_task' => $stringValue($context['highlighted_workflow_task'] ?? null),
            'highlighted_workflow_list' => $stringValue($context['highlighted_workflow_list'] ?? null),
        ];
    }

    private function normalizeChatOptions(mixed $options): ?array
    {
        $normalized = collect(is_array($options) ? $options : [])
            ->filter(fn (mixed $option): bool => is_array($option) && filled($option['label'] ?? null) && filled($option['prompt'] ?? null))
            ->take(6)
            ->values()
            ->all();

        return count($normalized) >= 2 ? $normalized : null;
    }

    private function normalizeImprovements(mixed $improvements): ?array
    {
        $normalized = collect(is_array($improvements) ? $improvements : [])
            ->filter(fn (mixed $improvement): bool => is_array($improvement) && filled($improvement['title'] ?? null))
            ->take(8)
            ->map(function (array $improvement, int $index): array {
                $severity = trim((string) ($improvement['severity'] ?? 'info'));

                return [
                    'id' => trim((string) ($improvement['id'] ?? 'improvement-'.$index)),
                    'workflow_id' => $this->positiveIntegerValue($improvement['workflow_id'] ?? null),
                    'run_id' => $this->positiveIntegerValue($improvement['run_id'] ?? null),
                    'severity' => in_array($severity, ['error', 'warning', 'info'], true) ? $severity : 'info',
                    'title' => Str::limit(trim((string) ($improvement['title'] ?? 'Hinweis')), 160, ''),
                    'explanation' => Str::limit(trim((string) ($improvement['explanation'] ?? '')), 800, ''),
                    'recommendation' => Str::limit(trim((string) ($improvement['recommendation'] ?? '')), 800, ''),
                    'target_type' => ($improvement['target_type'] ?? null) === 'workflow_task' ? 'workflow_task' : 'workflow_list',
                    'step_id' => $this->positiveIntegerValue($improvement['step_id'] ?? null),
                    'step_action_key' => Str::limit(trim((string) ($improvement['step_action_key'] ?? '')), 160, ''),
                    'task_card_key' => Str::limit(trim((string) ($improvement['task_card_key'] ?? '')), 160, ''),
                    'highlightable' => (bool) ($improvement['highlightable'] ?? false),
                ];
            })
            ->values()
            ->all();

        return $normalized !== [] ? $normalized : null;
    }

    private function positiveIntegerValue(mixed $value): ?int
    {
        $number = (int) $value;

        return $number > 0 ? $number : null;
    }

    private function displayMessageForUserPrompt(string $prompt): string
    {
        return str_replace(
            ['[WORKFLOW_ANALYZE_LAST_RUN]', '[WORKFLOW_CREATE_PLAN]'],
            ['Letzten Workflow-Lauf analysieren', 'Neuen Workflow planen'],
            $prompt,
        );
    }

    private function sanitizeAssistantText(string $text): string
    {
        $text = str_replace("\0", '', trim($text));
        $patterns = [
            '#\b(?:wss?|cdp)://[^\s"\']+#i' => '[BROWSER-ENDPOINT REDACTED]',
            '/\bBearer\s+[A-Za-z0-9._~+\/\-]+=*/i' => 'Bearer [REDACTED]',
            '/\b(password|passwd|pwd|secret|token|cookie|authorization|signature|credential|session(?:_?id)?|api[_-]?key)\s*[:=]\s*[^\s,;]+/i' => '$1=[REDACTED]',
            '/\beyJ[A-Za-z0-9_-]+\.[A-Za-z0-9_-]+\.[A-Za-z0-9_-]+\b/' => '[TOKEN REDACTED]',
            '/\b[A-Z0-9._%+\-]+@[A-Z0-9.\-]+\.[A-Z]{2,}\b/i' => '[EMAIL REDACTED]',
            '/(?<!\d)(?:\+?\d[\s().-]*){8,}(?!\d)/' => '[PHONE REDACTED]',
        ];

        foreach ($patterns as $pattern => $replacement) {
            $text = preg_replace($pattern, $replacement, $text) ?? $text;
        }

        $text = preg_replace('/([?&](?:token|secret|signature|session|api[_-]?key)=)[^&\s]+/i', '$1[REDACTED]', $text) ?? $text;
        $text = preg_replace('/\n{3,}/', "\n\n", $text) ?? $text;

        return Str::limit($text, 12000, '');
    }

    private function sanitizeAssistantChunk(string $chunk): string
    {
        $chunk = str_replace(["\0", "\r"], ['', ''], $chunk);

        return Str::limit($chunk, 4000, '');
    }

    private function encodeJson(mixed $value): string
    {
        return json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: '{}';
    }

    private function normalizeSpeechInputProvider(mixed $provider): string
    {
        $provider = trim((string) $provider);

        return in_array($provider, ['browser', 'whisper_local', 'vosk'], true) ? $provider : 'browser';
    }

    private function normalizeSpeechOutputProvider(mixed $provider): string
    {
        $provider = trim((string) $provider);

        return in_array($provider, ['piper_local', 'ai', 'espeak_ng'], true) ? $provider : 'ai';
    }
}
