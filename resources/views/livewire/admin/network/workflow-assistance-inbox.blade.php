@php
    $statusLabels = [
        'pending' => 'Offen',
        'claimed' => 'In Bearbeitung',
        'resolved' => 'Erledigt',
        'cancelled' => 'Abgebrochen',
        'expired' => 'Abgelaufen',
    ];
    $statusClasses = [
        'pending' => 'border-amber-200 bg-amber-50 text-amber-800',
        'claimed' => 'border-cyan-200 bg-cyan-50 text-cyan-800',
        'resolved' => 'border-emerald-200 bg-emerald-50 text-emerald-800',
        'cancelled' => 'border-slate-200 bg-slate-100 text-slate-700',
        'expired' => 'border-rose-200 bg-rose-50 text-rose-800',
    ];
    $selectedIsMine = $selected && (int) $selected->assigned_to_user_id === (int) $operator->id;
    $selectedIsOpen = $selected?->isOpen() ?? false;
    $selectedExpired = $selected?->isExpired() ?? false;
    $browserBusy = $selected && $selected->workflowRun?->status !== 'paused';
    $canInteract = $selectedIsMine && $selectedIsOpen && ! $selectedExpired && ! $browserBusy;
    $previewAvailable = filled($snapshot['preview_path'] ?? null);
@endphp

<div class="min-h-[calc(100vh-7rem)] space-y-5" wire:poll.3s>
    <section class="overflow-hidden rounded-[1.75rem] border border-slate-200 bg-slate-950 text-white shadow-sm">
        <div class="relative grid gap-6 px-5 py-6 sm:px-7 lg:grid-cols-[minmax(0,1fr)_auto] lg:items-end lg:px-9 lg:py-8">
            <div class="pointer-events-none absolute inset-0 opacity-30" aria-hidden="true" style="background-image: radial-gradient(circle at 14% 12%, rgba(34,211,238,.5), transparent 30%), radial-gradient(circle at 86% 70%, rgba(99,102,241,.35), transparent 28%);"></div>
            <div class="relative max-w-3xl">
                <p class="text-[11px] font-black uppercase tracking-[0.22em] text-cyan-300">Human-in-the-loop</p>
                <h1 class="mt-2 text-2xl font-black tracking-tight sm:text-3xl">Workflow-Aufgaben</h1>
                <p class="mt-2 max-w-2xl text-sm leading-6 text-slate-300">
                    Hier warten sicher pausierte Workflows auf eine menschliche Eingabe. CAPTCHA-Erkennung und Fortsetzung sind getrennt; gelöst wird ausschließlich durch einen Administrator.
                </p>
            </div>
            <div class="relative grid grid-cols-2 gap-3">
                <div class="min-w-28 rounded-2xl border border-white/10 bg-white/10 px-4 py-3 backdrop-blur">
                    <p class="text-[10px] font-bold uppercase tracking-wider text-slate-300">Offen</p>
                    <p class="mt-1 text-2xl font-black">{{ $openCount }}</p>
                </div>
                <div class="min-w-28 rounded-2xl border border-white/10 bg-white/10 px-4 py-3 backdrop-blur">
                    <p class="text-[10px] font-bold uppercase tracking-wider text-slate-300">Meine</p>
                    <p class="mt-1 text-2xl font-black">{{ $mineCount }}</p>
                </div>
            </div>
        </div>
    </section>

    @if($notice)
        <div @class([
            'rounded-2xl border px-4 py-3 text-sm font-semibold',
            'border-emerald-200 bg-emerald-50 text-emerald-800' => $noticeType === 'success',
            'border-cyan-200 bg-cyan-50 text-cyan-800' => $noticeType === 'info',
            'border-rose-200 bg-rose-50 text-rose-800' => $noticeType === 'error',
        ])>{{ $notice }}</div>
    @endif
    @error('assistance')
        <div class="rounded-2xl border border-rose-200 bg-rose-50 px-4 py-3 text-sm font-semibold text-rose-800">{{ $message }}</div>
    @enderror

    <div class="grid min-w-0 gap-5 xl:grid-cols-[23rem_minmax(0,1fr)]">
        <aside class="min-w-0 overflow-hidden rounded-[1.5rem] border border-slate-200 bg-white shadow-sm">
            <div class="border-b border-slate-200 p-4">
                <label for="workflow-assistance-search" class="sr-only">Aufgaben durchsuchen</label>
                <div class="relative">
                    <svg class="pointer-events-none absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-slate-400" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><circle cx="11" cy="11" r="7"></circle><path d="m20 20-3.5-3.5"></path></svg>
                    <input id="workflow-assistance-search" type="search" wire:model.live.debounce.350ms="search" placeholder="Workflow, URL, Aufgabe …" class="min-h-11 w-full rounded-xl border-slate-200 pl-10 pr-3 text-sm focus:border-cyan-500 focus:ring-cyan-500">
                </div>
                <div class="mt-3 grid grid-cols-3 gap-1 rounded-xl bg-slate-100 p-1" role="tablist" aria-label="Aufgabenfilter">
                    @foreach(['open' => 'Offen', 'mine' => 'Meine', 'history' => 'Verlauf'] as $filterValue => $filterLabel)
                        <button
                            type="button"
                            role="tab"
                            aria-selected="{{ $filter === $filterValue ? 'true' : 'false' }}"
                            wire:click="$set('filter', '{{ $filterValue }}')"
                            @class([
                            'min-h-11 rounded-lg px-2 text-xs font-bold transition',
                            'bg-white text-slate-950 shadow-sm' => $filter === $filterValue,
                            'text-slate-500 hover:text-slate-800' => $filter !== $filterValue,
                            ])
                        >{{ $filterLabel }}</button>
                    @endforeach
                </div>
            </div>

            <div class="max-h-[65vh] space-y-2 overflow-y-auto p-3 xl:max-h-[calc(100vh-18rem)]">
                @forelse($requests as $request)
                    @php
                        $active = $selected && (int) $selected->id === (int) $request->id;
                        $assignee = $request->assignedTo?->name;
                    @endphp
                    <button type="button" wire:click="selectRequest('{{ $request->request_uuid }}')" @class([
                        'w-full rounded-2xl border p-3.5 text-left transition focus:outline-none focus:ring-2 focus:ring-cyan-500 focus:ring-offset-2',
                        'border-cyan-300 bg-cyan-50/80 shadow-sm' => $active,
                        'border-slate-200 bg-white hover:border-slate-300 hover:bg-slate-50' => ! $active,
                    ])>
                        <div class="flex items-start justify-between gap-3">
                            <span class="inline-flex rounded-full border px-2 py-1 text-[10px] font-black uppercase tracking-wide {{ $statusClasses[$request->status] ?? $statusClasses['cancelled'] }}">
                                {{ $statusLabels[$request->status] ?? $request->status }}
                            </span>
                            <time class="shrink-0 text-[10px] font-semibold text-slate-400">{{ $request->requested_at?->diffForHumans(short: true) }}</time>
                        </div>
                        <p class="mt-2 line-clamp-2 text-sm font-extrabold leading-5 text-slate-950">{{ $request->title }}</p>
                        <p class="mt-1 truncate text-xs text-slate-500">{{ $request->workflow?->name ?: 'Workflow #'.$request->workflow_id }}</p>
                        <div class="mt-3 flex items-center gap-2 text-[11px] text-slate-500">
                            <span class="h-2 w-2 rounded-full {{ $assignee ? 'bg-cyan-500' : 'bg-amber-400' }}"></span>
                            <span class="truncate">{{ $assignee ?: 'Noch nicht übernommen' }}</span>
                        </div>
                    </button>
                @empty
                    <div class="rounded-2xl border border-dashed border-slate-300 px-4 py-10 text-center">
                        <p class="text-sm font-bold text-slate-700">Keine passenden Aufgaben</p>
                        <p class="mt-1 text-xs leading-5 text-slate-500">Sobald ein Workflow am reCAPTCHA pausiert, erscheint er hier.</p>
                    </div>
                @endforelse
            </div>
            @if($requests->hasPages())
                <div class="border-t border-slate-200 p-3">{{ $requests->onEachSide(1)->links() }}</div>
            @endif
        </aside>

        <main class="min-w-0">
            @if(! $selected)
                <div class="flex min-h-[28rem] items-center justify-center rounded-[1.5rem] border border-dashed border-slate-300 bg-white p-8 text-center">
                    <div class="max-w-sm">
                        <div class="mx-auto flex h-14 w-14 items-center justify-center rounded-2xl bg-slate-100 text-slate-500">
                            <svg class="h-6 w-6" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><path d="M9 3h6l1 3h3v15H5V6h3l1-3Z"></path><path d="M9 13h6M9 17h4"></path></svg>
                        </div>
                        <h2 class="mt-4 text-lg font-black text-slate-950">Aufgabe auswählen</h2>
                        <p class="mt-1 text-sm leading-6 text-slate-500">Links findest du alle offenen und abgeschlossenen Workflow-Hilfen.</p>
                    </div>
                </div>
            @else
                <div class="space-y-5">
                    <section class="overflow-hidden rounded-[1.5rem] border border-slate-200 bg-white shadow-sm">
                        <div class="flex flex-col gap-4 border-b border-slate-200 p-5 sm:flex-row sm:items-start sm:justify-between sm:p-6">
                            <div class="min-w-0">
                                <div class="flex flex-wrap items-center gap-2">
                                    <span class="inline-flex rounded-full border px-2.5 py-1 text-[10px] font-black uppercase tracking-wide {{ $statusClasses[$selected->status] ?? $statusClasses['cancelled'] }}">{{ $statusLabels[$selected->status] ?? $selected->status }}</span>
                                    <span class="font-mono text-[10px] font-bold text-slate-400">#{{ $selected->id }}</span>
                                </div>
                                <h2 class="mt-3 text-xl font-black tracking-tight text-slate-950 sm:text-2xl">{{ $selected->title }}</h2>
                                <p class="mt-1 text-sm text-slate-500">{{ $selected->workflowStep?->name ?: 'Workflow-Schritt' }} · Task {{ $selected->task_key }}</p>
                            </div>
                            <div class="flex flex-wrap gap-2 sm:justify-end">
                                @if($selectedIsOpen && ! $selected->assigned_to_user_id && ! $selectedExpired)
                                    <button type="button" wire:click="claim" class="inline-flex min-h-11 items-center justify-center rounded-xl bg-slate-950 px-4 text-sm font-extrabold text-white transition hover:bg-slate-800">Aufgabe übernehmen</button>
                                @elseif($selectedIsMine)
                                    <button type="button" wire:click="release" class="inline-flex min-h-11 items-center justify-center rounded-xl border border-slate-300 bg-white px-4 text-sm font-bold text-slate-700 transition hover:bg-slate-50">Freigeben</button>
                                @elseif($selected->assignedTo)
                                    <span class="inline-flex min-h-11 items-center rounded-xl border border-cyan-200 bg-cyan-50 px-4 text-sm font-bold text-cyan-800">{{ $selected->assignedTo->name }}</span>
                                @endif
                            </div>
                        </div>

                        <div class="grid gap-4 p-5 sm:grid-cols-3 sm:p-6">
                            <div class="rounded-2xl bg-slate-50 p-4">
                                <p class="text-[10px] font-black uppercase tracking-wide text-slate-400">Workflow-Lauf</p>
                                <p class="mt-1 text-sm font-extrabold text-slate-900">#{{ $selected->workflow_run_id }} · {{ $selected->workflowRun?->status }}</p>
                            </div>
                            <div class="rounded-2xl bg-slate-50 p-4">
                                <p class="text-[10px] font-black uppercase tracking-wide text-slate-400">Browserfenster</p>
                                <p class="mt-1 truncate text-sm font-extrabold text-slate-900">{{ $selected->browser_window }}</p>
                            </div>
                            <div class="rounded-2xl bg-slate-50 p-4" x-data="{ expiresAt: @js($selected->expires_at?->toIso8601String()), now: Date.now(), timer: null, init() { this.timer = setInterval(() => this.now = Date.now(), 1000) }, remaining() { if (!this.expiresAt) return '–'; const seconds = Math.max(0, Math.floor((new Date(this.expiresAt).getTime() - this.now) / 1000)); return `${Math.floor(seconds / 60)}:${String(seconds % 60).padStart(2, '0')}` } }">
                                <p class="text-[10px] font-black uppercase tracking-wide text-slate-400">Verbleibende Zeit</p>
                                <p class="mt-1 text-sm font-extrabold {{ $selectedExpired ? 'text-rose-700' : 'text-slate-900' }}" x-text="remaining()">–</p>
                            </div>
                        </div>
                    </section>

                    <section class="overflow-hidden rounded-[1.5rem] border border-slate-200 bg-slate-950 shadow-sm">
                        <div class="border-b border-white/10 px-4 py-4 sm:px-5">
                            <div class="flex flex-col gap-3 lg:flex-row lg:items-center lg:justify-between">
                                <div class="min-w-0">
                                    <div class="flex items-center gap-2">
                                        <span class="h-2.5 w-2.5 rounded-full {{ $browserBusy ? 'animate-pulse bg-amber-400' : 'bg-emerald-400' }}"></span>
                                        <h3 class="text-sm font-black text-white">Sicheres Browserbild</h3>
                                    </div>
                                    <p class="mt-1 truncate font-mono text-[11px] text-slate-400">{{ $snapshot['url'] ?? $selected->current_url ?: 'Keine URL verfügbar' }}</p>
                                </div>
                                <div class="flex flex-wrap gap-2">
                                    <button type="button" wire:click="refreshBrowser" @disabled(! $canInteract) class="inline-flex min-h-11 items-center justify-center rounded-xl border border-white/15 bg-white/10 px-3 text-xs font-bold text-white transition hover:bg-white/15 disabled:cursor-not-allowed disabled:opacity-40">Bild aktualisieren</button>
                                    <button type="button" wire:click="sendBrowserKey('Tab')" @disabled(! $canInteract) class="inline-flex min-h-11 min-w-11 items-center justify-center rounded-xl border border-white/15 bg-white/10 px-3 text-xs font-bold text-white transition hover:bg-white/15 disabled:opacity-40">Tab</button>
                                    <button type="button" wire:click="sendBrowserKey('Enter')" @disabled(! $canInteract) class="inline-flex min-h-11 min-w-11 items-center justify-center rounded-xl border border-white/15 bg-white/10 px-3 text-xs font-bold text-white transition hover:bg-white/15 disabled:opacity-40">Enter</button>
                                </div>
                            </div>
                        </div>

                        <div class="relative min-h-64 bg-slate-900 p-2 sm:p-4">
                            @if($previewAvailable)
                                <button
                                    type="button"
                                    @disabled(! $canInteract)
                                    x-data="{ busy: false, clickImage(event) { if (this.busy || @js(! $canInteract)) return; const box = event.currentTarget.getBoundingClientRect(); const x = (event.clientX - box.left) / box.width; const y = (event.clientY - box.top) / box.height; this.busy = true; $wire.clickBrowser(x, y, @js((string) ($snapshot['captured_at'] ?? ''))).finally(() => this.busy = false); } }"
                                    x-on:click="clickImage($event)"
                                    class="group relative mx-auto block max-w-full overflow-hidden rounded-xl border border-white/10 bg-black text-left shadow-2xl disabled:cursor-not-allowed"
                                    aria-label="Im Browserbild an dieser Position klicken"
                                >
                                    <img src="{{ route('network.workflow-assistance.preview', ['assistance' => $selected, 'v' => $snapshot['captured_at'] ?? now()->timestamp]) }}" alt="Aktuelles Browserbild des pausierten Workflow-Laufs" class="block h-auto max-h-[68vh] w-auto max-w-full select-none" draggable="false">
                                    @if($canInteract)
                                        <span class="pointer-events-none absolute bottom-3 left-1/2 -translate-x-1/2 rounded-full bg-slate-950/85 px-3 py-1.5 text-[10px] font-bold text-white opacity-0 backdrop-blur transition group-hover:opacity-100">Zum manuellen Klicken Bild berühren</span>
                                    @endif
                                </button>
                            @else
                                <div class="flex min-h-72 items-center justify-center rounded-xl border border-dashed border-white/15 p-8 text-center">
                                    <div class="max-w-sm">
                                        <p class="text-sm font-bold text-white">Noch kein Browserbild verfügbar</p>
                                        <p class="mt-2 text-xs leading-5 text-slate-400">Übernimm die Aufgabe und aktualisiere das Bild. Der interne Browser-Endpunkt bleibt dabei serverseitig verborgen.</p>
                                    </div>
                                </div>
                            @endif
                        </div>

                        <div class="border-t border-white/10 p-4 sm:p-5">
                            <form wire:submit="typeBrowserText" class="flex flex-col gap-2 sm:flex-row">
                                <label for="workflow-assistance-browser-text" class="sr-only">Text in das fokussierte Browserfeld eingeben</label>
                                <input id="workflow-assistance-browser-text" type="text" wire:model="browserText" maxlength="500" @disabled(! $canInteract) placeholder="Text in das aktuell fokussierte Browserfeld senden" class="min-h-11 min-w-0 flex-1 rounded-xl border-white/15 bg-white/10 text-sm text-white placeholder:text-slate-500 focus:border-cyan-400 focus:ring-cyan-400 disabled:opacity-40">
                                <button type="submit" @disabled(! $canInteract) class="inline-flex min-h-11 items-center justify-center rounded-xl bg-cyan-400 px-4 text-sm font-black text-slate-950 transition hover:bg-cyan-300 disabled:opacity-40">Text senden</button>
                            </form>
                            @error('browserText') <p class="mt-2 text-xs font-semibold text-rose-300">{{ $message }}</p> @enderror
                            <p class="mt-3 text-[11px] leading-5 text-slate-400">Jede Interaktion wird einzeln an denselben pausierten Browser gesendet. Automatisches Lösen, Token-Auslesen und CAPTCHA-Bypass sind ausgeschlossen.</p>
                        </div>
                    </section>

                    <section class="grid gap-5 lg:grid-cols-[minmax(0,1fr)_22rem]">
                        <div class="rounded-[1.5rem] border border-slate-200 bg-white p-5 shadow-sm sm:p-6">
                            <p class="text-[10px] font-black uppercase tracking-[0.18em] text-slate-400">Anweisung</p>
                            <p class="mt-2 text-sm leading-6 text-slate-700">{{ $selected->instructions }}</p>

                            <div @class([
                                'mt-5 rounded-2xl border p-4',
                                'border-emerald-200 bg-emerald-50' => ($verification['status'] ?? '') === 'passed',
                                'border-rose-200 bg-rose-50' => ($verification['status'] ?? '') === 'blocked',
                                'border-slate-200 bg-slate-50' => ! in_array(($verification['status'] ?? ''), ['passed', 'blocked'], true),
                            ])>
                                <div class="flex items-start gap-3">
                                    <span @class([
                                        'mt-1 h-2.5 w-2.5 shrink-0 rounded-full',
                                        'bg-emerald-500' => ($verification['status'] ?? '') === 'passed',
                                        'bg-rose-500' => ($verification['status'] ?? '') === 'blocked',
                                        'bg-slate-400' => ! in_array(($verification['status'] ?? ''), ['passed', 'blocked'], true),
                                    ])></span>
                                    <div>
                                        <p class="text-sm font-black text-slate-900">Erneute Sicherheitsprüfung</p>
                                        <p class="mt-1 text-xs leading-5 text-slate-600">{{ $verification['message'] ?? '' }}</p>
                                    </div>
                                </div>
                            </div>

                            @if($selectedIsOpen)
                                <div class="mt-4 grid gap-3 sm:grid-cols-2">
                                    <button type="button" wire:click="verifyCaptcha" @disabled(! $canInteract) class="inline-flex min-h-12 items-center justify-center rounded-xl border border-slate-300 bg-white px-4 text-sm font-extrabold text-slate-800 transition hover:bg-slate-50 disabled:cursor-not-allowed disabled:opacity-40">reCAPTCHA erneut prüfen</button>
                                    <button type="button" wire:click="resolveAndResume" @disabled(! $canInteract || ($verification['status'] ?? '') !== 'passed') class="inline-flex min-h-12 items-center justify-center rounded-xl bg-emerald-600 px-4 text-sm font-extrabold text-white transition hover:bg-emerald-500 disabled:cursor-not-allowed disabled:opacity-40">Geprüft & Workflow fortsetzen</button>
                                </div>
                                <label for="workflow-assistance-resolution-note" class="mt-4 block text-xs font-bold text-slate-600">Abschlussnotiz (optional)</label>
                                <textarea id="workflow-assistance-resolution-note" wire:model="resolutionNote" rows="2" maxlength="2000" @disabled(! $selectedIsMine) class="mt-1 min-h-20 w-full rounded-xl border-slate-200 text-sm focus:border-cyan-500 focus:ring-cyan-500 disabled:bg-slate-100" placeholder="Kurzer Hinweis zur manuellen Lösung"></textarea>
                                @error('resolutionNote') <p class="mt-1 text-xs font-semibold text-rose-600">{{ $message }}</p> @enderror
                            @endif
                        </div>

                        <aside class="rounded-[1.5rem] border border-slate-200 bg-white p-5 shadow-sm">
                            <div class="flex items-center justify-between gap-3">
                                <h3 class="text-sm font-black text-slate-950">Aktivitätsverlauf</h3>
                                <span class="rounded-full bg-slate-100 px-2 py-1 text-[10px] font-bold text-slate-500">{{ $selected->events->count() }}</span>
                            </div>
                            <ol class="mt-4 space-y-4">
                                @foreach($selected->events->sortByDesc('sequence')->take(12) as $event)
                                    <li class="relative pl-5">
                                        <span class="absolute left-0 top-1.5 h-2 w-2 rounded-full bg-cyan-500"></span>
                                        <p class="text-xs font-bold text-slate-800">{{ $event->message }}</p>
                                        <p class="mt-1 text-[10px] text-slate-400">{{ $event->actor?->name ?: 'System' }} · {{ $event->occurred_at?->format('d.m.Y H:i:s') }}</p>
                                    </li>
                                @endforeach
                            </ol>
                        </aside>
                    </section>
                </div>
            @endif
        </main>
    </div>
</div>
