<?php

namespace Tests\Unit;

use App\Services\Workflows\WorkflowExecutionService;
use ReflectionMethod;
use Tests\TestCase;

class WorkflowEmbeddedSnapshotSecurityTest extends TestCase
{
    public function test_frozen_unvisited_tasks_use_the_same_public_secret_boundary_as_executed_tasks(): void
    {
        $task = [
            'key' => 'nested-task', 'status' => 'configured', 'browser_window_name' => 'mail-leaf-popup',
            'embedded_workflow_path' => [['frame_key' => 'nested-frame', 'workflow_id' => 2]],
            'browserSessionFilePath' => 'private-browser-session',
            'encryptedSessionPayload' => 'private-session-secret',
            'account' => ['email' => 'qa@example.test', 'password' => 'private-account-secret'],
            'person' => ['password' => 'private-person-secret', 'metadata' => ['browser_sessions' => ['private-session']]],
        ];
        $snapshot = (new ReflectionMethod(WorkflowExecutionService::class, 'publicRunSnapshot'))
            ->invoke(app(WorkflowExecutionService::class), ['tasks' => [$task], 'configuredTasks' => [$task]]);

        $this->assertSame($snapshot['tasks'], $snapshot['configuredTasks']);
        $this->assertSame('mail-leaf-popup', $snapshot['configuredTasks'][0]['browser_window_name']);
        $this->assertSame($task['embedded_workflow_path'], $snapshot['configuredTasks'][0]['embedded_workflow_path']);
        $this->assertSame('qa@example.test', $snapshot['configuredTasks'][0]['account']['email']);
        $this->assertStringNotContainsString('private-', json_encode($snapshot));
        $this->assertArrayNotHasKey('browserSessionFilePath', $snapshot['configuredTasks'][0]);
        $this->assertArrayNotHasKey('password', $snapshot['configuredTasks'][0]['account']);
        $this->assertArrayNotHasKey('browser_sessions', $snapshot['configuredTasks'][0]['person']['metadata']);
    }
}
