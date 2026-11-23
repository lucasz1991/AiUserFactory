<?php

namespace Tests\Feature;

use App\Jobs\RunWorkflowJob;
use App\Models\User;
use App\Models\Workflow;
use App\Models\WorkflowAssistanceRequest;
use App\Models\WorkflowRun;
use App\Models\WorkflowStep;
use App\Models\WorkflowStepRun;
use App\Notifications\FactoryWebPushNotification;
use App\Services\Workflows\WorkflowAssistanceService;
use App\Services\Workflows\WorkflowExecutionService;
use App\Support\Push\PushCategory;
use DomainException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class WorkflowAssistanceServiceTest extends TestCase
{
    use RefreshDatabase;

    private const VAPID_PUBLIC = 'BFnRnMKRs1PnHWo8Sy4c2Q4YFbcSXjLlRi_yBEUZk6iPBw04sFbCVGnBWCEd3vFF1FIvmqMhH0BEeIBGGCA1a9M';

    private const VAPID_PRIVATE = 'sYAEeaSjGaHrhCsLGwlOgWFOHl1YS_gJvOd_Xrf1KTs';

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'app.key' => 'base64:MTIzNDU2Nzg5MDEyMzQ1Njc4OTAxMjM0NTY3ODkwMTI=',
            'webpush.enabled' => true,
            'webpush.auto_provision' => false,
            'webpush.vapid.subject' => 'mailto:betrieb@follow-flow.de',
            'webpush.vapid.public_key' => self::VAPID_PUBLIC,
            'webpush.vapid.private_key' => self::VAPID_PRIVATE,
        ]);
        Notification::fake();
        Queue::fake();
    }

    public function test_captcha_request_is_idempotent_redacts_browser_state_and_notifies_only_active_admins_once(): void
    {
        User::query()->update(['status' => false]);
        $firstAdmin = User::factory()->create(['role' => 'admin', 'status' => true]);
        $secondAdmin = User::factory()->create(['role' => 'admin', 'status' => true]);
        $inactiveAdmin = User::factory()->create(['role' => 'admin', 'status' => false]);
        $regularUser = User::factory()->create(['role' => 'staff', 'status' => true]);
        $deletedAdmin = User::factory()->create(['role' => 'admin', 'status' => true]);
        foreach ([$firstAdmin, $secondAdmin, $inactiveAdmin, $regularUser, $deletedAdmin] as $index => $user) {
            $this->enableWorkflowPush($user, 'assistance-'.$index);
        }
        $deletedAdmin->delete();
        [, , $run, $stepRun] = $this->pausedWorkflowRun();
        $service = app(WorkflowAssistanceService::class);
        $result = $this->captchaResult();

        $firstRequest = $service->requestCaptcha($stepRun, $result);
        $secondRequest = $service->requestCaptcha($stepRun->fresh(), $result);

        $this->assertSame($firstRequest->getKey(), $secondRequest->getKey());
        $this->assertSame($firstRequest->source_key, $secondRequest->source_key);
        $this->assertDatabaseCount('workflow_assistance_requests', 1);
        $this->assertDatabaseCount('workflow_assistance_events', 1);
        $this->assertSame(
            $firstRequest->getKey(),
            (int) data_get($run->fresh()->context_json, 'workflow_assistance.active_request_id'),
        );

        $browserState = $firstRequest->fresh()->browser_state_json;
        $this->assertSame('https://example.test/challenge', $browserState['current_url']);
        $this->assertJsonSame([[
            'key' => 'main',
            'label' => 'Main Browser',
            'url' => 'https://example.test/challenge',
            'title' => 'reCAPTCHA present',
            'preview_path' => 'workflow-task-runs/run-123/live.png',
            'captured_at' => '2026-08-03T10:00:00+02:00',
            'stale' => false,
        ]], $browserState['windows']);

        $serializedState = json_encode($browserState, JSON_THROW_ON_ERROR);
        foreach (['browser-secret', 'cookie-secret', 'runtime-secret', 'username', 'password'] as $secret) {
            $this->assertStringNotContainsString($secret, $serializedState);
        }
        $this->assertJsonSame([
            'provider' => 'recaptcha',
            'frameCount' => 2,
            'responsePresent' => false,
        ], data_get($firstRequest->fresh()->metadata_json, 'evidence'));

        Notification::assertSentToTimes($firstAdmin, FactoryWebPushNotification::class, 1);
        Notification::assertSentToTimes($secondAdmin, FactoryWebPushNotification::class, 1);
        Notification::assertNotSentTo($inactiveAdmin, FactoryWebPushNotification::class);
        Notification::assertNotSentTo($regularUser, FactoryWebPushNotification::class);
        Notification::assertNotSentTo($deletedAdmin, FactoryWebPushNotification::class);
        Notification::assertSentTo(
            $firstAdmin,
            FactoryWebPushNotification::class,
            fn (FactoryWebPushNotification $notification): bool => $notification->notificationId
                === 'workflow-assistance:'.$firstRequest->request_uuid
                && $notification->category === PushCategory::Workflows
                && $notification->url === 'netzwerk/workflow-aufgaben/'.$firstRequest->request_uuid,
        );
        Notification::assertCount(2);
    }

    public function test_normal_resume_is_blocked_while_an_assistance_request_is_active(): void
    {
        [, , $run, $stepRun] = $this->pausedWorkflowRun();
        app(WorkflowAssistanceService::class)->requestCaptcha($stepRun, $this->captchaResult());

        $response = app(WorkflowExecutionService::class)->resumeManualPause($run);

        $this->assertFalse($response['ok']);
        $this->assertStringContainsString('Admin-Aufgabe', $response['message']);
        $this->assertSame('paused', $run->fresh()->status);
        $this->assertSame('waiting', $stepRun->fresh()->status);
        Queue::assertNotPushed(RunWorkflowJob::class);
    }

    public function test_resolution_requires_a_negative_verification_probe_and_dispatches_resume_exactly_once(): void
    {
        User::query()->update(['status' => false]);
        $operator = User::factory()->create(['role' => 'admin', 'status' => true]);
        [, , $run, $stepRun] = $this->pausedWorkflowRun();
        $service = app(WorkflowAssistanceService::class);
        $request = $service->claim(
            $service->requestCaptcha($stepRun, $this->captchaResult()),
            $operator,
        );

        $this->assertDomainException(
            fn () => $service->resolveAndResume($request, $operator),
            'zuerst erneut pruefen',
        );
        Queue::assertNotPushed(RunWorkflowJob::class);

        $this->storeVerificationProbe($run, $request, true);
        $this->assertDomainException(
            fn () => $service->resolveAndResume($request, $operator),
            'weiterhin sichtbar',
        );
        $this->assertSame(WorkflowAssistanceRequest::STATUS_CLAIMED, $request->fresh()->status);
        $this->assertSame('paused', $run->fresh()->status);
        Queue::assertNotPushed(RunWorkflowJob::class);

        $this->storeVerificationProbe($run, $request, false);
        $firstResponse = $service->resolveAndResume($request, $operator, '<b>Captcha geloest</b>');
        $secondResponse = $service->resolveAndResume($request->fresh(), $operator);

        $this->assertTrue($firstResponse['ok']);
        $this->assertTrue($secondResponse['ok']);
        $this->assertStringContainsString('bereits fortgesetzt', $secondResponse['message']);
        $this->assertSame('running', $run->fresh()->status);
        $this->assertNull(data_get($run->fresh()->context_json, 'workflow_assistance'));
        $this->assertSame('queued', $stepRun->fresh()->status);
        $this->assertSame(WorkflowAssistanceRequest::STATUS_RESOLVED, $request->fresh()->status);
        $this->assertNotNull($request->fresh()->resume_dispatched_at);
        $this->assertSame('Captcha geloest', $request->fresh()->resolution_note);
        $this->assertSame(1, $request->events()->where('event_type', 'resumed')->count());
        Queue::assertPushed(
            RunWorkflowJob::class,
            fn (RunWorkflowJob $job): bool => $job->workflowRunId === $run->getKey(),
        );
        Queue::assertPushed(RunWorkflowJob::class, 1);
    }

    public function test_verification_handoff_keeps_provider_and_selector_and_only_accepts_its_own_probe(): void
    {
        User::query()->update(['status' => false]);
        $operator = User::factory()->create(['role' => 'admin', 'status' => true]);
        [, , $run, $stepRun] = $this->pausedWorkflowRun();
        $service = app(WorkflowAssistanceService::class);
        $result = $this->verificationHandoffResult();

        $this->assertTrue($service->requiresCaptchaAssistance($result));
        $request = $service->claim($service->requestCaptcha($stepRun, $result), $operator);

        $this->assertStringStartsWith('Proton-Verifizierung in', $request->title);
        $this->assertSame(WorkflowAssistanceService::VERIFICATION_TASK_KEY, $service->handoffTaskKey($request));
        $this->assertSame('proton', data_get($request->metadata_json, 'provider'));
        $this->assertSame(
            '[data-testid="verification"], .human-verification-modal',
            data_get($request->metadata_json, 'detection_selector'),
        );

        // Eine reCAPTCHA-Pruefung darf eine Proton-Aufgabe nicht freigeben.
        $this->storeVerificationProbe($run, $request, false);
        $this->assertDomainException(
            fn () => $service->resolveAndResume($request, $operator),
            'zuerst erneut pruefen',
        );

        $this->storeVerificationProbe($run, $request, true, WorkflowAssistanceService::VERIFICATION_TASK_KEY);
        $this->assertDomainException(
            fn () => $service->resolveAndResume($request, $operator),
            'weiterhin sichtbar',
        );

        $this->storeVerificationProbe($run, $request, false, WorkflowAssistanceService::VERIFICATION_TASK_KEY);
        $response = $service->resolveAndResume($request, $operator);

        $this->assertTrue($response['ok']);
        $this->assertSame(WorkflowAssistanceRequest::STATUS_RESOLVED, $request->fresh()->status);
        Queue::assertPushed(RunWorkflowJob::class, 1);
    }

    public function test_foreign_provider_without_generic_handoff_is_not_treated_as_captcha_assistance(): void
    {
        $result = $this->captchaResult();
        $result['humanIntervention']['provider'] = 'proton';

        $this->assertFalse(app(WorkflowAssistanceService::class)->requiresCaptchaAssistance($result));
    }

    public function test_probe_tasks_reuse_the_detection_selector_and_validate_drag_coordinates(): void
    {
        [, , , $stepRun] = $this->pausedWorkflowRun();
        $service = app(WorkflowAssistanceService::class);
        $request = $service->requestCaptcha($stepRun, $this->verificationHandoffResult());
        $probeTask = new \ReflectionMethod($service, 'probeTask');

        $verify = $probeTask->invoke($service, $request, 'verify', []);
        $this->assertSame(WorkflowAssistanceService::VERIFICATION_TASK_KEY, $verify['task_key']);
        $this->assertSame('[data-testid="verification"], .human-verification-modal', $verify['selector']);
        $this->assertSame('proton', $verify['provider']);
        $this->assertTrue($verify['verification_only']);

        $capturedAt = (string) ($service->latestBrowserSnapshot($request)['captured_at'] ?? '');
        $drag = $probeTask->invoke($service, $request, 'drag', [
            'from_x_ratio' => 0.2,
            'from_y_ratio' => 0.3,
            'to_x_ratio' => 0.6,
            'to_y_ratio' => 0.35,
            'captured_at' => $capturedAt,
        ]);
        $this->assertSame('browser.assistance_drag_coordinates', $drag['task_key']);
        $this->assertSame(0.6, $drag['to_x_ratio']);

        $this->assertDomainException(
            fn () => $probeTask->invoke($service, $request, 'drag', [
                'from_x_ratio' => 0.2,
                'from_y_ratio' => 0.3,
                'to_x_ratio' => 1.4,
                'to_y_ratio' => 0.35,
                'captured_at' => $capturedAt,
            ]),
            'ausserhalb des Browserbildes',
        );
    }

    private function pausedWorkflowRun(): array
    {
        $workflow = Workflow::query()->create([
            'name' => 'Assistance '.str()->random(8),
            'slug' => 'assistance-'.str()->random(12),
            'description' => '',
            'category' => 'test',
            'is_active' => true,
            'is_locked' => false,
            'trigger_type' => 'manual',
            'settings_json' => [],
        ]);
        $step = $workflow->steps()->create([
            'name' => 'Browser Tasks',
            'type' => WorkflowStep::TYPE_BROWSER_TASK,
            'action_key' => 'browser-tasks',
            'position' => 10,
            'is_enabled' => true,
            'config_json' => ['tasks' => [[
                'key' => 'captcha-task',
                'task_key' => WorkflowAssistanceService::CAPTCHA_TASK_KEY,
                'title' => 'reCAPTCHA pruefen',
            ]]],
        ]);
        $run = WorkflowRun::query()->create([
            'run_uuid' => (string) str()->uuid(),
            'workflow_id' => $workflow->getKey(),
            'current_workflow_step_id' => $step->getKey(),
            'status' => 'paused',
            'requested_by' => 'test',
            'queued_at' => now(),
            'started_at' => now(),
            'context_json' => [
                'next_task_key' => 'captcha-task',
                'manual_pause_checkpoint' => ['reason' => 'captcha'],
            ],
            'result_json' => [],
        ]);
        $stepRun = WorkflowStepRun::query()->create([
            'workflow_run_id' => $run->getKey(),
            'workflow_step_id' => $step->getKey(),
            'status' => 'waiting',
            'external_run_type' => 'workflow-task',
            'external_run_id' => 'captcha-runtime-123',
            'started_at' => now(),
            'logs_json' => [],
            'result_json' => [],
        ])->load(['workflowRun.workflow', 'workflowRun.studioSession', 'workflowStep']);

        return [$workflow, $step, $run, $stepRun];
    }

    private function captchaResult(): array
    {
        return [
            'manualInterventionRequired' => true,
            'humanIntervention' => [
                'id' => 'captcha-intervention-123',
                'type' => 'captcha',
                'provider' => 'recaptcha',
                'taskKey' => 'captcha-task',
                'browserWindow' => 'main',
                'instructions' => '<b>Bitte das Captcha loesen.</b>',
                'expiresAfterMinutes' => 15,
                'evidence' => [
                    'provider' => 'recaptcha',
                    'frameCount' => 2,
                    'responsePresent' => false,
                    'responseToken' => 'evidence-secret',
                ],
            ],
            'browserWindows' => [[
                'key' => 'main',
                'label' => '<b>Main Browser</b>',
                'url' => 'https://username:password@example.test/challenge?token=browser-secret#fragment',
                'title' => '<b>reCAPTCHA present</b>',
                'livePreviewRelativePath' => 'workflow-task-runs/run-123/live.png',
                'capturedAt' => '2026-08-03T10:00:00+02:00',
                'stale' => false,
                'cookies' => ['session' => 'cookie-secret'],
                'browserWsEndpoint' => 'ws://runtime-secret',
                'storageState' => ['authorization' => 'runtime-secret'],
            ]],
            'browserWsEndpoint' => 'ws://runtime-secret',
            'sessionToken' => 'runtime-secret',
        ];
    }

    private function verificationHandoffResult(): array
    {
        $result = $this->captchaResult();
        $result['humanIntervention'] = [
            ...$result['humanIntervention'],
            'id' => 'verification-intervention-123',
            'provider' => 'proton',
            'handoffTaskKey' => WorkflowAssistanceService::VERIFICATION_TASK_KEY,
            'detectionSelector' => '[data-testid="verification"], .human-verification-modal',
            'evidence' => ['provider' => 'proton', 'selectorSignals' => 1],
        ];

        return $result;
    }

    private function storeVerificationProbe(
        WorkflowRun $run,
        WorkflowAssistanceRequest $request,
        bool $captchaDetected,
        string $taskKey = WorkflowAssistanceService::CAPTCHA_TASK_KEY,
    ): void {
        $run->refresh();
        $context = is_array($run->context_json) ? $run->context_json : [];
        $context['studio_probe_result'] = [
            'task' => [
                'task_key' => $taskKey,
                'verification_only' => true,
                'assistance_request_uuid' => $request->request_uuid,
            ],
            'result' => [
                'tasks' => [[
                    'task_key' => $taskKey,
                    'captchaDetected' => $captchaDetected,
                ]],
            ],
            'completed_at' => now()->toIso8601String(),
        ];
        $run->forceFill(['context_json' => $context])->save();
    }

    private function enableWorkflowPush(User $user, string $endpointSuffix): void
    {
        $user->pushSubscriptions()->create([
            'endpoint' => 'https://fcm.googleapis.com/fcm/send/'.$endpointSuffix,
            'public_key' => 'test-public-key',
            'auth_token' => 'test-auth-token',
            'content_encoding' => 'aes128gcm',
        ]);
        $user->notificationPreferences()->create([
            'category' => PushCategory::Workflows->value,
            'web_push_enabled' => true,
        ]);
    }

    private function assertDomainException(callable $callback, string $expectedMessageFragment): void
    {
        try {
            $callback();
            $this->fail('Expected a DomainException to be thrown.');
        } catch (DomainException $exception) {
            $this->assertStringContainsString($expectedMessageFragment, $exception->getMessage());
        }
    }
}
