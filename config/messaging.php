<?php

/*
| Messaging (plan C7, decision H4): Africa's Talking for SMS/OTP, Meta WhatsApp
| Cloud API for WhatsApp, Laravel mail for email. Every channel has a `log`
| driver (writes to message_log only) so development and tests never send.
*/
return [
    'sms' => [
        'driver' => env('SMS_DRIVER', 'log'), // africastalking | log | null
        'africastalking' => [
            'username' => env('AFRICASTALKING_USERNAME'),
            'api_key' => env('AFRICASTALKING_API_KEY'),
            'sender_id' => env('AFRICASTALKING_SENDER_ID'),
            'endpoint' => env('AFRICASTALKING_ENDPOINT', 'https://api.africastalking.com/version1/messaging'),
        ],
    ],
    'whatsapp' => [
        'driver' => env('WHATSAPP_DRIVER', 'log'), // meta | log | null
        'meta' => [
            'token' => env('WHATSAPP_TOKEN'),
            'phone_number_id' => env('WHATSAPP_PHONE_NUMBER_ID'),
            'otp_template' => env('WHATSAPP_OTP_TEMPLATE', 'otp_code'),
            'receipt_template' => env('WHATSAPP_RECEIPT_TEMPLATE', 'sale_receipt'), // params: shop, receipt no., total, link
            'api_version' => env('WHATSAPP_API_VERSION', 'v21.0'),
        ],
    ],
    'mail' => [
        'driver' => env('MESSAGING_MAIL_DRIVER', 'log'), // mail | log | null
    ],
    'otp' => [
        'length' => 6,
        'ttl_minutes' => 10,
        'max_attempts' => 5,
        'max_per_hour' => 5,
    ],
];
