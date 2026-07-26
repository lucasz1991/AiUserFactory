<?php

namespace App\Services\Workflows;

use App\Models\Person;
use App\Models\Workflow;
use App\Models\WorkflowStep;
use App\Services\Mail\MailAccountRegistrationRunner;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class WorkflowBrowserSessionService
{
    public const SETTINGS_KEY = 'browser_session';

    public const LOAD_TASK_KEY = 'browser.open_browser_session';

    public const SAVE_TASK_KEY = 'data.persist_browser_session';

    public const DELETE_TASK_KEY = 'data.delete_browser_session';

    public function settings(?Workflow $workflow): array
    {
        $settings = is_array($workflow?->settings_json) ? $workflow->settings_json : [];

        return $this->normalize(
            is_array($settings[self::SETTINGS_KEY] ?? null) ? $settings[self::SETTINGS_KEY] : [],
        );
    }

    public function normalize(array $settings): array
    {
        return [
            'enabled' => $this->boolean($settings['enabled'] ?? true, true),
            'load_at_start' => $this->boolean($settings['load_at_start'] ?? $settings['loadAtStart'] ?? true, true),
            'save_at_end' => $this->boolean($settings['save_at_end'] ?? $settings['saveAtEnd'] ?? true, true),
            'session_key' => $this->normalizeSessionKey($settings['session_key'] ?? $settings['sessionKey'] ?? ''),
            'fallback_url' => $this->httpUrl($settings['fallback_url'] ?? $settings['fallbackUrl'] ?? ''),
            'target_domain' => $this->normalizeText($settings['target_domain'] ?? $settings['targetDomain'] ?? ''),
            'browser_window' => $this->normalizeBrowserWindow($settings['browser_window'] ?? $settings['browserWindow'] ?? 'main'),
            'session_label' => $this->normalizeText($settings['session_label'] ?? $settings['sessionLabel'] ?? ''),
        ];
    }

    public function runtimeConfig(Workflow $workflow, ?int $personId): array
    {
        $settings = $this->settings($workflow);
        $configuredKey = $this->normalizeSessionKey($settings['session_key']);
        $effectiveKey = $configuredKey !== ''
            ? $configuredKey
            : $this->defaultSessionKey((int) $workflow->getKey(), $personId);

        return [
            ...$settings,
            'configured_session_key' => $configuredKey,
            'effective_session_key' => $effectiveKey,
            'workflow_id' => (int) $workflow->getKey(),
            'person_id' => $personId,
            'scope' => $personId === null ? 'verification' : 'person',
        ];
    }

    public function runtimeContext(Workflow $workflow, ?int $personId): array
    {
        $person = $personId !== null && $personId > 0
            ? Person::query()->find($personId)
            : null;

        if ($person) {
            $metadata = is_array($person->metadata) ? $person->metadata : [];
            $sessions = $this->decryptSessions($metadata['browser_sessions'] ?? []);
        } else {
            $settings = app(MailAccountRegistrationRunner::class)->settings();
            $mailbox = is_array($settings['verification_mailbox'] ?? null) ? $settings['verification_mailbox'] : [];
            $sessions = $this->decryptSessions($mailbox['browser_sessions'] ?? []);
        }

        $automation = $this->runtimeConfig($workflow, $person?->id);

        return [
            'browserSessions' => $sessions,
            'browser_sessions' => $sessions,
            'browserSessionAutomation' => $automation,
            'browser_session_automation' => $automation,
        ];
    }

    public function defaultSessionKey(int $workflowId, ?int $personId): string
    {
        return 'workflow-'.$workflowId.'-person-'.($personId === null ? 'null' : $personId);
    }

    public function storeSettings(Workflow $workflow, array $browserSessionSettings): void
    {
        $settings = is_array($workflow->settings_json) ? $workflow->settings_json : [];
        $settings[self::SETTINGS_KEY] = $this->normalize($browserSessionSettings);
        $workflow->forceFill(['settings_json' => $settings])->save();
    }

    /**
     * Moves legacy visible load/save cards into workflow settings.
     *
     * Delete cards stay explicit because deleting a session is a deliberate
     * workflow action and must not happen automatically.
     */
    public function migrateLegacyTasks(Workflow $workflow): array
    {
        $workflow->loadMissing(['steps' => fn ($query) => $query->ordered()]);
        $legacyTasks = $workflow->steps
            ->flatMap(fn (WorkflowStep $step) => collect($step->task_cards)
                ->filter(fn (array $task): bool => in_array(
                    (string) ($task['task_key'] ?? ''),
                    [self::LOAD_TASK_KEY, self::SAVE_TASK_KEY],
                    true,
                ))
                ->map(fn (array $task): array => [...$task, '__workflow_step_id' => (int) $step->id]))
            ->values();

        if ($legacyTasks->isEmpty()) {
            return ['changed' => false, 'removed' => 0, 'settings' => $this->settings($workflow)];
        }

        $current = $this->settings($workflow);
        $workflowSettings = is_array($workflow->settings_json) ? $workflow->settings_json : [];
        $hasExplicitSettings = is_array($workflowSettings[self::SETTINGS_KEY] ?? null);
        $loadTasks = $legacyTasks->where('task_key', self::LOAD_TASK_KEY);
        $saveTasks = $legacyTasks->where('task_key', self::SAVE_TASK_KEY);
        $firstValue = function ($tasks, array $keys): string {
            foreach ($tasks as $task) {
                foreach ($keys as $key) {
                    $value = trim((string) ($task[$key] ?? ''));

                    if ($value !== '') {
                        return $value;
                    }
                }
            }

            return '';
        };

        $migrated = $this->normalize([
            ...$current,
            'enabled' => $hasExplicitSettings ? $current['enabled'] : true,
            'load_at_start' => $hasExplicitSettings ? $current['load_at_start'] : true,
            'save_at_end' => $hasExplicitSettings ? $current['save_at_end'] : true,
            'session_key' => $firstValue($legacyTasks, ['session_key', 'sessionKey'])
                ?: $current['session_key'],
            'fallback_url' => $firstValue($loadTasks, ['fallback_url', 'fallbackUrl', 'url', 'input', 'value'])
                ?: $current['fallback_url'],
            'target_domain' => $firstValue($saveTasks, ['target_domain', 'targetDomain', 'domain'])
                ?: $firstValue($loadTasks, ['target_domain', 'targetDomain', 'domain'])
                ?: $current['target_domain'],
            'browser_window' => $firstValue($legacyTasks, ['browser_window_name', 'browser_window', 'browserWindow'])
                ?: $current['browser_window'],
            'session_label' => $firstValue($saveTasks, ['session_label', 'sessionLabel', 'label'])
                ?: $current['session_label'],
        ]);

        DB::transaction(function () use ($workflow, $migrated): void {
            $settings = is_array($workflow->settings_json) ? $workflow->settings_json : [];
            $settings[self::SETTINGS_KEY] = $migrated;
            $workflow->forceFill(['settings_json' => $settings])->save();

            foreach ($workflow->steps as $step) {
                $config = is_array($step->config_json) ? $step->config_json : [];
                $tasks = is_array($config['tasks'] ?? null) ? $config['tasks'] : [];
                $filtered = $this->removeLegacyTasksAndRepairRoutes($tasks);

                if ($filtered === $tasks) {
                    continue;
                }

                $config['tasks'] = $filtered;
                $step->forceFill(['config_json' => $config])->save();
            }
        });

        $workflow->refresh();
        $workflow->unsetRelation('steps');

        return [
            'changed' => true,
            'removed' => $legacyTasks->count(),
            'settings' => $migrated,
        ];
    }

    protected function removeLegacyTasksAndRepairRoutes(array $tasks): array
    {
        $tasks = collect($tasks)->filter(fn (mixed $task): bool => is_array($task))->values()->all();
        $removedKeys = collect($tasks)
            ->filter(fn (array $task): bool => in_array(
                (string) ($task['task_key'] ?? ''),
                [self::LOAD_TASK_KEY, self::SAVE_TASK_KEY],
                true,
            ))
            ->pluck('key')
            ->map(fn (mixed $key): string => trim((string) $key))
            ->filter()
            ->values()
            ->all();

        if ($removedKeys === []) {
            return $tasks;
        }

        $replacementByKey = [];

        foreach ($tasks as $index => $task) {
            $key = trim((string) ($task['key'] ?? ''));

            if ($key === '' || ! in_array($key, $removedKeys, true)) {
                continue;
            }

            $replacementByKey[$key] = collect(array_slice($tasks, $index + 1))
                ->first(fn (array $candidate): bool => ! in_array(
                    (string) ($candidate['task_key'] ?? ''),
                    [self::LOAD_TASK_KEY, self::SAVE_TASK_KEY],
                    true,
                ));
        }

        return collect($tasks)
            ->reject(fn (array $task): bool => in_array(
                (string) ($task['task_key'] ?? ''),
                [self::LOAD_TASK_KEY, self::SAVE_TASK_KEY],
                true,
            ))
            ->map(function (array $task) use ($removedKeys, $replacementByKey): array {
                foreach (['next', 'on_error'] as $routeKey) {
                    if (is_array($task[$routeKey] ?? null)) {
                        $task[$routeKey] = $this->repairRoute(
                            $task[$routeKey],
                            $removedKeys,
                            $replacementByKey,
                        );

                        if ($task[$routeKey] === []) {
                            unset($task[$routeKey]);
                        }
                    }
                }

                if (is_array($task['status_routes'] ?? null)) {
                    $task['status_routes'] = collect($task['status_routes'])
                        ->map(fn (mixed $route): mixed => is_array($route)
                            ? $this->repairRoute($route, $removedKeys, $replacementByKey)
                            : $route)
                        ->filter(fn (mixed $route): bool => $route !== [])
                        ->all();
                }

                return $task;
            })
            ->values()
            ->all();
    }

    protected function repairRoute(array $route, array $removedKeys, array $replacementByKey): array
    {
        $targetKey = trim((string) ($route['card_key'] ?? $route['card'] ?? $route['task_key'] ?? ''));

        if ($targetKey === '' || ! in_array($targetKey, $removedKeys, true)) {
            return $route;
        }

        $replacement = $replacementByKey[$targetKey] ?? null;
        $replacementKey = is_array($replacement) ? trim((string) ($replacement['key'] ?? '')) : '';

        if ($replacementKey === '') {
            return [];
        }

        if (array_key_exists('card_key', $route)) {
            $route['card_key'] = $replacementKey;
        } elseif (array_key_exists('card', $route)) {
            $route['card'] = $replacementKey;
        } else {
            $route['task_key'] = $replacementKey;
        }

        return $route;
    }

    protected function normalizeSessionKey(mixed $value): string
    {
        $value = strtolower(trim((string) $value));
        $value = preg_replace('/[^a-z0-9._-]+/', '-', $value) ?? '';

        return trim($value, '-');
    }

    protected function decryptSessions(mixed $sessions): array
    {
        $sessions = is_array($sessions) ? $sessions : [];
        $decryptedSessions = [];

        foreach ($sessions as $key => $session) {
            if (! is_array($session)) {
                continue;
            }

            $encryptedPayload = trim((string) ($session['payload_encrypted'] ?? ''));

            try {
                $decrypted = $encryptedPayload !== '' ? Crypt::decryptString($encryptedPayload) : '';
            } catch (\Throwable) {
                $decrypted = '';
            }

            $payload = $decrypted !== '' ? json_decode($decrypted, true) : null;

            if (! is_array($payload)) {
                continue;
            }

            $sessionKey = trim((string) ($session['session_key'] ?? $key)) ?: (string) $key;
            $decryptedSessions[$sessionKey] = array_replace(
                collect($session)->except(['payload_encrypted'])->all(),
                $payload,
                [
                    'sessionKey' => $sessionKey,
                    'session_key' => $sessionKey,
                    'finalUrl' => $payload['finalUrl'] ?? $session['final_url'] ?? null,
                    'final_url' => $session['final_url'] ?? $payload['finalUrl'] ?? null,
                    'updated_at' => $session['updated_at'] ?? null,
                ],
            );
        }

        return $decryptedSessions;
    }

    protected function normalizeBrowserWindow(mixed $value): string
    {
        $value = Str::slug(trim((string) $value));

        return $value !== '' ? $value : 'main';
    }

    protected function normalizeText(mixed $value): string
    {
        return trim((string) $value);
    }

    protected function httpUrl(mixed $value): string
    {
        $value = trim((string) $value);

        return preg_match('#^https?://#i', $value) === 1 ? $value : '';
    }

    protected function boolean(mixed $value, bool $fallback): bool
    {
        if ($value === null || $value === '') {
            return $fallback;
        }

        return filter_var($value, FILTER_VALIDATE_BOOL, FILTER_NULL_ON_FAILURE) ?? $fallback;
    }
}
