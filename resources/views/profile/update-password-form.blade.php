<x-layout.form-section submit="updatePassword">
    <x-slot name="title">
        {{ __('Passwort aktualisieren') }}
    </x-slot>

    <x-slot name="description">
        {{ __('Stelle sicher, dass dein Konto ein langes, zufälliges Passwort verwendet, um sicher zu bleiben.') }}
    </x-slot>

    <x-slot name="form">
        <div class="col-span-6 sm:col-span-4">
            <x-forms.label for="current_password" value="{{ __('Aktuelles Passwort') }}" />
            <x-forms.input id="current_password" type="password" class="mt-1 block w-full" wire:model="state.current_password" autocomplete="current-password" />
            <x-forms.input-error for="current_password" class="mt-2" />
        </div>

        <div class="col-span-6 sm:col-span-4">
            <x-forms.label for="password" value="{{ __('Neues Passwort') }}" />
            <x-forms.input id="password" type="password" class="mt-1 block w-full" wire:model="state.password" autocomplete="new-password" />
            <x-forms.input-error for="password" class="mt-2" />
        </div>

        <div class="col-span-6 sm:col-span-4">
            <x-forms.label for="password_confirmation" value="{{ __('Passwort bestätigen') }}" />
            <x-forms.input id="password_confirmation" type="password" class="mt-1 block w-full" wire:model="state.password_confirmation" autocomplete="new-password" />
            <x-forms.input-error for="password_confirmation" class="mt-2" />
        </div>
    </x-slot>

    <x-slot name="actions">
        <x-feedback.action-message class="me-3" on="saved">
            {{ __('Gespeichert.') }}
        </x-feedback.action-message>

        <x-ui.button>
            {{ __('Speichern') }}
        </x-ui.button>
    </x-slot>
</x-layout.form-section>
