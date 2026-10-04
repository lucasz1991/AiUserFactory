@props(['feedback' => []])

@if($feedback['failed'] ?? false)
    <p class="ff-task-feedback ff-task-feedback--error" role="status" data-task-error>
        <strong>{{ in_array($feedback['status'], ['timed_out', 'timeout'], true) ? 'Zeitüberschreitung' : 'Task fehlgeschlagen' }}</strong>
        @if($feedback['message'] ?? '')<span>{{ $feedback['message'] }}</span>@endif
    </p>
@endif
