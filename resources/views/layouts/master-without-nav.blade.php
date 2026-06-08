<!DOCTYPE html>
<html lang="de" dir="ltr">

<head>
    @include('layouts.metahead')
    <title>@yield('title') | Personen Factory</title>
    <!-- css -->
    @include('layouts.head-css')
    @livewireStyles
</head>

<body data-mode="light" data-sidebar-size="lg" class="group">
    
    @include('layouts.no-auth-layout')
    <!-- script -->
    @include('layouts.vendor-scripts')
    <!-- Scripts -->
    @vite(['resources/js/app.js'])
    @livewireScripts
</body>

</html>
