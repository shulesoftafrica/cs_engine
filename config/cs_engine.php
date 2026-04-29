<?php

return [
    'wasender' => [
        'base_url' => env('WASENDER_BASE_URL', 'https://www.wasenderapi.com'),
        'webhook_secret_header' => env('WASENDER_WEBHOOK_SECRET_HEADER', 'X-Webhook-Secret'),
    ],

    'messages' => [
        'unregistered' => 'Your number is not registered. Contact support.',
        'intent_fallback' => 'For now I can only help with support questions. Please contact support for anything else.',
        'no_kb_match' => "I don't have info on that. Contact :support_email.",
        'technical_issue' => 'Brief technical issue. Please try again.',
        'technical_issue_shortly' => 'Brief technical issue. Please try again shortly.',
    ],
];