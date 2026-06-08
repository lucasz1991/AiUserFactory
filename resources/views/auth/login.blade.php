@extends('layouts.master-without-nav')
@section('title')
    Login
@endsection
@section('css')
    <link rel="stylesheet" href="{{ URL::asset('build/libs/swiper/swiper-bundle.min.css') }}">
@endsection
@section('content')
    <div class="my-auto">
        <div class="text-center">
            <h5 class="text-gray-600 dark:text-gray-100">Willkommen in der Personen Factory</h5>
            <p class="text-gray-500 dark:text-gray-100/60 mt-1">
                Melde dich an, um Scraper-Personas, Browser-Sessions und Bot-Vorbereitungen zu verwalten.
            </p>
        </div>

        @if (session('status'))
            <div class="">
                {{ session('status') }}
            </div>
        @endif
        <form method="POST" action="{{ route('login') }}" class="mt-4 pt-2">
            @csrf
            <div class="mb-4">
                <label for="email"
                    class="text-gray-600 dark:text-gray-100 font-medium mb-2 block">Email <span class="text-red-600">*</span></label>
                <input type="email" name="email" value=""
                    class="w-full rounded placeholder:text-sm py-2 px-1 border border-gray-300 dark:bg-zinc-700/50 dark:border-zinc-600 dark:text-gray-100 dark:placeholder:text-zinc-100/60"
                    id="email" placeholder="Email eingeben" required>
                @error('email')
                    <span class="text-sm text-red-600">{{ $message }}</span>
                @enderror
            </div>
            <div class="mb-3">
                <div class="flex">
                    <div class="flex-grow-1">
                        <label for="password"
                            class="text-gray-600 dark:text-gray-100 font-medium mb-2 block">Passwort <span class="text-red-600">*</span></label>
                    </div>
                    @if (Route::has('password.request'))
                        <div class="ltr:ml-auto rtl:mr-auto">
                            <a href="{{ route('password.request') }}"
                                class="text-gray-500 dark:text-gray-100">Passwort
                                vergessen?</a>
                        </div>
                    @endif
                </div>
                <div class="flex">
                    <input type="password" name="password" id="password" value=""
                        class="w-full rounded ltr:rounded-r-none rtl:rounded-l-none placeholder:text-sm py-2 px-1 border border-gray-300 dark:bg-zinc-700/50 dark:border-zinc-600 dark:text-gray-100 dark:placeholder:text-zinc-100/60"
                        placeholder="Passwort eingeben " aria-label="Password"
                        aria-describedby="password-addon" required>
                    @error('password')
                        <span class="text-sm text-red-600">{{ $message }}</span>
                    @enderror
                </div>
            </div>
            <div class="row mb-6">
                <div class="col">
                    <div>
                        <input type="checkbox" name="remember" id="remember"
                            class="h-4 w-4 border border-gray-300 rounded bg-white checked:bg-blue-600 checked:border-blue-600 focus:outline-none transition duration-200 mt-1 align-top bg-no-repeat bg-center bg-contain ltr:float-left rtl:float-right ltr:mr-2 rtl:ml-2 cursor-pointer focus:ring-offset-0"
                            checked id="exampleCheck1">
                        <label class="align-middle text-gray-600 dark:text-gray-100 font-medium" for="remember">
                            Angemeldet bleiben
                        </label>
                    </div>
                </div>
            </div>
            <div class="mb-3">
                <x-button
                    class="btn border-transparent bg-blue-200 w-full py-2.5 text-blue-500 text-lg w-100 waves-effect waves-light shadow-md shadow-gray-200 dark:shadow-zinc-600"
                    type="submit">Einloggen</x-button>
            </div>
        </form>
    </div>
@endsection
