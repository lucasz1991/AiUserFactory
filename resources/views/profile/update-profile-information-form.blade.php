<x-form-section submit="updateProfileInformation">
    <x-slot name="title">
         {{ __('Profilinformationen') }}
    </x-slot>

    <x-slot name="description">
        {{ __('Aktualisiere die Profilinformationen und die E-Mail-Adresse deines Kontos.') }}
    </x-slot>

    <x-slot name="form">
        <!-- Profilfoto -->
        @if (Laravel\Jetstream\Jetstream::managesProfilePhotos())
            <div  x-data="{
                photoName: null,
                photoPreview: null,
                resizedPhoto: null,
                resizeImage(file) {
                    const reader = new FileReader();
                    reader.onload = (e) => {
                        const img = new Image();
                        img.onload = () => {
                            const canvas = document.createElement('canvas');
                            const maxSize = 200; // Maximale Breite oder Höhe in Pixel
                            let width = img.width;
                            let height = img.height;

                            if (width > height && width > maxSize) {
                                height *= maxSize / width;
                                width = maxSize;
                            } else if (height > width && height > maxSize) {
                                width *= maxSize / height;
                                height = maxSize;
                            } else if (width > maxSize) {
                                width = maxSize;
                                height = maxSize;
                            }

                            canvas.width = width;
                            canvas.height = height;

                            const ctx = canvas.getContext('2d');
                            ctx.drawImage(img, 0, 0, width, height);

                            // Das Bild als Base64-Daten speichern
                            this.resizedPhoto = canvas.toDataURL('image/jpeg', 0.8); // Qualität auf 80%
                            this.photoPreview = this.resizedPhoto;
                        };
                        img.src = e.target.result;
                    };
                    reader.readAsDataURL(file);
                }
            }" 
            class="col-span-6 sm:col-span-4">
                <!-- Profilfoto-Dateieingabe -->
                <input type="file" id="photo" class="hidden"
                            wire:model.live="photo"
                            x-ref="photo"
                            x-on:change="
                                    photoName = $refs.photo.files[0].name;
                                    const reader = new FileReader();
                                    reader.onload = (e) => {
                                        photoPreview = e.target.result;
                                    };
                                    reader.readAsDataURL($refs.photo.files[0]);
                            " />

                <x-label for="photo" value="{{ __('Foto') }}" />

                <!-- Aktuelles Profilfoto -->
                <div class="mt-2" x-show="! photoPreview">
                    <img src="{{ $this->user->profile_photo_url }}" alt="{{ $this->user->name }}" class="rounded-full h-20 w-20 object-cover">
                </div>

                <!-- Vorschau neues Profilfoto -->
                <div class="mt-2" x-show="photoPreview" style="display: none;">
                    <span class="block rounded-full w-20 h-20 bg-cover bg-no-repeat bg-center"
                          x-bind:style="'background-image: url(\'' + photoPreview + '\');'">
                    </span>
                </div>

                <x-secondary-button class="mt-2 me-2" type="button" x-on:click.prevent="$refs.photo.click()">
                    {{ __('Neues Foto auswählen') }}
                </x-secondary-button>

                @if ($this->user->profile_photo_path)
                    <x-secondary-button type="button" class="mt-2" wire:click="deleteProfilePhoto">
                        {{ __('Foto entfernen') }}
                    </x-secondary-button>
                @endif

                <x-input-error for="photo" class="mt-2" />
            </div>
        @endif

        <!-- Name -->
        <div class="col-span-6 sm:col-span-4">
            <x-label for="name" value="{{ __('Benutzername') }}" />
            <x-input id="name" type="text" class="mt-1 block w-full" wire:model="state.name" required autocomplete="name" />
            <x-input-error for="name" class="mt-2" />
        </div>

        <!-- E-Mail -->
        <div class="col-span-6 sm:col-span-4">
            <x-label for="email" value="{{ __('E-Mail') }}" />
            <x-input id="email" type="email" class="mt-1 block w-full" wire:model="state.email" required autocomplete="username" />
            <x-input-error for="email" class="mt-2" />

            @if (Laravel\Fortify\Features::enabled(Laravel\Fortify\Features::emailVerification()) && ! $this->user->hasVerifiedEmail())
                <p class="text-sm mt-2">
                    {{ __('Deine E-Mail-Adresse ist nicht verifiziert.') }}

                    <button type="button" class="underline text-sm text-gray-600 hover:text-gray-900 rounded-md focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-indigo-500" wire:click.prevent="sendEmailVerification">
                        {{ __('Klicke hier, um die Verifizierungs-E-Mail erneut zu senden.') }}
                    </button>
                </p>

                @if ($this->verificationLinkSent)
                    <p class="mt-2 font-medium text-sm text-green-600">
                        {{ __('Ein neuer Verifizierungslink wurde an deine E-Mail-Adresse gesendet.') }}
                    </p>
                @endif
            @endif
        </div>
    </x-slot>

    <x-slot name="actions">
        <x-action-message class="me-3" on="saved">
            {{ __('Gespeichert.') }}
        </x-action-message>

        <x-button wire:loading.attr="disabled" wire:target="photo">
            {{ __('Speichern') }}
        </x-button>
    </x-slot>
</x-form-section>
