<?php

namespace App\Support;

use App\Models\BillingEvent;
use App\Models\ChatParticipant;
use App\Models\CompanyMember;
use App\Models\PingPinSubscription;
use App\Models\PingPinSubscriptionInvoice;
use App\Models\Subscription;
use App\Models\SubscriptionInvoice;
use App\Models\User;

/**
 * The one place that says which models are NOT narrowed by App\Scopes\CompanyScope, and why
 * (roadmap 02 §4, phase-0 plan P0-4). Every other model whose table has a company_id applies the
 * scope; tests/Feature/TenantModelsTest scans app/Models and fails when one does neither.
 *
 * Adding a model here is a decision, not a shortcut: give the reason in one line, and make sure every
 * query on it filters company_id by hand (or is meant to cross companies, with a test).
 */
final class TenantModels
{
    /**
     * Their table has a company_id, and they cross companies on purpose.
     *
     * @var array<class-string, string>
     */
    public const GLOBAL_ON_PURPOSE = [
        User::class => 'Sign-in finds a person before any company is known, platform admins manage people of every company, and Ping Pin members belong to several organisations: queries filter company_id by hand.',
        CompanyMember::class => 'This table is how membership is decided (Ping Pin organisations, DECISIONS.md D1): scoping it by one company_id would be circular.',
        Subscription::class => 'Platform billing: the Flutterwave webhook and the lifecycle sweep (no user) and platform admins read every company\'s plan.',
        SubscriptionInvoice::class => 'Platform billing: invoices are settled by the webhook and refunded by platform admins, across companies.',
        BillingEvent::class => 'The platform billing audit trail: written by webhooks and platform admins for any company, append-only.',
        PingPinSubscription::class => 'Ping Pin\'s own billing per organisation (DECISIONS.md D2): read for organisations other than the caller\'s primary company.',
        PingPinSubscriptionInvoice::class => 'Ping Pin\'s own billing per organisation (DECISIONS.md D2), settled by its webhook with no user.',
    ];

    /**
     * Tenant rows whose table has no company_id: they are reached only through a scoped parent.
     *
     * @var array<class-string, string>
     */
    public const THROUGH_PARENT = [
        ChatParticipant::class => 'chat_participants has no company_id: a row is reached only through its ChatConversation, which is scoped.',
    ];

    public static function isGlobalOnPurpose(string $class): bool
    {
        return array_key_exists($class, self::GLOBAL_ON_PURPOSE);
    }
}
