<div class="container-fluid">
    <div class="h-screen md:overflow-hidden">
        <div class="grid grid-cols-1 md:grid-cols-12">
            <div class="col-span-12 md:col-span-5 lg:col-span-4 xl:col-span-3 relative z-50">
                <div class="w-full bg-white xl:p-12 p-10 dark:bg-zinc-800">
                    <div class="flex h-[90vh] flex-col">
                        <div class="mx-auto">
                            <a href="/" class="flex items-center text-lg flex-shrink-0 font-bold dark:text-white leading-[69px]">
                                <x-application-mark />
                            </a>
                        </div>

                        @yield('content')

                        <div class="text-center">
                            <p class="text-gray-500 dark:text-gray-100 relative select-none">
                                &copy; <script>document.write(new Date().getFullYear())</script>
                                Personen Factory<br>Made by Zacharias
                            </p>
                            <p class="text-gray-500 dark:text-gray-100 relative mb-5 select-none">
                                <small>v.0.1</small>
                            </p>
                        </div>
                    </div>
                </div>
            </div>
            <div class="col-span-12 md:col-span-7 lg:col-span-8 xl:col-span-9">
                <x-auth-section-image-anim />
            </div>
        </div>
    </div>
</div>
