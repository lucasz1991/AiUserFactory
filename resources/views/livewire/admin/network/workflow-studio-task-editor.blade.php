<div
    class="h-full min-h-0 overflow-y-auto overscroll-contain xl:overflow-hidden"
    x-data="{
        focusedTask: '',
        hoveredRouteNode: '',
        activeRouteNode: '',
        routeFocusNode() {
            return this.hoveredRouteNode || this.activeRouteNode;
        },
        setHoveredRouteNode(node) {
            this.hoveredRouteNode = node || '';
        },
        setActiveRouteNode(node) {
            this.activeRouteNode = this.activeRouteNode === node ? '' : (node || '');
        },
        armTaskInsert(stepId) {
            $wire.selectCatalogTarget(stepId);
            this.$nextTick(() => document.querySelector('[data-studio-task-catalog]')?.scrollIntoView({ behavior: 'smooth', block: 'nearest' }));
        },
    }"
>
    <div class="grid min-h-full overflow-visible rounded-2xl border border-slate-200 bg-white shadow-sm xl:h-full xl:min-h-0 xl:grid-cols-[310px_minmax(0,1fr)] xl:overflow-hidden">
        <aside data-studio-task-catalog class="flex h-[520px] min-h-0 shrink-0 flex-col border-b border-slate-200 bg-slate-950 text-white sm:h-[600px] xl:h-auto xl:border-b-0 xl:border-r">
            <div class="border-b border-white/10 px-4 py-4">
                <div class="flex items-start justify-between gap-3">
                    <div>
                        <p class="text-[10px] font-bold uppercase tracking-[0.18em] text-cyan-300">Bausteine</p>
                        <h2 class="mt-1 text-base font-bold">Task-Katalog</h2>
                    </div>
                    <span class="rounded-full bg-white/10 px-2.5 py-1 text-[10px] font-bold text-slate-200">{{ $taskDefinitions->count() }}</span>
                </div>
                <p class="mt-2 text-xs leading-5 text-slate-400">Task anklicken oder direkt in eine Liste ziehen. Danach öffnet sich das vollständige Formular.</p>
            </div>

            <div class="space-y-3 border-b border-white/10 p-4">
                <div>
                    <label for="studio-catalog-target" class="text-[10px] font-bold uppercase tracking-wide text-slate-400">Zielliste</label>
                    <select id="studio-catalog-target" wire:model.live="catalogTargetStepId" class="mt-1.5 w-full rounded-lg border-white/10 bg-slate-900 text-xs font-semibold text-white focus:border-cyan-400 focus:ring-cyan-400" @disabled(! $canEdit)>
                        @forelse($steps as $step)
                            <option value="{{ $step->id }}">{{ $step->name }}</option>
                        @empty
                            <option value="">Zuerst eine Liste anlegen</option>
                        @endforelse
                    </select>
                </div>
                <div class="relative">
                    <svg class="pointer-events-none absolute left-3 top-2.5 h-4 w-4 text-slate-500" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><circle cx="11" cy="11" r="7"></circle><path d="m20 20-3.5-3.5"></path></svg>
                    <input type="search" wire:model.live.debounce.250ms="taskSearch" class="w-full rounded-lg border-white/10 bg-slate-900 py-2 pl-9 pr-3 text-xs text-white placeholder:text-slate-500 focus:border-cyan-400 focus:ring-cyan-400" placeholder="Task suchen …">
                </div>
            </div>

            <nav class="flex shrink-0 gap-1 overflow-x-auto border-b border-white/10 px-3" aria-label="Task-Gruppen">
                @foreach($taskGroups as $taskGroup)
                    <button
                        type="button"
                        wire:click="$set('activeTaskGroup', @js($taskGroup))"
                        class="whitespace-nowrap border-b-2 px-2 py-3 text-[11px] font-bold transition {{ $activeTaskGroup === $taskGroup ? 'border-cyan-400 text-cyan-300' : 'border-transparent text-slate-400 hover:text-white' }}"
                    >{{ $taskGroupLabels[$taskGroup] ?? $taskGroup }}</button>
                @endforeach
            </nav>

            <div class="min-h-0 flex-1 space-y-2 overflow-y-auto p-3">
                @forelse($visibleTaskDefinitions as $taskDefinition)
                    <button
                        type="button"
                        wire:click="prepareCatalogTask(@js($taskDefinition['key']))"
                        draggable="{{ $canEdit ? 'true' : 'false' }}"
                        x-on:dragstart.stop="$event.dataTransfer.setData('application/x-workflow-task-catalog', @js($taskDefinition['key'])); $event.dataTransfer.setData('text/plain', @js($taskDefinition['key'])); $event.dataTransfer.effectAllowed = 'copy'"
                        @disabled(! $canEdit || $steps->isEmpty())
                        class="group block w-full rounded-xl border border-white/10 bg-white/[0.06] p-3 text-left transition hover:border-cyan-400/60 hover:bg-white/10 disabled:cursor-not-allowed disabled:opacity-40"
                    >
                        <span class="flex items-start justify-between gap-3">
                            <span class="min-w-0">
                                <span class="block truncate text-xs font-bold text-white">{{ $taskDefinition['label'] }}</span>
                                <span class="mt-1 block line-clamp-2 text-[10px] leading-4 text-slate-400">{{ $taskDefinition['description'] }}</span>
                            </span>
                            <span class="mt-0.5 rounded-md bg-cyan-400/10 px-1.5 py-0.5 font-mono text-[9px] text-cyan-300">+</span>
                        </span>
                    </button>
                @empty
                    <div class="rounded-xl border border-dashed border-white/15 px-4 py-8 text-center text-xs text-slate-400">Keine passenden Tasks gefunden.</div>
                @endforelse
            </div>
        </aside>

        <section class="flex min-h-[560px] min-w-0 shrink-0 flex-col bg-slate-50 xl:min-h-0">
            <div class="flex shrink-0 flex-wrap items-center justify-between gap-3 border-b border-slate-200 bg-white px-4 py-3">
                <div>
                    <div class="flex items-center gap-2">
                        <h2 class="text-sm font-bold text-slate-950">Workflow aufbauen</h2>
                        <span class="rounded-full bg-slate-100 px-2 py-0.5 text-[10px] font-bold text-slate-600">{{ $steps->count() }} Listen</span>
                        <span class="rounded-full bg-slate-100 px-2 py-0.5 text-[10px] font-bold text-slate-600">{{ $steps->sum(fn ($step) => count($step->task_cards)) }} Tasks</span>
                    </div>
                    <p class="mt-1 text-xs text-slate-500">Listen und Tasks verschieben, bearbeiten oder direkt aus dem Katalog einsetzen.</p>
                </div>
                <button type="button" wire:click="$set('showAddStepModal', true)" @disabled(! $canEdit) class="inline-flex h-9 items-center gap-2 rounded-lg bg-slate-900 px-3 text-xs font-bold text-white shadow-sm transition hover:bg-slate-800 disabled:cursor-not-allowed disabled:opacity-40">
                    <span class="text-base leading-none">+</span> Neue Liste
                </button>
            </div>

            @if(! $canEdit)
                <div class="flex shrink-0 items-center justify-between gap-3 border-b border-amber-200 bg-amber-50 px-4 py-2.5 text-xs text-amber-900">
                    <span><strong>Bearbeitung gesperrt:</strong> Der Lauf ist {{ $runStatus ?: 'aktiv' }}. Pausiere ihn, damit Browserzustand und Task-Reihenfolge konsistent bleiben.</span>
                </div>
            @endif
            @error('studioBuilder')
                <div class="shrink-0 border-b border-rose-200 bg-rose-50 px-4 py-2.5 text-xs font-semibold text-rose-700">{{ $message }}</div>
            @enderror

            <div class="relative min-h-0 flex-1 overflow-auto" style="background-image:linear-gradient(rgba(148,163,184,.13) 1px,transparent 1px),linear-gradient(90deg,rgba(148,163,184,.13) 1px,transparent 1px);background-size:24px 24px;">
                <div
                    x-sort="$dispatch('reorderWorkflowSteps', { item: $item, position: $position })"
                    class="flex min-h-full min-w-max items-start gap-8 px-6 pb-10 pt-8"
                >
                    @forelse($steps as $step)
                        <div
                            x-sort:item="{{ $step->id }}"
                            wire:key="studio-builder-step-{{ $step->id }}"
                            class="rounded-2xl transition {{ (string) $step->id === $catalogTargetStepId ? 'ring-2 ring-cyan-500 ring-offset-4 ring-offset-slate-50' : '' }}"
                        >
                            <x-workflows.step-card :step="$step" :locked="! $canEdit">
                                <x-slot name="actions">
                                    <button type="button" wire:click="openEditStep({{ $step->id }})" class="block w-full rounded px-3 py-2 text-left text-xs font-semibold text-slate-700 hover:bg-slate-100">Liste bearbeiten</button>
                                    <button type="button" wire:click="toggleStep({{ $step->id }})" class="block w-full rounded px-3 py-2 text-left text-xs font-semibold text-slate-700 hover:bg-slate-100">{{ $step->is_enabled ? 'Pausieren' : 'Aktivieren' }}</button>
                                    <button type="button" wire:click="selectCatalogTarget({{ $step->id }})" class="block w-full rounded px-3 py-2 text-left text-xs font-semibold text-cyan-700 hover:bg-cyan-50">Katalog hier einsetzen</button>
                                    <button type="button" wire:click="removeStep({{ $step->id }})" wire:confirm="Liste samt Tasks wirklich entfernen?" class="block w-full rounded px-3 py-2 text-left text-xs font-semibold text-rose-700 hover:bg-rose-50">Liste entfernen</button>
                                </x-slot>
                            </x-workflows.step-card>
                        </div>
                    @empty
                        <button type="button" wire:click="$set('showAddStepModal', true)" @disabled(! $canEdit) class="flex min-h-64 w-[320px] items-center justify-center rounded-2xl border-2 border-dashed border-slate-300 bg-white/80 p-8 text-center text-sm font-bold text-slate-600 transition hover:border-cyan-400 hover:text-cyan-700 disabled:opacity-40">Erste Liste anlegen</button>
                    @endforelse

                    @if($steps->isNotEmpty())
                        <button type="button" wire:click="$set('showAddStepModal', true)" @disabled(! $canEdit) class="flex min-h-48 w-[240px] shrink-0 items-center justify-center rounded-2xl border-2 border-dashed border-slate-300 bg-white/70 p-6 text-center text-sm font-bold text-slate-500 transition hover:border-cyan-400 hover:bg-white hover:text-cyan-700 disabled:opacity-40">+ Weitere Liste</button>
                    @endif
                </div>
            </div>
        </section>
    </div>

    <x-dialog-modal wire:model="showAddStepModal" maxWidth="2xl">
        <x-slot name="title">
            <div><span class="text-base font-semibold text-slate-950">Neue Workflow-Liste</span><p class="mt-1 text-xs font-normal text-slate-500">Eine Liste gruppiert zusammengehörige Tasks und besitzt eigene Erfolgs- und Fehlerwege.</p></div>
        </x-slot>
        <x-slot name="content">
            <div class="grid gap-4 md:grid-cols-2">
                <div>
                    <label for="studio-new-step-type" class="block text-sm font-medium text-slate-700">Aufgabentyp</label>
                    <select id="studio-new-step-type" wire:model.live="newStepType" class="mt-1 block w-full rounded-lg border-slate-300 text-sm shadow-sm focus:border-cyan-500 focus:ring-cyan-500">
                        <option value="preparation">Vorbereitung</option>
                        <option value="data_processing">Daten verarbeiten</option>
                        <option value="browser_control">Browsersteuerung</option>
                        <option value="interaction">Interaktion</option>
                        <option value="decision">Status prüfen</option>
                        <option value="cleanup">Abschluss</option>
                    </select>
                </div>
                <div>
                    <label for="studio-new-step-name" class="block text-sm font-medium text-slate-700">Listenname</label>
                    <input id="studio-new-step-name" type="text" wire:model.defer="newStepName" class="mt-1 block w-full rounded-lg border-slate-300 text-sm shadow-sm focus:border-cyan-500 focus:ring-cyan-500" placeholder="z. B. Login vorbereiten">
                    @error('newStepName') <p class="mt-2 text-sm text-rose-600">{{ $message }}</p> @enderror
                </div>
            </div>
        </x-slot>
        <x-slot name="footer">
            <button type="button" x-on:click="$dispatch('close')" class="rounded-lg border border-slate-300 bg-white px-4 py-2 text-sm font-semibold text-slate-700 shadow-sm hover:bg-slate-50">Abbrechen</button>
            <button type="button" wire:click="addStep" class="ml-2 rounded-lg bg-slate-900 px-4 py-2 text-sm font-semibold text-white shadow-sm hover:bg-slate-800">Liste anlegen</button>
        </x-slot>
    </x-dialog-modal>

    <x-dialog-modal wire:model="showEditStepModal" maxWidth="2xl">
        <x-slot name="title">
            <div><span class="text-base font-semibold text-slate-950">Liste bearbeiten</span><p class="mt-1 text-xs font-normal text-slate-500">Name, Status, Pause und Routing dieser Liste ändern.</p></div>
        </x-slot>
        <x-slot name="content">
            <div class="space-y-4">
                <div>
                    <label for="studio-edit-step-name" class="block text-sm font-medium text-slate-700">Name</label>
                    <input id="studio-edit-step-name" type="text" wire:model.defer="editingStepName" class="mt-1 block w-full rounded-lg border-slate-300 text-sm shadow-sm focus:border-cyan-500 focus:ring-cyan-500">
                    @error('editingStepName') <p class="mt-2 text-sm text-rose-600">{{ $message }}</p> @enderror
                </div>
                <div>
                    <label for="studio-edit-step-description" class="block text-sm font-medium text-slate-700">Beschreibung</label>
                    <textarea id="studio-edit-step-description" rows="3" wire:model.defer="editingStepDescription" class="mt-1 block w-full rounded-lg border-slate-300 text-sm shadow-sm focus:border-cyan-500 focus:ring-cyan-500"></textarea>
                </div>
                <div class="grid gap-4 md:grid-cols-2">
                    <label class="flex items-center gap-3 rounded-lg border border-slate-200 bg-slate-50 p-3 text-sm font-medium text-slate-700">
                        <input type="checkbox" wire:model.defer="editingStepEnabled" class="rounded border-slate-300 text-cyan-600 shadow-sm focus:ring-cyan-500"> Aktiv
                    </label>
                    <div>
                        <label for="studio-edit-step-wait" class="block text-sm font-medium text-slate-700">Pause danach (Sekunden)</label>
                        <input id="studio-edit-step-wait" type="number" min="0" max="3600" wire:model.defer="editingStepWaitAfterSeconds" class="mt-1 block w-full rounded-lg border-slate-300 text-sm shadow-sm focus:border-cyan-500 focus:ring-cyan-500">
                    </div>
                </div>
                <div class="grid gap-4 md:grid-cols-2">
                    @foreach(['editingStepSuccessTarget' => 'Bei Erfolg', 'editingStepFailedTarget' => 'Bei Fehler'] as $model => $label)
                        <div>
                            <label class="block text-sm font-medium text-slate-700">{{ $label }}</label>
                            <select wire:model.defer="{{ $model }}" class="mt-1 block w-full rounded-lg border-slate-300 text-sm shadow-sm focus:border-cyan-500 focus:ring-cyan-500">
                                <option value="">Keine Route</option>
                                <option value="end">Workflow beenden</option>
                                <option value="fail">Fehlerroute</option>
                                @foreach($steps as $targetStep)
                                    <option value="step:{{ $targetStep->action_key }}">{{ $targetStep->name }}</option>
                                    @foreach($targetStep->task_cards as $targetTask)
                                        <option value="card:{{ $targetStep->id }}:{{ $targetTask['key'] ?? '' }}">Task: {{ $targetStep->name }} / {{ $targetTask['title'] ?? 'Task' }}</option>
                                    @endforeach
                                @endforeach
                            </select>
                        </div>
                    @endforeach
                </div>
                <div class="grid gap-4 md:grid-cols-2">
                    <div><label class="block text-sm font-medium text-slate-700">Grund bei Erfolg</label><input type="text" wire:model.defer="editingStepSuccessReason" class="mt-1 block w-full rounded-lg border-slate-300 text-sm shadow-sm focus:border-cyan-500 focus:ring-cyan-500"></div>
                    <div><label class="block text-sm font-medium text-slate-700">Grund bei Fehler</label><input type="text" wire:model.defer="editingStepFailedReason" class="mt-1 block w-full rounded-lg border-slate-300 text-sm shadow-sm focus:border-cyan-500 focus:ring-cyan-500"></div>
                </div>
                <div class="max-w-xs">
                    <label class="block text-sm font-medium text-slate-700">Fehler-Rückleitung: maximale Versuche</label>
                    <input type="number" min="0" max="20" wire:model.defer="editingStepFailedRetryLimit" class="mt-1 block w-full rounded-lg border-slate-300 text-sm shadow-sm focus:border-cyan-500 focus:ring-cyan-500">
                </div>
            </div>
        </x-slot>
        <x-slot name="footer">
            <button type="button" x-on:click="$dispatch('close')" class="rounded-lg border border-slate-300 bg-white px-4 py-2 text-sm font-semibold text-slate-700 shadow-sm hover:bg-slate-50">Abbrechen</button>
            <button type="button" wire:click="saveEditStep" class="ml-2 rounded-lg bg-cyan-600 px-4 py-2 text-sm font-semibold text-white shadow-sm hover:bg-cyan-500">Speichern & Revision erstellen</button>
        </x-slot>
    </x-dialog-modal>

    <x-dialog-modal wire:model="showAddTaskModal" maxWidth="3xl">
        <x-slot name="title">
            <div><span class="text-base font-semibold text-slate-950">Task einsetzen</span><p class="mt-1 text-xs font-normal text-slate-500">Parameter, Browserfenster sowie Erfolgs- und Fehlerwege vor dem Einfügen festlegen.</p></div>
        </x-slot>
        <x-slot name="content">@include('livewire.admin.network.partials.workflow-task-form', ['mode' => 'create', 'steps' => $steps, 'taskDefinitions' => $taskDefinitions])</x-slot>
        <x-slot name="footer">
            <button type="button" x-on:click="$dispatch('close')" class="rounded-lg border border-slate-300 bg-white px-4 py-2 text-sm font-semibold text-slate-700 shadow-sm hover:bg-slate-50">Abbrechen</button>
            <button type="button" x-on:click.prevent="const source = document.querySelector('[data-workflow-task-mailbox-source=&quot;newTask&quot;]')?.value || 'person'; $wire.addTaskCard(source);" class="ml-2 rounded-lg bg-cyan-600 px-4 py-2 text-sm font-semibold text-white shadow-sm hover:bg-cyan-500">Task einsetzen</button>
        </x-slot>
    </x-dialog-modal>

    <x-dialog-modal wire:model="showEditTaskModal" maxWidth="5xl">
        <x-slot name="title">
            <div><span class="text-base font-semibold text-slate-950">Task bearbeiten</span><p class="mt-1 text-xs font-normal text-slate-500">Alle Task-Einstellungen aus dem Workflow-Manager stehen auch im pausierten Testlauf zur Verfügung.</p></div>
        </x-slot>
        <x-slot name="content">@include('livewire.admin.network.partials.workflow-task-form', ['mode' => 'edit', 'steps' => $steps, 'taskDefinitions' => $taskDefinitions])</x-slot>
        <x-slot name="footer">
            <button type="button" x-on:click="$dispatch('close')" class="rounded-lg border border-slate-300 bg-white px-4 py-2 text-sm font-semibold text-slate-700 shadow-sm hover:bg-slate-50">Abbrechen</button>
            <button type="button" x-on:click.prevent="const source = document.querySelector('[data-workflow-task-mailbox-source=&quot;editingTask&quot;]')?.value || 'person'; $wire.saveEditTaskCard(source);" class="ml-2 rounded-lg bg-cyan-600 px-4 py-2 text-sm font-semibold text-white shadow-sm hover:bg-cyan-500">Speichern & Revision erstellen</button>
        </x-slot>
    </x-dialog-modal>
</div>
