document.addEventListener('DOMContentLoaded', function () {
    var element = document.getElementById('pageSelect');
    if (element) {
        new Choices(element, {
            removeItemButton: true,
        });
        console.log('#pageSelect - gefunden');
    }

    var element2 = document.getElementById('positionSelect');
    if (element2) {
        new Choices(element2, {
            removeItemButton: true,
        });
        console.log('#positionSelect - gefunden');
    }

    var element3 = document.getElementById('choices-multiple-remove-button');
    if (element3) {
        new Choices(element3, {
            removeItemButton: true,
        });
        console.log('#choices-multiple-remove-button - gefunden');
    }



});
window.initColorPickers = function () {
    console.log("Initialisiere Colorpicker...");

    document.querySelectorAll('.classic-colorpicker').forEach((pickerElement) => {
        // Das versteckte Input-Element anhand von data-target finden
        let inputId = pickerElement.dataset.target;
        let inputElement = document.getElementById(inputId);

        if (!inputElement ) {
            console.warn("Kein gültiges Input-Element für:", pickerElement);
            return;
        }

        // Standardfarbe aus dem Input übernehmen
        let defaultColor = inputElement.value ? inputElement.value.trim() : '#000000';

        let pickr = Pickr.create({
            el: pickerElement,
            theme: 'classic',
            default: defaultColor,
            swatches: [
                'rgba(244, 67, 54, 1)',
                'rgba(233, 30, 99, 0.95)',
                'rgba(156, 39, 176, 0.9)',
                'rgba(103, 58, 183, 0.85)',
                'rgba(63, 81, 181, 0.8)',
                'rgba(33, 150, 243, 0.75)',
                'rgba(3, 169, 244, 0.7)',
                'rgba(0, 188, 212, 0.7)',
                'rgba(0, 150, 136, 0.75)',
                'rgba(76, 175, 80, 0.8)',
                'rgba(139, 195, 74, 0.85)',
                'rgba(205, 220, 57, 0.9)',
                'rgba(255, 235, 59, 0.95)',
                'rgba(255, 193, 7, 1)'
            ],
            components: {
                preview: true,
                opacity: true,
                hue: true,
                interaction: {
                    hex: true,
                    rgba: true,
                    hsva: true,
                    input: true,
                    clear: true,
                    save: true
                }
            }
        });

        // Setzt die Farbe im Input-Feld beim Speichern
        pickr.on('save', (color) => {
            let selectedColor = color.toHEXA().toString();
            inputElement.value = selectedColor;
            inputElement.dispatchEvent(new Event('input', { bubbles: true })); // Livewire-Update triggern
            console.log('Gespeicherte Farbe:', selectedColor);
        });

        // Zeigt die Live-Vorschau an
        pickr.on('change', (color) => {
            let selectedColor = color.toHEXA().toString();
            inputElement.value = selectedColor;
            console.log('Gewählte Farbe:', selectedColor);
        });

        console.log('Colorpicker initialisiert für:', pickerElement);
    });
};
