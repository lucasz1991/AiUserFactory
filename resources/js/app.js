import './bootstrap';
import Swal from 'sweetalert2';
import 'sweetalert2/dist/sweetalert2.min.css';
import collapse from '@alpinejs/collapse';
import mask from '@alpinejs/mask';
import resize from '@alpinejs/resize';
import intersect from '@alpinejs/intersect';
import sort from '@alpinejs/sort';
import './components/workflow-motion';
import './app-shell';

window.Swal = Swal;

function registerAlpinePlugins() {
    if (!window.Alpine || window.Alpine.__aiUserFactoryPluginsRegistered) {
        return;
    }

    window.Alpine.plugin(collapse);
    window.Alpine.plugin(mask);
    window.Alpine.plugin(resize);
    window.Alpine.plugin(intersect);
    window.Alpine.plugin(sort);
    window.Alpine.__aiUserFactoryPluginsRegistered = true;
}

document.addEventListener('alpine:init', registerAlpinePlugins);
registerAlpinePlugins();

if (document.getElementById('studio-editor')) {
    import('./pagebuilder.js');
}
