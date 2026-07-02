<?php

namespace App\Livewire\Tools;

use App\Models\Setting;
use App\Services\Ai\AiConnectionService;
use App\Services\Ai\WorkflowAssistantToolService;
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

    public string $message = '';

    public array $chatHistory = [];

    public array $toolEvents = [];

    public array $pageContext = [];

    public bool $isLoading = false;

    public bool $assistantEnabled = true;

    public string $assistantName = 'Workflow Copilot';

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
        $this->chatHistory = Session::get(self::DISPLAY_HISTORY_KEY, []);
        $this->toolEvents = [];
        $this->pageContext = $this->initialPageContext();
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
            );
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
        $finalMessage = '';

        for ($round = 0; $round < $toolRounds; $round++) {
            $response = $ai->request([
                'messages' => $messages,
                'tools' => $toolService->tools(),
                'tool_choice' => 'auto',
            ], 'text');
            $assistantMessage = data_get($response, 'choices.0.message', []);
            $content = trim((string) data_get($assistantMessage, 'content', ''));
            $toolCalls = $this->normalizeToolCalls(data_get($assistantMessage, 'tool_calls', data_get($assistantMessage, 'toolCalls', [])));

            if ($toolCalls === []) {
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
                $result = $toolService->execute($toolCall['name'], $toolCall['arguments'], Auth::user());

                $this->appendToolEvent($toolCall['name'], $toolCall['arguments'], $result);

                if (is_array($result['chat_options'] ?? null)) {
                    $chatOptions = $result['chat_options'];
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
            $response = $ai->request([
                'messages' => [
                    ...$messages,
                    [
                        'role' => 'user',
                        'content' => 'Fasse die ausgefuehrten Workflow-Tool-Ergebnisse fuer den Nutzer knapp zusammen und nenne den naechsten sinnvollen Schritt.',
                    ],
                ],
                'tools' => $toolService->tools(),
                'tool_choice' => 'none',
            ], 'text');
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

    private function appendDisplayMessage(string $role, string $content, string $tone = 'neutral', ?array $options = null): void
    {
        $message = [
            'role' => $role,
            'content' => $content,
            'tone' => $tone,
            'time' => now()->format('H:i'),
            'options' => $options,
            'selected_option_index' => null,
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

        $this->toolEvents = array_slice($this->toolEvents, -10);
    }

    private function assistantSettings(): array
    {
        $settings = Setting::getValue('ai_assistant', 'workflow_copilot');

        return is_array($settings) ? $settings : [];
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

    private function encodeJson(mixed $value): string
    {
        return json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: '{}';
    }
}
