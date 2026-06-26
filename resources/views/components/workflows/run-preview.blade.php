@props([
    'workflowRun',
    'process' => null,
    'activeStepId' => null,
    'activeTaskKey' => null,
])

@php
    $publicUrl = static function (?string $relativePath): ?string {
        $relativePath = trim((string) $relativePath);

        if ($relativePath === '') {
            return null;
        }

        $absolutePath = storage_path('app/public/'.$relativePath);

        if (! \Illuminate\Support\Facades\File::exists($absolutePath)) {
            return null;
        }

        return \Illuminate\Support\Facades\Storage::disk('public')->url($relativePath).'?v='.\Illuminate\Support\Facades\File::lastModified($absolutePath);
    };

    $windowStatus = static function (array $window, array $result): array {
        $capturedAt = data_get($window, 'capturedAt', data_get($window, 'liveScreenshotAt'));
        $intervalSeconds = max(1, (int) data_get($result, 'livePreviewIntervalSeconds', data_get($result, 'livePreviewPollIntervalSeconds', 3)));

        return [
            'label' => data_get($window, 'label', 'Browser'),
            'alive' => ! data_get($window, 'error'),
            'stale' => false,
            'hasScreenshot' => (bool) (data_get($window, 'screenshotUrl') || data_get($window, 'livePreviewRelativePath')),
            'heartbeatAt' => $capturedAt,
            'ageSeconds' => null,
            'statusText' => $capturedAt ? 'Lebenszeichen aktiv' : 'Screenshot bereit',
            'state' => (string) data_get($result, 'status', 'running'),
            'stage' => (string) data_get($result, 'statusMessage', ''),
            'message' => (string) data_get($window, 'error', ''),
            'livePreviewEnabled' => true,
            'livePreviewIntervalSeconds' => $intervalSeconds,
        ];
    };

    $liveStatusForStepRun = static function ($stepRun): array {
        $externalRunId = trim((string) $stepRun?->external_run_id);

        if ($externalRunId === '') {
            return [];
        }

        return match ((string) $stepRun?->external_run_type) {
            'mail-registration' => app(\App\Services\Mail\MailAccountRegistrationRunner::class)->readRun($externalRunId) ?: [],
            'webmail-session' => app(\App\Services\Mail\WebmailSessionRunner::class)->readRun($externalRunId) ?: [],
            default => [],
        };
    };

    $stepRuns = $workflowRun?->stepRuns ?? collect();
    $screenshotPanels = collect($stepRuns)
        ->flatMap(function ($stepRun) use ($publicUrl, $windowStatus, $liveStatusForStepRun) {
            $storedResult = is_array($stepRun->result_json) ? $stepRun->result_json : [];
            $liveStatus = $liveStatusForStepRun($stepRun);
            $result = array_replace_recursive($storedResult, $liveStatus);
            $hasNamedWindows = is_array(data_get($result, 'registrationWindowStatus')) || is_array(data_get($result, 'webmailWindowStatus'));
            $hasBrowserWindows = false;
            $panels = [];

            foreach (data_get($result, 'browserWindows', []) as $window) {
                if (! is_array($window)) {
                    continue;
                }

                $image = data_get($window, 'screenshotUrl') ?: $publicUrl(data_get($window, 'livePreviewRelativePath'));

                if (! $image && ! data_get($window, 'error')) {
                    continue;
                }

                $panels[] = [
                    'title' => data_get($window, 'label', 'Browserfenster'),
                    'image' => $image,
                    'window' => $windowStatus($window, $result),
                    'dom' => data_get($window, 'debugDomUrl'),
                    'step' => $stepRun->workflowStep?->name ?? 'Schritt',
                ];
                $hasBrowserWindows = true;
            }

            if ($hasBrowserWindows) {
                return $panels;
            }

            foreach ([
                ['title' => 'Browser', 'image' => $hasNamedWindows ? null : data_get($result, 'screenshotUrl'), 'window' => data_get($result, 'windowStatus'), 'dom' => data_get($result, 'debugDomUrl')],
                ['title' => 'Registrierung', 'image' => data_get($result, 'registrationScreenshotUrl', is_array(data_get($result, 'registrationWindowStatus')) ? data_get($result, 'screenshotUrl') : null), 'window' => data_get($result, 'registrationWindowStatus'), 'dom' => data_get($result, 'registrationDebugDomUrl')],
                ['title' => 'Webmail', 'image' => data_get($result, 'webmailScreenshotUrl'), 'window' => data_get($result, 'webmailWindowStatus'), 'dom' => data_get($result, 'webmailDebugDomUrl')],
            ] as $panel) {
                if ($panel['image'] || is_array($panel['window']) || $panel['dom']) {
                    $panel['step'] = $stepRun->workflowStep?->name ?? 'Schritt';
                    $panels[] = $panel;
                }
            }

            return $panels;
        })
        ->filter(fn ($panel) => $panel['image'] || is_array($panel['window']) || $panel['dom'])
        ->unique(fn ($panel) => ($panel['title'] ?? '').'|'.($panel['image'] ?? '').'|'.($panel['step'] ?? ''))
        ->values();
    $latestStatusResult = collect($stepRuns)
        ->reverse()
        ->map(function ($stepRun) use ($liveStatusForStepRun) {
            $storedResult = is_array($stepRun->result_json) ? $stepRun->result_json : [];

            return array_replace_recursive($storedResult, $liveStatusForStepRun($stepRun));
        })
        ->first(fn (array $result): bool => $result !== []) ?? [];
@endphp

<div {{ $attributes->merge(['class' => 'space-y-5']) }}>
    @if($process)
        <div class="rounded-md border border-slate-200 bg-slate-50 px-4 py-3 text-sm text-slate-600">
            <span class="font-semibold text-slate-900">Prozess:</span>
            PID {{ $process->pid }} · {{ $process->process_type }} · {{ $process->status }}
        </div>
    @endif

    <x-workflows.minimap
        :workflow-run="$workflowRun"
        :active-step-id="$activeStepId"
        :active-task-key="$activeTaskKey"
    />

    <div class="grid gap-5 xl:grid-cols-[minmax(0,1fr)_minmax(360px,460px)]">
        <div class="grid gap-3">
            @forelse($screenshotPanels as $panel)
                <div class="overflow-hidden rounded-lg border border-slate-200 bg-slate-950">
                    <div class="flex items-center justify-between gap-3 border-b border-slate-800 px-3 py-2 text-xs font-semibold uppercase tracking-wide text-slate-300">
                        <div class="min-w-0">
                            <div class="truncate">{{ $panel['title'] }} · {{ $panel['step'] }}</div>
                            @include('livewire.admin.config.partials.browser-window-status', [
                                'windowStatus' => is_array($panel['window'] ?? null) ? $panel['window'] : [],
                            ])
                        </div>
                        @if($panel['dom'])
                            <a href="{{ $panel['dom'] }}" download="workflow-preview-dom.json" class="rounded border border-slate-700 px-2 py-1 text-[10px] text-slate-200 hover:bg-slate-800">
                                DOM
                            </a>
                        @endif
                    </div>
                    @if($panel['image'])
                        <img src="{{ $panel['image'] }}" alt="Workflow Live Screenshot" class="aspect-video w-full object-contain">
                    @else
                        <div class="flex aspect-video items-center justify-center text-sm font-semibold text-slate-300">
                            Noch kein Screenshot verfuegbar.
                        </div>
                    @endif
                </div>
            @empty
                <div class="rounded-md border border-dashed border-slate-300 bg-slate-50 p-4 text-sm text-slate-500">
                    Fuer diesen Workflow-Lauf wurden noch keine Browser-Screenshots gespeichert.
                </div>
            @endforelse
        </div>

        <div class="space-y-4">
            <div class="rounded-lg border border-slate-200 bg-white p-4">
                <div class="text-xs font-semibold uppercase tracking-wide text-slate-500">Status</div>
                <div class="mt-2 text-sm font-semibold text-slate-900">
                    {{ data_get($latestStatusResult, 'statusMessage', data_get($latestStatusResult, 'message', $workflowRun?->status)) }}
                </div>
                <div class="mt-2 break-all text-xs text-slate-500">
                    Run: {{ $workflowRun?->run_uuid ?: '-' }}
                </div>
                <div class="mt-1 text-xs text-slate-500">
                    Script: {{ data_get($latestStatusResult, 'scriptVersionLabel', data_get($latestStatusResult, 'scriptName', '-')) }}
                </div>
                @if(data_get($latestStatusResult, 'processHeartbeatStatus.statusText'))
                    <div class="mt-2 rounded-md {{ data_get($latestStatusResult, 'processHeartbeatStatus.stale') ? 'bg-amber-50 text-amber-800' : 'bg-emerald-50 text-emerald-800' }} px-3 py-2 text-xs font-semibold">
                        {{ data_get($latestStatusResult, 'processHeartbeatStatus.statusText') }}
                    </div>
                @endif
            </div>

            <div class="max-h-96 overflow-auto rounded-lg border border-slate-200 bg-slate-50 p-4">
                <div class="text-xs font-semibold uppercase tracking-wide text-slate-500">Ablauf</div>
                <div class="mt-3 space-y-2">
                    @forelse(array_reverse(data_get($latestStatusResult, 'events', [])) as $event)
                        <div class="rounded-md bg-white p-3 text-xs shadow-sm">
                            <div class="font-semibold text-slate-900">{{ data_get($event, 'stage', '-') }}</div>
                            <div class="mt-1 text-slate-600">{{ data_get($event, 'message', '-') }}</div>
                            <div class="mt-1 text-slate-400">{{ data_get($event, 'at', '') }}</div>
                        </div>
                    @empty
                        <div class="text-sm text-slate-500">Noch keine Ablaufdaten.</div>
                    @endforelse
                </div>
            </div>
        </div>
    </div>
</div>
