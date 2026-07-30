@php
    $viewportMode = ($contentMode ?? null) === 'viewport';
    $documentTitle = trim($__env->yieldContent('title')) ?: 'Factory AI';
@endphp
<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" dir="ltr">
<head>
    {{-- Spur W (PWA + Web-Push): muss VOR layouts.metahead stehen. Browser
         werten ausschliesslich das erste <link rel="manifest"> aus, und
         metahead traegt ein zweites, das nicht installierbar ist. Beim
         App-Shell-Umbau bitte Zeile und Reihenfolge uebernehmen — sonst
         faellt Installation und Push still aus. --}}
    @include('layouts.pwa-head')
    @include('layouts.metahead')
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>{{ $documentTitle }} | Factory AI &middot; User Factory</title>
    @include('layouts.head-css')
    @vite(['resources/css/app.css', 'resources/css/app-shell.css'])
    @livewireStyles
    @yield('css')
    @stack('styles')
</head>
<body
    data-mode="light"
    data-sidebar-size="sm"
    data-sidebar-collapsible="true"
    data-sidebar-expanded="false"
    class="group ff-app-body"
>
    <a href="#main-content" class="ff-skip-link">
        Zum Inhalt springen
    </a>

    @include('layouts.sidebar')
    @include('layouts.topbar')

    <main
        id="main-content"
        tabindex="-1"
        data-app-main
        data-ff-shell-main
        @class([
            'ff-app-main',
            'ff-app-main--viewport' => $viewportMode,
        ])
    >
        <div class="main-content">
            <div class="page-content">
                <div class="ff-shell-ambient" aria-hidden="true">
                    <span class="ff-shell-ambient__grid"></span>
                    <span class="ff-shell-ambient__orb ff-shell-ambient__orb--one"></span>
                    <span class="ff-shell-ambient__orb ff-shell-ambient__orb--two"></span>
                    <span class="ff-shell-ambient__rail"></span>
                </div>

                <div @class([
                    'container-fluid ff-shell-container',
                    'ff-shell-container--viewport' => $viewportMode,
                ])>
                    @yield('content')
                    {{ $slot ?? '' }}
                </div>
            </div>
        </div>
    </main>

    @auth
        @if(request()->routeIs('network.workflows', 'network.workflows.manage', 'network.workflows.studio'))
            @livewire('tools.chatbot')
        @endif
    @endauth

    @include('layouts.vendor-scripts')
    @vite(['resources/js/app.js'])
    @livewireScripts
    @yield('js')
    @stack('scripts')
</body>
</html>
