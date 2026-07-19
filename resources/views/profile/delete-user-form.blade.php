<x-layout.action-section>
    <x-slot name="title">
        {{ __('Delete Account') }}
    </x-slot>

    <x-slot name="description">
        {{ __('Permanently delete your account.') }}
    </x-slot>

    <x-slot name="content">
        <div class="max-w-xl text-sm text-gray-600">
            {{ __('Once your account is deleted, all of its resources and data will be permanently deleted. Before deleting your account, please download any data or information that you wish to retain.') }}
        </div>

        <div class="mt-5">
            <x-ui.danger-button wire:click="confirmUserDeletion" wire:loading.attr="disabled">
                {{ __('Delete Account') }}
            </x-ui.danger-button>
        </div>

        <!-- Delete User Confirmation Modal -->
        <x-ui.dialog-modal wire:model.live="confirmingUserDeletion">
            <x-slot name="title">
                {{ __('Delete Account') }}
            </x-slot>

            <x-slot name="content">
                {{ __('Are you sure you want to delete your account? Once your account is deleted, all of its resources and data will be permanently deleted. Please enter your password to confirm you would like to permanently delete your account.') }}

                <div class="mt-4" x-data="{}" x-on:confirming-delete-user.window="setTimeout(() => $refs.password.focus(), 250)">
                    <x-forms.input type="password" class="mt-1 block w-3/4"
                                autocomplete="current-password"
                                placeholder="{{ __('Password') }}"
                                x-ref="password"
                                wire:model="password"
                                wire:keydown.enter="deleteUser" />

                    <x-forms.input-error for="password" class="mt-2" />
                </div>
            </x-slot>

            <x-slot name="footer">
                <x-ui.secondary-button wire:click="$toggle('confirmingUserDeletion')" wire:loading.attr="disabled">
                    {{ __('Cancel') }}
                </x-ui.secondary-button>

                <x-ui.danger-button class="ms-3" wire:click="deleteUser" wire:loading.attr="disabled">
                    {{ __('Delete Account') }}
                </x-ui.danger-button>
            </x-slot>
        </x-ui.dialog-modal>
    </x-slot>
</x-layout.action-section>
