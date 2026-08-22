<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Typed AI API daily cost guard
    |--------------------------------------------------------------------------
    |
    | Every request reserves a deliberately conservative amount before the
    | provider is called. A positive provider cost replaces that reservation;
    | if the provider omits cost data, the reservation remains charged.
    |
    */
    'budget_enabled' => (bool) env('AI_API_BUDGET_ENABLED', true),

    'user_daily_budget_usd' => (float) env('AI_API_USER_DAILY_BUDGET_USD', 10.0),
    'team_daily_budget_usd' => (float) env('AI_API_TEAM_DAILY_BUDGET_USD', 50.0),

    'reservation_cost_per_1000_tokens_usd' => (float) env('AI_API_RESERVATION_COST_PER_1000_TOKENS_USD', 0.05),

    'endpoint_minimum_reservation_usd' => [
        'text' => (float) env('AI_API_TEXT_MINIMUM_RESERVATION_USD', 0.25),
        'json' => (float) env('AI_API_JSON_MINIMUM_RESERVATION_USD', 0.25),
        'image_generation' => (float) env('AI_API_IMAGE_MINIMUM_RESERVATION_USD', 1.5),
    ],
];
