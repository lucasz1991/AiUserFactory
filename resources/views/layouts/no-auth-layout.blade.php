<div class="min-h-screen w-full bg-slate-50 dark:bg-zinc-900">
    <div class="grid min-h-screen grid-cols-1 lg:grid-cols-12">
        {{-- Formularseite --}}
        <div class="relative z-10 col-span-1 flex flex-col bg-white dark:bg-zinc-900 lg:col-span-5 xl:col-span-4">
            <div class="flex flex-1 flex-col px-6 py-8 sm:px-10 lg:px-12 xl:px-14">
                {{-- Logo --}}
                <div class="mb-10">
                    <a href="/" class="inline-flex items-center">
                        <x-application-logo class="h-11 w-auto max-w-[200px]" />
                    </a>
                </div>

                {{-- Inhalt, vertikal zentriert --}}
                <div class="flex flex-1 items-center">
                    <div class="mx-auto w-full max-w-[420px]">
                        @yield('content')
                    </div>
                </div>

                {{-- Fusszeile --}}
                <div class="mt-10 flex items-center justify-between text-xs text-slate-400 dark:text-zinc-500">
                    <p>&copy; <script>document.write(new Date().getFullYear())</script> Factory AI &middot; User Factory</p>
                    <p class="font-semibold uppercase tracking-wider">v0.1</p>
                </div>
            </div>
        </div>

        {{-- Markenseite (ab lg sichtbar) --}}
        <div class="relative col-span-1 hidden lg:col-span-7 lg:block xl:col-span-8">
            <x-auth-section-image-anim />
        </div>
    </div>
</div>
