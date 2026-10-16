<?php

namespace App\Services\Licensing;

use App\Models\Plan;
use App\Services\Billing\Quotas;
use App\Support\Licensing\InstalledLicence;

/**
 * The one plan a standalone copy runs on (docs/STANDALONE_EDITION_PLAN.md §7.1): slug
 * "standalone", lifetime, every feature on. Its limits come from the licence, so every existing
 * quota check (Quotas, Entitlements) keeps working without knowing which edition it is in.
 *
 * Users, locations and devices come from the licence; every other key — products, sales per
 * month, storage — is always null (unlimited). Every Quotas key is written, so
 * Entitlements::DEFAULT_LIMITS never applies.
 */
final class StandalonePlan
{
    public const SLUG = 'standalone';

    /** @return array<string,int|null> one entry per Quotas key */
    public static function limitsFrom(InstalledLicence $licence): array
    {
        $limits = [];
        foreach (Quotas::KEYS as $key) {
            $limits[$key] = in_array($key, InstalledLicence::LIMIT_KEYS, true) ? $licence->limit($key) : null;
        }

        return $limits;
    }

    /**
     * Rewrite the standalone plan's limits from a valid licence. Null when no such plan exists
     * yet (the installer seeds it). An invalid licence never loosens or changes the limits.
     */
    public function apply(InstalledLicence $licence): ?Plan
    {
        if (! $licence->isValid()) {
            throw new \DomainException('The installed licence is missing or invalid.');
        }

        $plan = Plan::query()->where('slug', self::SLUG)->first();
        if ($plan === null) {
            return null;
        }

        $plan->limits = self::limitsFrom($licence);
        $plan->save();

        return $plan;
    }
}
