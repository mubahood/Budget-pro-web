<?php

/*
 * Fiscal receipts / e-invoicing (supermarket plan F2). Credentials are per shop (fiscal_settings, encrypted);
 * only the endpoints and protocol constants live here. Uganda EFRIS (URA) is the first connector.
 */
return [
    'efris' => [
        'sandbox_url' => env('EFRIS_SANDBOX_URL', 'https://efristest.ura.go.ug/efrisws/ws/taapp/getInformation'),
        'production_url' => env('EFRIS_PRODUCTION_URL', 'https://efrisws.ura.go.ug/ws/taapp/getInformation'),
        'app_id' => env('EFRIS_APP_ID', 'AP04'),
        'version' => env('EFRIS_VERSION', '1.1.20191201'),
        'timeout' => (int) env('EFRIS_TIMEOUT', 30),
    ],
];
