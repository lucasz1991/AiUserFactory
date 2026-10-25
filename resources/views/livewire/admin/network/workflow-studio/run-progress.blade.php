@php
    $progressTotal = max(1, (int) $runFocus['task_count']);
    $progressNumber = (int) ($runFocus['task_number'] ?? 0);
    $progressFailed = $runFocus['failure'] !== null;
    $progressFinished = $run && $runStatus === 'completed';
    $progressDone = $progressFinished ? $progressTotal : max(0, $progressNumber - 1);
    $progressDonePercent = round($progressDone / $progressTotal * 100, 2);
    $progressCurrentPercent = ! $progressFinished && $progressNumber > 0 ? round(100 / $progressTotal, 2) : 0;
@endphp

@if($run)
    <div class="ff-studio-progress" data-studio-run-progress role="group" aria-label="Fortschritt des Testlaufs">
        <div class="ff-studio-progress-label">
            <span>Fortschritt</span>
            <span>
                @if($progressFinished)
                    <strong>Alle {{ $runFocus['task_count'] }} Tasks</strong>
                @elseif($progressNumber > 0)
                    <strong>Task {{ $progressNumber }}</strong> von {{ $runFocus['task_count'] }}
                @else
                    {{ $runFocus['task_count'] }} Tasks
                @endif
                @if($runFocus['step_number'])
                    · Liste {{ $runFocus['step_number'] }}/{{ $runFocus['step_count'] }}
                @endif
            </span>
        </div>
        <div class="ff-studio-progress-bar" role="progressbar" aria-valuemin="0" aria-valuemax="{{ $progressTotal }}" aria-valuenow="{{ $progressDone }}">
            <span class="ff-studio-progress-done" style="width: {{ $progressDonePercent }}%"></span>
            @if($progressCurrentPercent > 0)
                <span class="ff-studio-progress-current" data-failed="{{ $progressFailed ? 'true' : 'false' }}" style="width: {{ $progressCurrentPercent }}%"></span>
            @endif
        </div>
    </div>
@endif
