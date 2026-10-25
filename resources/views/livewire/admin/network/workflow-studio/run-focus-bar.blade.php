@php
    $focusFailure = $runFocus['failure'];
    $focusTone = $focusFailure ? 'failed' : ($runFocus['live'] ? 'active' : 'idle');
@endphp

<div class="ff-studio-focus-bar shrink-0" data-studio-focus-bar>
    <div class="flex min-w-0 flex-wrap items-center gap-2 px-3 py-2 sm:px-4">
        <h2 class="text-sm font-bold text-slate-950">Workflow-Übersicht</h2>

        @if($run && $runFocus['task_title'] !== '')
            <p class="ff-studio-focus-chip min-w-0" data-studio-focus-task data-failed="{{ $focusFailure ? 'true' : 'false' }}" data-tone="{{ $focusTone }}" role="status" aria-live="polite">
                <span class="ff-studio-focus-dot" aria-hidden="true"></span>
                <span class="shrink-0">{{ $runFocus['live'] ? 'Aktiv' : 'Ausgewählt' }}:</span>
                <strong class="truncate" title="{{ $runFocus['step_name'] }} › {{ $runFocus['task_title'] }}">{{ $runFocus['task_title'] }}</strong>
            </p>
        @endif

        <div class="ml-auto flex shrink-0 items-center gap-3">
            <div class="ff-studio-legend hidden lg:flex" aria-hidden="true">
                <span><i data-tone="done"></i>Erledigt</span>
                <span><i data-tone="active"></i>Aktiv</span>
                <span><i data-tone="failed"></i>Fehler</span>
                <span><i data-tone="open"></i>Offen</span>
            </div>
            <button
                type="button"
                data-studio-focus-active-task
                x-on:click="
                    const diagram = $el.closest('[data-workflow-studio-diagram]');
                    const target = diagram?.querySelector('[data-workflow-primary-diagram] [aria-current=step]')
                        || diagram?.querySelector('[data-workflow-primary-diagram] [data-workflow-minimap-active-target=true]')
                        || diagram?.querySelector('[data-workflow-primary-diagram] [data-workflow-minimap-selected-task=true]');
                    target?.scrollIntoView({ behavior: 'smooth', block: 'center', inline: 'center' });
                    target?.classList.add('ff-studio-focus-ping');
                    setTimeout(() => target?.classList.remove('ff-studio-focus-ping'), 1200);
                "
                @disabled(! $run)
                class="ff-studio-control"
                title="Diagramm zum aktiven Task scrollen"
            >
                <svg viewBox="0 0 20 20" fill="none" stroke="currentColor" stroke-width="1.5" aria-hidden="true"><circle cx="10" cy="10" r="6" /><circle cx="10" cy="10" r="2" fill="currentColor" /></svg>
                Aktiven Task zeigen
            </button>
        </div>
    </div>

    @if($focusFailure)
        <div class="ff-studio-alert mx-3 mb-2 sm:mx-4" data-studio-run-alert role="alert">
            <span class="ff-studio-alert-icon" aria-hidden="true">!</span>
            <p class="min-w-0 flex-1 text-xs leading-5">
                <strong>Fehler in {{ $focusFailure['title'] }}</strong>
                <span class="ff-studio-alert-message">· {{ \Illuminate\Support\Str::limit($focusFailure['message'], 220) }}</span>
            </p>
            <div class="flex shrink-0 flex-wrap items-center gap-1.5">
                @if(! $autonomousMode && ! $historicalRunView && $focusFailure['step_id'] > 0 && $focusFailure['task_key'] !== '')
                    <button type="button" wire:click="editTask({{ $focusFailure['step_id'] }}, @js($focusFailure['task_key']))" class="ff-studio-control">Task bearbeiten</button>
                @endif
                <button type="button" wire:click="openToolModal('debug')" class="ff-studio-control">Diagnose</button>
            </div>
        </div>
    @endif
</div>
