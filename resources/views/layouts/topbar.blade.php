<nav class="fixed top-0 left-0 right-0 z-10 flex items-center bg-white print:hidden">
    <div class="flex w-full justify-between">
        <div class="flex items-center topbar-brand">
            <div class="hidden lg:flex navbar-brand items-center justify-between shrink px-3 h-[70px] ltr:border-r rtl:border-l bg-[#fbfaff] border-gray-50 shadow-none">
                <a href="{{ route('admin.index') }}" class="flex items-center text-lg flex-shrink-0 font-bold leading-[69px]">
                    <x-navigation.application-icon class="inline-block w-10 aspect-square align-middle" />
                    <span class="hidden font-semibold text-gray-700 align-middle xl:block leading-[69px]">
                        Scraper Factory
                    </span>
                </a>
            </div>

            <button type="button" class="border-b border-gray-300 group-data-[sidebar-size=sm]:border-[#e9e9ef] text-gray-800 h-[70px] px-4 py-1 vertical-menu-btn text-16" id="vertical-menu-btn">
                <div class="z-50 text-gray-600 burger-container group-data-[sidebar-size=lg]:open">
                    <div class="burger-bar bar1"></div>
                    <div class="burger-bar bar2"></div>
                    <div class="burger-bar bar3"></div>
                </div>
            </button>
        </div>

        <div class="flex w-full items-center justify-end ltr:pl-6 rtl:pr-6 ltr:pr-6 rtl:pl-6 border-b border-gray-300">
            @auth
                <div class="ms-3 relative">
                    <x-dropdown align="" width="48">
                        <x-slot name="trigger">
                            <button class="flex items-center space-x-2 text-sm border-2 border-transparent rounded-full focus:outline-none focus:border-gray-300 transition">
                                <img class="h-8 w-8 rounded-full object-cover" src="{{ Auth::user()->profile_photo_url }}" alt="{{ Auth::user()->name }}" />
                                <span class="hidden font-medium xl:block">{{ Auth::user()->name }}</span>
                                <i class="hidden align-bottom mdi mdi-chevron-down xl:block"></i>
                            </button>
                        </x-slot>

                        <x-slot name="content">
                            <div class="block px-4 py-2 text-xs text-gray-400">
                                Konto verwalten
                            </div>

                            <x-dropdown-link href="{{ route('profile.show') }}">
                                Profil
                            </x-dropdown-link>

                            <div class="border-t border-gray-200"></div>

                            <form method="POST" action="{{ route('logout') }}" x-data>
                                @csrf
                                <x-dropdown-link href="{{ route('logout') }}" @click.prevent="$root.submit();">
                                    Abmelden
                                </x-dropdown-link>
                            </form>
                        </x-slot>
                    </x-dropdown>
                </div>
            @else
                <div class="flex items-center gap-2">
                    <a href="{{ route('login') }}" class="rounded-md border border-gray-300 px-3 py-2 text-sm font-semibold text-gray-700 hover:bg-gray-50">
                        Anmelden
                    </a>
                    <a href="{{ route('register') }}" class="rounded-md bg-blue-600 px-3 py-2 text-sm font-semibold text-white hover:bg-blue-700">
                        Registrieren
                    </a>
                </div>
            @endauth
        </div>
    </div>
</nav>
