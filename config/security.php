<?php

return [
    'cookie_files' => [
        'retention_days' => max(1, (int) env('SECURITY_COOKIE_RETENTION_DAYS', 30)),

        // Kommagetrennte, absolute Zusatzpfade. storage/app und ein explizit
        // vom internen Aufrufer uebergebener Storage-Root sind immer erlaubt;
        // public/ sowie storage/app/public bleiben stets gesperrt.
        'allowed_roots' => array_values(array_filter(array_map(
            static fn (string $root): string => trim($root),
            explode(',', (string) env('SECURITY_COOKIE_ALLOWED_ROOTS', '')),
        ))),
    ],
];
