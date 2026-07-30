@include('livewire.admin.network.partials.workflow-definition-editor', [
    'modalOnly' => $modalOnly ?? false,
    'revisionMode' => true,
    'editorInstance' => 'studio-'.$studioSessionId,
])
