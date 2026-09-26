<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * The billing audit trail (POWER_PLAN §4.2): every platform-admin change to a shop's plan (comp,
 * trial/period extension, manual payment, refund) and every payment the system fulfils — who, what, why.
 * Append-only.
 *
 * @property int $company_id
 * @property int|null $actor_id
 * @property string $action
 */
class BillingEvent extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = ['company_id', 'actor_id', 'action', 'subscription_id', 'invoice_id', 'meta', 'reason'];

    protected $casts = ['meta' => 'array', 'created_at' => 'datetime'];

    public static function record(int $companyId, string $action, ?int $actorId = null, array $meta = [], ?string $reason = null, ?int $subscriptionId = null, ?int $invoiceId = null): self
    {
        return self::create(['company_id' => $companyId, 'actor_id' => $actorId, 'action' => $action, 'subscription_id' => $subscriptionId,
            'invoice_id' => $invoiceId, 'meta' => $meta ?: null, 'reason' => $reason !== null ? mb_substr($reason, 0, 500) : null]);
    }
}
