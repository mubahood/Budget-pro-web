<?php

/*
| Who to ask for help (docs/roadmap/06-selling-seo-and-whatsapp.md §4).
|
| One number for every surface: the public site, both back offices, emails, error pages, the
| optional receipt line, the API's `support` block and the four phone apps. It is read through
| App\Support\SupportContact and nothing else; a view, an email, a PDF or a Dart file never types
| it (scripts/ci/support-number-scan.sh fails the build when one does). There is no platform
| settings table on the SaaS, so the environment is the setting. Both apps read this file
| (budget-pro-new bridges it through config/budgetpro.php).
|
| A standalone copy shows its own contact first (SupportContact::forCompany()): the shop's own
| phone and email, then ours.
*/

return [
    // Digits only, country code first, for wa.me links.
    'whatsapp' => env('SUPPORT_WHATSAPP', '256783204665'),

    // As people read it; dialled as digits.
    'phone' => env('SUPPORT_PHONE', '+256 783 204 665'),

    // The platform's support inbox. Empty until there is a real one: a personal address never goes here.
    'email' => env('SUPPORT_EMAIL', ''),

    // When someone is there to answer.
    'hours' => env('SUPPORT_HOURS', 'Monday to Saturday, 8:00 to 20:00'),

    // A public status page, when there is one (platform only).
    'status_url' => env('SUPPORT_STATUS_URL', ''),

    // The small "Powered by Budget Pro · WhatsApp …" line on receipts and invoices, per edition, for a
    // shop that has not chosen (companies.receipt_support_line is null). Each shop can switch it in
    // Settings › Receipts. A white-label licence never prints it.
    'receipt_line_default' => [
        'saas' => true,
        'standalone' => false,
    ],
];
