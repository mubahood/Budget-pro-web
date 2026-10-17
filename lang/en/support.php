<?php

/*
| One prefilled WhatsApp message per place a support link appears (docs/roadmap/06 §4), read by
| App\Support\SupportContact in both apps through the `bp` namespace (`bp::support.<key>`), and
| sent to the phone apps in the API's `support.templates`.
|
| Placeholders are filled only from: the page or screen name, the app's name, the shop's name, the
| person's role, an error reference, a version, a licence id or the app's diagnostics report.
| NEVER a customer's name, an amount or a phone number.
|
| A part in [square brackets] is kept only when every placeholder inside it has a value, so a
| message reads well whatever is known: "I need help[ with :screen]." becomes "I need help." on a
| page with no name. A placeholder outside brackets with no value is left out (never ":name").
*/

return [

    // The public site.
    'site.general' => 'Hello Budget Pro, I found you on your website and I would like to know more.',
    'site.page' => 'Hello Budget Pro, I am reading your page[ about :page] and I have a question.',
    'site.app' => 'Hello Budget Pro, I am looking at[ :app on] your website and I have a question.',
    'site.pricing' => 'Hello Budget Pro, I would like help choosing a plan[ for :app].',
    'site.demo' => 'Hello Budget Pro, I opened the live demo and I would like someone to show me around.',
    'site.own_copy' => 'Hello Budget Pro, I am interested in buying our own copy of Budget Pro.',
    'site.error' => 'Hello Budget Pro, I saw an error page on your website.[ Reference :ref.]',

    // Signing in and opening an account.
    'auth.signin' => 'Hello Budget Pro, I am having trouble signing in[ to :app].',
    'auth.register' => 'Hello Budget Pro, I am opening an account[ for :app] and I have a question.',
    'trial.welcome' => 'Hello Budget Pro, I have just opened an account[ for :shop]. Please help me set up.',

    // The back office (the new screens and the classic ones).
    'admin.help' => 'Hello Budget Pro, I need help[ with :screen].[ I am the :role at :shop.]',
    'admin.error' => 'Hello Budget Pro, something went wrong[ at :shop].[ Reference :ref.]',
    'admin.limit' => 'Hello Budget Pro, we have reached the[ :limit] limit on our plan[ for :shop]. Can you help us choose?',
    'admin.renew' => 'Hello Budget Pro, I would like to renew the subscription[ for :shop].',
    'admin.suspended' => 'Hello Budget Pro, our account[ for :shop] is suspended and I would like to talk about it.',

    // The phone apps (filled by the app itself from these templates).
    'app.help' => 'Hello Budget Pro, I need help with the[ :app] app[ (version :version)].[ I am the :role at :shop.]',
    'app.diagnostics' => 'Hello Budget Pro, here is my[ :app] app diagnostics report:[ :report]',

    // Emails and printed documents.
    'mail.general' => 'Hello Budget Pro, I got an email from you and I have a question.',
    'receipt.powered' => 'Hello Budget Pro, I saw your name on a receipt and I would like to know more.',

    // A copy on the buyer's own server.
    'standalone.install' => 'Hello Budget Pro, I need help installing our copy[ (licence :licence)].',
    'standalone.update' => 'Hello Budget Pro, our copy[ (version :version)] needs help with an update.',
];
