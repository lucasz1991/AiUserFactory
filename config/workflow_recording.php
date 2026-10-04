<?php

return [
    'enabled' => true,
    'browser_executable_path' => null,
    'idle_timeout_seconds' => 600,
    'startup_timeout_seconds' => 30,
    'max_events' => 500,
    // Never enable this in a deployed environment. It exists solely for isolated QA.
    'testing_local_hosts' => false,
    'viewport' => ['width' => 1280, 'height' => 800],
];
