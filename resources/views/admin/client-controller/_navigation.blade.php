<nav class="flex flex-wrap gap-2 rounded-xl border border-slate-200 bg-white p-2 shadow-sm">
    @foreach([
        'client-controller.dashboard' => ['Übersicht', 'fa-grid-2'],
        'client-controller.nodes.index' => ['Nodes', 'fa-server'],
        'client-controller.devices.index' => ['Geräte', 'fa-mobile-screen'],
        'client-controller.jobs.index' => ['Jobs', 'fa-list-check'],
        'client-controller.targets.index' => ['Targets', 'fa-bullseye'],
    ] as $routeName => [$label, $icon])
        <a href="{{ route($routeName) }}" class="inline-flex items-center gap-2 rounded-lg px-4 py-2 text-sm font-semibold transition {{ request()->routeIs($routeName) ? 'bg-slate-900 text-white shadow' : 'text-slate-600 hover:bg-slate-100 hover:text-slate-900' }}">
            <i class="fa-solid {{ $icon }}"></i>
            {{ $label }}
        </a>
    @endforeach
    <a href="{{ route('admin.settings', ['tab' => 'client-controller']) }}" class="ml-auto inline-flex items-center gap-2 rounded-lg px-4 py-2 text-sm font-semibold text-slate-600 transition hover:bg-slate-100 hover:text-slate-900">
        <i class="fa-solid fa-gear"></i>
        Einstellungen
    </a>
</nav>
