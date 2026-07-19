<x-layout.action-section>
    <x-slot name="title">
        {{ __('Zwei-Faktor-Authentifizierung') }}
    </x-slot>

    <x-slot name="description">
        {{ __('Füge zusätzliche Sicherheit zu deinem Konto hinzu, indem du die Zwei-Faktor-Authentifizierung verwendest.') }}
    </x-slot>

    <x-slot name="content">
        <h3 class="text-lg font-medium text-gray-900">
            @if ($this->enabled)
                @if ($showingConfirmation)
                    {{ __('Beende das Aktivieren der Zwei-Faktor-Authentifizierung.') }}
                @else
                    {{ __('Du hast die Zwei-Faktor-Authentifizierung aktiviert.') }}
                @endif
            @else
                {{ __('Du hast die Zwei-Faktor-Authentifizierung nicht aktiviert.') }}
            @endif
        </h3>

        <div class="mt-3 max-w-xl text-sm text-gray-600">
            <p>
                {{ __('Wenn die Zwei-Faktor-Authentifizierung aktiviert ist, wirst du bei der Anmeldung nach einem sicheren, zufälligen Token gefragt. Du kannst dieses Token mit der Google Authenticator-App auf deinem Telefon abrufen.') }}
            </p>
        </div>

        @if ($this->enabled)
            @if ($showingQrCode)
                <div class="mt-4 max-w-xl text-sm text-gray-600">
                    <p class="font-semibold">
                        @if ($showingConfirmation)
                            {{ __('Um die Zwei-Faktor-Authentifizierung abzuschließen, scanne den folgenden QR-Code mit der Authenticator-App auf deinem Telefon oder gib den Setup-Schlüssel ein und sende den generierten OTP-Code.') }}
                        @else
                            {{ __('Die Zwei-Faktor-Authentifizierung ist jetzt aktiviert. Scanne den folgenden QR-Code mit der Authenticator-App auf deinem Telefon oder gib den Setup-Schlüssel ein.') }}
                        @endif
                    </p>
                </div>

                <div class="mt-4 p-2 inline-block bg-white">
                    {!! $this->user->twoFactorQrCodeSvg() !!}
                </div>

                <div class="mt-4 max-w-xl text-sm text-gray-600">
                    <p class="font-semibold">
                        {{ __('Setup-Schlüssel') }}: {{ decrypt($this->user->two_factor_secret) }}
                    </p>
                </div>

                @if ($showingConfirmation)
                    <div class="mt-4">
                        <x-forms.label for="code" value="{{ __('Code') }}" />

                        <x-forms.input id="code" type="text" name="code" class="block mt-1 w-1/2" inputmode="numeric" autofocus autocomplete="one-time-code"
                            wire:model="code"
                            wire:keydown.enter="confirmTwoFactorAuthentication" />

                        <x-forms.input-error for="code" class="mt-2" />
                    </div>
                @endif
            @endif

            @if ($showingRecoveryCodes)
                <div class="mt-4 max-w-xl text-sm text-gray-600">
                    <p class="font-semibold">
                        {{ __('Speichere diese Wiederherstellungscodes in einem sicheren Passwort-Manager. Sie können verwendet werden, um den Zugriff auf dein Konto wiederherzustellen, falls dein Zwei-Faktor-Authentifizierungsgerät verloren geht.') }}
                    </p>
                </div>

                <div class="grid gap-1 max-w-xl mt-4 px-4 py-4 font-mono text-sm bg-gray-100 rounded-lg">
                    @foreach (json_decode(decrypt($this->user->two_factor_recovery_codes), true) as $code)
                        <div>{{ $code }}</div>
                    @endforeach
                </div>
            @endif
        @endif

        <div class="mt-5">
            @if (! $this->enabled)
                <x-ui.confirms-password wire:then="enableTwoFactorAuthentication">
                    <x-ui.button type="button" wire:loading.attr="disabled">
                        {{ __('Aktivieren') }}
                    </x-ui.button>
                </x-ui.confirms-password>
            @else
                @if ($showingRecoveryCodes)
                    <x-ui.confirms-password wire:then="regenerateRecoveryCodes">
                        <x-ui.secondary-button class="me-3">
                            {{ __('Wiederherstellungscodes neu generieren') }}
                        </x-ui.secondary-button>
                    </x-ui.confirms-password>
                @elseif ($showingConfirmation)
                    <x-ui.confirms-password wire:then="confirmTwoFactorAuthentication">
                        <x-ui.button type="button" class="me-3" wire:loading.attr="disabled">
                            {{ __('Bestätigen') }}
                        </x-ui.button>
                    </x-ui.confirms-password>
                @else
                    <x-ui.confirms-password wire:then="showRecoveryCodes">
                        <x-ui.secondary-button class="me-3">
                            {{ __('Wiederherstellungscodes anzeigen') }}
                        </x-ui.secondary-button>
                    </x-ui.confirms-password>
                @endif

                @if ($showingConfirmation)
                    <x-ui.confirms-password wire:then="disableTwoFactorAuthentication">
                        <x-ui.secondary-button wire:loading.attr="disabled">
                            {{ __('Abbrechen') }}
                        </x-ui.secondary-button>
                    </x-ui.confirms-password>
                @else
                    <x-ui.confirms-password wire:then="disableTwoFactorAuthentication">
                        <x-ui.danger-button wire:loading.attr="disabled">
                            {{ __('Deaktivieren') }}
                        </x-ui.danger-button>
                    </x-ui.confirms-password>
                @endif
            @endif
        </div>
    </x-slot>
</x-layout.action-section>
