<?php

namespace Tests\Feature;

use App\Livewire\Admin\Network\WorkflowRunPreview;
use App\Models\WorkflowStepRun;
use Illuminate\Support\Collection;
use Tests\TestCase;

class WorkflowRunPreviewWindowIdentityTest extends TestCase
{
    public function test_live_browser_windows_replace_stored_windows_atomically(): void
    {
        $preview = $this->previewHarness();
        $storedWindows = [
            ['key' => 'main', 'targetId' => 'target-main'],
            ['key' => 'stale-popup', 'targetId' => 'target-stale-popup'],
        ];
        $liveWindow = ['key' => 'popup', 'targetId' => 'target-popup'];

        $merged = $preview->mergeLiveStatusForTest(
            ['browserWindows' => $storedWindows],
            ['browserWindows' => [$liveWindow]],
        );
        $closed = $preview->mergeLiveStatusForTest(
            ['browserWindows' => $storedWindows],
            ['browserWindows' => []],
        );
        $nested = $preview->mergeLiveStatusForTest(
            ['browserWindows' => $storedWindows],
            ['result' => ['browserWindows' => [$liveWindow]]],
        );

        $this->assertSame([$liveWindow], $merged['browserWindows']);
        $this->assertSame([], $closed['browserWindows']);
        $this->assertSame([$liveWindow], $nested['browserWindows']);
    }

    public function test_multi_window_dom_tree_identity_mismatch_is_removed_from_the_screenshot_panel(): void
    {
        $preview = $this->previewHarness();
        $panels = $preview->screenshotPanelsForTest([
            'browserWindows' => [
                [
                    'key' => 'main',
                    'targetId' => 'target-main',
                    'label' => 'Main',
                    'screenshotUrl' => 'https://example.test/main.png',
                    'domTree' => $this->domTree('main', 'target-main'),
                ],
                [
                    'key' => 'popup',
                    'targetId' => 'target-popup',
                    'label' => 'Popup',
                    'screenshotUrl' => 'https://example.test/popup.png',
                    'domTree' => $this->domTree('popup', 'target-main'),
                ],
            ],
        ]);

        $this->assertCount(2, $panels);
        $this->assertIsArray($panels->firstWhere('windowKey', 'main')['domTree']);
        $this->assertNull($panels->firstWhere('windowKey', 'popup')['domTree']);
        $this->assertSame('https://example.test/popup.png', $panels->firstWhere('windowKey', 'popup')['image']);
    }

    public function test_multi_window_dom_tree_falls_back_to_window_key_and_single_legacy_window_stays_compatible(): void
    {
        $preview = $this->previewHarness();
        $multiWindowPanels = $preview->screenshotPanelsForTest([
            'browserWindows' => [
                [
                    'key' => 'main',
                    'screenshotUrl' => 'https://example.test/main.png',
                    'domTree' => $this->domTree('main'),
                ],
                [
                    'key' => 'popup',
                    'screenshotUrl' => 'https://example.test/popup.png',
                    'domTree' => $this->domTree('main'),
                ],
            ],
        ]);
        $singleWindowPanels = $preview->screenshotPanelsForTest([
            'browserWindows' => [[
                'key' => 'legacy',
                'screenshotUrl' => 'https://example.test/legacy.png',
                'domTree' => ['frames' => []],
            ]],
        ]);

        $this->assertIsArray($multiWindowPanels->firstWhere('windowKey', 'main')['domTree']);
        $this->assertNull($multiWindowPanels->firstWhere('windowKey', 'popup')['domTree']);
        $this->assertIsArray($singleWindowPanels->first()['domTree']);
    }

    private function previewHarness(): WorkflowRunPreview
    {
        return new class extends WorkflowRunPreview
        {
            public function mergeLiveStatusForTest(array $storedResult, array $liveStatus): array
            {
                return $this->mergeLiveStatus($storedResult, $liveStatus);
            }

            public function screenshotPanelsForTest(array $result): Collection
            {
                return $this->screenshotPanels(collect([
                    new WorkflowStepRun(['result_json' => $result]),
                ]));
            }
        };
    }

    private function domTree(string $windowKey, string $targetId = ''): array
    {
        return [
            'windowKey' => $windowKey,
            'targetId' => $targetId,
            'frames' => [],
        ];
    }
}
