@extends('layouts.master-without-nav')

@section('title')
    Register
@endsection

@section('content')
    <div class="my-auto">
        <div class="text-center">
            <h5 class="text-gray-600 dark:text-gray-100">Factory-Account registrieren</h5>
        </div>

        <form method="POST" action="{{ route('register') }}" class="mt-4 pt-2">
            @csrf

            <div class="mb-4">
                <label for="name" class="text-gray-600 font-medium mb-2 block dark:text-gray-100">
                    Name <span class="text-red-600">*</span>
                </label>
                <input
                    id="name"
                    type="text"
                    name="name"
                    value="{{ old('name') }}"
                    class="w-full border-gray-100 rounded placeholder:text-sm py-2 px-1 dark:bg-zinc-700/50 dark:border-zinc-600 dark:text-gray-100 dark:placeholder:text-zinc-100/60"
                    placeholder="Name eingeben"
                    required
                >
                @error('name')
                    <span class="text-sm text-red-600">{{ $message }}</span>
                @enderror
            </div>

            <div class="mb-4">
                <label for="email" class="text-gray-600 font-medium mb-2 block dark:text-gray-100">
                    Email <span class="text-red-600">*</span>
                </label>
                <input
                    id="email"
                    type="email"
                    name="email"
                    value="{{ old('email') }}"
                    class="w-full border-gray-100 rounded placeholder:text-sm py-2 px-1 dark:bg-zinc-700/50 dark:border-zinc-600 dark:text-gray-100 dark:placeholder:text-zinc-100/60"
                    placeholder="Email eingeben"
                    required
                >
                @error('email')
                    <span class="text-sm text-red-600">{{ $message }}</span>
                @enderror
            </div>

            <div class="mb-3">
                <label for="password" class="text-gray-600 font-medium mb-2 block dark:text-gray-100">
                    Passwort <span class="text-red-600">*</span>
                </label>
                <input
                    type="password"
                    id="password"
                    name="password"
                    class="w-full border-gray-100 rounded placeholder:text-sm py-2 px-1 dark:bg-zinc-700/50 dark:border-zinc-600 dark:text-gray-100 dark:placeholder:text-zinc-100/60"
                    placeholder="Passwort eingeben"
                    required
                >
                @error('password')
                    <span class="text-sm text-red-600">{{ $message }}</span>
                @enderror
            </div>

            <div class="mb-3">
                <label for="password_confirmation" class="text-gray-600 font-medium mb-2 block dark:text-gray-100">
                    Passwort bestaetigen <span class="text-red-600">*</span>
                </label>
                <input
                    type="password"
                    id="password_confirmation"
                    name="password_confirmation"
                    class="w-full border-gray-100 rounded placeholder:text-sm py-2 px-1 dark:bg-zinc-700/50 dark:border-zinc-600 dark:text-gray-100 dark:placeholder:text-zinc-100/60"
                    placeholder="Passwort bestaetigen"
                    required
                >
            </div>

            @if (Laravel\Jetstream\Jetstream::hasTermsAndPrivacyPolicyFeature())
                <div class="mb-6">
                    <x-label for="terms">
                        <div class="flex items-center">
                            <x-checkbox name="terms" id="terms" required />
                            <div class="ml-2">
                                {!! __('I agree to the :terms_of_service and :privacy_policy', [
                                    'terms_of_service' => '<a target="_blank" href="'.route('terms.show').'" class="underline text-sm text-gray-600 hover:text-gray-900 rounded-md">'.__('Terms of Service').'</a>',
                                    'privacy_policy' => '<a target="_blank" href="'.route('policy.show').'" class="underline text-sm text-gray-600 hover:text-gray-900 rounded-md">'.__('Privacy Policy').'</a>',
                                ]) !!}
                            </div>
                        </div>
                    </x-label>
                </div>
            @endif

            <div class="mb-3">
                <button class="btn border-transparent bg-blue-50 w-full py-2.5 text-blue-200 text-lg waves-effect waves-light shadow-md shadow-gray-200 dark:shadow-zinc-600" type="submit">
                    Registrieren
                </button>
            </div>
        </form>

        <div class="mt-12 text-center">
            <p class="text-gray-500 dark:text-zinc-100/60">
                Du hast schon einen Account?
                <a href="{{ route('login') }}" class="text-blue-200 font-semibold">Einloggen</a>
            </p>
        </div>
    </div>
@endsection
