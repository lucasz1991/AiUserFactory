<?php

namespace Tests\Feature;

use App\Models\Workflow;
use App\Models\WorkflowStep;
use App\Services\Workflows\Tasks\PersistBrowserSessionTask;
use App\Services\Workflows\WorkflowBrowserSessionService;
use App\Services\Workflows\WorkflowTaskCatalog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class WorkflowBrowserSessionSettingsTest extends TestCase
{
    use RefreshDatabase;

    public function test_browser_session_automation_is_enabled_by_default_and_can_be_disabled_explicitly(): void
    {
        $workflow = $this->workflow();
        $service = app(WorkflowBrowserSessionService::class);

        $this->assertSame([
            'enabled' => true,
            'load_at_start' => true,
            'save_at_end' => true,
            'session_key' => '',
            'fallback_url' => '',
            'target_domain' => '',
            'browser_window' => 'main',
            'session_label' => '',
        ], $service->settings($workflow));
        $this->assertSame('workflow-'.$workflow->id.'-person-17', $service->runtimeConfig($workflow, 17)['effective_session_key']);
        $this->assertSame('workflow-'.$workflow->id.'-person-null', $service->runtimeConfig($workflow, null)['effective_session_key']);

        $service->storeSettings($workflow, [
            'enabled' => true,
            'load_at_start' => false,
            'save_at_end' => false,
            'session_key' => 'Shared Webmail',
        ]);

        $workflow->refresh();
        $settings = $service->settings($workflow);
        $this->assertTrue($settings['enabled']);
        $this->assertFalse($settings['load_at_start']);
        $this->assertFalse($settings['save_at_end']);
        $this->assertSame('shared-webmail', $settings['session_key']);
        $this->assertSame('shared-webmail', $service->runtimeConfig($workflow, 17)['effective_session_key']);
    }

    public function test_legacy_load_and_save_cards_are_migrated_to_settings_and_delete_stays_explicit(): void
    {
        $workflow = $this->workflow();
        $step = WorkflowStep::query()->create([
            'workflow_id' => $workflow->id,
            'name' => 'Webmail',
            'type' => WorkflowStep::TYPE_BROWSER_CONTROL,
            'action_key' => 'webmail',
            'position' => 10,
            'is_enabled' => true,
            'config_json' => [
                'tasks' => [
                    [
                        'key' => 'open',
                        'task_key' => 'browser.open',
                        'next' => ['type' => 'card', 'card_key' => 'load-session'],
                    ],
                    [
                        'key' => 'load-session',
                        'task_key' => WorkflowBrowserSessionService::LOAD_TASK_KEY,
                        'session_key' => 'shared-mail',
                        'url' => 'https://mail.example.test',
                        'browser_window' => 'webmail',
                    ],
                    [
                        'key' => 'read-mail',
                        'task_key' => 'mail.inbox_list_scan',
                    ],
                    [
                        'key' => 'save-session',
                        'task_key' => WorkflowBrowserSessionService::SAVE_TASK_KEY,
                        'target_domain' => 'mail.example.test',
                        'session_label' => 'Mailbox',
                    ],
                    [
                        'key' => 'delete-session',
                        'task_key' => WorkflowBrowserSessionService::DELETE_TASK_KEY,
                    ],
                ],
            ],
        ]);

        $result = app(WorkflowBrowserSessionService::class)->migrateLegacyTasks($workflow);

        $this->assertTrue($result['changed']);
        $this->assertSame(2, $result['removed']);
        $settings = $workflow->fresh()->settings_json['browser_session'];
        $this->assertSame('shared-mail', $settings['session_key']);
        $this->assertSame('https://mail.example.test', $settings['fallback_url']);
        $this->assertSame('mail.example.test', $settings['target_domain']);
        $this->assertSame('webmail', $settings['browser_window']);
        $this->assertSame('Mailbox', $settings['session_label']);

        $tasks = $step->fresh()->task_cards;
        $this->assertSame(['open', 'read-mail', 'delete-session'], collect($tasks)->pluck('key')->all());
        $this->assertSame('read-mail', data_get($tasks, '0.next.card_key'));
    }

    public function test_legacy_card_migration_does_not_reenable_an_explicit_opt_out(): void
    {
        $workflow = $this->workflow();
        app(WorkflowBrowserSessionService::class)->storeSettings($workflow, [
            'enabled' => true,
            'load_at_start' => false,
            'save_at_end' => false,
        ]);
        WorkflowStep::query()->create([
            'workflow_id' => $workflow->id,
            'name' => 'Legacy',
            'type' => WorkflowStep::TYPE_BROWSER_CONTROL,
            'action_key' => 'legacy',
            'position' => 10,
            'is_enabled' => true,
            'config_json' => [
                'tasks' => [
                    ['key' => 'load', 'task_key' => WorkflowBrowserSessionService::LOAD_TASK_KEY],
                    ['key' => 'save', 'task_key' => WorkflowBrowserSessionService::SAVE_TASK_KEY],
                ],
            ],
        ]);

        app(WorkflowBrowserSessionService::class)->migrateLegacyTasks($workflow);

        $settings = app(WorkflowBrowserSessionService::class)->settings($workflow->fresh());
        $this->assertFalse($settings['load_at_start']);
        $this->assertFalse($settings['save_at_end']);
    }

    public function test_load_and_save_remain_registered_but_are_hidden_from_task_library(): void
    {
        $catalog = app(WorkflowTaskCatalog::class);
        $optionKeys = collect($catalog->options())->pluck('key');

        $this->assertNotNull($catalog->task(WorkflowBrowserSessionService::LOAD_TASK_KEY));
        $this->assertNotNull($catalog->task(WorkflowBrowserSessionService::SAVE_TASK_KEY));
        $this->assertFalse($optionKeys->contains(WorkflowBrowserSessionService::LOAD_TASK_KEY));
        $this->assertFalse($optionKeys->contains(WorkflowBrowserSessionService::SAVE_TASK_KEY));
        $this->assertTrue($optionKeys->contains(WorkflowBrowserSessionService::DELETE_TASK_KEY));
    }

    public function test_verification_mailbox_browser_sessions_can_be_saved_and_deleted_by_shared_key(): void
    {
        $task = app(PersistBrowserSessionTask::class);
        $result = [
            'encryptedBrowserSessionPayload' => 'encrypted-payload',
            'sessionKey' => 'shared-mail',
            'sessionLabel' => 'Shared Mailbox',
            'browserSessionSummary' => [
                'domain' => 'mail.example.test',
                'finalUrl' => 'https://mail.example.test/inbox',
                'domains' => ['mail.example.test'],
                'cookieDomains' => ['.example.test'],
                'cookieCount' => 1,
            ],
        ];

        $saved = $task->handleVerificationMailbox($result);

        $this->assertTrue($saved['ok']);
        $settings = app(\App\Services\Mail\MailAccountRegistrationRunner::class)->settings();
        $this->assertSame(
            'encrypted-payload',
            data_get($settings, 'verification_mailbox.browser_sessions.shared-mail.payload_encrypted'),
        );

        $deleted = $task->deleteVerificationMailbox([
            'sessionKey' => 'shared-mail',
            'domain' => 'mail.example.test',
        ]);

        $this->assertSame(['shared-mail'], $deleted['deletedSessionKeys']);
        $settings = app(\App\Services\Mail\MailAccountRegistrationRunner::class)->settings();
        $this->assertSame([], data_get($settings, 'verification_mailbox.browser_sessions'));
    }

    protected function workflow(): Workflow
    {
        return Workflow::query()->create([
            'name' => 'Browser Session Test',
            'slug' => 'browser-session-test-'.uniqid(),
            'description' => '',
            'category' => 'test',
            'is_active' => true,
            'trigger_type' => 'manual',
            'settings_json' => [],
        ]);
    }
}
