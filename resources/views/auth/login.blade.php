@extends('layouts.master-without-nav')
@section('title')
    Login
@endsection
@section('content')
    @php
        $input = 'block w-full rounded-lg border border-slate-300 bg-white px-3.5 py-2.5 text-sm text-slate-900 placeholder:text-slate-400 shadow-sm transition focus:border-primary-base focus:ring-2 focus:ring-primary-base/30 dark:border-zinc-600 dark:bg-zinc-800 dark:text-zinc-100 dark:placeholder:text-zinc-500';
    @endphp

    <div>
        <h1 class="text-2xl font-bold text-slate-800 dark:text-white">Anmelden</h1>

        @if (session('status'))
            <div class="mt-5 rounded-lg border border-secondary-base/30 bg-secondary-base/10 px-4 py-3 text-sm font-medium text-secondary-base">
                {{ session('status') }}
            </div>
        @endif

        <form method="POST" action="{{ route('login') }}" class="mt-7 space-y-5">
            @csrf

            <div>
                <label for="email" class="mb-1.5 block text-sm font-medium text-slate-700 dark:text-zinc-200">E-Mail</label>
                <input id="email" type="email" name="email" value="{{ old('email') }}" required autofocus autocomplete="username"
                    placeholder="name@firma.de" class="{{ $input }}">
                @error('email')
                    <p class="mt-1.5 text-sm text-red-500">{{ $message }}</p>
                @enderror
            </div>

            <div x-data="{ show: false }">
                <div class="mb-1.5 flex items-center justify-between">
                    <label for="password" class="block text-sm font-medium text-slate-700 dark:text-zinc-200">Passwort</label>
                    @if (Route::has('password.request'))
                        <a href="{{ route('password.request') }}" class="text-sm font-medium text-primary-base transition hover:text-secondary-base">
                            Passwort vergessen?
                        </a>
                    @endif
                </div>
                <div class="relative">
                    <input id="password" name="password" required autocomplete="current-password"
                        :type="show ? 'text' : 'password'" placeholder="&bull;&bull;&bull;&bull;&bull;&bull;&bull;&bull;"
                        class="{{ $input }} pr-11">
                    <button type="button" @click="show = !show" tabindex="-1" aria-label="Passwort anzeigen"
                        class="absolute inset-y-0 right-0 flex items-center pr-3 text-slate-400 transition hover:text-slate-600 dark:hover:text-zinc-200">
                        <span class="mdi mdi-eye-outline text-lg" x-show="!show"></span>
                        <span class="mdi mdi-eye-off-outline text-lg" x-show="show" style="display:none"></span>
                    </button>
                </div>
                @error('password')
                    <p class="mt-1.5 text-sm text-red-500">{{ $message }}</p>
                @enderror
            </div>

            <label class="flex cursor-pointer select-none items-center gap-2.5 text-sm text-slate-600 dark:text-zinc-300">
                <input type="checkbox" name="remember" id="remember" checked
                    class="h-4 w-4 rounded border-slate-300 text-primary-base focus:ring-primary-base/40">
                Angemeldet bleiben
            </label>

            <button type="submit"
                class="w-full rounded-lg bg-gradient-to-r from-primary-base to-[#3f74c0] px-4 py-2.5 text-center text-base font-semibold text-white shadow-lg shadow-primary-base/25 transition hover:brightness-[1.08] hover:shadow-primary-base/40 focus:outline-none focus:ring-2 focus:ring-primary-base/40 focus:ring-offset-2 active:scale-[0.99]">
                Einloggen
            </button>
        </form>
    </div>
@endsection
