@php
    $pageContext = match (true) {
        request()->routeIs('network.workflows*') => 'Workflow Management',
        request()->routeIs('client-controller.*') => 'ClientController',
        request()->routeIs('persons.*', 'network.actions') => 'Netzwerk',
        request()->routeIs('processes.*') => 'Prozesse',
        request()->routeIs('admin.settings') => 'Einstellungen',
        default => 'Uebersicht',
    };
@endphp

<nav
    class="ff-shell-topbar z-40 print:hidden"
    data-app-topbar
    data-ff-shell-topbar
    aria-label="Kopfnavigation"
>
    <div class="ff-topbar-brand topbar-brand">
        <a
            href="{{ route('admin.index') }}"
            class="ff-topbar-brand__link"
            wire:navigate
            aria-label="Factory AI Dashboard"
        >
            <x-navigation.application-icon class="ff-topbar-brand__mark" />
            <span class="ff-topbar-brand__copy">
                <strong>Factory AI</strong>
                <small>User Factory</small>
            </span>
        </a>

        <button
            type="button"
            class="vertical-menu-btn ff-mobile-menu-button"
            id="vertical-menu-btn"
            aria-label="Hauptnavigation oeffnen"
            aria-controls="app-sidebar"
            aria-expanded="false"
        >
            <span class="ff-menu-icon" aria-hidden="true">
                <span></span>
                <span></span>
                <span></span>
            </span>
        </button>
    </div>

    <div class="ff-topbar-content">
        <div class="ff-topbar-context" aria-current="page">
            <span class="ff-topbar-context__dot" aria-hidden="true"></span>
            <span>{{ $pageContext }}</span>
        </div>

        <div class="ff-topbar-actions">
            <div class="ff-system-clock">
                <livewire:system-clock />
            </div>

            @auth
                <a
                    href="{{ route('admin.settings') }}"
                    class="ff-topbar-control"
                    wire:navigate
                    aria-label="Einstellungen"
                    title="Einstellungen"
                    data-ff-topbar-control
                >
                    <i data-feather="settings" aria-hidden="true"></i>
                </a>

                <div class="ff-profile-menu">
                    <x-ui.dropdown align="right" width="48" content-classes="py-1 bg-white" dropdown-classes="ff-profile-dropdown">
                        <x-slot name="trigger">
                            <button
                                type="button"
                                class="ff-profile-trigger"
                                aria-label="Benutzermenue fuer {{ Auth::user()->name }}"
                                aria-haspopup="menu"
                                x-bind:aria-expanded="open.toString()"
                            >
                                <img
                                    class="ff-profile-trigger__avatar"
                                    src="{{ Auth::user()->profile_photo_url }}"
                                    alt=""
                                />
                                <span class="ff-profile-trigger__copy">
                                    <strong>{{ Auth::user()->name }}</strong>
                                    <small>Konto</small>
                                </span>
                                <i data-feather="chevron-down" class="ff-profile-trigger__chevron" aria-hidden="true"></i>
                            </button>
                        </x-slot>

                        <x-slot name="content">
                            <div class="ff-profile-dropdown__heading">
                                Konto verwalten
                            </div>

                            <x-ui.dropdown-link href="{{ route('profile.show') }}">
                                Profil
                            </x-ui.dropdown-link>

                            <div class="ff-profile-dropdown__divider"></div>

                            <form method="POST" action="{{ route('logout') }}" x-data>
                                @csrf
                                <x-ui.dropdown-link href="{{ route('logout') }}" @click.prevent="$root.submit();">
                                    Abmelden
                                </x-ui.dropdown-link>
                            </form>
                        </x-slot>
                    </x-ui.dropdown>
                </div>
            @else
                <a href="{{ route('login') }}" class="ff-login-link">
                    Anmelden
                </a>
            @endauth
        </div>
    </div>
</nav>
