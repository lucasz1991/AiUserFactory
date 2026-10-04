<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\Workflow;
use App\Models\WorkflowRecording;
use App\Services\Workflows\WorkflowDefinitionValidator;
use App\Services\Workflows\WorkflowRecordingBrowser;
use App\Services\Workflows\WorkflowRecordingService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Mockery;
use Tests\TestCase;

class WorkflowRecordingServiceTest extends TestCase
{
    use RefreshDatabase;

    protected WorkflowRecordingBrowser $browser;

    protected WorkflowRecordingService $service;

    protected User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->browser = Mockery::mock(WorkflowRecordingBrowser::class);
        $this->app->instance(WorkflowRecordingBrowser::class, $this->browser);
        $this->service = app(WorkflowRecordingService::class);
        $this->admin = User::factory()->create(['role' => 'admin', 'status' => true]);
    }

    public function test_only_active_admin_can_create_recordings(): void
    {
        foreach ([['role' => 'user', 'status' => true], ['role' => 'admin', 'status' => false]] as $attributes) {
            $user = User::factory()->create($attributes);
            try {
                $this->service->create($user, 'Test', 'https://example.test');
                $this->fail('An unauthorized user created a recording.');
            } catch (AuthorizationException) {
                $this->assertDatabaseCount('workflow_recordings', 0);
            }
        }
    }

    public function test_deleted_admin_cannot_create_recordings(): void
    {
        $this->admin->delete();
        $this->expectException(AuthorizationException::class);
        $this->service->create($this->admin, 'Test', 'https://example.test');
    }

    public function test_new_recording_is_a_private_encrypted_draft(): void
    {
        $recording = $this->recording('draft');
        $recording->forceFill(['runtime_json' => ['secret' => 'synthetic-runtime-token'], 'events_json' => [['note' => 'synthetic-draft-text']]])->save();
        $stored = DB::table('workflow_recordings')->find($recording->id);
        $this->assertSame('draft', $recording->status);
        $this->assertNotSame('', $recording->recording_uuid);
        $this->assertStringNotContainsString('synthetic-runtime-token', $stored->runtime_json);
        $this->assertStringNotContainsString('synthetic-draft-text', $stored->events_json);
        $this->assertArrayNotHasKey('runtime_json', $recording->toArray());
        $this->assertArrayNotHasKey('events_json', $recording->toArray());
        $this->assertArrayNotHasKey('state_json', $recording->toArray());
    }

    public function test_url_validation_refuses_non_http_and_embedded_credentials(): void
    {
        foreach (['javascript:alert(1)', 'file:///etc/passwd', 'data:text/html,Test', 'https://admin:password@example.test', 'https://example.test/a b'] as $url) {
            try {
                $this->service->create($this->admin, 'Test', $url);
                $this->fail('An unsafe URL was accepted.');
            } catch (ValidationException) {
                $this->assertDatabaseCount('workflow_recordings', 0);
            }
        }
    }

    public function test_foreign_recording_is_unavailable_even_to_another_admin(): void
    {
        $recording = $this->recording();
        $other = User::factory()->create(['role' => 'admin', 'status' => true]);
        $this->expectException(ModelNotFoundException::class);
        $this->service->owned($other, $recording->id);
    }

    public function test_secret_start_query_and_hash_values_are_refused_before_persistence(): void
    {
        foreach ([
            'https://example.test/?access_token=synthetic-private-token',
            'https://example.test/?api_key=synthetic-private-token',
            'https://example.test/?%70assword=synthetic-private-token',
            'https://example.test/#/login?session_id=synthetic-private-token',
            'https://example.test/#authorization=synthetic-private-token',
            'https://example.test/?q=ordinary&PHPSESSID=synthetic-private-token',
        ] as $url) {
            try {
                $this->service->create($this->admin, 'Test', $url);
                $this->fail('A secret-bearing start URL was persisted.');
            } catch (ValidationException $exception) {
                $this->assertStringNotContainsString('synthetic-private-token', json_encode($exception->errors()));
                $this->assertDatabaseCount('workflow_recordings', 0);
            }
        }
    }

    public function test_ordinary_search_and_shipping_parameters_are_allowed(): void
    {
        $url = 'https://example.test/?q=Search+phrase&shipping=express&product_code=abc#search';
        $recording = $this->service->create($this->admin, 'Test', $url);
        $this->assertSame($url, $recording->start_url);
    }

    public function test_secret_navigation_is_refused_before_browser_io(): void
    {
        $recording = $this->recording();
        $this->browser->shouldNotReceive('command');
        $this->expectException(ValidationException::class);
        $this->service->action($this->admin, $recording->id, ['type' => 'navigate', 'url' => 'https://example.test/?token=synthetic-private-token']);
    }

    public function test_redacted_runtime_url_is_not_saved_as_a_broken_navigation_task(): void
    {
        $recording = $this->recording();
        $this->browser->shouldReceive('state')->once()->andReturn($this->state([
            $this->event(1, 'navigate', ['url' => 'https://example.test/?access_token=%5Bgeschuetzt%5D']),
        ]));
        $this->browser->shouldReceive('close')->once();
        try {
            $this->service->poll($this->admin, $recording->id);
            $this->fail('A redacted executable URL was accepted.');
        } catch (ValidationException) {
            $this->assertSame([], $recording->fresh()->events_json);
            $this->assertSame('failed', $recording->fresh()->status);
        }
    }

    public function test_builder_refuses_preexisting_redacted_url_without_partial_workflow(): void
    {
        $recording = $this->recording('stopped', [$this->event(1, 'navigate', ['url' => 'https://example.test/#token=[geschuetzt]'])]);
        try {
            $this->service->save($this->admin, $recording->id);
            $this->fail('A broken redacted URL reached a workflow.');
        } catch (ValidationException) {
            $this->assertDatabaseCount('workflows', 0);
            $this->assertDatabaseCount('workflow_steps', 0);
        }
    }

    public function test_browser_start_navigation_and_actions_are_outside_short_transactions(): void
    {
        $recording = $this->recording('draft');
        $baseline = DB::transactionLevel();
        $this->browser->shouldReceive('start')->once()->andReturnUsing(function (WorkflowRecording $snapshot) use ($baseline): array {
            $this->assertSame($baseline, DB::transactionLevel());
            $this->assertSame('recording', $snapshot->status);

            return ['port' => 12345, 'secret' => 'synthetic-runtime-token'];
        });
        $this->browser->shouldReceive('command')->once()->andReturnUsing(function (WorkflowRecording $snapshot, array $command) use ($baseline): array {
            $this->assertSame($baseline, DB::transactionLevel());
            $this->assertSame(['type' => 'navigate', 'url' => 'https://example.test/start'], $command);
            $this->assertSame(12345, $snapshot->runtime_json['port']);

            return $this->state([$this->event(1, 'navigate', ['url' => 'https://example.test/start'])]);
        });
        $started = $this->service->start($this->admin, $recording->id);
        $this->assertSame('recording', $started->status);
        $this->assertCount(1, $started->events_json);
        $this->assertSame(1, $started->last_event_sequence);
    }

    public function test_initial_browser_failure_marks_own_recording_failed_without_details(): void
    {
        $recording = $this->recording('draft');
        $this->browser->shouldReceive('start')->once()->andThrow(new \RuntimeException('synthetic failure'));
        $this->browser->shouldReceive('close')->once();
        try {
            $this->service->start($this->admin, $recording->id);
            $this->fail('The failed browser unexpectedly started.');
        } catch (\RuntimeException) {
            $failed = $recording->fresh();
            $this->assertSame('failed', $failed->status);
            $this->assertSame(['error' => 'browser_unavailable'], $failed->state_json);
        }
    }

    public function test_capture_actions_are_refused_while_paused(): void
    {
        $recording = $this->recording('paused');
        $this->expectException(ValidationException::class);
        $this->service->action($this->admin, $recording->id, ['type' => 'click', 'x' => .4, 'y' => .2]);
    }

    public function test_unknown_command_is_refused_before_browser_io(): void
    {
        $recording = $this->recording();
        $this->expectException(ValidationException::class);
        $this->service->action($this->admin, $recording->id, ['type' => 'evaluate', 'script' => 'alert(1)']);
    }

    public function test_unknown_fixed_binding_is_refused_before_browser_input(): void
    {
        $recording = $this->recording();
        $this->expectException(ValidationException::class);
        $this->service->action($this->admin, $recording->id, ['type' => 'fill', 'source' => 'fixed', 'value' => 'person.hallucinated', 'selector' => '#email']);
    }

    public function test_invalid_variable_path_is_refused_before_browser_input(): void
    {
        $recording = $this->recording();
        $this->browser->shouldNotReceive('command');
        foreach (['workflow_inputs..email', 'workflow_inputs.email.', 'workflow_inputs.email-value'] as $variable) {
            try {
                $this->service->action($this->admin, $recording->id, [
                    'type' => 'fill', 'source' => 'workflow_variable', 'workflow_variable' => $variable, 'selector' => '#email',
                ]);
                $this->fail('An invalid variable path reached browser input.');
            } catch (ValidationException $exception) {
                $this->assertArrayHasKey('workflow_variable', $exception->errors());
                $this->assertSame([], $recording->fresh()->events_json);
                $this->assertSame('recording', $recording->fresh()->status);
            }
        }
    }

    public function test_valid_nested_variable_path_is_accepted_for_browser_input(): void
    {
        $recording = $this->recording();
        $variable = 'workflow_inputs.signup.email';
        $this->browser->shouldReceive('command')->once()->withArgs(fn ($owned, $command) => $owned->id === $recording->id
            && $command['workflow_variable'] === $variable)->andReturn($this->state([
                $this->event(1, 'fill', ['selector' => '#email', 'value_source' => 'workflow_variable', 'workflow_variable' => $variable]),
            ]));
        $result = $this->service->action($this->admin, $recording->id, [
            'type' => 'fill', 'source' => 'workflow_variable', 'workflow_variable' => $variable, 'selector' => '#email',
        ]);
        $this->assertSame($variable, $result->events_json[0]['workflow_variable']);
    }

    public function test_valid_generated_fixed_path_is_accepted_and_secret_sample_never_persisted(): void
    {
        $recording = $this->recording();
        $this->browser->shouldReceive('command')->once()->andReturn($this->state([
            $this->event(1, 'fill', ['selector' => '#password', 'input_type' => 'password', 'value_source' => 'fixed', 'value' => 'generated_password']),
        ]));
        $result = $this->service->action($this->admin, $recording->id, [
            'type' => 'fill', 'source' => 'fixed', 'value' => 'generated_password', 'selector' => '#password', 'previewValue' => 'synthetic-secret-sample',
        ]);
        $this->assertSame('generated_password', $result->events_json[0]['value']);
        $this->assertStringNotContainsString('synthetic-secret-sample', json_encode($this->service->publicState($result)));
    }

    public function test_paused_inspection_is_allowed_but_returns_only_safe_state(): void
    {
        $recording = $this->recording('paused');
        $this->browser->shouldReceive('command')->once()->andReturn($this->state([], [
            'secret' => 'synthetic-daemon-token',
            'selected' => ['selector' => '#password', 'input_type' => 'password', 'value' => 'synthetic-password', 'label' => 'Passwort', 'editable' => true],
        ]));
        $result = $this->service->action($this->admin, $recording->id, ['type' => 'inspect', 'x' => .4, 'y' => .2]);
        $public = $this->service->publicState($result);
        $this->assertTrue($public['selected']['sensitive']);
        $this->assertArrayNotHasKey('value', $public['selected']);
        $this->assertStringNotContainsString('synthetic-password', json_encode($public));
        $this->assertStringNotContainsString('synthetic-daemon-token', json_encode($public));
    }

    public function test_password_literal_is_never_saved_or_exposed_and_becomes_required_input(): void
    {
        $recording = $this->recording();
        $events = [
            $this->event(1, 'navigate', ['url' => 'https://example.test/start']),
            $this->event(2, 'fill', ['selector' => '#password', 'input_type' => 'password', 'value_source' => 'literal', 'value' => 'synthetic-private-password', 'previewValue' => 'synthetic-private-password']),
        ];
        $this->browser->shouldReceive('state')->once()->andReturn($this->state($events));
        $result = $this->service->poll($this->admin, $recording->id);
        $password = $result->events_json[1];
        $this->assertTrue($password['secret']);
        $this->assertTrue($password['sensitive']);
        $this->assertSame('workflow_variable', $password['value_source']);
        $this->assertSame('workflow_inputs.recorded_secret_2', $password['workflow_variable']);
        $this->assertArrayNotHasKey('value', $password);
        $this->assertArrayNotHasKey('previewValue', $password);
        $this->assertStringNotContainsString('synthetic-private-password', json_encode($this->service->publicState($result)));
        $result->forceFill(['status' => 'stopped', 'runtime_json' => []])->save();
        $workflow = $this->service->save($this->admin, $result->id);
        $cards = $workflow->steps->first()->task_cards;
        $this->assertSame('data.validate_inputs', $cards[0]['task_key']);
        $definition = json_decode($cards[0]['input_definitions'], true)[0];
        $this->assertSame('recorded_secret_2', $definition['name']);
        $this->assertTrue($definition['required']);
        $this->assertArrayNotHasKey('default', $definition);
        $this->assertSame(['recorded_secret_2'], $workflow->settings_json['recording_secret_input_names']);
        $this->assertStringNotContainsString('synthetic-private-password', json_encode($workflow->steps->first()->config_json));
    }

    public function test_password_source_cannot_be_downgraded_to_literal_by_editor(): void
    {
        $recording = $this->recording('stopped', [$this->event(1, 'fill', ['selector' => '#password', 'input_type' => 'password', 'secret' => true, 'value_source' => 'workflow_variable', 'workflow_variable' => 'workflow_inputs.password'])]);
        $this->expectException(ValidationException::class);
        $this->service->updateEvent($this->admin, $recording->id, 'event-1', ['value_source' => 'literal', 'value' => 'synthetic-private-password']);
    }

    public function test_value_sources_match_existing_catalog_and_preserve_literal_whitespace(): void
    {
        $recording = $this->recording();
        $this->browser->shouldReceive('state')->once()->andReturn($this->state([
            $this->event(1, 'fill', ['selector' => '#email', 'value_source' => 'fixed', 'value' => 'person.email']),
            $this->event(2, 'fill', ['selector' => '#query', 'value_source' => 'literal', 'value' => '  Search phrase  ']),
            $this->event(3, 'fill', ['selector' => '#query', 'value_source' => 'workflow_variable', 'workflow_variable' => 'workflow_inputs.query']),
        ]));
        $result = $this->service->poll($this->admin, $recording->id);
        $this->assertSame('person.email', $result->events_json[0]['value']);
        $this->assertSame('  Search phrase  ', $result->events_json[1]['value']);
        $this->assertSame('workflow_inputs.query', $result->events_json[2]['workflow_variable']);
    }

    public function test_unknown_fixed_source_is_not_accepted(): void
    {
        $recording = $this->recording();
        $this->browser->shouldReceive('state')->once()->andReturn($this->state([$this->event(1, 'fill', ['selector' => '#email', 'value_source' => 'fixed', 'value' => 'person.hallucinated'])]));
        $this->expectException(ValidationException::class);
        $this->service->poll($this->admin, $recording->id);
    }

    public function test_invalid_event_types_fail_atomically_without_partial_append(): void
    {
        $recording = $this->recording();
        $this->browser->shouldReceive('state')->once()->andReturn($this->state([
            $this->event(1, 'navigate', ['url' => 'https://example.test']),
            $this->event(2, 'evaluate', ['script' => 'alert(1)']),
        ]));
        try {
            $this->service->poll($this->admin, $recording->id);
            $this->fail('The unknown event was accepted.');
        } catch (ValidationException) {
            $this->assertSame([], $recording->fresh()->events_json);
            $this->assertSame(0, $recording->fresh()->last_event_sequence);
        }
    }

    public function test_missing_event_sequence_fails_closed(): void
    {
        $recording = $this->recording();
        $this->browser->shouldReceive('state')->once()->andReturn($this->state([$this->event(2, 'navigate', ['url' => 'https://example.test'])]));
        $this->expectException(ValidationException::class);
        $this->service->poll($this->admin, $recording->id);
    }

    public function test_duplicate_poll_does_not_duplicate_events(): void
    {
        $recording = $this->recording();
        $state = $this->state([$this->event(1, 'navigate', ['url' => 'https://example.test'])]);
        $this->browser->shouldReceive('state')->twice()->andReturn($state);
        $this->service->poll($this->admin, $recording->id);
        $result = $this->service->poll($this->admin, $recording->id);
        $this->assertCount(1, $result->events_json);
        $this->assertSame(1, $result->last_event_sequence);
    }

    public function test_daemon_event_limit_pause_is_reflected_without_false_db_reactivation(): void
    {
        $recording = $this->recording();
        $this->browser->shouldReceive('state')->once()->andReturn($this->state([], ['state' => 'paused']));
        $result = $this->service->poll($this->admin, $recording->id);
        $this->assertSame('paused', $result->status);
        $this->browser->shouldReceive('state')->once()->andReturn($this->state([], ['state' => 'recording']));
        $result = $this->service->poll($this->admin, $recording->id);
        $this->assertSame('paused', $result->status);
    }

    public function test_late_poll_cannot_restore_state_after_pause(): void
    {
        $recording = $this->recording();
        $this->browser->shouldReceive('state')->once()->andReturnUsing(function () use ($recording): array {
            $recording->fresh()->forceFill(['status' => 'paused', 'revision' => $recording->revision + 1])->save();

            return $this->state([$this->event(1, 'navigate', ['url' => 'https://late.example.test'])]);
        });
        $result = $this->service->poll($this->admin, $recording->id);
        $this->assertSame('paused', $result->status);
        $this->assertSame([], $result->events_json);
        $this->assertSame([], $result->state_json);
    }

    public function test_late_poll_does_not_overwrite_newer_browser_response(): void
    {
        $recording = $this->recording();
        $this->browser->shouldReceive('state')->once()->andReturnUsing(function () use ($recording): array {
            $recording->fresh()->forceFill(['state_json' => ['url' => 'https://new.example.test'], 'revision' => $recording->revision + 1])->save();

            return $this->state([], ['url' => 'https://old.example.test']);
        });
        $result = $this->service->poll($this->admin, $recording->id);
        $this->assertSame('https://new.example.test', $result->state_json['url']);
    }

    public function test_editor_changes_and_removal_survive_cumulative_runtime_poll(): void
    {
        $recording = $this->recording('paused', [
            $this->event(1, 'navigate', ['url' => 'https://example.test']),
            $this->event(2, 'fill', ['selector' => '#email', 'value_source' => 'literal', 'value' => 'original@example.test']),
        ]);
        $this->service->updateEvent($this->admin, $recording->id, 'event-2', ['value_source' => 'fixed', 'value' => 'person.email']);
        $this->service->removeEvent($this->admin, $recording->id, 'event-1');
        $this->browser->shouldReceive('state')->once()->andReturn($this->state([
            $this->event(1, 'navigate', ['url' => 'https://example.test']),
            $this->event(2, 'fill', ['selector' => '#email', 'value_source' => 'literal', 'value' => 'original@example.test']),
            $this->event(3, 'click', ['selector' => '#submit']),
        ]));
        $result = $this->service->poll($this->admin, $recording->id);
        $this->assertSame(['event-2', 'event-3'], array_column($result->events_json, 'id'));
        $this->assertSame('fixed', $result->events_json[0]['value_source']);
        $this->assertSame('person.email', $result->events_json[0]['value']);
    }

    public function test_editor_reorders_only_owned_existing_events(): void
    {
        $recording = $this->recording('stopped', [
            $this->event(1, 'navigate', ['url' => 'https://example.test']),
            $this->event(2, 'click', ['selector' => '#next']),
        ]);
        $result = $this->service->moveEvent($this->admin, $recording->id, 'event-2', -1);
        $this->assertSame(['event-2', 'event-1'], array_column($result->events_json, 'id'));
        $this->expectException(ValidationException::class);
        $this->service->moveEvent($this->admin, $recording->id, 'event-2', 4);
    }

    public function test_editor_cannot_mutate_sequence_type_or_secret_flag(): void
    {
        $recording = $this->recording('stopped', [$this->event(1, 'click', ['selector' => '#submit'])]);
        $this->expectException(ValidationException::class);
        $this->service->updateEvent($this->admin, $recording->id, 'event-1', ['type' => 'navigate', 'sequence' => 400, 'secret' => false]);
    }

    public function test_selector_alternatives_preserve_commas_inside_attribute_values(): void
    {
        $recording = $this->recording();
        $this->browser->shouldReceive('state')->once()->andReturn($this->state([$this->event(1, 'click', ['selectors' => ['button[data-label="A,B"]', '#submit', '#submit']])]));
        $result = $this->service->poll($this->admin, $recording->id);
        $this->assertSame('button[data-label="A,B"], #submit', $result->events_json[0]['selector']);
        $result->forceFill(['status' => 'paused'])->save();
        $edited = $this->service->updateEvent($this->admin, $result->id, 'event-1', ['selector' => 'button:is(.one,.two), #submit']);
        $this->assertSame('button:is(.one,.two), #submit', $edited->events_json[0]['selector']);
    }

    public function test_invalid_selector_is_rejected_on_edit(): void
    {
        $recording = $this->recording('stopped', [$this->event(1, 'click', ['selector' => '#submit'])]);
        $this->expectException(ValidationException::class);
        $this->service->updateEvent($this->admin, $recording->id, 'event-1', ['selector' => 'button[data-label="unclosed]']);
    }

    public function test_captured_identity_and_stable_alternatives_survive_value_edit_and_builder(): void
    {
        $recording = $this->recording();
        $signature = ['tag' => 'input', 'type' => 'email', 'name' => 'email'];
        $this->browser->shouldReceive('state')->once()->andReturn($this->state([
            $this->event(1, 'fill', [
                'selector' => '#email, input[name="email"]', 'selectors' => ['#email', 'input[name="email"]'],
                'stable_selectors' => ['#email', 'input[name="email"]'], 'target_signature' => $signature,
                'value_source' => 'literal', 'value' => 'test@example.test',
            ]),
        ]));
        $result = $this->service->poll($this->admin, $recording->id);
        $result->forceFill(['status' => 'paused'])->save();
        $result = $this->service->updateEvent($this->admin, $result->id, 'event-1', [
            'selector' => '#email, input[name="email"]', 'value_source' => 'fixed', 'value' => 'person.email',
        ]);
        $this->assertSame($signature, $result->events_json[0]['target_signature']);
        $this->assertSame(['#email', 'input[name="email"]'], $result->events_json[0]['stable_selectors']);
        $result->forceFill(['status' => 'stopped', 'runtime_json' => []])->save();
        $workflow = $this->service->save($this->admin, $result->id);
        $card = $workflow->steps->first()->task_cards[1];
        $this->assertSame($signature, $card['recorded_target_signature']);
        $this->assertSame(['#email', 'input[name="email"]'], $card['recorded_stable_selectors']);
    }

    public function test_explicit_selector_edit_keeps_identity_without_inventing_stable_evidence(): void
    {
        $signature = ['tag' => 'button', 'type' => '', 'text' => 'Submit'];
        $recording = $this->recording('stopped', [$this->event(1, 'click', [
            'selector' => '#submit', 'selectors' => ['#submit'], 'stable_selectors' => ['#submit'], 'target_signature' => $signature,
        ])]);
        $result = $this->service->updateEvent($this->admin, $recording->id, 'event-1', ['selector' => '.submit']);
        $this->assertSame($signature, $result->events_json[0]['target_signature']);
        $this->assertSame([], $result->events_json[0]['stable_selectors']);
    }

    public function test_unproven_stable_selector_fails_closed(): void
    {
        $recording = $this->recording();
        $this->browser->shouldReceive('state')->once()->andReturn($this->state([
            $this->event(1, 'click', ['selector' => '#submit', 'selectors' => ['#submit'], 'stable_selectors' => ['#delete']]),
        ]));
        $this->expectException(ValidationException::class);
        $this->service->poll($this->admin, $recording->id);
    }

    public function test_sensitive_identity_cannot_contain_text_or_unknown_value_fields(): void
    {
        $recording = $this->recording();
        $this->browser->shouldReceive('state')->once()->andReturn($this->state([
            $this->event(1, 'fill', [
                'selector' => '#password', 'input_type' => 'password', 'value_source' => 'fixed', 'value' => 'person.loginPassword',
                'target_signature' => ['tag' => 'input', 'type' => 'password', 'text' => 'synthetic-private-password'],
            ]),
        ]));
        try {
            $this->service->poll($this->admin, $recording->id);
            $this->fail('Sensitive text metadata was stored.');
        } catch (ValidationException $exception) {
            $this->assertSame([], $recording->fresh()->events_json);
            $this->assertStringNotContainsString('synthetic-private-password', json_encode($exception->errors()));
        }
    }

    public function test_missing_capture_identity_fails_closed(): void
    {
        $recording = $this->recording();
        $this->browser->shouldReceive('state')->once()->andReturn($this->state([
            ['id' => 'event-1', 'sequence' => 1, 'type' => 'click', 'selector' => '#submit'],
        ]));
        $this->expectException(ValidationException::class);
        $this->service->poll($this->admin, $recording->id);
    }

    public function test_mouse_paths_are_bounded_and_preserved_for_replay(): void
    {
        $recording = $this->recording();
        $path = [['x' => .1, 'y' => .2, 'ms' => 0], ['x' => .5, 'y' => .4, 'ms' => 200]];
        $this->browser->shouldReceive('state')->once()->andReturn($this->state([$this->event(1, 'hover', ['selector' => '#menu', 'mouse_path' => $path])]));
        $result = $this->service->poll($this->admin, $recording->id);
        $this->assertSame($path, $result->events_json[0]['mouse_path']);
        $result->forceFill(['status' => 'stopped', 'runtime_json' => []])->save();
        $workflow = $this->service->save($this->admin, $result->id);
        $cards = $workflow->steps->first()->task_cards;
        $this->assertSame('browser.open_url', $cards[0]['task_key']);
        $this->assertSame($path, $cards[1]['recorded_mouse_path']);
        $this->assertTrue($cards[1]['recorded_selector_strict']);
    }

    public function test_out_of_bounds_mouse_path_is_refused(): void
    {
        $recording = $this->recording();
        $this->browser->shouldReceive('state')->once()->andReturn($this->state([$this->event(1, 'hover', ['selector' => '#menu', 'mouse_path' => [['x' => 2, 'y' => .4]]])]));
        $this->expectException(ValidationException::class);
        $this->service->poll($this->admin, $recording->id);
    }

    public function test_stop_captures_last_actions_closes_browser_and_clears_private_descriptor(): void
    {
        $recording = $this->recording();
        $baseline = DB::transactionLevel();
        $this->browser->shouldReceive('command')->once()->andReturnUsing(function (WorkflowRecording $snapshot, array $command) use ($baseline): array {
            $this->assertSame($baseline, DB::transactionLevel());
            $this->assertSame(['type' => 'stop'], $command);
            $this->assertSame('stopped', $snapshot->status);

            return $this->state([$this->event(1, 'navigate', ['url' => 'https://example.test'])]);
        });
        $this->browser->shouldReceive('close')->once()->andReturnUsing(function () use ($baseline): void {
            $this->assertSame($baseline, DB::transactionLevel());
        });
        $result = $this->service->stop($this->admin, $recording->id);
        $this->assertSame('stopped', $result->status);
        $this->assertCount(1, $result->events_json);
        $this->assertSame([], $result->runtime_json);
        $this->assertNotNull($result->finished_at);
    }

    public function test_save_produces_a_new_inactive_valid_catalog_workflow_and_is_idempotent(): void
    {
        $existing = Workflow::query()->create(['name' => 'Test Aufnahme', 'slug' => 'test-aufnahme', 'is_active' => true, 'trigger_type' => 'manual']);
        $recording = $this->recording('stopped', [
            $this->event(1, 'navigate', ['url' => 'https://example.test/start']),
            $this->event(2, 'hover', ['selector' => '#menu']),
            $this->event(3, 'click', ['selector' => '#next']),
            $this->event(4, 'fill', ['selector' => '#email', 'value_source' => 'fixed', 'value' => 'person.email']),
            $this->event(5, 'key', ['key' => 'Enter']),
            $this->event(6, 'scroll', ['direction' => 'down', 'pixels' => 240]),
        ]);
        $workflow = $this->service->save($this->admin, $recording->id);
        $again = $this->service->save($this->admin, $recording->id, 'Ignored duplicate name');
        $this->assertFalse($workflow->is_active);
        $this->assertSame($workflow->id, $again->id);
        $this->assertNotSame($existing->id, $workflow->id);
        $this->assertSame('test-aufnahme', $existing->fresh()->slug);
        $this->assertTrue($existing->fresh()->is_active);
        $this->assertDatabaseCount('workflows', 2);
        $this->assertSame('saved', $recording->fresh()->status);
        $this->assertTrue(app(WorkflowDefinitionValidator::class)->validate($workflow)['valid']);
        $cards = $workflow->steps->first()->task_cards;
        $this->assertSame(['browser.open_url', 'browser.hover', 'browser.click', 'input.fill_field', 'browser.press_key', 'browser.scroll'], array_column($cards, 'task_key'));
        $this->assertSame('recorded-2', $cards[0]['next']['card_key']);
        $this->assertSame('end', $cards[5]['next']['type']);
        foreach ($cards as $card) {
            $this->assertSame('fail', $card['on_error']['type']);
            $this->assertSame('fail', $card['on_partial']['type']);
        }
    }

    public function test_capture_must_be_stopped_before_save(): void
    {
        $recording = $this->recording('recording', [$this->event(1, 'navigate', ['url' => 'https://example.test'])]);
        $this->expectException(ValidationException::class);
        $this->service->save($this->admin, $recording->id);
    }

    public function test_removed_initial_navigation_is_replaced_by_known_start_url(): void
    {
        $recording = $this->recording('stopped', [
            $this->event(1, 'navigate', ['url' => 'https://example.test/start']),
            $this->event(2, 'click', ['selector' => '#next']),
        ]);
        $this->service->removeEvent($this->admin, $recording->id, 'event-1');
        $workflow = $this->service->save($this->admin, $recording->id, 'New reviewed title');
        $cards = $workflow->steps->first()->task_cards;
        $this->assertSame('browser.open_url', $cards[0]['task_key']);
        $this->assertSame('https://example.test/start', $cards[0]['url']);
        $this->assertSame('browser.click', $cards[1]['task_key']);
        $this->assertTrue($cards[1]['recorded_selector_strict']);
        $this->assertSame('New reviewed title', $recording->fresh()->name);
        $this->assertTrue(app(WorkflowDefinitionValidator::class)->validate($workflow)['valid']);
    }

    public function test_invalid_empty_recording_does_not_leave_partial_workflow(): void
    {
        $recording = $this->recording('stopped');
        try {
            $this->service->save($this->admin, $recording->id);
            $this->fail('An empty recording was saved.');
        } catch (ValidationException) {
            $this->assertDatabaseCount('workflows', 0);
            $this->assertSame('stopped', $recording->fresh()->status);
        }
    }

    public function test_real_validator_error_rolls_back_entire_build(): void
    {
        $recording = $this->recording('stopped', [$this->event(1, 'fill', ['selector' => '#query', 'value_source' => 'literal', 'value' => ''])]);
        try {
            $this->service->save($this->admin, $recording->id);
            $this->fail('An invalid required input was saved.');
        } catch (ValidationException) {
            $this->assertDatabaseCount('workflows', 0);
            $this->assertDatabaseCount('workflow_steps', 0);
            $this->assertSame('stopped', $recording->fresh()->status);
        }
    }

    protected function recording(string $status = 'recording', array $events = []): WorkflowRecording
    {
        $recording = $this->service->create($this->admin, 'Test Aufnahme', 'https://example.test/start');
        $recording->forceFill([
            'status' => $status,
            'runtime_json' => $status === 'draft' ? [] : ['port' => 12345, 'secret' => 'synthetic-runtime-token'],
            'runtime_generation' => '11111111-2222-4333-8444-555555555555',
            'events_json' => $events,
            'last_event_sequence' => $events === [] ? 0 : max(array_column($events, 'sequence')),
        ])->save();

        return $recording;
    }

    protected function event(int $sequence, string $type, array $values = []): array
    {
        $event = array_replace(['id' => 'event-'.$sequence, 'sequence' => $sequence, 'type' => $type, 'label' => ucfirst($type)], $values);
        if (in_array($type, ['click', 'hover', 'fill'], true)) {
            $event['target_signature'] ??= ['tag' => $type === 'fill' ? 'input' : 'button', 'type' => (string) ($event['input_type'] ?? '')];
            $event['stable_selectors'] ??= [];
        }

        return $event;
    }

    protected function state(array $events, array $values = []): array
    {
        return array_replace(['events' => $events, 'url' => 'https://example.test/start', 'title' => 'Aufnahme-Test', 'viewport' => ['width' => 1365, 'height' => 768]], $values);
    }
}
