@include('livewire.admin.network.partials.workflow-definition-editor', [
    'modalOnly' => $modalOnly ?? false,
    'definitionDrawerOpen' => $definitionDrawerOpen ?? false,
    'revisionMode' => true,
    'editorInstance' => 'studio-'.$studioSessionId,
    'definitionSurfaceMode' => ($modalOnly ?? false) ? 'studio-drawer' : 'workbench',
])
