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
                    <a href="{{ route('persons.index') }}" class="block py-2.5 px-6 text-sm font-medium text-gray-600 transition-all duration-150 ease-linear hover:text-blue-500">
                        <i data-feather="user-check" fill="#545a6d33"></i>
                        <span>Personen</span>
                    </a>
                </li>
                <li>
                    <a href="{{ route('admin.settings') }}" class="block py-2.5 px-6 text-sm font-medium text-gray-600 transition-all duration-150 ease-linear hover:text-blue-500">
                        <i data-feather="settings" fill="#545a6d33"></i>
                        <span>Einstellungen</span>
                    </a>
                </li>
            </ul>
        </div>
    </div>
</div>
<!-- Left Sidebar End -->
