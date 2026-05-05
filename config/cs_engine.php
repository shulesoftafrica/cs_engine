<?php

return [
    'wasender' => [
        'base_url' => env('WASENDER_BASE_URL', 'https://www.wasenderapi.com'),
        'webhook_secret_header' => env('WASENDER_WEBHOOK_SECRET_HEADER', 'X-Webhook-Secret'),
    ],

    'messages' => [
        'unregistered' => 'Your number is not registered. Contact support.',
        'intent_fallback' => 'We have received your request and will get back to you within 24 hours.',
        'no_kb_match' => "I don't have info on that. Contact :support_email.",
        'permission_denied' => 'Based on your role and permissions, You do not have permission to access this information. Contact support.',
        'technical_issue' => 'Brief technical issue. Please try again.',
        'technical_issue_shortly' => 'Brief technical issue. Please try again shortly.',
    ],
];