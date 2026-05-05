<?php

return [
    'wasender' => [
        'base_url' => env('WASENDER_BASE_URL', 'https://www.wasenderapi.com'),
        'webhook_secret_header' => env('WASENDER_WEBHOOK_SECRET_HEADER', 'X-Webhook-Secret'),
    ],

    'messages' => [
        'en' => [
            'unregistered' => 'Your number is not registered. Contact support.',
            'intent_fallback' => 'We have received your message, our support team will get back to you within 24 hours.',
            'greeting' => 'Hello! How can I help you today? Feel free to ask any support questions.',
            'no_kb_match' => "I don't have info on that. Contact :support_email.",
            'permission_denied' => 'Based on your role and permissions, you do not have permission to access this information. Contact support.',
            'technical_issue' => 'Brief technical issue. Please try again.',
            'technical_issue_shortly' => 'Brief technical issue. Please try again shortly.',
        ],
        'sw' => [
            'unregistered' => 'Nambari yako haijasajiliwa. Wasiliana na msaada.',
            'intent_fallback' => 'Tumepokea ujumbe wako, timu yetu ya msaada itakujibu ndani ya masaa 24.',
            'greeting' => 'Habari! Naweza kukusaidia vipi leo? Kuwa huru kuuliza maswali yoyote ya msaada.',
            'no_kb_match' => 'Sina taarifa kuhusu hilo. Wasiliana na  huduma kwa wateja:support_email.',
            'permission_denied' => 'Kulingana na jukumu na ruhusa zako, huna ruhusa ya kupata taarifa hii. Wasiliana na msaada.',
            'technical_issue' => 'Tatizo dogo la kiufundi. Tafadhali jaribu tena.',
            'technical_issue_shortly' => 'Tatizo dogo la kiufundi. Tafadhali jaribu tena baadaye kidogo.',
        ],
    ],
];