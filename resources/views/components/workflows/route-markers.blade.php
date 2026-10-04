@props(['instance'])

<defs>
    @foreach([
        'neutral' => '#94a3b8',
        'success' => '#10b981',
        'failed' => '#fb7185',
        'partial' => '#3b82f6',
        'timeout' => '#8b5cf6',
        'runtime' => '#0ea5e9',
        'default' => '#3b82f6',
    ] as $markerName => $markerColor)
        <marker id="{{ $instance }}-arrow-{{ $markerName }}" viewBox="0 0 8 8" refX="7" refY="4"
            markerWidth="8" markerHeight="8" markerUnits="userSpaceOnUse" orient="auto">
            <path d="M 0 0 L 8 4 L 0 8 z" fill="{{ $markerColor }}"></path>
        </marker>
    @endforeach
</defs>
