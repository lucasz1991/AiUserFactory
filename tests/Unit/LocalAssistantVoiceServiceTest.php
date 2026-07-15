<?php

namespace Tests\Unit;

use App\Services\Ai\LocalAssistantVoiceException;
use App\Services\Ai\LocalAssistantVoiceService;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Str;
use Tests\TestCase;

class LocalAssistantVoiceServiceTest extends TestCase
{
    private string $runtimePath;

    private string $requestTempPath;

    private string $whisperLogPath;

    private string $piperLogPath;

    protected function setUp(): void
    {
        parent::setUp();

        $this->runtimePath = storage_path('framework/testing/local-voice-'.Str::uuid());
        $this->requestTempPath = $this->runtimePath.DIRECTORY_SEPARATOR.'requests';
        $this->whisperLogPath = $this->runtimePath.DIRECTORY_SEPARATOR.'whisper-arguments.json';
        $this->piperLogPath = $this->runtimePath.DIRECTORY_SEPARATOR.'piper-arguments.json';

        (new Filesystem)->ensureDirectoryExists($this->runtimePath, 0700);

        $ffmpegScript = $this->writeFakeBinary('ffmpeg.php', <<<'PHP'
<?php
$arguments = array_slice($argv, 1);
$outputPath = end($arguments);
file_put_contents($outputPath, 'RIFF'.str_repeat("\0", 128));
PHP);
        $whisperScript = $this->writeFakeBinary('whisper.php', <<<'PHP'
<?php
$logPath = $argv[1];
file_put_contents($logPath, json_encode(array_slice($argv, 2), JSON_THROW_ON_ERROR));
echo "[00:00:00.000 --> 00:00:02.000] Hallo aus Whisper\n[BLANK_AUDIO]\n";
PHP);
        $piperScript = $this->writeFakeBinary('piper.php', <<<'PHP'
<?php
$logPath = $argv[1];
$arguments = array_slice($argv, 2);
file_put_contents($logPath, json_encode($arguments, JSON_THROW_ON_ERROR));
$outputOption = array_search('--output-file', $arguments, true);
if ($outputOption === false) {
    $outputOption = array_search('--output_file', $arguments, true);
}
$outputPath = $arguments[$outputOption + 1] ?? null;
if ($outputPath) {
    file_put_contents($outputPath, 'RIFF'.str_repeat("\0", 128));
}
PHP);
        $whisperModel = $this->runtimePath.DIRECTORY_SEPARATOR.'ggml-small.bin';
        $piperModel = $this->runtimePath.DIRECTORY_SEPARATOR.'de_DE-thorsten-medium.onnx';
        $piperConfig = $piperModel.'.json';
        file_put_contents($whisperModel, 'fake-whisper-model');
        file_put_contents($piperModel, 'fake-piper-model');
        file_put_contents($piperConfig, '{}');

        config([
            'services.local_assistant_voice.enabled' => true,
            'services.local_assistant_voice.temp_path' => $this->requestTempPath,
            'services.local_assistant_voice.lock_wait_seconds' => 1,
            'services.local_assistant_voice.ffmpeg.command' => [PHP_BINARY, $ffmpegScript],
            'services.local_assistant_voice.ffmpeg.timeout' => 10,
            'services.local_assistant_voice.whisper.command' => [PHP_BINARY, $whisperScript, $this->whisperLogPath],
            'services.local_assistant_voice.whisper.model' => $whisperModel,
            'services.local_assistant_voice.whisper.language' => 'de',
            'services.local_assistant_voice.whisper.threads' => 2,
            'services.local_assistant_voice.whisper.timeout' => 10,
            'services.local_assistant_voice.piper.command' => [PHP_BINARY, $piperScript, $this->piperLogPath],
            'services.local_assistant_voice.piper.model' => $piperModel,
            'services.local_assistant_voice.piper.config' => $piperConfig,
            'services.local_assistant_voice.piper.mode' => 'cli',
            'services.local_assistant_voice.piper.timeout' => 10,
        ]);
    }

    protected function tearDown(): void
    {
        (new Filesystem)->deleteDirectory($this->runtimePath);

        parent::tearDown();
    }

    public function test_runtime_status_reports_both_local_engines_ready(): void
    {
        $status = app(LocalAssistantVoiceService::class)->status();

        $this->assertTrue($status['enabled']);
        $this->assertTrue($status['transcription_ready']);
        $this->assertTrue($status['synthesis_ready']);
        $this->assertTrue($status['ready']);
        $this->assertSame([], $status['missing']);
    }

    public function test_transcription_uses_ffmpeg_and_whisper_and_cleans_request_files(): void
    {
        $transcript = app(LocalAssistantVoiceService::class)->transcribe(
            UploadedFile::fake()->createWithContent('speech.webm', 'fake-browser-audio'),
            'test-whisper-connection',
        );

        $this->assertSame('Hallo aus Whisper', $transcript);
        $arguments = json_decode((string) file_get_contents($this->whisperLogPath), true, flags: JSON_THROW_ON_ERROR);
        $this->assertContains('-m', $arguments);
        $this->assertContains('-f', $arguments);
        $this->assertContains('-nt', $arguments);
        $this->assertContains('-l', $arguments);
        $this->assertContains('de', $arguments);
        $this->assertContains('-t', $arguments);
        $this->assertContains('2', $arguments);
        $this->assertRequestDirectoryIsEmpty();
    }

    public function test_synthesis_uses_piper_cli_without_putting_text_in_arguments(): void
    {
        $audio = app(LocalAssistantVoiceService::class)->synthesize(
            'Vertraulicher gesprochener Text',
            1.2,
            'test-piper-connection',
        );

        $this->assertStringStartsWith('RIFF', $audio);
        $arguments = json_decode((string) file_get_contents($this->piperLogPath), true, flags: JSON_THROW_ON_ERROR);
        $this->assertContains('--model', $arguments);
        $this->assertContains('--config', $arguments);
        $this->assertContains('--input-file', $arguments);
        $this->assertContains('--output-file', $arguments);
        $this->assertContains('--length-scale', $arguments);
        $this->assertContains('0.8333', $arguments);
        $this->assertNotContains('Vertraulicher gesprochener Text', $arguments);
        $this->assertRequestDirectoryIsEmpty();
    }

    public function test_disabled_runtime_fails_with_stable_reason_code(): void
    {
        config(['services.local_assistant_voice.enabled' => false]);

        try {
            app(LocalAssistantVoiceService::class)->synthesize('Test');
            $this->fail('Expected local voice exception was not thrown.');
        } catch (LocalAssistantVoiceException $exception) {
            $this->assertSame('local_voice_disabled', $exception->reasonCode);
        }
    }

    private function writeFakeBinary(string $name, string $contents): string
    {
        $path = $this->runtimePath.DIRECTORY_SEPARATOR.$name;
        file_put_contents($path, $contents);

        return $path;
    }

    private function assertRequestDirectoryIsEmpty(): void
    {
        $entries = is_dir($this->requestTempPath)
            ? array_values(array_diff(scandir($this->requestTempPath) ?: [], ['.', '..']))
            : [];

        $this->assertSame([], $entries);
    }
}
