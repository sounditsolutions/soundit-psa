<?php

return [
    'enabled' => env('SIGNING_ENABLED', false),
    'token_ttl_days' => (int) env('SIGNING_TOKEN_TTL_DAYS', 14),
];
