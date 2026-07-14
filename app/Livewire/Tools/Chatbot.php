<?php

namespace App\Livewire\Tools;

use App\Models\Setting;
use App\Models\WorkflowCopilotSession;
use App\Services\Ai\AiConnectionService;
use App\Services\Ai\WorkflowAssistantToolService;
use App\Services\Workflows\WorkflowCopilotSessionService;
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

        if (! $this->activeCopilotSessionId && $this->pageContext['workflow_id']) {
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
                app(WorkflowCopilotSessionService::class)->instruction($copilotSession, $userMessage, [
                    'user_id' => Auth::id(),
                    'source' => 'workflow-copilot-chat',
                ]);
                $this->appendDisplayMessage(
                    'assistant',
                    'Die Anweisung wurde gespeichert und wird am naechsten sicheren Checkpoint beruecksichtigt.',
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

        $this->chatHistory = [];
        $this->toolEvents = [];
        $this->message = '';
        $this->workflowImportFile = null;
        $this->dispatch('assistant-ui-action', action: [
            'type' => 'highlight_workflow_improvements',
            'improvements' => [],
        ]);
    }

    public function attachCopilotSession(int $sessionId): void
    {
        $session = WorkflowCopilotSession::query()->find($sessionId);

        if (! $session) {
            return;
        }

        $this->activeCopilotSessionId = (int) $session->getKey();
        $this->copilotLastEventSequence = 0;
        $this->copilotEventFeed = [];
        Session::put(self::COPILOT_SESSION_KEY, $this->activeCopilotSessionId);
        $this->pollCopilotSession();
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
                    ['copilot_event_id' => (int) $event->getKey()],
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
            app(WorkflowCopilotSessionService::class)->resume($session);
            $this->pollCopilotSession();
        }
    }

    public function stopCopilotSession(): void
    {
        if ($session = $this->activeCopilotSession()) {
            app(WorkflowCopilotSessionService::class)->stop($session, 'Im Workflow-Copilot-Chat gestoppt.');
            $this->pollCopilotSession();
        }
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

                $result = $toolService->execute($toolCall['name'], $toolCall['arguments'], Auth::user());

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
                    $uiAction = $result['ui_action'];
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
            'content' => $content,
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
        $sessionId = (int) Session::get(self::COPILOT_SESSION_KEY, 0);

        if ($sessionId > 0) {
            $this->attachCopilotSession($sessionId);
        }
    }

    private function attachLatestCopilotSession(int $workflowId): void
    {
        $session = WorkflowCopilotSession::query()
            ->where('workflow_id', $workflowId)
            ->whereIn('status', WorkflowCopilotSession::ACTIVE_STATUSES)
            ->latest('id')
            ->first();

        if ($session) {
            $this->attachCopilotSession((int) $session->getKey());
        }
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

        return ! in_array($eventType, [
            'reasoning',
            'model_reasoning',
            'internal_reasoning',
            'internal_analysis',
            'chain_of_thought',
            'thought',
        ], true);
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
            'latest_screenshot_url' => (string) data_get($state, 'latest_screenshot_url', data_get($state, 'observation.screenshot_url', '')),
            'page_state' => (string) data_get($state, 'page_state', data_get($state, 'observation.page_state', '')),
            'last_action' => (string) data_get($state, 'last_action', ''),
            'current_result' => (string) data_get($state, 'current_result', ''),
            'next_action' => (string) data_get($state, 'next_action', ''),
            'repair_iteration' => (int) ($session->repair_round ?? 0),
            'max_repair_iterations' => max(1, (int) ($budget['max_repair_iterations'] ?? 15)),
            'probe_actions' => (int) ($usage['probe_actions'] ?? 0),
            'max_probe_actions' => max(1, (int) ($budget['max_probe_actions'] ?? 60)),
            'elapsed_minutes' => $elapsedMinutes,
            'remaining_minutes' => max(0, $maxMinutes - $elapsedMinutes),
            'started_at' => optional($session->started_at)->format('d.m.Y H:i:s'),
            'finished_at' => optional($session->finished_at)->format('d.m.Y H:i:s'),
        ];
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
        $text = preg_replace('/\n{3,}/', "\n\n", trim($text)) ?? trim($text);

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

        return in_array($provider, ['browser', 'vosk'], true) ? $provider : 'browser';
    }

    private function normalizeSpeechOutputProvider(mixed $provider): string
    {
        $provider = trim((string) $provider);

        return in_array($provider, ['ai', 'espeak_ng'], true) ? $provider : 'ai';
    }
}
