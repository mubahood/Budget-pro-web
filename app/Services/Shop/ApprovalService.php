<?php

namespace App\Services\Shop;

use App\Exceptions\BusinessRuleException;
use App\Models\Approval;
use App\Models\Company;
use App\Models\User;
use App\Services\Team\Permissions;
use App\Support\StoreFeatures;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;

/**
 * Supervisor approval at the till (supermarket plan A5). A cashier's risky action (void, refund,
 * price override, no-sale…) waits for a supervisor to type their PIN, without the cashier signing
 * out. Each approval is logged (`approvals`) and can be used once, by the action it was given for.
 *
 * Off unless the shop has the `approvals` feature (StoreFeatures): required() is then false for every
 * action and nothing changes.
 */
class ApprovalService
{
    /** action => what it is, in shop words */
    public const ACTIONS = [
        'void_sale' => 'Void a sale',
        'refund' => 'Take a return / refund',
        'reverse_payment' => 'Reverse a payment',
        'price_override' => 'Cut a price beyond the limit',
        'line_void_after_total' => 'Remove a line after the total',
        'no_sale' => 'Open the drawer (no sale)',
        'waste' => 'Write off stock beyond the limit',
        'cash_out' => 'Pay cash out of the drawer',
    ];

    /** Wrong PINs allowed per shop and person per minute. */
    public const MAX_TRIES = 5;

    /** An approval must be used within this many minutes. */
    public const VALID_MINUTES = 10;

    /** A person's own till PIN: 4–6 digits, stored hashed. $by (when given) must be them or a team manager of their shop. */
    public function setPin(User $user, string $pin, ?User $by = null): void
    {
        $pin = trim($pin);
        if (! preg_match('/^\d{4,6}$/', $pin)) {
            throw BusinessRuleException::make('invalid_pin', 'The PIN must be 4 to 6 digits.');
        }
        if ($by !== null && (int) $by->id !== (int) $user->id) {
            if ((int) $by->company_id !== (int) $user->company_id || ! Permissions::can($by, 'manage_team')) {
                throw BusinessRuleException::make('forbidden', 'Only the person or the shop owner can set this PIN.');
            }
            $owner = Company::withoutGlobalScopes()->whereKey($user->company_id)->value('owner_id');
            if ((int) $owner === (int) $user->id) {
                throw BusinessRuleException::make('owner_pin', 'Only the owner can set the owner’s PIN.');
            }
        }
        $user->forceFill(['pos_pin_hash' => Hash::make($pin)])->saveQuietly();
    }

    public function clearPin(User $user): void
    {
        $user->forceFill(['pos_pin_hash' => null])->saveQuietly();
    }

    public function hasPin(User $user): bool
    {
        return User::withoutGlobalScopes()->whereKey($user->id)->whereNotNull('pos_pin_hash')->exists();
    }

    /**
     * Does this action need a supervisor here? Only with the `approvals` feature on.
     *
     * @param  array{amount?: float|int|string|null, pct?: float|int|string|null, original?: float|int|string|null, price?: float|int|string|null}  $context
     *                                                                                                                                                        price_override: `pct` (the cut, %) or `original` + `price`; waste: `amount` (value written off).
     */
    public function required(Company|int|null $company, string $action, array $context = []): bool
    {
        $company = is_int($company) ? Company::withoutGlobalScopes()->find($company) : $company;
        if ($company === null || ! isset(self::ACTIONS[$action]) || ! StoreFeatures::enabled($company, 'approvals')) {
            return false;
        }

        return match ($action) {
            'price_override' => self::cutPct($context) > (float) StoreFeatures::setting($company, 'override_limit_pct'),
            'waste' => (float) ($context['amount'] ?? 0) > (float) StoreFeatures::setting($company, 'waste_limit'),
            default => true,
        };
    }

    private static function cutPct(array $c): float
    {
        if (isset($c['pct']) && is_numeric($c['pct'])) {
            return (float) $c['pct'];
        }
        $original = (float) ($c['original'] ?? 0);

        return $original > 0 ? round(($original - (float) ($c['price'] ?? $original)) / $original * 100, 4) : 0.0;
    }

    /**
     * A supervisor typed their PIN: find them (a member of this shop with a PIN and the `approve`
     * permission, which owners and managers have), log the approval and return the approver.
     *
     * @param  array{sale_record_id?: int|null, amount?: float|null, reason?: string|null}  $context
     */
    public function approve(int $companyId, string $action, string $pin, int $requestedBy, array $context = []): User
    {
        return $this->grant($companyId, $action, $pin, $requestedBy, $context)->approver;
    }

    /** approve(), returning the logged row (its id is what the action hands back to consume()). */
    public function grant(int $companyId, string $action, string $pin, int $requestedBy, array $context = []): Approval
    {
        if (! isset(self::ACTIONS[$action])) {
            throw BusinessRuleException::make('invalid_action', 'This action does not take an approval.');
        }
        $key = 'approval-pin:'.$companyId.':'.$requestedBy;
        if (RateLimiter::tooManyAttempts($key, self::MAX_TRIES)) {
            throw BusinessRuleException::make('too_many_attempts', 'Too many wrong PINs. Wait '.max(1, RateLimiter::availableIn($key)).' seconds and try again.');
        }
        $pin = trim($pin);
        $candidates = User::withoutGlobalScopes()->where('company_id', $companyId)->whereNotNull('pos_pin_hash')->get();
        if ($candidates->isEmpty()) {
            throw BusinessRuleException::make('no_supervisor_pin', 'No supervisor has a till PIN yet. The owner or a manager can set one in Team.');
        }
        $approver = preg_match('/^\d{4,6}$/', $pin)
            ? $candidates->first(fn (User $u) => Hash::check($pin, (string) $u->pos_pin_hash) && Permissions::can($u, 'approve'))
            : null;
        if ($approver === null) {
            RateLimiter::hit($key, 60);
            throw BusinessRuleException::make('wrong_pin', 'That PIN is not a supervisor’s PIN. Try again.');
        }
        RateLimiter::clear($key);

        $amount = $context['amount'] ?? null;
        $row = new Approval();
        $row->forceFill([
            'company_id' => $companyId,
            'action' => $action,
            'requested_by' => $requestedBy,
            'approved_by' => $approver->id,
            'sale_record_id' => isset($context['sale_record_id']) ? (int) $context['sale_record_id'] : null,
            'amount' => is_numeric($amount) ? round((float) $amount, 2) : null,
            'reason' => isset($context['reason']) ? mb_substr(trim((string) $context['reason']), 0, 500) ?: null : null,
        ])->save();

        return $row->setRelation('approver', $approver);
    }

    /**
     * Use an approval for the action it was given for: same shop, same action, asked for by the same
     * person (and for the same sale when there is one), unused and recent. It can't be used twice.
     */
    public function consume(int $companyId, int $approvalId, string $action, int $requestedBy, ?int $saleId = null): Approval
    {
        $row = Approval::withoutGlobalScopes()->where('company_id', $companyId)->whereKey($approvalId)->lockForUpdate()->first();
        $ok = $row !== null && $row->action === $action && (int) $row->requested_by === $requestedBy
            && ($saleId === null || (int) $row->sale_record_id === $saleId)
            && $row->consumed_at === null && $row->created_at !== null && $row->created_at->gt(now()->subMinutes(self::VALID_MINUTES));
        if (! $ok) {
            throw BusinessRuleException::make('approval_invalid', 'That approval is no longer valid. Ask the supervisor to enter their PIN again.');
        }
        $row->forceFill(['consumed_at' => now()])->save();

        return $row;
    }
}
