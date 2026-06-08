import '@grapesjs/studio-sdk/dist/style.css';
import '@grapesjs/studio-sdk/style';
import { createStudioEditor } from '@grapesjs/studio-sdk';
import { rteTinyMce } from '@grapesjs/studio-sdk-plugins';
import { iconifyComponent } from "@grapesjs/studio-sdk-plugins";
import { lightGalleryComponent } from "@grapesjs/studio-sdk-plugins";
import { fsLightboxComponent } from "@grapesjs/studio-sdk-plugins";
import { swiperComponent } from '@grapesjs/studio-sdk-plugins';
import { dialogComponent } from "@grapesjs/studio-sdk-plugins";
import addCustomBlocks from './components/grapesjs-blocks';

window.initGrapesJs = async function() {
    if (!document.getElementById("studio-editor") && document.getElementById('studio-editor').getAttribute('data-project') != null) {
        return;
    }
    var selectedProject = document.getElementById('studio-editor').getAttribute('data-project');
    console.log("Initialisiere GrapesJS Studio mit Lizenz:", 'a15cafec95f0407b8d6ed899618f792c8a45f41b505c4736a22acb54236e8b15');
    if (window.editor) {
        console.log("Bestehenden GrapesJS Editor zerstören...");
        window.editor.destroy();
        window.editor = null;
    }
    try {
        window.editor = await createStudioEditor({
            root: '#studio-editor',
            licenseKey: 'a15cafec95f0407b8d6ed899618f792c8a45f41b505c4736a22acb54236e8b15',
            plugins: [
              rteTinyMce.init({
                enableOnClick: true,
                loadConfig: ({ component, config }) => {
                  const demoRte = component.get('demorte');
                  if (demoRte === 'fixed') {
                    return {
                      toolbar:
                        'bold italic underline strikethrough | alignleft aligncenter alignright alignjustify | link image media',
                      fixed_toolbar_container_target: document.querySelector('.rteContainer')
                    };
                  } else if (demoRte === 'quickbar') {
                    return {
                      plugins: `${config.plugins} quickbars`,
                      toolbar: false,
                      quickbars_selection_toolbar: 'bold italic underline strikethrough | quicklink image'
                    };
                  }
                  return {};
                }
              }),
              iconifyComponent?.init({
                block: { category: 'Extra', label: 'Iconify' }
              }),
              fsLightboxComponent?.init({
                block: { category: 'Extra', label: 'FS Lightbox' }
              }),
              lightGalleryComponent?.init({
                block: { category: 'Extra', label: 'Light Gallery' }
              }),
              swiperComponent?.init({
                block: false
              }),
              dialogComponent.init({
                block: { category: 'Extra', label: 'My Dialog' }
              }),
              editor => {
                addCustomBlocks(editor);
              } 
            ],
            layout: {
              default: {
                type: 'row',
                style: { height: '100%' },
                colors: {
                  global: {
                    focus: "rgba(37, 99, 235, 1)"
                  },
                  primary: {
                    background1: "rgba(101, 118, 95, 1)",
                    backgroundHover: "rgba(64, 84, 57, 1)"
                  }
                },
                children: [
                  {
                    type: 'sidebarLeft',
                    children: {
                      type: 'tabs',
                      value: 'blocks',
                      tabs: [
                        {
                          id: 'blocks',
                          label: 'Blocks',
                          children: { type: 'panelBlocks', style: { height: '100%' } },
                        },
                        {
                          id: 'layers',
                          label: 'Elements',
                          children: { type: 'panelLayers', style: { height: '100%' } },
                        },
                      ],
                    },
                  },
                  {
                    type: 'canvasSidebarTop',
                    sidebarTop: { leftContainer: { buttons: [] } },
                  },
                  {
                    type: 'sidebarRight',
                    children: {
                      type: 'tabs',
                      value: 'styles',
                      tabs: [
                        {
                          id: 'styles',
                          label: 'Styles',
                          children: {
                            type: 'column',
                            style: { height: '100%' },
                            children: [
                              { type: 'panelSelectors', style: { padding: 5 } },
                              { type: 'panelStyles' },
                            ],
                          },
                        },
                        {
                          id: 'props',
                          label: 'Properties',
                          children: { type: 'panelProperties', style: { padding: 5, height: '100%' } },
                        },
                        {
                          id: 'amimations',
                          label: 'Effects',
                          children: [
                            { type: 'panelSelectors', style: { padding: 5 } },
                            { type: 'panelAnimations', style: { padding: 25, height: '100%' } }
                          ],
                        },
                      ],
                    },
                  },
                ],
              },
          },
            project: { 
                type: 'web'
            },
            pages: {
                add: false,
                duplicate: false,
                remove: false,
                select: false,
                settings: false
            },
            canvas: {
                styles: [
                    './../css/components/tailwind.min.css',
                ],
            },
            assets: {
                storageType: 'self',
                onUpload: async ({ files }) => {
                    var body = new FormData();
                    for (var file of files) {
                        body.append('file', file);
                    }
                    var csrfToken = document.querySelector('meta[name="csrf-token"]').getAttribute('content');
                    var response = await fetch('https://dev.regulierungs-check.de/api/pagebuilder/upload', {
                        method: 'POST',
                        body,
                        headers: { 'Authorization': 'Bearer ' + localStorage.getItem('auth_token'), 'X-CSRF-TOKEN': csrfToken }
                    });
                    console.log(response);
                    if (!response.ok) {
                        console.error('Bild-Upload fehlgeschlagen');
                        return [];
                    }
                    var result = await response.json();
                    return [{ src: result.url }];
                },
                onLoad: async () => {
                    var response = await fetch('https://dev.regulierungs-check.de/api/pagebuilder/assets', {
                        headers: { 'Authorization': 'Bearer ' + localStorage.getItem('auth_token') },
                    });
                    console.log(response);
                    if (!response.ok) {
                        console.error('Fehler beim Laden der Assets');
                        return [];
                    }
                    return await response.json();
                }
            },
            storage: {
                type: 'self',
                onSave: async ({ project, editor }) => {
                    var files = await editor.runCommand('studio:projectFiles');
                    var htmldata = files.find(file => file.mimeType === 'text/html').content;
                    var body = new FormData();
                    body.append('id', selectedProject);
                    body.append('data', JSON.stringify(project));
                    body.append('html', htmldata);
                    var csrfToken = document.querySelector('meta[name="csrf-token"]').getAttribute('content');
                    var response = await fetch('/admin/pagebuilder/save', {
                        method: 'POST',
                        body,
                        headers: {
                            'Authorization': 'Bearer ' + localStorage.getItem('auth_token'),
                            'X-CSRF-TOKEN': csrfToken
                        },
                    });
                    console.log(response);
                    if (!response.ok) {
                        console.error('Speichern fehlgeschlagen');
                    } else {
                        console.log('Projekt gespeichert!');
                    }
                },
                onLoad: async () => {
                    var csrfToken = document.querySelector('meta[name="csrf-token"]').getAttribute('content');
                    var response = await fetch('/admin/pagebuilder/load/'+selectedProject, {
                        headers: { 'Authorization': 'Bearer ' + localStorage.getItem('auth_token'),'X-CSRF-TOKEN': csrfToken },
                    });
                    console.log(response);
                    if (!response.ok) {
                        console.error('Laden fehlgeschlagen');
                        return {};
                    }
                    var projektJson = await response.json();
                    return  { project: projektJson };
                },
                autosaveChanges: 100,
                autosaveIntervalMs: 10000
            },
            identity: {
                id: "1MZssHHwuOi2kNaH"
            }
        });
        console.log("GrapesJS Studio erfolgreich initialisiert!");
        return window.editor;
    } catch (error) {
        console.error("Fehler beim Initialisieren von GrapesJS Studio:", error);
    }
}
