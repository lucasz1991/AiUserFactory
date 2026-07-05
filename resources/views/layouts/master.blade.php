<!DOCTYPE html>
<html lang="de" dir="ltr">

<head>
    @include('layouts.metahead')
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>@yield('title') | Personen Factory</title>
    <!-- css files -->
    @include('layouts.head-css')
    @vite(['resources/css/app.css'])
    <!-- Styles -->
    @livewireStyles
    @yield('css')
</head>
    <body data-mode="light" data-sidebar-size="lg" class="group font-notosans">
        <!-- sidebar -->
        @include('layouts.sidebar')
        <!-- topbar -->
        @include('layouts.topbar')
        <!-- content -->
        @yield('content')
        <!-- Page Content -->
        @if(isset($slot))
            <main class="bg-mainbg-base">
                <div class="main-content group-data-[sidebar-size=sm]:ml-[70px]">
                    <div class="min-h-screen page-content px-1" style="box-shadow: inset 0px 80px 30px -10px rgba(0, 0, 0, 0.2);">
                        <div class="container-fluid px-0 md:px-5">
                            @php
                                $routeName = Route::currentRouteName();
                                $bgWhiteRoutes = [
                                    'dashboard',
                                    'admin.index', 
                                    'admin.network.workflows', 
                                    'admin.network.workflow.edit', 
                                    'admin.network.workflow.create', 
                                    'admin.network.workflow.run', 
                                    'admin.network.workflow.run.edit'
                                ];
                                $isBackgroundedWhite = in_array($routeName, $bgWhiteRoutes);
                            @endphp
                            <div class=" @if($isBackgroundedWhite) bg-white rounded-md border border-gray-200 p-4  @endif ">
                                {{ $slot }}
                            </div>
                        </div>
                    </div>
                </div>
            </main>
        @endif
        @auth
            @if(request()->routeIs('network.workflows', 'network.workflows.manage'))
                @livewire('tools.chatbot')
            @endif
        @endauth
        <!-- script -->
        @include('layouts.vendor-scripts')
        <!-- Scripts -->
        @vite(['resources/js/app.js'])
        @livewireScripts
        @yield('js')
    </body>
</html>
 
