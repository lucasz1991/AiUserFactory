// wire:confirm ruft in Livewire das native window.confirm() auf. Eingebettete
// Browser (z. B. App-Vorschaufenster) unterdruecken native Dialoge; dann liefert
// confirm() sofort false und die Aktion - etwa "Stoppen" - bricht kommentarlos
// ab. Livewire legt die Bestaetigung als el.__livewire_confirm(action, instead)
// ab; dieser Setter ersetzt sie durch einen In-App-Dialog. wire:confirm.prompt
// und Seiten ohne SweetAlert behalten das native Verhalten.

const CONFIRM_ATTRIBUTE = /^wire:confirm(\.|$)/;

function confirmAttribute(el) {
    return [...(el.attributes || [])].find((attribute) => CONFIRM_ATTRIBUTE.test(attribute.name)) || null;
}

export function confirmWithDialog(message, action, instead) {
    window.Swal.fire({
        text: message,
        icon: 'warning',
        showCancelButton: true,
        confirmButtonText: 'Bestätigen',
        cancelButtonText: 'Abbrechen',
        reverseButtons: true,
        focusCancel: true,
    }).then((result) => (result.isConfirmed ? action() : instead()));
}

export function installLivewireConfirmDialog() {
    if (Object.getOwnPropertyDescriptor(HTMLElement.prototype, '__livewire_confirm')) {
        return;
    }

    Object.defineProperty(HTMLElement.prototype, '__livewire_confirm', {
        configurable: true,
        get() {
            return this.__ffLivewireConfirm;
        },
        set(original) {
            const attribute = confirmAttribute(this);

            if (!attribute || attribute.name.includes('.prompt') || typeof window.Swal?.fire !== 'function') {
                this.__ffLivewireConfirm = original;

                return;
            }

            const message = (attribute.value || 'Bist du sicher?').replaceAll('\\n', '\n');
            this.__ffLivewireConfirm = (action, instead) => confirmWithDialog(message, action, instead);
        },
    });
}
