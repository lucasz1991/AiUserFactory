@extends('layouts.master-without-nav')
@section('title')
    Passwort zuruecksetzen
@endsection
@section('content')
    @php
        $input = 'block w-full rounded-lg border border-slate-300 bg-white px-3.5 py-2.5 text-sm text-slate-900 placeholder:text-slate-400 shadow-sm transition focus:border-primary-base focus:ring-2 focus:ring-primary-base/30 dark:border-zinc-600 dark:bg-zinc-800 dark:text-zinc-100 dark:placeholder:text-zinc-500';
    @endphp

    <div>
        <span class="inline-flex items-center gap-2 text-xs font-semibold uppercase tracking-[0.18em] text-secondary-base">
            <span class="h-1.5 w-1.5 rounded-full bg-secondary-base"></span> Neues Passwort
        </span>
        <h1 class="mt-3 text-2xl font-bold text-slate-800 dark:text-white">Passwort neu vergeben</h1>
        <p class="mt-2 text-sm text-slate-500 dark:text-zinc-400">
            Waehle ein sicheres neues Passwort fuer dein Factory-AI-Konto.
        </p>

        @if (session('status'))
            <div class="mt-5 rounded-lg border border-secondary-base/30 bg-secondary-base/10 px-4 py-3 text-sm font-medium text-secondary-base">
                {{ session('status') }}
            </div>
        @endif

        <form method="POST" action="{{ route('password.update') }}" class="mt-7 space-y-5">
            @csrf
            <input type="hidden" name="token" value="{{ $request->route('token') }}">

            <div>
                <label for="email" class="mb-1.5 block text-sm font-medium text-slate-700 dark:text-zinc-200">E-Mail</label>
                <input id="email" type="email" name="email" :value="old('email', $request->email)" required autocomplete="username"
                    placeholder="name@firma.de" class="{{ $input }}">
                @error('email')
                    <p class="mt-1.5 text-sm text-red-500">{{ $message }}</p>
                @enderror
            </div>

            <div x-data="{ show: false }">
                <label for="password" class="mb-1.5 block text-sm font-medium text-slate-700 dark:text-zinc-200">Neues Passwort</label>
                <div class="relative">
                    <input id="password" name="password" required autocomplete="new-password"
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

            <div>
                <label for="password_confirmation" class="mb-1.5 block text-sm font-medium text-slate-700 dark:text-zinc-200">Passwort bestaetigen</label>
                <input id="password_confirmation" type="password" name="password_confirmation" required autocomplete="new-password"
                    placeholder="&bull;&bull;&bull;&bull;&bull;&bull;&bull;&bull;" class="{{ $input }}">
            </div>

            <button type="submit"
                class="w-full rounded-lg bg-gradient-to-r from-primary-base to-[#3f74c0] px-4 py-2.5 text-center text-base font-semibold text-white shadow-lg shadow-primary-base/25 transition hover:brightness-[1.08] hover:shadow-primary-base/40 focus:outline-none focus:ring-2 focus:ring-primary-base/40 focus:ring-offset-2 active:scale-[0.99]">
                Passwort speichern
            </button>
        </form>

        <p class="mt-8 text-center text-sm text-slate-500 dark:text-zinc-400">
            <a href="{{ route('login') }}" class="inline-flex items-center gap-1.5 font-semibold text-primary-base transition hover:text-secondary-base">
                <span class="mdi mdi-arrow-left"></span> Zurueck zum Login
            </a>
        </p>
    </div>
@endsection
