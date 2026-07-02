<div
    x-data="{
        open: false,
        draft: @entangle('message'),
        isLoading: @entangle('isLoading'),
        chatHistory: @entangle('chatHistory'),
        toolEvents: @entangle('toolEvents'),
        init() {
            this.syncContext();
            this.$watch('chatHistory', () => this.scrollMessages());
        },
        collectContext() {
            const path = window.location.pathname;
            const workflowMatch = path.match(/\/netzwerk\/workflows\/(\d+)/);

            return {
                route_name: null,
                path,
                page_title: document.title,
                workflow_id: workflowMatch ? workflowMatch[1] : null,
                workflow_slug: null,
            };
        },
        async syncContext() {
            await $wire.updatePageContext(this.collectContext());
        },
        scrollMessages() {
            this.$nextTick(() => {
                const messages = this.$refs.messages;
                if (!messages) return;
                messages.scrollTo({ top: messages.scrollHeight, behavior: 'smooth' });
            });
        },
        async send() {
            if (this.isLoading || !(this.draft || '').trim()) return;

            await this.syncContext();
            await $wire.sendMessage(this.draft);
            this.scrollMessages();
        },
        async quick(prompt) {
            if (this.isLoading) return;

            await this.syncContext();
            await $wire.sendMessage(prompt);
            this.open = true;
            this.scrollMessages();
        },
    }"
    x-on:keydown.escape.window="open = false"
    class="fixed bottom-5 right-5 z-[80]"
>
    <button
        type="button"
        x-show="!open"
        x-on:click="open = true; syncContext(); scrollMessages()"
        class="flex h-14 w-14 items-center justify-center rounded-full bg-slate-900 text-sm font-semibold text-white shadow-lg ring-1 ring-slate-700/20 hover:bg-slate-800"
        aria-label="AI Workflow Copilot oeffnen"
    >
        AI
    </button>

    <section
        x-cloak
        x-show="open"
        x-transition
        class="flex h-[min(720px,calc(100vh-2.5rem))] w-[min(440px,calc(100vw-2.5rem))] flex-col overflow-hidden rounded-lg border border-slate-200 bg-white shadow-2xl"
    >
        <header class="flex items-center justify-between gap-3 border-b border-slate-200 bg-slate-950 px-4 py-3 text-white">
            <div class="min-w-0">
                <div class="truncate text-sm font-semibold">{{ $assistantName }}</div>
                <div class="truncate text-xs text-slate-300">Workflow-Analyse, Tasks, Listen, Tags und Imports</div>
            </div>
            <div class="flex items-center gap-1">
                <button type="button" wire:click="clearChat" class="rounded px-2 py-1 text-xs font-semibold text-slate-200 hover:bg-white/10">
                    Leeren
                </button>
                <button type="button" x-on:click="open = false" class="rounded px-2 py-1 text-lg leading-none text-slate-200 hover:bg-white/10" aria-label="Schliessen">
                    &times;
                </button>
            </div>
        </header>

        <div class="border-b border-slate-200 bg-slate-50 px-4 py-3">
            <div class="flex flex-wrap gap-2">
                <button type="button" x-on:click="quick('Analysiere bitte den letzten Workflow-Lauf und nenne Fehlerursache, betroffene Liste/Task und naechste Reparatur.')" class="rounded border border-slate-200 bg-white px-2.5 py-1.5 text-xs font-semibold text-slate-700 hover:bg-slate-100">
                    Letzten Run analysieren
                </button>
                <button type="button" x-on:click="quick('Finde den Workflow DIBAG oeffnen, analysiere den letzten Lauf und schlage konkrete Korrekturen vor.')" class="rounded border border-slate-200 bg-white px-2.5 py-1.5 text-xs font-semibold text-slate-700 hover:bg-slate-100">
                    DIBAG oeffnen
                </button>
                <button type="button" x-on:click="quick('Hilf mir, einen neuen Workflow zu planen. Frage zuerst nach Ziel, Webseiten, benoetigten Listen, Tasks und eingebetteten Workflows.')" class="rounded border border-slate-200 bg-white px-2.5 py-1.5 text-xs font-semibold text-slate-700 hover:bg-slate-100">
                    Neuer Workflow
                </button>
                <button type="button" x-on:click="quick('Liste bitte passende Task-Katalog Eintraege fuer Browser, Mail und Datenverarbeitung und erklaere kurz, wann ich welchen Task nutze.')" class="rounded border border-slate-200 bg-white px-2.5 py-1.5 text-xs font-semibold text-slate-700 hover:bg-slate-100">
                    Task-Katalog
                </button>
            </div>
        </div>

        <div x-ref="messages" class="min-h-0 flex-1 space-y-3 overflow-y-auto bg-white px-4 py-4">
            @forelse($chatHistory as $index => $item)
                @php
                    $role = $item['role'] ?? 'assistant';
                    $tone = $item['tone'] ?? 'neutral';
                    $isUser = $role === 'user';
                    $bubbleClass = $isUser
                        ? 'ml-auto bg-slate-900 text-white'
                        : ($tone === 'error'
                            ? 'mr-auto border border-red-200 bg-red-50 text-red-900'
                            : ($tone === 'success'
                                ? 'mr-auto border border-emerald-200 bg-emerald-50 text-emerald-900'
                                : 'mr-auto border border-slate-200 bg-slate-50 text-slate-800'));
                @endphp
                <div class="max-w-[88%] rounded-lg px-3 py-2 text-sm shadow-sm {{ $bubbleClass }}">
                    <div class="whitespace-pre-wrap break-words leading-relaxed">{!! nl2br(e($item['content'] ?? '')) !!}</div>
                    @if(($item['options'] ?? null) && is_array($item['options']))
                        <div class="mt-2 space-y-1">
                            @foreach($item['options'] as $optionIndex => $option)
                                <button
                                    type="button"
                                    wire:click="sendChatOption({{ $index }}, {{ $optionIndex }})"
                                    @disabled(($item['selected_option_index'] ?? null) !== null)
                                    class="block w-full rounded border border-slate-300 bg-white px-2 py-1 text-left text-xs font-semibold text-slate-700 hover:bg-slate-100 disabled:cursor-not-allowed disabled:opacity-60"
                                >
                                    {{ $option['label'] ?? 'Option' }}
                                    @if(! blank($option['description'] ?? null))
                                        <span class="block font-normal text-slate-500">{{ $option['description'] }}</span>
                                    @endif
                                </button>
                            @endforeach
                        </div>
                    @endif
                    <div class="mt-1 text-[10px] {{ $isUser ? 'text-slate-300' : 'text-slate-400' }}">{{ $item['time'] ?? '' }}</div>
                </div>
            @empty
                <div class="rounded-lg border border-dashed border-slate-300 bg-slate-50 p-4 text-sm text-slate-600">
                    Frage nach einem Workflow, einem letzten Run, einem neuen Task-Aufbau oder importiere eine Workflow-CSV/ZIP.
                </div>
            @endforelse

            <div wire:loading.flex wire:target="sendMessage" class="items-center gap-2 rounded-lg border border-slate-200 bg-slate-50 px-3 py-2 text-xs text-slate-500">
                <span class="h-2 w-2 animate-pulse rounded-full bg-slate-500"></span>
                Der Copilot arbeitet...
            </div>
        </div>

        @if($toolEvents !== [])
            <div class="max-h-28 space-y-1 overflow-y-auto border-t border-slate-200 bg-slate-50 px-4 py-2">
                @foreach(array_reverse($toolEvents) as $event)
                    <div class="flex items-start justify-between gap-2 rounded border border-slate-200 bg-white px-2 py-1 text-xs">
                        <div class="min-w-0">
                            <div class="truncate font-semibold {{ ($event['status'] ?? '') === 'success' ? 'text-emerald-700' : 'text-red-700' }}">{{ $event['tool'] ?? 'Tool' }}</div>
                            <div class="truncate text-slate-500">{{ $event['message'] ?? '' }}</div>
                        </div>
                        <button type="button" wire:click="dismissToolEvent('{{ $event['id'] ?? '' }}')" class="shrink-0 text-slate-400 hover:text-slate-700">&times;</button>
                    </div>
                @endforeach
            </div>
        @endif

        <div class="border-t border-slate-200 bg-white p-3">
            <div class="mb-2 rounded border border-slate-200 bg-slate-50 p-2">
                <div class="flex items-center gap-2">
                    <input type="file" wire:model="workflowImportFile" accept=".csv,.zip" class="block min-w-0 flex-1 text-xs text-slate-600 file:mr-2 file:rounded file:border-0 file:bg-slate-900 file:px-2 file:py-1 file:text-xs file:font-semibold file:text-white">
                    <button type="button" wire:click="importWorkflowUpdate" wire:loading.attr="disabled" wire:target="workflowImportFile,importWorkflowUpdate" class="rounded bg-slate-900 px-3 py-1.5 text-xs font-semibold text-white hover:bg-slate-800 disabled:opacity-50">
                        Import
                    </button>
                </div>
                @error('workflowImportFile') <div class="mt-1 text-xs text-red-600">{{ $message }}</div> @enderror
            </div>

            <form x-on:submit.prevent="send()" class="flex items-end gap-2">
                <textarea
                    x-model="draft"
                    rows="2"
                    placeholder="Workflow besprechen, Task einstellen, Tags setzen..."
                    class="min-h-[52px] flex-1 resize-none rounded-md border border-slate-300 px-3 py-2 text-sm shadow-sm focus:border-slate-500 focus:ring-slate-500"
                    x-on:keydown.enter.meta.prevent="send()"
                    x-on:keydown.enter.ctrl.prevent="send()"
                ></textarea>
                <button type="submit" x-bind:disabled="isLoading || !(draft || '').trim()" class="rounded-md bg-slate-900 px-4 py-2 text-sm font-semibold text-white shadow-sm hover:bg-slate-800 disabled:cursor-not-allowed disabled:opacity-50">
                    Senden
                </button>
            </form>
        </div>
    </section>
</div>
