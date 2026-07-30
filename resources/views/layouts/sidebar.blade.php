@persist('factory-ai-sidebar')
    <button
        type="button"
        class="ff-mobile-sidebar-backdrop z-20 print:hidden"
        data-ff-sidebar-backdrop
        aria-label="Navigation schliessen"
        tabindex="-1"
    ></button>

    <aside
        id="app-sidebar"
        class="vertical-menu ff-shell-sidebar z-30 print:hidden"
        aria-label="Hauptnavigation"
        data-ff-shell-sidebar
    >
        <div data-simplebar class="ff-sidebar-scroll">
            <nav class="ff-sidebar-nav" aria-label="Anwendungsbereiche">
                <ul id="side-menu">
                    <x-menu.sidebar-nav-link
                        :href="route('admin.index')"
                        icon="home"
                        :active="request()->routeIs('admin.index', 'admin.dashboard')"
                    >
                        Dashboard
                    </x-menu.sidebar-nav-link>

                    <x-menu.sidebar-nav label="User Factory">
                        <x-menu.sidebar-nav-link
                            :href="route('admin.settings')"
                            icon="settings"
                            :active="request()->routeIs('admin.settings')"
                        >
                            Einstellungen
                        </x-menu.sidebar-nav-link>

                        <x-menu.sidebar-nav-link
                            :href="route('processes.index')"
                            icon="activity"
                            :active="request()->routeIs('processes.*')"
                        >
                            Prozesse
                        </x-menu.sidebar-nav-link>
                    </x-menu.sidebar-nav>

                    <x-menu.sidebar-nav label="Automation">
                        <x-menu.sidebar-nav-group
                            label="Netzwerk"
                            icon="share-2"
                            :active="request()->routeIs('persons.*', 'network.*')"
                        >
                            <x-menu.sidebar-nav-link
                                :href="route('persons.index')"
                                :active="request()->routeIs('persons.*')"
                                nested
                            >
                                Personen
                            </x-menu.sidebar-nav-link>
                            <x-menu.sidebar-nav-link
                                :href="route('network.actions')"
                                :active="request()->routeIs('network.actions')"
                                nested
                            >
                                Aktionen
                            </x-menu.sidebar-nav-link>
                            <x-menu.sidebar-nav-link
                                :href="route('network.workflows')"
                                :active="request()->routeIs('network.workflows*')"
                                nested
                            >
                                Workflows
                            </x-menu.sidebar-nav-link>
                        </x-menu.sidebar-nav-group>

                        <x-menu.sidebar-nav-group
                            label="ClientController"
                            icon="cpu"
                            :active="request()->routeIs('client-controller.*')"
                        >
                            <x-menu.sidebar-nav-link
                                :href="route('client-controller.dashboard')"
                                :active="request()->routeIs('client-controller.dashboard')"
                                nested
                            >
                                Uebersicht
                            </x-menu.sidebar-nav-link>
                            <x-menu.sidebar-nav-link
                                :href="route('client-controller.nodes.index')"
                                :active="request()->routeIs('client-controller.nodes.*')"
                                nested
                            >
                                Nodes
                            </x-menu.sidebar-nav-link>
                            <x-menu.sidebar-nav-link
                                :href="route('client-controller.devices.index')"
                                :active="request()->routeIs('client-controller.devices.*')"
                                nested
                            >
                                Geraete
                            </x-menu.sidebar-nav-link>
                            <x-menu.sidebar-nav-link
                                :href="route('client-controller.targets.index')"
                                :active="request()->routeIs('client-controller.targets.*')"
                                nested
                            >
                                Targets
                            </x-menu.sidebar-nav-link>
                            <x-menu.sidebar-nav-link
                                :href="route('client-controller.jobs.index')"
                                :active="request()->routeIs('client-controller.jobs.*')"
                                nested
                            >
                                Jobs
                            </x-menu.sidebar-nav-link>
                        </x-menu.sidebar-nav-group>
                    </x-menu.sidebar-nav>
                </ul>
            </nav>

            <div class="ff-sidebar-footer">
                <span class="ff-sidebar-footer__pulse" aria-hidden="true"></span>
                <span class="ff-sidebar-footer__copy">
                    <strong>Factory Runtime</strong>
                    <small>Workflow-System bereit</small>
                </span>
            </div>
        </div>
    </aside>
@endpersist
