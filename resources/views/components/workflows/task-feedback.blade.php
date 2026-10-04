@props(['node', 'feedback' => [], 'routes' => [], 'compact' => false])

@if($feedback['failed'] ?? false)
    <p class="ff-task-feedback ff-task-feedback--error" role="status" data-task-error>
        <strong>{{ in_array($feedback['status'], ['timed_out', 'timeout'], true) ? 'Zeitüberschreitung' : 'Task fehlgeschlagen' }}</strong>
        @if($feedback['message'] ?? '')<span>{{ $feedback['message'] }}</span>@endif
    </p>
@endif
@if(count($routes))
    {{-- Native disclosure stays put while tasks are selected. Preserve its open
         state across polling without preventing route-message updates. --}}
    <details wire:ignore.self class="ff-task-routes" data-task-routes x-on:click.stop x-on:dblclick.stop aria-label="Ausgehende Routen dieses Tasks" @if($compact) x-cloak x-show.important="zoomLevel !== 'overview'" @endif>
        <summary class="ff-task-route-toggle">Routen <span>{{ count($routes) }}</span></summary>
        <div class="ff-task-route-list">
        @foreach($routes as $route)
            @php
                $outcome = $route['outcome'] ?? 'default';
                $tone = match ($outcome) { 'success' => 'success', 'failed', 'error' => 'error', 'timeout' => 'timeout', default => 'other' };
                $label = match ($outcome) { 'success' => 'Erfolg', 'failed', 'error' => 'Fehler', 'timeout' => 'Timeout', 'partial' => 'Teilergebnis', default => 'Weiter' };
            @endphp
            <p class="ff-task-route ff-task-route--{{ $tone }}">
                <span class="ff-task-route__dot" aria-hidden="true"></span>
                <span><strong>{{ $label }}</strong> → {{ $route['targetLabel'] ?? $route['routeLabel'] ?? $route['label'] ?? $route['target'] ?? 'Nächster Task' }}</span>
            </p>
        @endforeach
        </div>
    </details>
@endif
