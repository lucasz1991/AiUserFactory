@props(['id', 'value' => '', 'stored' => false])

<div class="ff-account-password" data-account-password x-data="{ concealed: false }" wire:key="{{ $id }}">
    <label for="{{ $id }}" class="ff-account-password__label">Passwort</label>
    @if($value !== '')
        <div class="ff-account-password__control">
            <input id="{{ $id }}" type="text" :type="concealed ? 'password' : 'text'"
                value="{{ $value }}" readonly autocomplete="off" spellcheck="false"
                autocapitalize="off" aria-describedby="{{ $id }}-hint" data-readable-password>
            <button type="button" @click="concealed = !concealed" :aria-pressed="concealed.toString()"
                aria-controls="{{ $id }}" x-text="concealed ? 'Anzeigen' : 'Ausblenden'">Ausblenden</button>
        </div>
        <p id="{{ $id }}-hint" class="ff-account-password__hint">Zum Kopieren den Text markieren. Sichtbar für Administratoren.</p>
    @else
        <p class="ff-account-password__hint">{{ $stored ? 'Gespeichert, aber hier nicht entschlüsselbar. Zugangsdaten bitte neu hinterlegen.' : 'Kein Passwort hinterlegt.' }}</p>
    @endif
</div>
