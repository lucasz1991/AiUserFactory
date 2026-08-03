@php
    $workflowLocked = (bool) ($selectedWorkflow?->is_edit_locked ?? false);
    $formatRunDuration = static function ($run): string {
        if (! $run) {
            return '-';
        }

        $stored = $run->duration_ms
            ?? data_get($run->result_json, 'durationMs')
            ?? data_get($run->result_json, 'duration_ms');

        if (is_numeric($stored) && (int) $stored >= 0) {
            $milliseconds = (int) $stored;
        } else {
            $startedAt = $run->started_at ?? $run->queued_at;

            if (! $startedAt) {
                return '-';
            }

            $milliseconds = max(0, $startedAt->diffInMilliseconds($run->finished_at ?? now()));
        }

        if ($milliseconds > 0 && $milliseconds < 1000) {
            return '< 1s';
        }

        $seconds = intdiv($milliseconds, 1000);
        $hours = intdiv($seconds, 3600);
        $minutes = intdiv($seconds % 3600, 60);
        $remainingSeconds = $seconds % 60;

        return collect([
            $hours > 0 ? $hours.'h' : null,
            $minutes > 0 ? $minutes.'m' : null,
            ($hours === 0 && $remainingSeconds > 0) || ($hours === 0 && $minutes === 0) ? $remainingSeconds.'s' : null,
        ])->filter()->implode(' ');
    };
    $formatWorkflowValue = static function ($value): string {
        if (is_bool($value)) {
            return $value ? 'true' : 'false';
        }

        if ($value === null) {
            return 'null';
        }

        if (is_array($value) || is_object($value)) {
            return \Illuminate\Support\Str::limit(json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: '[]', 120);
        }

        return \Illuminate\Support\Str::limit((string) $value, 120);
    };
    $workflowReturnLabel = static function ($run) use ($formatWorkflowValue): ?string {
        if (! $run) {
            return null;
        }

        foreach ([is_array($run->result_json) ? $run->result_json : [], is_array($run->context_json) ? $run->context_json : []] as $source) {
            if (\Illuminate\Support\Arr::has($source, 'workflow_return')) {
                $value = data_get($source, 'workflow_return');
            } elseif (\Illuminate\Support\Arr::has($source, 'workflowReturn')) {
                $value = data_get($source, 'workflowReturn');
            } elseif (\Illuminate\Support\Arr::has($source, 'workflow_variables.workflow_return')) {
                $value = data_get($source, 'workflow_variables.workflow_return');
            } elseif (\Illuminate\Support\Arr::has($source, 'workflowVariables.workflow_return')) {
                $value = data_get($source, 'workflowVariables.workflow_return');
            } else {
                continue;
            }

            return 'Rueckgabe: '.$formatWorkflowValue($value);
        }

        return null;
    };
    $quickPreviewDurationLabel = $quickPreviewRun ? $formatRunDuration($quickPreviewRun) : null;
    $quickPreviewReturnLabel = $quickPreviewRun ? $workflowReturnLabel($quickPreviewRun) : null;
    $workbenchStatusLabel = match ($workbenchRunStatus) {
        'queued' => 'Startet',
        'running' => 'Läuft',
        'waiting' => 'Wartet',
        'paused' => 'Pausiert',
        'stop_requested' => 'Wird gestoppt',
        'completed' => 'Abgeschlossen',
        'failed' => 'Fehlgeschlagen',
        'cancelled' => 'Gestoppt',
        'timed_out' => 'Zeitüberschreitung',
        'lost', 'unreachable' => 'Nicht erreichbar',
        default => 'Bereit',
    };
    $managerWorkbenchPollEnabled = $workbenchSurface === 'definition';
    $managerWorkbenchPollSeconds = $workbenchPauseRequested || in_array($workbenchRunStatus, ['queued', 'running', 'waiting', 'stop_requested', 'unreachable'], true)
        ? 2
        : 15;
@endphp
<div
    class="workflow-experience space-y-5"
    wire:loading.class="opacity-60 pointer-events-none"
    wire:target.except="taskSearch,selectTaskGroup,catalogTargetStepId,refreshWorkbenchContext"
    x-data="{
        taskInsertTarget: null,
        armTaskInsert(stepId, stepName) {
            this.taskInsertTarget = { stepId: Number(stepId), stepName: String(stepName || '') };
            if (! $wire.showTaskPanel) {
                $wire.set('showTaskPanel', true);
            }
        },
        clearTaskInsert() {
            this.taskInsertTarget = null;
        },
        insertCatalogTask(taskKey) {
            if (! this.taskInsertTarget) {
                return;
            }
            const target = this.taskInsertTarget;
            this.taskInsertTarget = null;
            $wire.prepareTaskFromCatalog(target.stepId, taskKey, null);
        },
        workbenchOpen: $wire.entangle('workbenchOpen').live,
        workbenchSurface: $wire.entangle('workbenchSurface').live,
        workbenchTrigger: null,
        workbenchCopilotPinned: false,
        rememberWorkbenchTrigger(element = null) {
            const requested = element || document.activeElement;
            const menuTrigger = requested?.closest?.('.ff-menu')
                ?.parentElement
                ?.querySelector(':scope > button[aria-expanded]');

            this.workbenchTrigger = menuTrigger || requested;
        },
        elementIsVisible(element) {
            return element instanceof HTMLElement
                && element.getClientRects().length > 0
                && window.getComputedStyle(element).visibility !== 'hidden';
        },
        handleWorkbenchEscape(event) {
            if (! this.workbenchOpen || event.defaultPrevented) return;

            const shell = this.$refs.workflowWorkbench;
            if (! shell) return;

            const childDialog = Array.from(shell.querySelectorAll('.jetstream-modal, [role="dialog"][aria-modal="true"]'))
                .reverse()
                .find((dialog) => dialog !== shell && this.elementIsVisible(dialog));
            const openMenu = Array.from(shell.querySelectorAll('.ff-menu'))
                .find((menu) => this.elementIsVisible(menu));

            // Kinddialoge und Kartenmenues besitzen eigene Escape-Handler. Die
            // Workbench darf denselben Tastendruck nicht ebenfalls auswerten.
            if (childDialog || openMenu) return;

            const mobileLibrary = shell.querySelector('[data-workflow-mobile-library][data-open="true"]');
            if (mobileLibrary && this.elementIsVisible(mobileLibrary)) {
                event.preventDefault();
                event.stopImmediatePropagation();
                window.dispatchEvent(new CustomEvent('workflow-library-close-requested'));
                return;
            }

            event.preventDefault();
            $wire.closeWorkflowWorkbench();
        },
        syncWorkbenchCopilot() {
            const shouldPin = this.workbenchOpen && this.workbenchSurface === 'test';
            if (shouldPin === this.workbenchCopilotPinned) return;

            this.workbenchCopilotPinned = shouldPin;
            this.$dispatch(shouldPin ? 'workflow-studio-pin-copilot' : 'workflow-studio-unpin-copilot');
        },
        destroy() {
            if (this.workbenchCopilotPinned) {
                this.$dispatch('workflow-studio-unpin-copilot');
            }
        },
    }"
    x-init="
        $wire.$watch('showTaskPanel', open => { if (! open) clearTaskInsert() });
        $watch('workbenchOpen', open => {
            syncWorkbenchCopilot();
            if (open) {
                $nextTick(() => $refs.workbenchClose?.focus({ preventScroll: true }));
            } else {
                $nextTick(() => {
                    const fallback = document.querySelector('[data-workflow-edit-cta]');
                    const target = elementIsVisible(workbenchTrigger) ? workbenchTrigger : fallback;
                    target?.focus({ preventScroll: true });
                });
            }
        });
        $watch('workbenchSurface', () => syncWorkbenchCopilot());
    "
    data-workflow-manager-root
    data-workflow-id="{{ $selectedWorkflow?->id ?? '' }}"
    x-on:assistant-open-workflow-improvement.window="
        const detail = Array.isArray($event.detail) ? ($event.detail[0] || {}) : ($event.detail || {});
        const workflowId = Number(detail.workflow_id || 0);
        const stepId = Number(detail.step_id || 0);
        if (workflowId === {{ (int) ($selectedWorkflow?->id ?? 0) }} && stepId > 0) {
            $wire.openAssistantImprovement(workflowId, stepId, detail.task_card_key || null);
        }
    "
    x-on:assistant-open-workflow-run-preview.window="
        const detail = Array.isArray($event.detail) ? ($event.detail[0] || {}) : ($event.detail || {});
        const workflowId = Number(detail.workflow_id || 0);
        if (!workflowId || workflowId === {{ (int) ($selectedWorkflow?->id ?? 0) }}) {
            $wire.openRunPreviewFromAssistant(Number(detail.run_id || 0), Number(detail.session_id || 0));
        }
    "
    x-on:keydown.escape.window="handleWorkbenchEscape($event)"
>
    <section class="ff-command-surface overflow-visible px-4 py-5 sm:px-6 sm:py-6" aria-labelledby="workflow-manager-title">
        <div class="relative z-10 flex flex-wrap items-start justify-between gap-5">
            <div class="min-w-0">
                <div class="flex flex-wrap items-center gap-2">
                    <a href="{{ route('network.workflows') }}" class="ff-kicker transition hover:text-blue-800">Workflow Management</a>
                    <span class="text-xs text-slate-300" aria-hidden="true">/</span>
                    <span class="text-xs font-semibold text-slate-500">Editor</span>
                </div>
                <div class="mt-2 flex items-center gap-2">
                    <h1 id="workflow-manager-title" class="ff-page-title">{{ $selectedWorkflow?->name ?? 'Workflow Management' }}</h1>
                    @if($workflowLocked)
                        <span title="{{ $selectedWorkflow->lock_reason }}" class="inline-flex h-7 w-7 items-center justify-center rounded-full bg-amber-100 text-amber-700" aria-label="Workflow gesperrt">
                            <svg class="h-4 w-4" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M16.5 10.5V6.75a4.5 4.5 0 0 0-9 0v3.75m-.75 0h10.5A2.25 2.25 0 0 1 19.5 12.75v6A2.25 2.25 0 0 1 17.25 21H6.75a2.25 2.25 0 0 1-2.25-2.25v-6A2.25 2.25 0 0 1 6.75 10.5Z" />
                            </svg>
                        </span>
                    @endif
                </div>
                <p class="ff-page-copy mt-2 text-sm">
                    Listen strukturieren den Ablauf. Tasks lassen sich direkt platzieren, verbinden und anschließend schrittweise testen.
                </p>
            </div>

            @if($selectedWorkflow)
                <div class="ml-auto flex max-w-full flex-col items-end gap-3">
                    <div class="flex flex-wrap justify-end gap-2">
                        <div class="relative" x-data="{ open: false }" x-on:keydown.escape.window="open = false">
                            <button type="button" x-on:click="rememberWorkbenchTrigger($el); open = ! open" x-bind:aria-expanded="open" class="ff-action-trigger ff-action-trigger--primary inline-flex min-h-11 items-center gap-2 px-4 py-2 text-sm font-semibold">
                                <span class="inline-flex h-6 w-6 items-center justify-center rounded-lg bg-white/10" aria-hidden="true">▶</span>
                                Testen
                                <svg class="h-4 w-4" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="m19.5 8.25-7.5 7.5-7.5-7.5" /></svg>
                            </button>
                            <div x-cloak x-show="open" x-transition.origin.top.right x-on:click.outside="open = false" class="ff-menu absolute right-0 z-50 mt-2 w-72 p-1.5">
                                <button type="button" wire:click="openTestWorkbench('interactive')" x-on:click="rememberWorkbenchTrigger($el); open = false" class="block w-full rounded-md px-3 py-2 text-left text-sm font-semibold text-slate-900 hover:bg-slate-100">
                                    Eine Task nach der anderen
                                    <span class="mt-0.5 block text-xs font-medium text-slate-500">Auswählen, ausführen, prüfen und direkt bearbeiten</span>
                                </button>
                                <button type="button" wire:click="openTestWorkbench('autonomous')" x-on:click="rememberWorkbenchTrigger($el); open = false" class="block w-full rounded-md px-3 py-2 text-left text-sm font-semibold text-cyan-800 hover:bg-cyan-50">
                                    Autonom optimieren
                                    <span class="mt-0.5 block text-xs font-medium text-cyan-600">Copilot plant, testet und repariert exklusiv</span>
                                </button>
                                <button type="button" wire:click="$set('showCopilotRunsModal', true)" x-on:click="open = false" class="block w-full rounded-md px-3 py-2 text-left text-sm font-semibold text-cyan-800 hover:bg-cyan-50">
                                    Optimierungslaeufe anzeigen
                                    <span class="mt-0.5 block text-xs font-medium text-cyan-600">Kosten, Tests, Logs und Daten</span>
                                </button>
                                <button type="button" @if($quickPreviewRun) wire:click="openTestWorkbench('{{ $activeCopilotSession ? 'autonomous' : 'interactive' }}', {{ $quickPreviewRun->id }})" @endif x-on:click="rememberWorkbenchTrigger($el); open = false" class="block w-full rounded-md px-3 py-2 text-left text-sm font-semibold text-indigo-700 hover:bg-indigo-50 {{ $quickPreviewRun ? '' : 'pointer-events-none opacity-40' }}">
                                    {{ $quickPreviewRun && in_array($quickPreviewRun->status, ['queued', 'running', 'waiting'], true) ? 'Laufenden Test öffnen' : 'Letzten Test öffnen' }}
                                    @if($quickPreviewDurationLabel)
                                        <span class="mt-0.5 block text-xs font-medium text-indigo-500">Dauer: {{ $quickPreviewDurationLabel }}</span>
                                    @endif
                                    @if($quickPreviewReturnLabel)
                                        <span class="mt-0.5 block break-words text-xs font-medium text-indigo-500">{{ $quickPreviewReturnLabel }}</span>
                                    @endif
                                </button>
                                <button type="button" wire:click="downloadLatestRunDebugPackage" x-on:click="open = false" @disabled(! $quickPreviewRun) class="block w-full rounded-md px-3 py-2 text-left text-sm font-semibold text-emerald-700 hover:bg-emerald-50 disabled:cursor-not-allowed disabled:opacity-40">
                                    Debug-Paket herunterladen
                                    <span class="mt-0.5 block text-xs font-medium text-emerald-500">CSV, letzter Run, DOM</span>
                                </button>
                            </div>
                        </div>

                        <div class="relative" x-data="{ open: false }" x-on:keydown.escape.window="open = false">
                            <button type="button" x-on:click="rememberWorkbenchTrigger($el); open = ! open" x-bind:aria-expanded="open" class="ff-action-trigger inline-flex min-h-11 items-center gap-2 px-3 py-2 text-sm font-semibold">
                                Bearbeiten
                                <svg class="h-4 w-4" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="m19.5 8.25-7.5 7.5-7.5-7.5" /></svg>
                            </button>
                            <div x-cloak x-show="open" x-transition.origin.top.right x-on:click.outside="open = false" class="ff-menu absolute right-0 z-50 mt-2 w-64 p-1.5">
                                <button type="button" wire:click="openDefinitionWorkbench" x-on:click="rememberWorkbenchTrigger($el); open = false" class="block min-h-11 w-full rounded-md px-3 py-2 text-left text-sm font-semibold text-blue-800 hover:bg-blue-50">Workflow im Vollbild bearbeiten</button>
                                <button type="button" wire:click="$set('showWorkflowModal', true)" x-on:click="open = false" class="block w-full rounded-md px-3 py-2 text-left text-sm font-semibold text-slate-700 hover:bg-slate-100">Workflow-Einstellungen</button>
                                <button type="button" wire:click="openDefinitionWorkbench('add-step')" x-on:click="rememberWorkbenchTrigger($el); open = false" class="block min-h-11 w-full rounded-md px-3 py-2 text-left text-sm font-semibold text-slate-700 hover:bg-slate-100">Liste hinzufügen</button>
                                <button type="button" wire:click="$set('showActionLibraryModal', true)" x-on:click="open = false" class="block w-full rounded-md px-3 py-2 text-left text-sm font-semibold text-amber-700 hover:bg-amber-50">Aktionsbibliothek</button>
                            </div>
                        </div>

                        <div class="relative" x-data="{ open: false }" x-on:keydown.escape.window="open = false">
                            <button type="button" x-on:click="open = ! open" x-bind:aria-expanded="open" class="ff-action-trigger inline-flex items-center gap-2 px-3 py-2 text-sm font-semibold">
                                Weitere
                                <svg class="h-4 w-4" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="m19.5 8.25-7.5 7.5-7.5-7.5" /></svg>
                            </button>
                            <div x-cloak x-show="open" x-transition.origin.top.right x-on:click.outside="open = false" class="ff-menu absolute right-0 z-50 mt-2 w-64 p-1.5">
                                <button type="button" wire:click="openRevisionHistory" x-on:click="open = false" class="block w-full rounded-md px-3 py-2 text-left text-sm font-semibold text-violet-700 hover:bg-violet-50">
                                    Revisionen
                                    <span class="mt-0.5 block text-xs font-medium text-violet-500">Einsehen, vergleichen, zurücksetzen</span>
                                </button>
                                <div class="my-1 border-t border-slate-100"></div>
                                <button type="button" wire:click="exportWorkflow" x-on:click="open = false" class="block w-full rounded-md px-3 py-2 text-left text-sm font-semibold text-blue-700 hover:bg-blue-50">Als ZIP exportieren</button>
                                <a href="{{ route('processes.index') }}" class="block rounded-md px-3 py-2 text-sm font-semibold text-slate-700 hover:bg-slate-100">Prozesse öffnen</a>
                                @if(! $workflowLocked)
                                    <div class="my-1 border-t border-slate-100"></div>
                                    <button type="button" wire:click="deleteWorkflow" wire:confirm="Workflow samt Aufgaben, Tasks und Ausfuehrungen wirklich loeschen?" x-on:click="open = false" class="block w-full rounded-md px-3 py-2 text-left text-sm font-semibold text-red-700 hover:bg-red-50">Workflow löschen</button>
                                @endif
                            </div>
                        </div>
                    </div>

                    <dl class="ff-metric-rail" aria-label="Workflow-Statistik">
                        @foreach([
                            ['Aufgaben', $summary['actions']],
                            ['Listen', $summary['lists']],
                            ['Tasks', $summary['task_cards']],
                            ['Testläufe', $summary['runs']],
                            ['Erfolgreich', $summary['successful_runs']],
                            ['Fehlerhaft', $summary['failed_runs']],
                        ] as [$label, $value])
                            <div class="ff-metric">
                                <dt>{{ $label }}</dt>
                                <dd>{{ $value }}</dd>
                            </div>
                        @endforeach
                    </dl>
                </div>
            @endif
        </div>
    </section>

    @if (session()->has('success'))
        <div class="rounded-lg border border-emerald-200 bg-emerald-50 p-2 text-sm text-emerald-900">
            {{ session('success') }}
        </div>
    @endif

    @if (session()->has('warning'))
        <div class="rounded-lg border border-amber-200 bg-amber-50 p-2 text-sm text-amber-900" role="status">{{ session('warning') }}</div>
    @endif

    @if (session()->has('error'))
        <div class="rounded-lg border border-red-200 bg-red-50 p-2 text-sm text-red-900">{{ session('error') }}</div>
    @endif

    @if(! $selectedWorkflow)
        <x-admin.panel>
            <div class="text-sm text-gray-500">Dieser Workflow wurde nicht gefunden.</div>
        </x-admin.panel>
    @else
        @if($workflowLocked)
            <div class="rounded-lg border border-amber-200 bg-amber-50 p-2 text-sm text-amber-900">
                <span class="font-semibold">Achtung: Dieser Workflow ist gesperrt.</span> {{ $selectedWorkflow->lock_reason }} Als Admin kannst du ihn trotzdem bearbeiten. Aenderungen koennen laufende oder eingebundene Workflows beeinflussen.
            </div>
        @endif

        <section
            data-workflow-overview-card
            class="group overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-[0_22px_60px_-42px_rgba(15,23,42,.45)] transition hover:border-blue-300 hover:shadow-[0_26px_70px_-40px_rgba(37,99,235,.35)]"
            aria-labelledby="workflow-overview-card-title"
        >
            <header class="flex flex-wrap items-center justify-between gap-4 border-b border-slate-200 bg-white px-4 py-4 sm:px-5">
                <div class="min-w-0">
                    <p class="ff-kicker">Workflow-Karte</p>
                    <h2 id="workflow-overview-card-title" class="mt-1 text-lg font-bold tracking-tight text-slate-950">Ablauf auf einen Blick</h2>
                    <p class="mt-1 text-xs leading-5 text-slate-500">Die Größen ändern nur diese Übersicht. Zum Aufbauen öffnet sich die gemeinsame Vollbild-Workbench.</p>
                </div>
                <button
                    type="button"
                    x-ref="overviewEditCta"
                    data-workflow-edit-cta
                    wire:click.stop="openDefinitionWorkbench"
                    x-on:click.stop="rememberWorkbenchTrigger($el)"
                    class="ff-action-trigger ff-action-trigger--primary inline-flex min-h-11 items-center gap-2 px-4 text-sm font-bold focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-blue-500 focus-visible:ring-offset-2"
                >
                    Workflow bearbeiten
                    <span aria-hidden="true">↗</span>
                </button>
            </header>

            <div
                x-on:click="
                    if ($event.target.closest('[data-workflow-minimap-zoom]')) return;
                    rememberWorkbenchTrigger($refs.overviewEditCta);
                    $wire.openDefinitionWorkbench();
                "
                class="cursor-pointer bg-slate-50/70 p-3 sm:p-5"
                aria-label="Workflow-Karte anklicken, um den Editor zu öffnen"
            >
                <x-workflows.minimap
                    :workflow="$selectedWorkflow"
                    :route-map="$routeMap"
                    :selectable-tasks="false"
                    :zoomable="true"
                    initial-zoom="overview"
                    :show-header="false"
                    :instance="'manager-overview-'.$selectedWorkflow->id"
                    :source="'manager-overview-'.$selectedWorkflow->id"
                />
            </div>
        </section>


        <x-ui.dialog-modal wire:model="showWorkflowModal" maxWidth="2xl">
            <x-slot name="title">Workflow bearbeiten</x-slot>
            <x-slot name="content">
                <x-workflows.workflow-form
                    name-model="workflowName"
                    group-model="workflowGroup"
                    subcategory-model="workflowSubcategory"
                    description-model="workflowDescription"
                    active-model="workflowActive"
                    lock-model="workflowLocked"
                    development-model="workflowDevelopment"
                    browser-session-enabled-model="workflowBrowserSessionEnabled"
                    browser-session-load-model="workflowBrowserSessionLoadAtStart"
                    browser-session-save-model="workflowBrowserSessionSaveAtEnd"
                    browser-session-key-model="workflowBrowserSessionKey"
                    browser-session-fallback-url-model="workflowBrowserSessionFallbackUrl"
                    browser-session-target-domain-model="workflowBrowserSessionTargetDomain"
                    browser-session-window-model="workflowBrowserSessionWindow"
                    browser-session-label-model="workflowBrowserSessionLabel"
                    lock-help="Gesperrte Workflows bleiben fuer Admins bearbeitbar. Der Sperrstatus wird weiterhin als Warnung angezeigt."
                />
            </x-slot>
            <x-slot name="footer">
                <button type="button" x-on:click="$dispatch('close')" class="rounded-md border border-gray-300 bg-white px-4 py-2 text-sm font-semibold text-gray-700 shadow-sm hover:bg-gray-50">Abbrechen</button>
                <button type="button" wire:click="saveWorkflow" class="rounded-md bg-blue-600 px-4 py-2 text-sm font-semibold text-white shadow-sm hover:bg-blue-700">Speichern</button>
            </x-slot>
        </x-ui.dialog-modal>

        @if($workbenchBooted && $workbenchStudioSessionId && $selectedWorkflow)
            <div
                x-cloak
                x-show.important="workbenchOpen"
                x-ref="workflowWorkbench"
                x-init="$nextTick(() => syncWorkbenchCopilot())"
                x-trap.inert.noscroll="workbenchOpen"
                @if($managerWorkbenchPollEnabled)
                    wire:poll.visible.{{ $managerWorkbenchPollSeconds }}s="refreshWorkbenchContext"
                @endif
                class="fixed inset-0 top-0 z-[70] !mt-0 flex h-[100dvh] min-h-0 w-full min-w-0 flex-col overflow-hidden bg-slate-100"
                style="margin-top: 0 !important;"
                data-workflow-workbench
                data-workflow-test-workbench
                data-workflow-workbench-session="{{ $workbenchStudioSessionId }}"
                data-workflow-workbench-run="{{ $workbenchRunId }}"
                data-workflow-manager-poll="{{ $managerWorkbenchPollEnabled ? $managerWorkbenchPollSeconds.'s' : 'hosted-studio' }}"
                role="dialog"
                aria-modal="true"
                aria-labelledby="workflow-workbench-title"
            >
                <header class="relative z-50 shrink-0 border-b border-slate-200 bg-white/95 px-3 py-2.5 shadow-sm backdrop-blur-xl sm:px-4 lg:px-6">
                    <div class="flex min-w-0 flex-wrap items-center gap-2 sm:gap-3">
                        <button
                            type="button"
                            wire:click="closeWorkflowWorkbench"
                            class="ff-action-trigger inline-flex min-h-11 shrink-0 items-center gap-2 px-3 text-xs font-bold"
                        >
                            <span aria-hidden="true">←</span>
                            <span class="hidden sm:inline">Zur Übersicht</span>
                            <span class="sm:hidden">Übersicht</span>
                        </button>

                        <div class="min-w-0 flex-1 px-1">
                            <p class="ff-kicker">Workflow-Workbench</p>
                            <div class="mt-0.5 flex min-w-0 items-center gap-2">
                                <h2 id="workflow-workbench-title" class="truncate text-sm font-bold text-slate-950 sm:text-base">{{ $selectedWorkflow->name }}</h2>
                                <span class="ff-status-island shrink-0" data-active="{{ in_array($workbenchRunStatus, ['queued', 'running', 'waiting', 'stop_requested', 'unreachable'], true) ? 'true' : 'false' }}" role="status" aria-live="polite">
                                    <span class="ff-status-dot" aria-hidden="true"></span>
                                    <span class="text-[10px] font-bold tracking-wide">{{ $workbenchStatusLabel }}</span>
                                </span>
                            </div>
                        </div>

                        <nav class="order-3 grid w-full grid-cols-2 rounded-xl border border-slate-200 bg-slate-100 p-1 sm:order-none sm:w-auto" role="tablist" aria-label="Workflow-Workbench">
                            <button
                                type="button"
                                role="tab"
                                wire:click="switchWorkbenchSurface('definition')"
                                x-bind:aria-selected="(workbenchSurface === 'definition').toString()"
                                x-bind:class="workbenchSurface === 'definition' ? 'bg-white text-slate-950 shadow-sm' : 'text-slate-500 hover:text-slate-800'"
                                class="inline-flex min-h-11 items-center justify-center rounded-lg px-4 text-xs font-bold transition"
                            >Bearbeiten</button>
                            <button
                                type="button"
                                role="tab"
                                wire:click="switchWorkbenchSurface('test')"
                                x-bind:aria-selected="(workbenchSurface === 'test').toString()"
                                x-bind:class="workbenchSurface === 'test' ? 'bg-white text-slate-950 shadow-sm' : 'text-slate-500 hover:text-slate-800'"
                                class="inline-flex min-h-11 items-center justify-center rounded-lg px-4 text-xs font-bold transition"
                            >Testen</button>
                        </nav>

                        <button
                            type="button"
                            x-ref="workbenchClose"
                            wire:click="closeWorkflowWorkbench"
                            class="ff-action-trigger inline-flex h-11 w-11 shrink-0 items-center justify-center text-lg font-bold"
                            aria-label="Workflow-Workbench schließen"
                        >×</button>
                    </div>
                </header>

                <div class="relative min-h-0 min-w-0 flex-1 overflow-hidden">
                    <section
                        x-show.important="workbenchSurface === 'definition'"
                        x-bind:inert="workbenchSurface !== 'definition'"
                        x-bind:aria-hidden="(workbenchSurface !== 'definition').toString()"
                        class="absolute inset-0 flex min-h-0 min-w-0 flex-col overflow-hidden bg-slate-100"
                        role="tabpanel"
                        aria-label="Workflow bearbeiten"
                        data-workflow-workbench-definition
                    >
                        @if($workbenchHistoricalRun)
                            <div class="shrink-0 border-b border-violet-200 bg-violet-50 px-4 py-2 text-xs font-semibold text-violet-900">
                                Der Test-Tab zeigt einen historischen Lauf. Bearbeiten verwendet immer die klar getrennte aktuelle Workflow-Definition (Revision {{ $selectedWorkflow->copilot_revision }}).
                            </div>
                        @endif

                        @if(! $workbenchCanEdit)
                            <div class="flex shrink-0 flex-wrap items-center justify-between gap-3 border-b border-amber-200 bg-amber-50 px-4 py-2.5 text-xs text-amber-950">
                                <div class="min-w-0">
                                    <p class="font-bold">Aktuelle Definition ist schreibgeschützt</p>
                                    <p class="mt-0.5 leading-5">{{ $workbenchLockMessage }}</p>
                                </div>
                                @if($workbenchCanPauseForEdit)
                                    <button
                                        type="button"
                                        wire:click="requestPauseAndEdit"
                                        wire:loading.attr="disabled"
                                        wire:target="requestPauseAndEdit"
                                        @disabled($workbenchPauseRequested)
                                        class="inline-flex min-h-11 shrink-0 items-center rounded-lg bg-amber-700 px-4 text-xs font-bold text-white transition hover:bg-amber-800 disabled:cursor-wait disabled:opacity-60"
                                    >{{ $workbenchPauseRequested ? 'Pause angefordert …' : 'Pausieren & bearbeiten' }}</button>
                                @endif
                            </div>
                        @elseif($workbenchRunStatus === 'paused')
                            <div class="shrink-0 border-b border-emerald-200 bg-emerald-50 px-4 py-2 text-xs font-semibold text-emerald-900">
                                Sicher pausiert: Änderungen werden als neue Revision gespeichert und derselbe Run bleibt fortsetzbar.
                            </div>
                        @endif

                        <div class="min-h-0 min-w-0 flex-1 overflow-hidden">
                            <livewire:admin.network.workflow-studio-task-editor
                                :workflow="$selectedWorkflow"
                                :studio-session-id="$workbenchStudioSessionId"
                                :modal-only="false"
                                :key="'workflow-workbench-definition-'.$selectedWorkflow->id.'-'.$testWorkbenchKey"
                            />
                        </div>
                    </section>

                    <section
                        x-show.important="workbenchSurface === 'test'"
                        x-bind:inert="workbenchSurface !== 'test'"
                        x-bind:aria-hidden="(workbenchSurface !== 'test').toString()"
                        class="absolute inset-0 min-h-0 min-w-0 overflow-hidden bg-slate-100"
                        role="tabpanel"
                        aria-label="Workflow testen"
                        data-workflow-workbench-test
                    >
                        <livewire:admin.network.workflow-studio
                            :workflow="$selectedWorkflow"
                            :embedded="true"
                            :hosted="true"
                            :initial-mode="$testWorkbenchMode"
                            :run-id="$workbenchRunId"
                            :studio-session-id="$workbenchStudioSessionId"
                            :host-instance="'workflow-manager-'.$selectedWorkflow->id"
                            :key="'workflow-workbench-test-'.$selectedWorkflow->id.'-'.$testWorkbenchKey"
                        />
                    </section>
                </div>
            </div>
        @endif

        <x-ui.dialog-modal wire:model="showRunModal" maxWidth="xl" :interactive-aside="true">
            <x-slot name="title">Workflow testen</x-slot>
            <x-slot name="content">
                <div class="space-y-4">
                    <div>
                    <label for="workflow-run-person" class="block text-sm font-medium text-gray-700">Person / Kontext</label>
                    <select id="workflow-run-person" wire:model.defer="runPersonId" class="mt-1 block w-full rounded-md border border-gray-300 p-2 text-sm shadow-sm focus:border-blue-500 focus:ring-blue-500">
                        <option value="">System (bisheriges Haupt-Verifikationskonto)</option>
                        @foreach($persons as $person)
                            <option value="{{ $person->id }}">{{ $person->display_name }} - {{ $person->profile_key }}</option>
                        @endforeach
                    </select>
                    @error('runPersonId') <p class="mt-2 text-sm text-red-600">{{ $message }}</p> @enderror
                    </div>

                    <div>
                        <label for="workflow-run-target" class="block text-sm font-medium text-gray-700">Ausfuehrung</label>
                        <select id="workflow-run-target" wire:model.live="runExecutionTarget" class="mt-1 block w-full rounded-md border border-gray-300 p-2 text-sm shadow-sm focus:border-blue-500 focus:ring-blue-500">
                            <option value="system">Server / System (bisheriger Ablauf)</option>
                            <option value="client_controller">ClientController-Netzwerk</option>
                        </select>
                        @error('runExecutionTarget') <p class="mt-2 text-sm text-red-600">{{ $message }}</p> @enderror
                    </div>

                    @if($runExecutionTarget === 'client_controller')
                        <div class="rounded-md border border-blue-200 bg-blue-50 p-3 text-sm text-blue-900">
                            Browser-Workflows laufen direkt auf dem Node im lokalen CloakBrowser. Ein angeschlossenes Geraet ist nicht erforderlich.
                        </div>
                        <div>
                            <label for="workflow-run-node" class="block text-sm font-medium text-gray-700">ClientController-Node (optional)</label>
                            <select id="workflow-run-node" wire:model.live="runNetworkNodeId" class="mt-1 block w-full rounded-md border border-gray-300 p-2 text-sm shadow-sm">
                                <option value="">Automatisch freien Node waehlen / einreihen</option>
                                @foreach($runNetworkNodes as $node)
                                    <option value="{{ $node->id }}">{{ $node->name }} · {{ $node->is_online ? 'online' : 'offline' }}</option>
                                @endforeach
                            </select>
                            @error('runNetworkNodeId') <p class="mt-2 text-sm text-red-600">{{ $message }}</p> @enderror
                        </div>
                        <div>
                            <label for="workflow-run-device" class="block text-sm font-medium text-gray-700">Geraet (optional)</label>
                            <select id="workflow-run-device" wire:model.defer="runDeviceId" class="mt-1 block w-full rounded-md border border-gray-300 p-2 text-sm shadow-sm">
                                <option value="">Kein festes Geraet</option>
                                @foreach($runNetworkNodeId ? $runDevices->where('network_node_id', (int) $runNetworkNodeId) : $runDevices as $device)
                                    <option value="{{ $device->id }}">{{ $device->name }} · {{ $device->status }}</option>
                                @endforeach
                            </select>
                            @error('runDeviceId') <p class="mt-2 text-sm text-red-600">{{ $message }}</p> @enderror
                        </div>
                    @endif

                    <div>
                        <label for="workflow-run-inputs" class="block text-sm font-medium text-gray-700">Workflow-Eingaben (JSON)</label>
                        <textarea id="workflow-run-inputs" rows="7" wire:model.defer="runWorkflowInputs" placeholder='{"browser_window":"main","Mail-Inbox-Liste-Scan.subject_filter":["Rechnung"],"Mail-Inbox-Liste-Scan.max_age_minutes":30,"Mail-Inbox-Liste-Scan.mail_ids":[]}' class="mt-1 block w-full rounded-md border border-gray-300 p-2 font-mono text-sm shadow-sm focus:border-blue-500 focus:ring-blue-500"></textarea>
                        <p class="mt-1 text-xs text-gray-500">Diese Werte werden vom Task „Workflow-Eingaben pruefen“ gelesen und koennen folgende Tasks ueberschreiben.</p>
                        @error('runWorkflowInputs') <p class="mt-2 text-sm text-red-600">{{ $message }}</p> @enderror
                    </div>
                </div>
            </x-slot>
            <x-slot name="footer">
                <button type="button" x-on:click="$dispatch('close')" class="rounded-md border border-gray-300 bg-white px-4 py-2 text-sm font-semibold text-gray-700 shadow-sm hover:bg-gray-50">Abbrechen</button>
                <button type="button" wire:click="runWorkflow" wire:loading.attr="disabled" class="rounded-md bg-slate-900 px-4 py-2 text-sm font-semibold text-white shadow-sm hover:bg-slate-800 disabled:opacity-60">Normalen Testdurchlauf starten</button>
            </x-slot>
        </x-ui.dialog-modal>

        <x-ui.dialog-modal wire:model="showCopilotModal" maxWidth="3xl" :interactive-aside="true">
            <x-slot name="title">Workflow mit Copilot optimieren</x-slot>
            <x-slot name="content">
                <div class="space-y-5">
                    <div class="rounded-xl border border-cyan-200 bg-cyan-50 p-4 text-sm text-cyan-950">
                        <p class="font-bold">Ausschliesslich System-Ausfuehrung</p>
                        <p class="mt-1 leading-5">Der Copilot verwendet dieselbe Workflow-Vorschau wie ein normaler System-Test, einschliesslich Workflow-Karte, Tasks, Browserfenstern und Logs. Ist der Workflow leer, plant er aus Ziel, Erfolgskriterien und Eingaben zuerst eine katalogkonforme Erstdefinition. Eine ClientController-Ausfuehrung ist fuer Reparaturen ausgeschlossen.</p>
                    </div>

                    <div class="rounded-xl border p-4 text-sm {{ $copilotAutoExecute ? 'border-emerald-200 bg-emerald-50 text-emerald-950' : 'border-rose-200 bg-rose-50 text-rose-950' }}">
                        <p class="font-bold">{{ $copilotAutoExecute ? 'Autonome Aktionen sind freigegeben' : 'Autonome Aktionen sind deaktiviert' }}</p>
                        <p class="mt-1 leading-5">
                            {{ $copilotAutoExecute
                                ? 'Mit dem bewussten Start darf der Copilot vorhandene Workflow-Tasks im System-Test ausfuehren und Konfigurationen versioniert anpassen.'
                                : 'Aktiviere zuerst in den AI-Workflow-Chatbot-Einstellungen die Freigabe fuer autonome Workflow-Aktionen. Ohne diese Freigabe wird serverseitig keine Reparatursitzung gestartet.' }}
                        </p>
                        @error('copilotAutoExecute') <p class="mt-2 font-semibold text-rose-700">{{ $message }}</p> @enderror
                    </div>

                    <div>
                        <label for="copilot-goal" class="block text-sm font-medium text-gray-700">Ziel der Optimierung</label>
                        <textarea id="copilot-goal" rows="4" wire:model.defer="copilotGoal" placeholder="Beispiel: Der komplette Registrierungsablauf soll bis zur sichtbaren Erfolgsseite durchlaufen." class="mt-1 block w-full rounded-md border border-gray-300 p-3 text-sm shadow-sm focus:border-cyan-500 focus:ring-cyan-500"></textarea>
                        <p class="mt-1 text-xs text-gray-500">Dieses Ziel bleibt waehrend der Sitzung unveraendert.</p>
                        @error('copilotGoal') <p class="mt-2 text-sm text-red-600">{{ $message }}</p> @enderror
                    </div>

                    <div>
                        <label for="copilot-success-criteria" class="block text-sm font-medium text-gray-700">Feste Erfolgskriterien</label>
                        <textarea id="copilot-success-criteria" rows="5" wire:model.live.debounce.400ms="copilotSuccessCriteria" placeholder="Finale URL enthaelt /success&#10;Text Registrierung abgeschlossen ist sichtbar&#10;Rueckgabewert = array" aria-describedby="copilot-criteria-hint copilot-criteria-feedback" class="mt-1 block w-full rounded-md border border-gray-300 p-3 text-sm shadow-sm focus:border-cyan-500 focus:ring-cyan-500"></textarea>
                        <p id="copilot-criteria-hint" class="mt-1 text-xs text-gray-500">Ein Kriterium pro Zeile oder ein strukturiertes JSON-Objekt. Der Copilot darf diese Kriterien nicht abschwaechen.</p>

                        {{-- Sofortige Rueckmeldung, ob jede Zeile automatisch pruefbar ist. Ein nicht
                             pruefbares Kriterium wird spaeter still verworfen; bleibt keines uebrig,
                             gilt die fachliche Endpruefung als bestanden, ohne je etwas geprueft zu
                             haben. Genau das soll hier vor dem Start sichtbar werden. --}}
                        @php
                            $copilotCriteriaFeedback = $this->copilotCriteriaFeedback;
                            $copilotCriteriaOpen = collect($copilotCriteriaFeedback)
                                ->filter(fn (array $criterion): bool => ! $criterion['checkable']);
                        @endphp
                        @if (filled($copilotCriteriaFeedback))
                            <div id="copilot-criteria-feedback" class="mt-2 space-y-1" role="status" aria-live="polite">
                                @foreach ($copilotCriteriaFeedback as $criterion)
                                    <p @class([
                                        'flex items-start gap-2 rounded-md px-2 py-1 text-xs',
                                        'bg-emerald-50 text-emerald-800' => $criterion['checkable'],
                                        'bg-amber-50 text-amber-900' => ! $criterion['checkable'],
                                    ])>
                                        <span aria-hidden="true" class="mt-px font-bold">{{ $criterion['checkable'] ? '✓' : '!' }}</span>
                                        <span class="min-w-0 flex-1 break-words">
                                            <span class="font-medium">{{ $criterion['text'] }}</span>
                                            <span class="opacity-80">— {{ $criterion['checkable'] ? 'wird automatisch geprueft' : 'nicht pruefbar, wird beim Lauf verworfen' }}</span>
                                        </span>
                                    </p>
                                @endforeach

                                @if ($copilotCriteriaOpen->isNotEmpty())
                                    <p class="rounded-md bg-amber-100 px-2 py-1.5 text-xs text-amber-900">
                                        <span class="font-semibold">Pruefbare Formulierungen:</span>
                                        <span class="font-mono">URL enthaelt /erfolg</span> ·
                                        <span class="font-mono">Titel enthaelt X</span> ·
                                        <span class="font-mono">Text X ist sichtbar</span> ·
                                        <span class="font-mono">Seitenzustand ist Y</span> ·
                                        <span class="font-mono">Rueckgabewert = array</span>
                                        @if ($copilotCriteriaOpen->count() === count($copilotCriteriaFeedback))
                                            <span class="mt-1 block font-semibold">Achtung: Aktuell ist kein einziges Kriterium pruefbar — die fachliche Endpruefung liefe dann ins Leere.</span>
                                        @endif
                                    </p>
                                @endif
                            </div>
                        @endif

                        @error('copilotSuccessCriteria') <p class="mt-2 text-sm text-red-600">{{ $message }}</p> @enderror
                    </div>

                    <div class="grid gap-5 md:grid-cols-2">
                        <div>
                            <label for="copilot-person" class="block text-sm font-medium text-gray-700">Person / Kontext</label>
                            <select id="copilot-person" wire:model.defer="copilotPersonId" class="mt-1 block w-full rounded-md border border-gray-300 p-2.5 text-sm shadow-sm focus:border-cyan-500 focus:ring-cyan-500">
                                <option value="">System (Haupt-Verifikationskonto)</option>
                                @foreach($persons as $person)
                                    <option value="{{ $person->id }}">{{ $person->display_name }} - {{ $person->profile_key }}</option>
                                @endforeach
                            </select>
                            @error('copilotPersonId') <p class="mt-2 text-sm text-red-600">{{ $message }}</p> @enderror
                        </div>

                        <div class="rounded-lg border border-slate-200 bg-slate-50 p-3 text-sm">
                            <p class="font-semibold text-slate-700">Ausfuehrungsziel</p>
                            <p class="mt-1 font-bold text-slate-950">Server / System</p>
                            <p class="mt-1 text-xs text-slate-500">Fest vorgegeben; keine Client- oder Node-Auswahl.</p>
                        </div>
                    </div>

                    <div>
                        <label for="copilot-workflow-inputs" class="block text-sm font-medium text-gray-700">Workflow-Eingaben (JSON)</label>
                        <textarea id="copilot-workflow-inputs" rows="5" wire:model.defer="copilotWorkflowInputs" placeholder='{"browser_window":"main"}' class="mt-1 block w-full rounded-md border border-gray-300 p-3 font-mono text-sm shadow-sm focus:border-cyan-500 focus:ring-cyan-500"></textarea>
                        @error('copilotWorkflowInputs') <p class="mt-2 text-sm text-red-600">{{ $message }}</p> @enderror
                    </div>

                    <fieldset>
                        <legend class="text-sm font-semibold text-slate-800">Sicherheits- und Arbeitsbudgets</legend>
                        <div class="mt-3 grid gap-4 sm:grid-cols-2 lg:grid-cols-5">
                            <div>
                                <label for="copilot-max-minutes" class="block text-xs font-medium text-gray-600">Laufzeit (Min.)</label>
                                <input id="copilot-max-minutes" type="number" min="5" max="1440" wire:model.defer="copilotMaxMinutes" class="mt-1 block w-full rounded-md border border-gray-300 p-2 text-sm shadow-sm">
                                @error('copilotMaxMinutes') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
                            </div>
                            <div>
                                <label for="copilot-max-repairs" class="block text-xs font-medium text-gray-600">Reparaturrunden</label>
                                <input id="copilot-max-repairs" type="number" min="1" max="100" wire:model.defer="copilotMaxRepairIterations" class="mt-1 block w-full rounded-md border border-gray-300 p-2 text-sm shadow-sm">
                                @error('copilotMaxRepairIterations') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
                            </div>
                            <div>
                                <label for="copilot-max-probes" class="block text-xs font-medium text-gray-600">Probeaktionen</label>
                                <input id="copilot-max-probes" type="number" min="1" max="500" wire:model.defer="copilotMaxProbeActions" class="mt-1 block w-full rounded-md border border-gray-300 p-2 text-sm shadow-sm">
                                @error('copilotMaxProbeActions') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
                            </div>
                            <div>
                                <label for="copilot-max-same-state" class="block text-xs font-medium text-gray-600">Gleicher Zustand</label>
                                <input id="copilot-max-same-state" type="number" min="1" max="10" wire:model.defer="copilotMaxSameStateRepeats" class="mt-1 block w-full rounded-md border border-gray-300 p-2 text-sm shadow-sm">
                                @error('copilotMaxSameStateRepeats') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
                            </div>
                            <div>
                                <label for="copilot-max-cost-usd" class="block text-xs font-medium text-gray-600">AI-Kosten (USD)</label>
                                <input id="copilot-max-cost-usd" type="number" min="0" max="10000" step="0.0001" wire:model.defer="copilotMaxCostUsd" class="mt-1 block w-full rounded-md border border-gray-300 p-2 text-sm shadow-sm">
                                <p class="mt-1 text-[11px] text-slate-500">0 = unbegrenzt</p>
                                @error('copilotMaxCostUsd') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
                            </div>
                        </div>
                    </fieldset>

                    <div class="rounded-lg border border-amber-200 bg-amber-50 p-3 text-xs leading-5 text-amber-900">
                        Webseitenaktionen koennen externe Wirkungen ausloesen. Ein Zurueckspulen setzt den Workflowcursor und internen Kontext zurueck, kann bereits versendete Formulare, Nachrichten oder Registrierungen aber nicht rueckgaengig machen.
                    </div>
                </div>
            </x-slot>
            <x-slot name="footer">
                <button type="button" x-on:click="$dispatch('close')" class="rounded-md border border-gray-300 bg-white px-4 py-2 text-sm font-semibold text-gray-700 shadow-sm hover:bg-gray-50">Abbrechen</button>
                <button type="button" wire:click="startCopilotOptimization" wire:loading.attr="disabled" wire:target="startCopilotOptimization" @disabled(! $copilotAutoExecute) class="rounded-md bg-cyan-700 px-4 py-2 text-sm font-semibold text-white shadow-sm hover:bg-cyan-800 disabled:cursor-not-allowed disabled:opacity-40">System-Optimierung starten</button>
            </x-slot>
        </x-ui.dialog-modal>

        <x-ui.dialog-modal wire:model="showRunPreviewModal" maxWidth="7xl" :interactive-aside="true">
            <x-slot name="title">
                <div class="flex flex-wrap items-center gap-2">
                    <span>{{ $activeCopilotSession ? 'Testlauf & Copilot-Optimierung' : 'Workflow-Testlauf' }}</span>
                    @if($activeCopilotSession && $copilotStatus !== [])
                        @php
                            $managerCopilotStatus = (string) data_get($copilotStatus, 'status', 'unknown');
                            $managerCopilotStatusLabel = match ($managerCopilotStatus) {
                                'running' => 'Laeuft',
                                'paused' => 'Pausiert',
                                'repairing' => 'Repariert',
                                'verifying' => 'Verifiziert',
                                'succeeded' => 'Erfolgreich abgeschlossen',
                                'budget_exhausted' => 'Budget erreicht',
                                'failed' => 'Fehlgeschlagen',
                                'stopped' => 'Gestoppt',
                                default => $managerCopilotStatus,
                            };
                        @endphp
                        <span class="rounded-full px-2.5 py-1 text-xs font-bold {{ $managerCopilotStatus === 'succeeded' ? 'bg-emerald-100 text-emerald-800' : (in_array($managerCopilotStatus, ['failed', 'budget_exhausted'], true) ? 'bg-rose-100 text-rose-800' : 'bg-cyan-100 text-cyan-800') }}">{{ $managerCopilotStatusLabel }}</span>
                    @endif
                </div>
            </x-slot>
            <x-slot name="content">
                <div @if($showRunPreviewModal) wire:poll.2s="refreshRunPreview" @endif class="space-y-5">
                    @if($activeCopilotSession && data_get($copilotStatus, 'status') === 'succeeded')
                        <section data-workflow-copilot-completed-state class="flex flex-col gap-3 rounded-xl border border-emerald-200 bg-gradient-to-r from-emerald-50 to-teal-50 p-4 text-emerald-950 sm:flex-row sm:items-center sm:justify-between">
                            <div>
                                <p class="text-xs font-black uppercase tracking-[.14em] text-emerald-700">Optimierung abgeschlossen</p>
                                <h3 class="mt-1 text-base font-black">Ziel und Erfolgskriterien wurden im Kontrolllauf bestaetigt.</h3>
                                <p class="mt-1 text-sm text-emerald-800">Ergebnis, Screenshot, Ereignisse und Revisionen bleiben fuer die Nachvollziehbarkeit sichtbar.</p>
                            </div>
                            <span class="inline-flex shrink-0 items-center justify-center rounded-full bg-emerald-600 px-4 py-2 text-xs font-black text-white">BESTANDEN</span>
                        </section>
                    @elseif($activeCopilotSession && in_array(data_get($copilotStatus, 'status'), ['failed', 'budget_exhausted'], true))
                        <section class="rounded-xl border border-rose-200 bg-rose-50 p-4 text-rose-950">
                            <p class="text-xs font-black uppercase tracking-[.14em] text-rose-700">Optimierung beendet</p>
                            <h3 class="mt-1 text-base font-black">Der Workflow hat das Ziel in diesem Lauf nicht erreicht.</h3>
                            <p class="mt-1 text-sm text-rose-800">Analysiere Ereignisse und letzten Bildschirm oder starte mit denselben Vorgaben und frischen Budgets neu.</p>
                        </section>
                    @endif

                    @if($previewWorkflowRun)
                        @if(! $activeCopilotSession && in_array($previewWorkflowRun->status, ['running', 'waiting', 'paused'], true))
                            <section class="rounded-xl border border-amber-200 bg-amber-50 p-4">
                                <div class="flex flex-col gap-3 lg:flex-row lg:items-end lg:justify-between">
                                    <div class="min-w-0">
                                        <p class="text-xs font-black uppercase tracking-[.12em] text-amber-700">Interaktiver Debug-Lauf</p>
                                        @if($previewWorkflowRun->status === 'paused')
                                            <p class="mt-1 text-sm text-amber-950">Der Browser-, Variablen- und Routingzustand ist eingefroren. Du kannst den Workflow jetzt bearbeiten und danach ab einem Task fortsetzen.</p>
                                        @else
                                            <p class="mt-1 text-sm text-amber-950">Die Pause greift am naechsten sicheren Task- bzw. Step-Checkpoint.</p>
                                        @endif
                                    </div>
                                    @if($previewWorkflowRun->status === 'paused')
                                        <label class="block min-w-0 lg:w-[30rem]">
                                            <span class="mb-1 block text-xs font-bold text-amber-900">Optional ab diesem Task fortsetzen</span>
                                            <select wire:model="manualResumeCursor" class="w-full rounded-md border-amber-300 bg-white text-sm focus:border-amber-500 focus:ring-amber-500">
                                                <option value="">Gespeicherten Cursor verwenden</option>
                                                @foreach($manualResumeOptions as $resumeOption)
                                                    <option value="{{ $resumeOption['value'] }}">{{ $resumeOption['label'] }}</option>
                                                @endforeach
                                            </select>
                                        </label>
                                    @endif
                                </div>

                                @if($previewWorkflowRun->status === 'paused')
                                    <div class="mt-3 grid gap-2 text-xs sm:grid-cols-3">
                                        <div class="rounded-lg bg-white/80 p-2"><span class="block font-semibold text-amber-700">Naechster Step</span><code>{{ data_get($previewWorkflowRun->context_json, 'manual_pause_checkpoint.next_step_action_key') ?: '-' }}</code></div>
                                        <div class="rounded-lg bg-white/80 p-2"><span class="block font-semibold text-amber-700">Naechster Task</span><code>{{ data_get($previewWorkflowRun->context_json, 'manual_pause_checkpoint.next_task_key') ?: '-' }}</code></div>
                                        <div class="rounded-lg bg-white/80 p-2"><span class="block font-semibold text-amber-700">Variablen</span>{{ count((array) data_get($previewWorkflowRun->context_json, 'manual_pause_checkpoint.workflow_variables', [])) }} gespeichert</div>
                                    </div>
                                @endif
                            </section>
                        @endif
                        <x-workflows.run-preview :workflow-run="$previewWorkflowRun" />
                    @elseif($activeCopilotSession)
                        <div class="rounded-md border border-dashed border-cyan-300 bg-cyan-50 p-4 text-sm text-cyan-900">
                            Der Copilot plant den Workflow und bereitet den ersten gemeinsamen Vorschau-Test vor.
                        </div>
                    @else
                        <div class="rounded-md border border-dashed border-slate-300 bg-slate-50 p-4 text-sm text-slate-500">
                            Dieser Workflow-Lauf wurde noch nicht geladen.
                        </div>
                    @endif

                    @if($activeCopilotSession && $copilotStatus !== [])
                        <div class="grid gap-4 xl:grid-cols-[minmax(0,1.3fr)_minmax(320px,.7fr)]">
                            <div class="space-y-4">
                                <div class="overflow-hidden rounded-xl border border-cyan-200 bg-white">
                                    <div class="flex flex-wrap items-start justify-between gap-3 bg-gradient-to-r from-slate-950 via-cyan-900 to-emerald-800 px-4 py-3 text-white">
                                        <div>
                                            <p class="font-bold">{{ data_get($copilotStatus, 'workflow_name') }}</p>
                                            <p class="mt-0.5 text-xs text-cyan-100">System-Ausfuehrung · {{ data_get($copilotStatus, 'phase') }}</p>
                                        </div>
                                        <span class="rounded-full bg-white/15 px-3 py-1 text-xs font-bold">{{ data_get($copilotStatus, 'status') }}</span>
                                    </div>

                                    @if(data_get($copilotStatus, 'latest_screenshot_url'))
                                        <a href="{{ data_get($copilotStatus, 'latest_screenshot_url') }}" target="_blank" rel="noopener noreferrer" class="block bg-slate-100 p-2">
                                            <img src="{{ data_get($copilotStatus, 'latest_screenshot_url') }}" alt="Aktueller Workflow-Copilot-Bildschirm" class="mx-auto max-h-[440px] w-full object-contain" loading="lazy">
                                        </a>
                                    @else
                                        <div class="flex min-h-48 items-center justify-center bg-slate-100 px-4 text-sm text-slate-500">Der erste Bildschirm wird beim naechsten Checkpoint erfasst.</div>
                                    @endif

                                    <div class="grid gap-3 p-4 sm:grid-cols-2">
                                        <div class="rounded-lg bg-slate-50 p-3 text-sm"><span class="block text-xs font-semibold text-slate-500">Step</span><strong>{{ data_get($copilotStatus, 'current_step_name') ?: 'Wird vorbereitet' }}</strong></div>
                                        <div class="rounded-lg bg-slate-50 p-3 text-sm"><span class="block text-xs font-semibold text-slate-500">Task</span><strong>{{ data_get($copilotStatus, 'current_task_title') ?: data_get($copilotStatus, 'current_task_key', '-') }}</strong></div>
                                        @foreach(['page_state' => 'Erkannter Bildschirm', 'last_action' => 'Letzte Aktion', 'current_result' => 'Ergebnis', 'next_action' => 'Naechster Schritt'] as $key => $label)
                                            <div class="rounded-lg border border-slate-200 p-3 text-sm">
                                                <span class="block text-xs font-semibold text-slate-500">{{ $label }}</span>
                                                <span class="mt-1 block break-words text-slate-800">{{ data_get($copilotStatus, $key) ?: '-' }}</span>
                                            </div>
                                        @endforeach
                                    </div>
                                </div>

                                @if(is_array(data_get($copilotStatus, 'vision_analysis')))
                                    @php
                                        $visionAnalysis = data_get($copilotStatus, 'vision_analysis');
                                        $visionVerdict = (string) data_get($visionAnalysis, 'verdict', 'pause');
                                    @endphp
                                    <section data-workflow-copilot-vision-analysis class="rounded-xl border border-slate-200 bg-white p-4 text-sm">
                                        <div class="flex flex-wrap items-start justify-between gap-3">
                                            <div>
                                                <p class="text-xs font-black uppercase tracking-[.12em] text-slate-500">Letzte Bildanalyse</p>
                                                <h3 class="mt-1 font-bold text-slate-950">
                                                    {{ data_get($visionAnalysis, 'page_type') ?: 'Unbekannte Seite' }}
                                                    · {{ data_get($visionAnalysis, 'ui_state') ?: 'Unbekannter Zustand' }}
                                                </h3>
                                            </div>
                                            <span class="rounded-full px-3 py-1 text-xs font-black {{ $visionVerdict === 'pass' ? 'bg-emerald-100 text-emerald-800' : ($visionVerdict === 'continue' ? 'bg-cyan-100 text-cyan-800' : 'bg-amber-100 text-amber-800') }}">
                                                {{ data_get($visionAnalysis, 'verdict_label') }}
                                                @if(data_get($visionAnalysis, 'confidence') !== null)
                                                    · {{ number_format((float) data_get($visionAnalysis, 'confidence') * 100, 0, ',', '.') }} %
                                                @endif
                                            </span>
                                        </div>

                                        <dl class="mt-3 grid gap-2 text-xs sm:grid-cols-3">
                                            <div class="rounded-lg bg-slate-50 p-2"><dt class="font-semibold text-slate-500">Seitentyp</dt><dd class="mt-1 font-bold text-slate-900">{{ data_get($visionAnalysis, 'page_type') ?: '-' }}</dd></div>
                                            <div class="rounded-lg bg-slate-50 p-2"><dt class="font-semibold text-slate-500">UI-Zustand</dt><dd class="mt-1 font-bold text-slate-900">{{ data_get($visionAnalysis, 'ui_state') ?: '-' }}</dd></div>
                                            <div class="rounded-lg bg-slate-50 p-2"><dt class="font-semibold text-slate-500">Zielfortschritt</dt><dd class="mt-1 font-bold text-slate-900">{{ data_get($visionAnalysis, 'goal_progress') ?: '-' }}</dd></div>
                                        </dl>

                                        @if(filled(data_get($visionAnalysis, 'browser_screen_description')))
                                            <div class="mt-3 rounded-lg border border-cyan-100 bg-cyan-50/60 p-3">
                                                <h4 class="text-xs font-bold uppercase tracking-[.08em] text-cyan-800">Beschreibung der Browseransicht</h4>
                                                <p class="mt-1 whitespace-pre-line text-sm leading-6 text-slate-700">{{ data_get($visionAnalysis, 'browser_screen_description') }}</p>
                                            </div>
                                        @endif

                                        <div class="mt-4 grid gap-4 lg:grid-cols-2">
                                            <div>
                                                <h4 class="text-xs font-bold uppercase tracking-[.08em] text-slate-500">Erkannte Elemente</h4>
                                                <div class="mt-2 space-y-2">
                                                    @forelse(data_get($visionAnalysis, 'relevant_elements', []) as $element)
                                                        <div class="break-words border-l-2 border-cyan-300 pl-2 text-xs text-slate-700">
                                                            <code class="font-bold text-cyan-800">{{ $element['element_ref'] }}</code>
                                                            · {{ $element['reason'] ?: 'Als relevant erkannt' }}
                                                            @if($element['confidence'] !== null)
                                                                ({{ number_format((float) $element['confidence'] * 100, 0, ',', '.') }} %)
                                                            @endif
                                                        </div>
                                                    @empty
                                                        <p class="text-xs text-slate-500">Keine sicher zugeordneten Elemente.</p>
                                                    @endforelse
                                                </div>
                                            </div>

                                            <div>
                                                <h4 class="text-xs font-bold uppercase tracking-[.08em] text-slate-500">Vorgeschlagene Workflow-Aktionen</h4>
                                                <div class="mt-2 space-y-2">
                                                    @forelse(data_get($visionAnalysis, 'suggested_task_actions', []) as $action)
                                                        <div class="break-words border-l-2 border-emerald-300 pl-2 text-xs text-slate-700">
                                                            <code class="font-bold text-emerald-800">{{ $action['task_key'] }}</code>
                                                            @if(filled($action['workflow_task_key'] ?? null))
                                                                fuer <code>{{ $action['workflow_task_key'] }}</code>
                                                            @endif
                                                            @if(filled($action['element_ref']))
                                                                an <code>{{ $action['element_ref'] }}</code>
                                                            @endif
                                                            @if(filled($action['reason']))
                                                                · {{ $action['reason'] }}
                                                            @endif
                                                        </div>
                                                    @empty
                                                        <p class="text-xs text-slate-500">Keine direkte Task-Aktion vorgeschlagen.</p>
                                                    @endforelse
                                                </div>
                                            </div>
                                        </div>

                                        @if(data_get($visionAnalysis, 'blockers', []) !== [])
                                            <div class="mt-4 border-t border-amber-100 pt-3">
                                                <h4 class="text-xs font-bold uppercase tracking-[.08em] text-amber-800">Hinweise und Hindernisse</h4>
                                                <ul class="mt-2 list-disc space-y-1 pl-5 text-xs text-amber-900">
                                                    @foreach(data_get($visionAnalysis, 'blockers', []) as $blocker)
                                                        <li class="break-words">{{ $blocker }}</li>
                                                    @endforeach
                                                </ul>
                                            </div>
                                        @endif

                                        @if(filled(data_get($visionAnalysis, 'model')) || data_get($visionAnalysis, 'duration_ms', 0) > 0)
                                            <p class="mt-3 text-xs text-slate-400">
                                                {{ data_get($visionAnalysis, 'model') ?: data_get($visionAnalysis, 'analysis_source') }}
                                                @if(data_get($visionAnalysis, 'duration_ms', 0) > 0)
                                                    · {{ number_format((int) data_get($visionAnalysis, 'duration_ms') / 1000, 1, ',', '.') }} s
                                                @endif
                                            </p>
                                        @endif
                                    </section>
                                @endif

                                @if(is_array(data_get($copilotStatus, 'verification_report')))
                                    @php
                                        $verificationReport = data_get($copilotStatus, 'verification_report');
                                    @endphp
                                    <section
                                        data-workflow-copilot-verification-report
                                        class="rounded-xl border p-4 text-sm {{ data_get($verificationReport, 'pass') ? 'border-emerald-200 bg-emerald-50 text-emerald-950' : 'border-amber-200 bg-amber-50 text-amber-950' }}"
                                    >
                                        <div class="flex flex-wrap items-center justify-between gap-2">
                                            <h3 class="font-bold">{{ data_get($verificationReport, 'final') ? 'Finaler Verifikationsbericht' : 'Letzte Verifikationspruefung' }}</h3>
                                            <span class="rounded-full bg-white/70 px-3 py-1 text-xs font-bold">{{ data_get($verificationReport, 'pass') ? 'BESTANDEN' : 'NICHT BESTANDEN' }}</span>
                                        </div>
                                        <p class="mt-2 leading-6">{{ data_get($verificationReport, 'message') }}</p>
                                        <dl class="mt-3 grid gap-2 text-xs sm:grid-cols-2 lg:grid-cols-4">
                                            <div class="rounded-lg bg-white/60 p-2"><dt class="font-semibold opacity-60">Revision</dt><dd class="mt-1 font-bold">{{ data_get($verificationReport, 'revision') ?: '-' }}</dd></div>
                                            <div class="rounded-lg bg-white/60 p-2"><dt class="font-semibold opacity-60">Kontrolllauf</dt><dd class="mt-1 font-bold">{{ data_get($verificationReport, 'workflow_run_id') ? '#'.data_get($verificationReport, 'workflow_run_id') : '-' }}</dd></div>
                                            <div class="rounded-lg bg-white/60 p-2"><dt class="font-semibold opacity-60">Zielassertionen</dt><dd class="mt-1 font-bold">{{ data_get($verificationReport, 'criteria_total') > 0 ? data_get($verificationReport, 'criteria_passed').'/'.data_get($verificationReport, 'criteria_total') : '-' }}</dd></div>
                                            <div class="rounded-lg bg-white/60 p-2">
                                                <dt class="font-semibold opacity-60">Bildanalyse</dt>
                                                <dd class="mt-1 font-bold">
                                                    {{ data_get($verificationReport, 'vision_verdict') ?: '-' }}
                                                    @if(data_get($verificationReport, 'vision_confidence') !== null)
                                                        ({{ number_format((float) data_get($verificationReport, 'vision_confidence') * 100, 0, ',', '.') }} %)
                                                    @endif
                                                </dd>
                                            </div>
                                        </dl>
                                    </section>
                                @endif

                                <div class="rounded-xl border border-slate-200 bg-white p-4">
                                    <div class="flex items-center justify-between gap-3">
                                        <h3 class="text-sm font-bold text-slate-900">Live-Ereignisse</h3>
                                        <span class="text-xs text-slate-500">{{ data_get($copilotStatus, 'active') ? 'Aktualisierung alle 2 Sekunden' : 'Gespeicherter Sitzungsverlauf' }}</span>
                                    </div>
                                    <div class="mt-3 max-h-72 space-y-2 overflow-y-auto">
                                        @forelse($copilotEvents as $event)
                                            <div wire:key="manager-copilot-event-{{ $event['id'] }}" class="flex items-start gap-3 rounded-lg border px-3 py-2 text-sm {{ in_array($event['level'], ['error', 'critical'], true) ? 'border-rose-200 bg-rose-50' : ($event['level'] === 'success' ? 'border-emerald-200 bg-emerald-50' : 'border-slate-200 bg-slate-50') }}">
                                                <span class="mt-1.5 h-2 w-2 shrink-0 rounded-full {{ in_array($event['level'], ['error', 'critical'], true) ? 'bg-rose-500' : ($event['level'] === 'success' ? 'bg-emerald-500' : 'bg-cyan-500') }}"></span>
                                                <div class="min-w-0 flex-1">
                                                    <div class="flex items-center justify-between gap-2"><span class="text-xs font-bold uppercase text-slate-500">{{ $event['phase'] ?: 'Status' }}</span><time class="text-xs text-slate-400">{{ $event['time'] }}</time></div>
                                                    <p class="mt-1 break-words text-slate-800">{{ $event['message'] }}</p>
                                                </div>
                                            </div>
                                        @empty
                                            <p class="rounded-lg border border-dashed border-slate-300 p-4 text-sm text-slate-500">Noch keine sichtbaren Arbeitsschritte vorhanden.</p>
                                        @endforelse
                                    </div>
                                </div>
                            </div>

                            <aside class="space-y-4">
                                <div class="rounded-xl border border-slate-200 bg-white p-4">
                                    <h3 class="text-sm font-bold text-slate-900">Fortschritt und Budget</h3>
                                    <div class="mt-3 grid grid-cols-2 gap-2 text-center text-xs sm:grid-cols-4">
                                        <div class="rounded-lg bg-slate-50 p-2"><strong class="block text-base">{{ data_get($copilotStatus, 'repair_iteration', 0) }}/{{ data_get($copilotStatus, 'max_repair_iterations', 15) }}</strong>Runden</div>
                                        <div class="rounded-lg bg-slate-50 p-2"><strong class="block text-base">{{ data_get($copilotStatus, 'probe_actions', 0) }}/{{ data_get($copilotStatus, 'max_probe_actions', 60) }}</strong>Proben</div>
                                        <div class="rounded-lg bg-slate-50 p-2"><strong class="block text-base">{{ data_get($copilotStatus, 'remaining_minutes', 0) }}m</strong>Restzeit</div>
                                        <div class="rounded-lg bg-slate-50 p-2"><strong class="block text-base">${{ number_format((float) data_get($copilotStatus, 'cost_usd', 0), 4) }}</strong>{{ (float) data_get($copilotStatus, 'max_cost_usd', 0) > 0 ? 'von $'.number_format((float) data_get($copilotStatus, 'max_cost_usd'), 2) : 'AI-Kosten' }}</div>
                                    </div>
                                    <p class="mt-3 text-xs leading-5 text-slate-500">Ziel: {{ data_get($copilotStatus, 'goal') }}</p>
                                </div>

                                <div class="rounded-xl border border-slate-200 bg-white p-4">
                                    <h3 class="text-sm font-bold text-slate-900">Checkpoints</h3>
                                    <div class="mt-3 max-h-48 space-y-2 overflow-y-auto">
                                        @forelse(data_get($copilotStatus, 'checkpoints', []) as $checkpoint)
                                            <label class="flex items-start gap-2 rounded-lg border border-slate-200 p-2 text-xs {{ $checkpoint['is_reproducible'] ? 'cursor-pointer hover:bg-cyan-50' : 'cursor-not-allowed opacity-50' }}">
                                                <input type="radio" wire:model.live="copilotRewindCheckpoint" value="{{ $checkpoint['id'] }}" @disabled(! $checkpoint['is_reproducible']) class="mt-0.5 border-slate-300 text-cyan-700 focus:ring-cyan-600">
                                                <span class="min-w-0"><strong>#{{ $checkpoint['sequence'] }} · {{ $checkpoint['step_name'] ?: $checkpoint['phase'] }}</strong><span class="mt-0.5 block break-words text-slate-500">{{ $checkpoint['task_key'] ?: 'vor dem Step' }}{{ $checkpoint['has_side_effects'] ? ' · externe Wirkung protokolliert' : '' }}</span></span>
                                            </label>
                                        @empty
                                            <p class="text-xs text-slate-500">Noch kein reproduzierbarer Checkpoint vorhanden.</p>
                                        @endforelse
                                    </div>
                                    @error('copilotRewindCheckpoint') <p class="mt-2 text-xs text-red-600">{{ $message }}</p> @enderror
                                    <button type="button" wire:click="rewindCopilotOptimization" wire:confirm="Zum ausgewaehlten Checkpoint zurueckspringen? Externe Wirkungen werden nicht rueckgaengig gemacht." @disabled(blank($copilotRewindCheckpoint)) class="mt-3 w-full rounded-lg border border-amber-300 bg-amber-50 px-3 py-2 text-xs font-bold text-amber-800 hover:bg-amber-100 disabled:cursor-not-allowed disabled:opacity-40">Zum Checkpoint zurueckspulen</button>
                                </div>

                                <div class="rounded-xl border border-slate-200 bg-white p-4">
                                    <h3 class="text-sm font-bold text-slate-900">Workflow-Revisionen</h3>
                                    <div class="mt-3 max-h-48 space-y-2 overflow-y-auto">
                                        @forelse(data_get($copilotStatus, 'revisions', []) as $revision)
                                            <details class="rounded-lg border border-slate-200 p-2 text-xs">
                                                <summary class="cursor-pointer font-bold text-slate-800">Revision {{ $revision['revision_number'] }}{{ $revision['is_verified'] ? ' · verifiziert' : '' }}</summary>
                                                <p class="mt-2 text-slate-600">{{ $revision['reason'] ?: 'Automatische Workflow-Anpassung' }}</p>
                                                @if($revision['diff'] !== [])
                                                    <pre class="mt-2 max-h-32 overflow-auto rounded bg-slate-950 p-2 text-[10px] text-slate-100">{{ json_encode($revision['diff'], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) }}</pre>
                                                @endif
                                            </details>
                                        @empty
                                            <p class="text-xs text-slate-500">Noch keine Workflow-Aenderung gespeichert.</p>
                                        @endforelse
                                    </div>
                                </div>

                                <div class="rounded-xl border border-slate-200 bg-white p-4">
                                    <h3 class="text-sm font-bold text-slate-900">Bereinigte DOM-Elementkarte</h3>
                                    <div class="mt-3 max-h-48 space-y-1.5 overflow-y-auto text-xs">
                                        @forelse(data_get($copilotStatus, 'dom_elements', []) as $element)
                                            <div class="rounded-lg bg-slate-50 p-2"><strong>[{{ $element['ref'] ?: '?' }}] {{ $element['role'] }}</strong><span class="mt-0.5 block break-words text-slate-600">{{ $element['text'] ?: $element['selector'] }}</span></div>
                                        @empty
                                            <p class="text-slate-500">Die DOM-Karte erscheint nach der ersten Beobachtung.</p>
                                        @endforelse
                                    </div>
                                </div>
                            </aside>
                        </div>
                    @endif
                </div>
            </x-slot>
            <x-slot name="footer">
                @if($activeCopilotSession)
                    <button type="button" wire:click="downloadCopilotOptimizationLog" wire:loading.attr="disabled" wire:target="downloadCopilotOptimizationLog" class="rounded-md border border-cyan-300 bg-white px-4 py-2 text-sm font-semibold text-cyan-800 hover:bg-cyan-50 disabled:opacity-50">Komplettes Optimierungslog exportieren</button>
                    <button type="button" wire:click="openCopilotChat" class="rounded-md bg-cyan-700 px-4 py-2 text-sm font-semibold text-white hover:bg-cyan-800">Copilot-Chat oeffnen</button>
                    <button type="button" wire:click="restartCopilotOptimization" wire:confirm="Copilot-Optimierung vollstaendig neu starten? Der aktuelle Testlauf wird beendet und die Budgets werden zurueckgesetzt. Bereits ausgeloeste externe Wirkungen bleiben bestehen." wire:loading.attr="disabled" wire:target="restartCopilotOptimization" class="rounded-md bg-slate-950 px-4 py-2 text-sm font-semibold text-white hover:bg-slate-800 disabled:opacity-50">Optimierung neu starten</button>
                    @if(data_get($copilotStatus, 'active'))
                        @if(data_get($copilotStatus, 'paused'))
                            <button type="button" wire:click="resumeCopilotOptimization" class="rounded-md bg-emerald-600 px-4 py-2 text-sm font-semibold text-white hover:bg-emerald-700">Fortsetzen</button>
                        @else
                            <button type="button" wire:click="pauseCopilotOptimization" class="rounded-md border border-amber-300 bg-amber-50 px-4 py-2 text-sm font-semibold text-amber-800 hover:bg-amber-100">Pausieren</button>
                        @endif
                        <button type="button" wire:click="stopCopilotOptimization" wire:confirm="Autonome Workflow-Optimierung wirklich stoppen?" class="rounded-md border border-rose-300 bg-white px-4 py-2 text-sm font-semibold text-rose-700 hover:bg-rose-50">Stoppen</button>
                        <button type="button" wire:click="terminateCopilotOptimization" wire:confirm="Copilot wirklich beenden und alle Node-Prozesse seiner Testlaeufe erzwungen schliessen?" class="rounded-md bg-rose-700 px-4 py-2 text-sm font-semibold text-white hover:bg-rose-600">Copilot beenden</button>
                    @elseif(in_array(data_get($copilotStatus, 'status'), ['budget_exhausted', 'failed'], true))
                        <button type="button" wire:click="stopCopilotOptimization" wire:confirm="Sitzung beenden und Workflow-Lock freigeben? Die letzte Revision bleibt unverifiziert gespeichert." class="rounded-md border border-rose-300 bg-white px-4 py-2 text-sm font-semibold text-rose-700 hover:bg-rose-50">Sitzung beenden und Lock freigeben</button>
                        <button type="button" wire:click="terminateCopilotOptimization" wire:confirm="Sitzung, Testlaeufe und alle zugeordneten Node-Prozesse erzwungen beenden?" class="rounded-md bg-rose-700 px-4 py-2 text-sm font-semibold text-white hover:bg-rose-600">Alles beenden</button>
                    @endif
                @endif
                @if($previewWorkflowRun && $previewWorkflowRun->status === 'queued')
                    <button type="button" wire:click="deleteQueuedPreviewWorkflowRun" wire:confirm="Eingeplanten Workflow-Test wirklich loeschen?" class="rounded-md border border-red-300 bg-white px-4 py-2 text-sm font-semibold text-red-700 shadow-sm hover:bg-red-50">
                        Loeschen
                    </button>
                @elseif($previewWorkflowRun && in_array($previewWorkflowRun->status, ['running', 'waiting'], true))
                    @if(! $activeCopilotSession)
                        <button type="button" wire:click="pausePreviewWorkflowRun" class="rounded-md border border-amber-300 bg-amber-50 px-4 py-2 text-sm font-semibold text-amber-800 shadow-sm hover:bg-amber-100">
                            Pausieren
                        </button>
                    @endif
                    <button type="button" wire:click="cancelPreviewWorkflowRun" wire:confirm="Workflow-Test wirklich stoppen?" class="rounded-md border border-red-300 bg-white px-4 py-2 text-sm font-semibold text-red-700 shadow-sm hover:bg-red-50">
                        Stoppen
                    </button>
                @elseif($previewWorkflowRun && $previewWorkflowRun->status === 'paused' && ! $activeCopilotSession)
                    <button type="button" wire:click="resumePreviewWorkflowRun" wire:loading.attr="disabled" wire:target="resumePreviewWorkflowRun" class="rounded-md bg-emerald-600 px-4 py-2 text-sm font-semibold text-white shadow-sm hover:bg-emerald-700 disabled:opacity-50">
                        {{ $manualResumeCursor !== '' ? 'Ab Task fortsetzen' : 'Fortsetzen' }}
                    </button>
                    <button type="button" wire:click="cancelPreviewWorkflowRun" wire:confirm="Pausierten Workflow-Test wirklich stoppen?" class="rounded-md border border-red-300 bg-white px-4 py-2 text-sm font-semibold text-red-700 shadow-sm hover:bg-red-50">
                        Stoppen
                    </button>
                @endif
                @if($previewWorkflowRun)
                    <button type="button" wire:click="terminatePreviewWorkflowRun" wire:confirm="Diesen Testlauf wirklich beenden und seinen vollstaendigen Node-Prozessbaum erzwungen schliessen?" class="rounded-md bg-red-700 px-4 py-2 text-sm font-semibold text-white shadow-sm hover:bg-red-600">
                        Test + Node-Prozesse beenden
                    </button>
                @endif
                <button type="button" wire:click="closeRunPreview" class="rounded-md border border-gray-300 bg-white px-4 py-2 text-sm font-semibold text-gray-700 shadow-sm hover:bg-gray-50">
                    Schliessen
                </button>
            </x-slot>
        </x-ui.dialog-modal>

        <x-ui.dialog-modal wire:model="showCopilotRunsModal" maxWidth="7xl" :interactive-aside="true">
            <x-slot name="title">Copilot-Optimierungslaeufe dieses Workflows</x-slot>
            <x-slot name="content">
                @if($showCopilotRunsModal && $selectedWorkflow)
                    @livewire('admin.network.workflow-copilot-runs', ['workflowId' => $selectedWorkflow->id], key('workflow-copilot-runs-workflow-'.$selectedWorkflow->id))
                @endif
            </x-slot>
            <x-slot name="footer">
                <button type="button" x-on:click="$dispatch('close')" class="rounded-md border border-gray-300 bg-white px-4 py-2 text-sm font-semibold text-gray-700 shadow-sm hover:bg-gray-50">Schliessen</button>
            </x-slot>
        </x-ui.dialog-modal>


        <x-ui.dialog-modal wire:model="showRevisionHistoryModal" maxWidth="6xl">
            <x-slot name="title">
                <div>
                    <span class="text-base font-semibold text-slate-950">Workflow-Revisionen</span>
                    <p class="mt-1 text-xs font-normal text-slate-500">Stände einsehen, vergleichen und als neue aktuelle Revision wiederherstellen.</p>
                </div>
            </x-slot>
            <x-slot name="content">
                @if($revisionStudioSessionId)
                    <livewire:admin.network.workflow-revision-history
                        :workflow-id="$selectedWorkflow->id"
                        :studio-session-id="$revisionStudioSessionId"
                        :key="'manager-workflow-revisions-'.$selectedWorkflow->id.'-'.$revisionStudioSessionId"
                    />
                @else
                    <div class="rounded-xl border border-dashed border-slate-300 bg-slate-50 p-8 text-center text-sm text-slate-500">Der Revisionsverlauf wird vorbereitet.</div>
                @endif
            </x-slot>
            <x-slot name="footer">
                <button type="button" x-on:click="$dispatch('close')" class="rounded-lg border border-slate-300 bg-white px-4 py-2 text-sm font-semibold text-slate-700 shadow-sm hover:bg-slate-50">Schließen</button>
            </x-slot>
        </x-ui.dialog-modal>

        <x-ui.dialog-modal wire:model="showActionLibraryModal" maxWidth="5xl">
            <x-slot name="title">Aktionsbibliothek</x-slot>
            <x-slot name="content">
                <div class="grid gap-4 md:grid-cols-2">
                    <div>
                        <label for="workflow-action-person" class="block text-sm font-medium text-gray-700">Person</label>
                        <select id="workflow-action-person" wire:model.live="actionPersonFilter" class="mt-1 block w-full rounded-md border border-gray-300 p-2 text-sm shadow-sm focus:border-blue-500 focus:ring-blue-500">
                            <option value="">Alle Personen</option>
                            @foreach($personOptions as $person)
                                <option value="{{ $person['id'] }}">{{ $person['label'] }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div>
                        <label for="workflow-action-type" class="block text-sm font-medium text-gray-700">Typ</label>
                        <select id="workflow-action-type" wire:model.live="actionTypeFilter" class="mt-1 block w-full rounded-md border border-gray-300 p-2 text-sm shadow-sm focus:border-blue-500 focus:ring-blue-500">
                            <option value="all">Alle Aktionen</option>
                            <option value="step">Session-Schritte</option>
                            <option value="content">Content</option>
                        </select>
                    </div>
                </div>

                <div class="mt-5 grid gap-3 md:grid-cols-2">
                    @forelse($actions as $action)
                        <x-workflows.action-template-card :action="$action" wire:key="workflow-action-template-{{ $action['id'] }}">
                            <x-slot name="actions">
                                <button type="button" wire:click="addActionStep(@js($action['id']))" class="rounded-md border border-blue-200 bg-white px-3 py-1.5 text-xs font-semibold text-blue-700 shadow-sm hover:bg-blue-50">
                                    Hinzufuegen
                                </button>
                            </x-slot>
                        </x-workflows.action-template-card>
                    @empty
                        <div class="md:col-span-2 rounded-md border border-dashed border-slate-300 bg-slate-50 p-6 text-center text-sm text-slate-500">
                            Keine geplanten Aktionen gefunden.
                        </div>
                    @endforelse
                </div>
            </x-slot>
            <x-slot name="footer">
                <button type="button" x-on:click="$dispatch('close')" class="rounded-md border border-gray-300 bg-white px-4 py-2 text-sm font-semibold text-gray-700 shadow-sm hover:bg-gray-50">Schliessen</button>
            </x-slot>
        </x-ui.dialog-modal>
    @endif
</div>
