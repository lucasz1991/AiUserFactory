import './bootstrap';
import Swal from 'sweetalert2';
import 'sweetalert2/dist/sweetalert2.min.css';
import collapse from '@alpinejs/collapse';
import mask from '@alpinejs/mask';
import resize from '@alpinejs/resize';
import intersect from '@alpinejs/intersect';
import sort from '@alpinejs/sort';
import './components/workflow-motion';
import { workflowSelectorField } from './components/workflow-selector-syntax';
import { workflowRouteSurface } from './components/workflow-route-surface';
import { workflowDomInspector } from './components/workflow-dom-inspector';
import './app-shell';
// Spur W (PWA + Web-Push). Beim App-Shell-Umbau bitte uebernehmen — ohne
// diesen Import registriert sich kein Service Worker und die Alpine-
// Komponenten `factoryPwaInstall` / `factoryPushSettings` fehlen. Der Ausfall
// waere still: keine Fehlermeldung, nur eine tote Seite /app-installation.
import {
    registerFactoryPushSettings,
    registerFactoryPwaInstall,
    setupFactoryPwa,
} from './pwa';

window.Swal = Swal;

function registerAlpinePlugins() {
    if (!window.Alpine) {
        return;
    }

    if (!window.Alpine.__workflowSelectorSyntaxRegistered) {
        window.Alpine.data('workflowSelectorField', workflowSelectorField);
        window.Alpine.__workflowSelectorSyntaxRegistered = true;
    }

    if (!window.Alpine.__workflowRouteSurfaceRegistered) {
        window.Alpine.data('workflowRouteSurface', workflowRouteSurface);
        window.Alpine.__workflowRouteSurfaceRegistered = true;
    }

    if (!window.Alpine.__workflowDomInspectorRegistered) {
        window.Alpine.data('workflowDomInspector', workflowDomInspector);
        window.Alpine.__workflowDomInspectorRegistered = true;
    }

    if (window.Alpine.__aiUserFactoryPluginsRegistered) {
        return;
    }

    window.Alpine.plugin(collapse);
    window.Alpine.plugin(mask);
    window.Alpine.plugin(resize);
    window.Alpine.plugin(intersect);
    window.Alpine.plugin(sort);

    // Alpine.data() muss vor dem Start von Alpine laufen; Livewire feuert
    // `alpine:init` genau dafuer.
    registerFactoryPushSettings(window.Alpine);
    registerFactoryPwaInstall(window.Alpine);

    window.Alpine.__aiUserFactoryPluginsRegistered = true;
}

document.addEventListener('alpine:init', registerAlpinePlugins);
registerAlpinePlugins();

setupFactoryPwa();

if (document.getElementById('studio-editor')) {
    import('./pagebuilder.js');
}
