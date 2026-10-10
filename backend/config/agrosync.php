<?php

return [

    /*
    | Lifetime of API bearer tokens issued at login, in days.
    */
    'token_ttl_days' => (int) env('API_TOKEN_TTL_DAYS', 7),

    /*
    | Local payment simulator. When enabled, payment initiation falls back to a
    | mock gateway page whose "Simulate Success" link marks the payment paid.
    | Never enable this in production.
    */
    'payments_mock_enabled' => (bool) env('PAYMENTS_MOCK_ENABLED', false),

    /*
    | Failed OTP verifications allowed before the code is invalidated.
    */
    'otp_max_attempts' => 5,

    /*
    | Seeded demo advisory cases excluded from the regional statistics map.
    */
    'demo_advisory_case_ids' => [
        'ADV-2026-0000001',
        'ADV-2026-0000002',
        'ADV-2026-0000003',
        'ADV-2026-0000004',
    ],

];
