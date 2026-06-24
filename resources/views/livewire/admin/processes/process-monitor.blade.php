<section
    @if($autoRefresh) wire:poll.10s="syncProcesses(false)" @endif
    class="{{ $compact ? 'rounded-lg border border-gray-200 bg-white p-4 shadow-sm' : 'space-y-6' }}"
>
    @if($showHeader)
        <div class="{{ $compact ? '' : 'rounded-lg border border-gray-200 bg-white p-6 shadow-sm' }}">
            <div class="flex flex-wrap items-start justify-between gap-4">
                <div>
                    <h1 class="{{ $compact ? 'text-lg' : 'text-2xl' }} font-semibold text-gray-900">Prozesse</h1>
                    <p class="mt-1 text-sm text-gray-500">
                        Erkannte Followflow-Prozesse, Node-Scripts und zugehoerige Browser-Kindprozesse.
                    </p>
                </div>
                <button type="button" wire:click="syncProcesses" wire:loading.attr="disabled" class="rounded-md border border-slate-300 bg-white px-4 py-2 text-sm font-semibold text-slate-700 shadow-sm hover:bg-slate-50 disabled:cursor-wait disabled:opacity-60">
                    Jetzt synchronisieren
                </button>
            </div>
        </div>
    @endif

    @if(! $tableReady)
        <div class="rounded-lg border border-amber-200 bg-amber-50 p-4 text-sm text-amber-900">
            Die Prozess-Tabelle ist noch nicht vorhanden. Bitte Migrationen ausfuehren.
        </div>
    @else
        <div class="grid gap-4 md:grid-cols-4">
            <div class="rounded-lg border border-gray-200 bg-white p-4 shadow-sm">
                <p class="text-xs font-semibold uppercase tracking-wide text-gray-500">Gesamt</p>
                <p class="mt-2 text-2xl font-semibold text-gray-900">{{ $stats['total'] }}</p>
            </div>
            <div class="rounded-lg border border-emerald-200 bg-emerald-50 p-4 shadow-sm">
                <p class="text-xs font-semibold uppercase tracking-wide text-emerald-700">Laufend</p>
                <p class="mt-2 text-2xl font-semibold text-emerald-900">{{ $stats['running'] }}</p>
            </div>
            <div class="rounded-lg border border-amber-200 bg-amber-50 p-4 shadow-sm">
                <p class="text-xs font-semibold uppercase tracking-wide text-amber-700">Leerlauf-Verdacht</p>
                <p class="mt-2 text-2xl font-semibold text-amber-900">{{ $stats['idle'] }}</p>
            </div>
            <div class="rounded-lg border border-slate-200 bg-slate-50 p-4 shadow-sm">
                <p class="text-xs font-semibold uppercase tracking-wide text-slate-600">Beendet</p>
                <p class="mt-2 text-2xl font-semibold text-slate-900">{{ $stats['exited'] }}</p>
            </div>
        </div>

        @if($notice)
            <div class="rounded-lg border border-blue-200 bg-blue-50 p-4 text-sm font-semibold text-blue-900">
                {{ $notice }}
            </div>
        @endif

        <div class="rounded-lg border border-gray-200 bg-white shadow-sm">
            <div class="flex flex-wrap items-center justify-between gap-3 border-b border-gray-200 px-4 py-3">
                <div class="inline-flex rounded-md border border-slate-200 bg-slate-50 p-1 text-xs font-semibold">
                    @foreach(['running' => 'Laufend', 'idle' => 'Leerlauf', 'exited' => 'Beendet', 'all' => 'Alle'] as $value => $label)
                        <button type="button" wire:click="setFilter('{{ $value }}')" class="rounded px-3 py-1.5 {{ $filter === $value ? 'bg-slate-900 text-white' : 'text-slate-600 hover:bg-white' }}">
                            {{ $label }}
                        </button>
                    @endforeach
                </div>
                @if($compact)
                    <a href="{{ route('processes.index') }}" class="text-sm font-semibold text-blue-700 hover:text-blue-900">
                        Alle Prozesse
                    </a>
                @endif
            </div>

            <div class="overflow-x-auto">
                <div class="min-w-[980px]">
                    <div class="grid grid-cols-[160px_180px_150px_minmax(360px,1fr)_150px] border-b border-slate-200 bg-slate-50 px-4 py-3 text-xs font-semibold uppercase tracking-wide text-slate-500">
                        <div>PID</div>
                        <div>Typ</div>
                        <div>Status</div>
                        <div>Kommando</div>
                        <div class="text-right">Aktion</div>
                    </div>

                    @forelse($processTree as $process)
                        @include('livewire.admin.processes.partials.process-tree-node', [
                            'process' => $process,
                            'depth' => 0,
                            'defaultOpen' => ! $compact,
                        ])
                    @empty
                        <div class="px-4 py-12 text-center text-sm text-gray-500">
                            Keine Prozesse fuer diesen Filter.
                        </div>
                    @endforelse
                </div>
            </div>
        </div>
    @endif
</section>
