<?php

namespace Tests\Unit;

use App\Models\WorkflowRecording;
use App\Services\Workflows\WorkflowRecordingBrowser;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Str;
use Tests\TestCase;

class WorkflowRecordingBrowserTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config(['app.key' => 'base64:MTIzNDU2Nzg5MDEyMzQ1Njc4OTAxMjM0NTY3ODkwMTI=']);
    }

    public function test_private_endpoint_is_always_exact_loopback_and_validates_bearer_and_port(): void
    {
        $this->assertSame('http://127.0.0.1:34567', $this->bridge()->publicEndpoint(['port' => 34567, 'secret' => str_repeat('a', 64)]));
        foreach ([[], ['port' => 80, 'secret' => str_repeat('a', 64)], ['port' => '34567', 'secret' => str_repeat('a', 64)],
            ['port' => 34567, 'secret' => "\r\nInjected"], ['port' => 65536, 'secret' => str_repeat('a', 64)]] as $bad) {
            try {
                $this->bridge()->publicEndpoint($bad);
                $this->fail('Invalid private metadata must not construct an endpoint.');
            } catch (\RuntimeException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function test_state_and_frame_use_only_private_bearer_without_redirects(): void
    {
        Http::fake([
            'http://127.0.0.1:34567/state' => Http::response(['state' => 'paused', 'events' => []]),
            'http://127.0.0.1:34567/frame' => Http::response("\xFF\xD8own-jpeg"),
        ]);
        $recording = new WorkflowRecording(['recording_uuid' => (string) Str::uuid(), 'runtime_json' => ['port' => 34567, 'secret' => str_repeat('a', 64)]]);
        $this->assertSame('paused', $this->bridge()->state($recording)['state']);
        $this->assertSame("\xFF\xD8own-jpeg", $this->bridge()->frame($recording));
        Http::assertSentCount(2);
        Http::assertSent(fn ($request) => $request->url() === 'http://127.0.0.1:34567/state'
            && $request->hasHeader('Authorization', 'Bearer '.str_repeat('a', 64)));
    }

    public function test_unsafe_browser_error_body_is_never_published(): void
    {
        Http::fake(['*' => Http::response(['error' => 'private-browser-value password'], 422)]);
        $recording = new WorkflowRecording(['runtime_json' => ['port' => 34567, 'secret' => str_repeat('a', 64)]]);
        try {
            $this->bridge()->command($recording, ['type' => 'key', 'key' => 'Enter']);
            $this->fail('Browser command must fail closed.');
        } catch (\RuntimeException $exception) {
            $this->assertStringNotContainsString('private-browser-value', $exception->getMessage());
            $this->assertStringNotContainsString('password', $exception->getMessage());
        }
    }

    public function test_frame_rejects_non_jpeg_payload_even_on_success(): void
    {
        Http::fake(['*' => Http::response('<html>private-error</html>', 200)]);
        $recording = new WorkflowRecording(['runtime_json' => ['port' => 34567, 'secret' => str_repeat('a', 64)]]);
        $this->expectException(\RuntimeException::class);
        $this->bridge()->frame($recording);
    }

    public function test_runtime_directory_rejects_non_uuid_before_any_file_operation(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->bridge()->publicDirectory(new WorkflowRecording(['recording_uuid' => '../../outside']));
    }

    public function test_detached_launch_uses_validated_node_without_shell_or_visible_window(): void
    {
        Process::fake();
        $this->bridge()->publicSpawn('C:/Node With Spaces/node.exe', base_path('node/recorder/serve.cjs'), 'C:/Private Recording/config.json', 'C:/Private Recording');
        Process::assertRan(fn ($process) => $process->command[0] === 'C:/Node With Spaces/node.exe'
            && $process->command[1] === '-e'
            && str_contains($process->command[2], 'windowsHide: true')
            && str_contains($process->command[2], 'detached: true')
            && $process->command[3] === base_path('node/recorder/serve.cjs')
            && $process->command[4] === 'C:/Private Recording/config.json');
    }

    private function bridge(): WorkflowRecordingBrowser
    {
        return new class extends WorkflowRecordingBrowser
        {
            public function __construct() {}

            public function publicEndpoint(array $runtime): string
            {
                return $this->endpoint($runtime);
            }

            public function publicDirectory(WorkflowRecording $recording): string
            {
                return $this->directory($recording);
            }

            public function publicSpawn(string $binary, string $script, string $config, string $directory): void
            {
                $this->spawn($binary, $script, $config, $directory);
            }
        };
    }
}
