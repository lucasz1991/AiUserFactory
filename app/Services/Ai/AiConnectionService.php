<?php

namespace App\Services\Ai;

use App\Models\Setting;
use GuzzleHttp\Psr7\Utils;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Psr\Http\Message\StreamInterface;
use RuntimeException;
use Symfony\Component\HttpFoundation\StreamedResponse;

class AiConnectionService
{
    protected string $settingsGroup = 'services';

    protected string $settingsKey = 'openrouter';

    public function request(array $payload, ?string $profile = null): array
    {
        $timeout = (int) ($payload['_timeout'] ?? $this->setting('timeout', 120));
        unset($payload['_timeout']);

        $payload = $this->preparePayload($payload, $profile);
        $apiUrl = $this->apiUrl();

        $response = Http::timeout(max(5, $timeout))
            ->withHeaders($this->headers())
            ->post($apiUrl, $payload);

        $this->throwIfFailed($response);

        $decoded = $response->json() ?? [];
        $this->recordCopilotUsage($payload, $decoded, $profile);

        return $decoded;
    }

    public function requestStreamed(array $payload, ?string $profile = null, ?callable $onTextDelta = null): array
    {
        if (! (bool) $this->setting('stream_enabled', true)) {
            $response = $this->request($payload, $profile);
            $content = data_get($response, 'choices.0.message.content');
            $toolCalls = data_get($response, 'choices.0.message.tool_calls', []);

            if (is_callable($onTextDelta) && is_string($content) && $content !== '' && $toolCalls === []) {
                $this->streamBufferedText($content, $onTextDelta);
            }

            return $response;
        }

        $timeout = (int) ($payload['_timeout'] ?? $this->setting('timeout', 120));
        unset($payload['_timeout']);

        $payload = $this->preparePayload([
            ...$payload,
            'stream' => true,
        ], $profile);
        $apiUrl = $this->apiUrl();

        $response = Http::timeout(max(5, $timeout))
            ->withOptions([
                'stream' => true,
                'http_errors' => false,
            ])
            ->withHeaders($this->headers())
            ->post($apiUrl, $payload);

        $this->throwIfFailed($response);

        $body = $response->toPsrResponse()->getBody();
        $contentType = strtolower((string) $response->header('Content-Type'));

        if (str_contains($contentType, 'text/event-stream')) {
            $decoded = $this->parseChatEventStream($body, $onTextDelta);
            $this->recordCopilotUsage($payload, $decoded, $profile);

            return $decoded;
        }

        $decoded = json_decode((string) $body, true);

        if (! is_array($decoded)) {
            throw new RuntimeException('AI connection returned invalid JSON.');
        }

        $content = data_get($decoded, 'choices.0.message.content');
        $toolCalls = data_get($decoded, 'choices.0.message.tool_calls', []);

        if (is_callable($onTextDelta) && is_string($content) && $content !== '' && $toolCalls === []) {
            $this->streamBufferedText($content, $onTextDelta);
        }

        $this->recordCopilotUsage($payload, $decoded, $profile);

        return $decoded;
    }

    public function text(string $prompt, ?string $system = null, array $options = []): string
    {
        $response = $this->request([
            'messages' => $this->messages($prompt, $system),
            ...$options,
        ], 'text');

        return (string) data_get($response, 'choices.0.message.content', '');
    }

    public function json(string $prompt, ?string $system = null, array $options = []): array
    {
        $response = $this->request([
            'messages' => $this->messages($prompt, $system),
            'response_format' => ['type' => 'json_object'],
            ...$options,
        ], 'data');

        $content = (string) data_get($response, 'choices.0.message.content', '');
        $content = $this->cleanJson($content);

        $decoded = json_decode($content, true);

        if (json_last_error() !== JSON_ERROR_NONE || ! is_array($decoded)) {
            Log::error('AI returned invalid JSON', [
                'content' => $content,
                'json_error' => json_last_error_msg(),
            ]);

            throw new RuntimeException('AI returned invalid JSON: '.json_last_error_msg());
        }

        return $decoded;
    }

    public function stream(array $payload, ?string $profile = null): StreamedResponse
    {
        $payload = $this->preparePayload([
            ...$payload,
            'stream' => true,
        ], $profile);

        $apiUrl = $this->apiUrl();

        return response()->stream(function () use ($payload, $apiUrl) {
            $response = Http::timeout(0)
                ->withOptions(['stream' => true])
                ->withHeaders($this->headers())
                ->post($apiUrl, $payload);

            $this->throwIfFailed($response);

            $body = $response->toPsrResponse()->getBody();

            while (! $body->eof()) {
                echo $body->read(4096);

                @ob_flush();
                flush();
            }
        }, 200, [
            'Content-Type' => 'text/event-stream',
            'Cache-Control' => 'no-cache',
            'X-Accel-Buffering' => 'no',
        ]);
    }

    protected function parseChatEventStream(StreamInterface $body, ?callable $onTextDelta = null): array
    {
        $content = '';
        $toolCalls = [];
        $finishReason = null;
        $usage = [];
        $responseId = null;
        $model = null;
        $provider = null;

        while (! $body->eof()) {
            $line = trim(Utils::readLine($body));

            if ($line === '' || str_starts_with($line, ':') || ! str_starts_with($line, 'data:')) {
                continue;
            }

            $data = trim(substr($line, 5));

            if ($data === '[DONE]') {
                break;
            }

            $event = json_decode($data, true);

            if (! is_array($event)) {
                continue;
            }

            if (is_array($event['usage'] ?? null)) {
                $usage = $event['usage'];
            }

            $responseId = is_string($event['id'] ?? null) ? $event['id'] : $responseId;
            $model = is_string($event['model'] ?? null) ? $event['model'] : $model;
            $provider = is_string($event['provider'] ?? null) ? $event['provider'] : $provider;

            $providerError = data_get($event, 'error.message');

            if (is_string($providerError) && $providerError !== '') {
                throw new RuntimeException('AI streaming error: '.$providerError);
            }

            $choice = data_get($event, 'choices.0', []);

            if (! is_array($choice)) {
                continue;
            }

            $delta = $choice['delta'] ?? $choice['message'] ?? [];
            $textDelta = is_array($delta) ? ($delta['content'] ?? '') : '';

            if (is_string($textDelta) && $textDelta !== '') {
                $content .= $textDelta;

                if (is_callable($onTextDelta)) {
                    $onTextDelta($textDelta);
                }
            }

            foreach (is_array($delta) ? ($delta['tool_calls'] ?? []) : [] as $toolCallDelta) {
                if (! is_array($toolCallDelta)) {
                    continue;
                }

                $index = max(0, (int) ($toolCallDelta['index'] ?? 0));
                $toolCalls[$index] ??= [
                    'id' => '',
                    'type' => 'function',
                    'function' => [
                        'name' => '',
                        'arguments' => '',
                    ],
                ];

                $idDelta = (string) ($toolCallDelta['id'] ?? '');
                $typeDelta = (string) ($toolCallDelta['type'] ?? '');
                $nameDelta = (string) data_get($toolCallDelta, 'function.name', '');
                $argumentsDelta = (string) data_get($toolCallDelta, 'function.arguments', '');

                if ($idDelta !== '') {
                    $toolCalls[$index]['id'] = $idDelta;
                }

                if ($typeDelta !== '') {
                    $toolCalls[$index]['type'] = $typeDelta;
                }

                if ($nameDelta !== '') {
                    $currentName = (string) $toolCalls[$index]['function']['name'];

                    if ($currentName === '' || str_starts_with($nameDelta, $currentName)) {
                        $toolCalls[$index]['function']['name'] = $nameDelta;
                    } elseif (! str_ends_with($currentName, $nameDelta)) {
                        $toolCalls[$index]['function']['name'] .= $nameDelta;
                    }
                }

                if ($argumentsDelta !== '') {
                    $toolCalls[$index]['function']['arguments'] .= $argumentsDelta;
                }
            }

            if (is_string($choice['finish_reason'] ?? null)) {
                $finishReason = $choice['finish_reason'];
            }
        }

        $message = [
            'role' => 'assistant',
            'content' => $content,
        ];

        if ($toolCalls !== []) {
            ksort($toolCalls);
            $message['tool_calls'] = array_values($toolCalls);
        }

        return array_filter([
            'id' => $responseId,
            'model' => $model,
            'provider' => $provider,
            'usage' => $usage !== [] ? $usage : null,
            'choices' => [[
                'message' => $message,
                'finish_reason' => $finishReason,
            ]],
        ], static fn (mixed $value): bool => $value !== null);
    }

    protected function streamBufferedText(string $content, callable $onTextDelta): void
    {
        $parts = preg_split('/(\s+)/u', $content, -1, PREG_SPLIT_DELIM_CAPTURE | PREG_SPLIT_NO_EMPTY) ?: [$content];
        $chunk = '';

        foreach ($parts as $part) {
            $chunk .= $part;

            if (mb_strlen($chunk) < 36) {
                continue;
            }

            $onTextDelta($chunk);
            $chunk = '';
            usleep(12000);
        }

        if ($chunk !== '') {
            $onTextDelta($chunk);
        }
    }

    public function imageGeneration(string $prompt, array $options = []): array
    {
        $referenceImages = $options['reference_images'] ?? [];
        unset($options['reference_images']);

        return $this->request([
            '_timeout' => $options['_timeout'] ?? (int) $this->setting('image_generation_timeout', 600),
            'messages' => [
                [
                    'role' => 'user',
                    'content' => $this->imageMessageContent($prompt, $referenceImages),
                ],
            ],
            'modalities' => $options['modalities'] ?? ['image', 'text'],
            ...$options,
        ], 'image_generation');
    }

    public function generatedImageUrls(array $response): array
    {
        $images = data_get($response, 'choices.0.message.images', []);

        if (! is_array($images)) {
            return [];
        }

        return array_values(array_filter(array_map(function (mixed $image): string {
            if (! is_array($image)) {
                return '';
            }

            return trim((string) (
                data_get($image, 'image_url.url')
                ?? data_get($image, 'imageUrl.url')
                ?? data_get($image, 'url')
                ?? ''
            ));
        }, $images)));
    }

    public function imageUnderstanding(string $prompt, string $imageUrl, array $options = []): array
    {
        return $this->request([
            'messages' => [
                [
                    'role' => 'user',
                    'content' => [
                        ['type' => 'text', 'text' => $prompt],
                        [
                            'type' => 'image_url',
                            'image_url' => [
                                'url' => $imageUrl,
                            ],
                        ],
                    ],
                ],
            ],
            ...$options,
        ], 'image_understanding');
    }

    public function textToSpeech(string $text, array $options = []): array
    {
        return $this->request([
            'messages' => [
                [
                    'role' => 'user',
                    'content' => $text,
                ],
            ],
            'modalities' => ['audio'],
            ...$options,
        ], 'text_to_speech');
    }

    public function speechToText(string $audioUrl, array $options = []): array
    {
        // Das Chat-Completions-Schema erwartet input_audio als {data, format};
        // data-URLs werden entsprechend zerlegt, alles andere als url durchgereicht.
        $inputAudio = ['url' => $audioUrl];

        if (preg_match('/^data:audio\/([a-z0-9.+-]+);base64,(.+)$/is', $audioUrl, $matches) === 1) {
            $inputAudio = [
                'data' => $matches[2],
                'format' => strtolower($options['format'] ?? $matches[1]),
            ];
        }

        unset($options['format']);

        return $this->request([
            'messages' => [
                [
                    'role' => 'user',
                    'content' => [
                        ['type' => 'text', 'text' => 'Transkribiere die Audiodatei wortgetreu.'],
                        [
                            'type' => 'input_audio',
                            'input_audio' => $inputAudio,
                        ],
                    ],
                ],
            ],
            ...$options,
        ], 'speech_to_text');
    }

    protected function preparePayload(array $payload, ?string $profile): array
    {
        $model = $payload['model'] ?? $this->modelForProfile($profile);

        if (! is_string($model) || trim($model) === '') {
            throw new RuntimeException('OpenRouter Modell fehlt. Bitte unter Einstellungen > OpenRouter / AI Connection speichern.');
        }

        $prepared = [
            'model' => trim($model),
            'messages' => $payload['messages'] ?? [],
            'temperature' => $payload['temperature'] ?? (float) $this->setting('temperature', 0.4),
            'max_completion_tokens' => $payload['max_completion_tokens'] ?? (int) $this->setting('max_completion_tokens', 1500),
            ...$payload,
        ];

        return array_filter($prepared, static fn ($value) => $value !== null);
    }

    protected function modelForProfile(?string $profile): ?string
    {
        return match ($profile) {
            'text' => $this->setting('text_model'),
            'data', 'data_analysis' => $this->firstSetting(['data_model', 'analysis_model']),
            'image_generation' => $this->firstSetting(['image_generation_model', 'image_model']),
            'image_understanding', 'vision' => $this->firstSetting(['image_understanding_model', 'vision_model']),
            'speech_to_text', 'stt' => $this->setting('speech_to_text_model'),
            'text_to_speech', 'tts' => $this->setting('text_to_speech_model'),
            default => $this->setting('text_model'),
        };
    }

    protected function messages(string $prompt, ?string $system = null): array
    {
        $messages = [];

        if (filled($system)) {
            $messages[] = [
                'role' => 'system',
                'content' => $system,
            ];
        }

        $messages[] = [
            'role' => 'user',
            'content' => $prompt,
        ];

        return $messages;
    }

    protected function headers(): array
    {
        $apiKey = $this->setting('api_key');

        if (! is_string($apiKey) || trim($apiKey) === '') {
            throw new RuntimeException('OpenRouter API Key fehlt. Bitte unter Einstellungen > OpenRouter / AI Connection speichern.');
        }

        return array_filter([
            'Authorization' => 'Bearer '.trim($apiKey),
            'HTTP-Referer' => $this->firstSetting(['referer_url', 'site_url']),
            'X-Title' => $this->firstSetting(['model_title', 'app_name']),
            'Content-Type' => 'application/json',
        ], static fn ($value) => filled($value));
    }

    protected function apiUrl(): string
    {
        $apiUrl = $this->setting('api_url');

        if (! is_string($apiUrl) || trim($apiUrl) === '') {
            $baseUrl = trim((string) $this->setting('base_url', 'https://openrouter.ai/api/v1'));
            $apiUrl = rtrim($baseUrl, '/').'/chat/completions';
        }

        if (! is_string($apiUrl) || trim($apiUrl) === '') {
            throw new RuntimeException('OpenRouter API URL fehlt. Bitte unter Einstellungen > OpenRouter / AI Connection speichern.');
        }

        return trim($apiUrl);
    }

    protected function setting(string $key, mixed $default = null): mixed
    {
        $settings = Setting::getValue($this->settingsGroup, $this->settingsKey);

        if (! is_array($settings)) {
            $settings = [];
        }

        return $settings[$key] ?? config("services.openrouter.{$key}", $default);
    }

    protected function firstSetting(array $keys, mixed $default = null): mixed
    {
        foreach ($keys as $key) {
            $value = $this->setting($key);

            if (filled($value)) {
                return $value;
            }
        }

        return $default;
    }

    protected function imageMessageContent(string $prompt, mixed $referenceImages): array|string
    {
        $referenceImages = is_array($referenceImages) ? $referenceImages : [];
        $referenceImages = array_values(array_filter(array_map(
            static fn (mixed $image): string => trim((string) $image),
            $referenceImages
        )));

        if ($referenceImages === []) {
            return $prompt;
        }

        $content = [
            ['type' => 'text', 'text' => $prompt],
        ];

        foreach ($referenceImages as $imageUrl) {
            $content[] = [
                'type' => 'image_url',
                'image_url' => [
                    'url' => $imageUrl,
                ],
            ];
        }

        return $content;
    }

    protected function throwIfFailed(Response $response): void
    {
        if ($response->successful()) {
            return;
        }

        Log::error('AI connection failed', [
            'status' => $response->status(),
            'body' => $response->body(),
        ]);

        throw new RuntimeException(
            'AI connection failed with status '.$response->status().': '.$response->body()
        );
    }

    protected function recordCopilotUsage(array $request, array $response, ?string $profile): void
    {
        app(WorkflowCopilotAiUsageTracker::class)->recordResponse($request, $response, $profile);
    }

    protected function cleanJson(string $content): string
    {
        $content = trim($content);
        $content = preg_replace('/^```json\s*/i', '', $content);
        $content = preg_replace('/^```\s*/', '', $content);
        $content = preg_replace('/```$/', '', $content);

        $firstBrace = strpos($content, '{');
        $lastBrace = strrpos($content, '}');

        if ($firstBrace !== false && $lastBrace !== false && $lastBrace > $firstBrace) {
            $content = substr($content, $firstBrace, $lastBrace - $firstBrace + 1);
        }

        return trim($content);
    }
}
