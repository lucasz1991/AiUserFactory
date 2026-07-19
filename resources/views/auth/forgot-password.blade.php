@extends('layouts.master-without-nav')
@section('title')
    Passwort vergessen
@endsection
@section('content')
    @php
        $input = 'block w-full rounded-lg border border-slate-300 bg-white px-3.5 py-2.5 text-sm text-slate-900 placeholder:text-slate-400 shadow-sm transition focus:border-primary-base focus:ring-2 focus:ring-primary-base/30 dark:border-zinc-600 dark:bg-zinc-800 dark:text-zinc-100 dark:placeholder:text-zinc-500';
    @endphp

    <div>
        <h1 class="text-2xl font-bold text-slate-800 dark:text-white">Passwort vergessen</h1>

        @if (session('status'))
            <div class="mt-5 rounded-lg border border-secondary-base/30 bg-secondary-base/10 px-4 py-3 text-sm font-medium text-secondary-base">
                {{ session('status') }}
            </div>
        @endif

        <form method="POST" action="{{ route('password.email') }}" class="mt-7 space-y-5">
            @csrf

            <div>
                <label for="email" class="mb-1.5 block text-sm font-medium text-slate-700 dark:text-zinc-200">E-Mail</label>
                <input id="email" type="email" name="email" value="{{ old('email') }}" required autofocus
                    placeholder="name@firma.de" class="{{ $input }}">
                @error('email')
                    <p class="mt-1.5 text-sm text-red-500">{{ $message }}</p>
                @enderror
            </div>

            <button type="submit"
                class="w-full rounded-lg bg-gradient-to-r from-primary-base to-[#3f74c0] px-4 py-2.5 text-center text-base font-semibold text-white shadow-lg shadow-primary-base/25 transition hover:brightness-[1.08] hover:shadow-primary-base/40 focus:outline-none focus:ring-2 focus:ring-primary-base/40 focus:ring-offset-2 active:scale-[0.99]">
                Reset-Link senden
            </button>
        </form>

        <p class="mt-8 text-center text-sm text-slate-500 dark:text-zinc-400">
            <a href="{{ route('login') }}" class="inline-flex items-center gap-1.5 font-semibold text-primary-base transition hover:text-secondary-base">
                <span class="mdi mdi-arrow-left"></span> Zurueck zum Login
            </a>
        </p>
    </div>
@endsection
