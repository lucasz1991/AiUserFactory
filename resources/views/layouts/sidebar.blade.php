<!-- ========== Left Sidebar Start ========== -->
<div class="fixed bottom-0 z-10 h-screen ltr:border-r rtl:border-l vertical-menu rtl:right-0 ltr:left-0 top-[70px] pt-12 bg-slate-50 border-gray-50 print:hidden">
    <div data-simplebar class="h-full">
        <div class="metismenu pb-10 pt-2.5" id="sidebar-menu">
            <ul id="side-menu">
                <li>
                    <a href="{{ route('admin.index') }}" class="block py-2.5 px-6 text-sm font-medium text-gray-600 transition-all duration-150 ease-linear hover:text-blue-500">
                        <i data-feather="home" fill="#545a6d33"></i>
                        <span>Dashboard</span>
                    </a>
                </li>

                <li class="px-5 py-3 text-xs font-medium text-gray-500 cursor-default leading-[18px] group-data-[sidebar-size=sm]:hidden block">
                    Factory
                </li>

                <li>
                    <a href="{{ route('admin.settings') }}" class="block py-2.5 px-6 text-sm font-medium transition-all duration-150 ease-linear {{ request()->routeIs('admin.settings') ? 'text-blue-600' : 'text-gray-600 hover:text-blue-500' }}">
                        <i data-feather="settings" fill="#545a6d33"></i>
                        <span>Einstellungen</span>
                    </a>
                </li>

                <li>
                    <a href="javascript: void(0);" aria-expanded="{{ request()->routeIs('persons.*') || request()->routeIs('network.*') ? 'true' : 'false' }}" class="block py-2.5 px-6 text-sm font-medium transition-all duration-150 ease-linear nav-menu {{ request()->routeIs('persons.*') || request()->routeIs('network.*') ? 'text-blue-600' : 'text-gray-600 hover:text-blue-500' }}">
                        <i data-feather="share-2" fill="#545a6d33"></i>
                        <span>Netzwerk</span>
                    </a>
                    <ul>
                        <li>
                            <a href="{{ route('persons.index') }}" class="pl-[52.8px] pr-6 py-[6.4px] block text-[13.5px] font-medium transition-all duration-150 ease-linear {{ request()->routeIs('persons.*') ? 'text-blue-600' : 'text-gray-600 hover:text-blue-500' }}">
                                <span>Personen</span>
                            </a>
                        </li>
                        <li>
                            <a href="{{ route('network.actions') }}" class="pl-[52.8px] pr-6 py-[6.4px] block text-[13.5px] font-medium transition-all duration-150 ease-linear {{ request()->routeIs('network.actions') ? 'text-blue-600' : 'text-gray-600 hover:text-blue-500' }}">
                                <span>Aktionen</span>
                            </a>
                        </li>
                    </ul>
                </li>

                <li>
                    <a href="javascript: void(0);" aria-expanded="{{ request()->routeIs('client-controller.*') ? 'true' : 'false' }}" class="block py-2.5 px-6 text-sm font-medium transition-all duration-150 ease-linear nav-menu {{ request()->routeIs('client-controller.*') ? 'text-blue-600' : 'text-gray-600 hover:text-blue-500' }}">
                        <i data-feather="cpu" fill="#545a6d33"></i>
                        <span>ClientController</span>
                    </a>
                    <ul>
                        <li>
                            <a href="{{ route('client-controller.dashboard') }}" class="pl-[52.8px] pr-6 py-[6.4px] block text-[13.5px] font-medium transition-all duration-150 ease-linear {{ request()->routeIs('client-controller.dashboard') ? 'text-blue-600' : 'text-gray-600 hover:text-blue-500' }}">
                                <span>Übersicht</span>
                            </a>
                        </li>
                        <li>
                            <a href="{{ route('client-controller.nodes.index') }}" class="pl-[52.8px] pr-6 py-[6.4px] block text-[13.5px] font-medium transition-all duration-150 ease-linear {{ request()->routeIs('client-controller.nodes.*') ? 'text-blue-600' : 'text-gray-600 hover:text-blue-500' }}">
                                <span>Nodes</span>
                            </a>
                        </li>
                        <li>
                            <a href="{{ route('client-controller.devices.index') }}" class="pl-[52.8px] pr-6 py-[6.4px] block text-[13.5px] font-medium transition-all duration-150 ease-linear {{ request()->routeIs('client-controller.devices.*') ? 'text-blue-600' : 'text-gray-600 hover:text-blue-500' }}">
                                <span>Geräte</span>
                            </a>
                        </li>
                        <li>
                            <a href="{{ route('client-controller.targets.index') }}" class="pl-[52.8px] pr-6 py-[6.4px] block text-[13.5px] font-medium transition-all duration-150 ease-linear {{ request()->routeIs('client-controller.targets.*') ? 'text-blue-600' : 'text-gray-600 hover:text-blue-500' }}">
                                <span>Targets</span>
                            </a>
                        </li>
                        <li>
                            <a href="{{ route('client-controller.jobs.index') }}" class="pl-[52.8px] pr-6 py-[6.4px] block text-[13.5px] font-medium transition-all duration-150 ease-linear {{ request()->routeIs('client-controller.jobs.*') ? 'text-blue-600' : 'text-gray-600 hover:text-blue-500' }}">
                                <span>Jobs</span>
                            </a>
                        </li>
                    </ul>
                </li>
            </ul>
        </div>
    </div>
</div>
<!-- Left Sidebar End -->
