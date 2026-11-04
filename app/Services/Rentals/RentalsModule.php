<?php

namespace App\Services\Rentals;

use App\Exceptions\BusinessRuleException;
use App\Models\Company;
use App\Models\RentalLandlord;
use App\Models\RentalLease;
use App\Models\RentalPayment;
use App\Models\RentalProperty;
use App\Models\RentalSetting;
use App\Models\RentalUnit;
use App\Models\User;
use App\Services\Rentals\Demo\RentalsDemo;
use App\Services\Rentals\Ledger\RentalLedger;
use App\Support\Money;
use Illuminate\Support\Facades\Validator;

/**
 * An Estate Ledger account's settings and first run (docs/estate-ledger/04-architecture.md §3.1, §5.11): agency or owner mode,
 * billing, commission, deposit and late-fee defaults, payment instructions, message texts, and the numbering of
 * receipts, leases, payouts, expenses, tickets, tenants and landlords.
 */
final class RentalsModule
{
    /** Fields a manager may change in Settings. */
    public const WRITABLE = ['mode', 'currency', 'bill_days_before', 'grace_days', 'anchor', 'prorate_first', 'commission_type', 'commission_bp',
        'commission_minor', 'commission_on_items', 'deposit_months', 'deposit_held_by', 'late_fee_type', 'late_fee_minor', 'late_fee_bp',
        'late_fee_to', 'payment_instructions', 'receipt_footer', 'messages'];

    /** Numbering: sequence column => [prefix, digits]. */
    public const NUMBERS = ['receipt' => ['R-', 6], 'lease' => ['L-', 4], 'payout' => ['PO-', 4], 'expense' => ['EX-', 4],
        'ticket' => ['MR-', 4], 'tenant' => ['T-', 4], 'landlord' => ['LL-', 3]];

    /** The account's settings row, created with the defaults the first time it is asked for. */
    public static function settings(int $companyId): RentalSetting
    {
        $s = RentalSetting::withoutGlobalScopes()->where('company_id', $companyId)->first();
        if ($s !== null) {
            return $s;
        }
        $company = Company::withoutGlobalScopes()->find($companyId);
        $d = (array) config('rentals.defaults');

        return RentalSetting::withoutGlobalScopes()->firstOrCreate(['company_id' => $companyId], [
            'mode' => $d['mode'] ?? 'agency', 'currency' => strtoupper((string) ($company?->currency ?: 'UGX')),
            'bill_days_before' => $d['bill_days_before'] ?? 5, 'grace_days' => $d['grace_days'] ?? 5, 'anchor' => $d['anchor'] ?? 'start',
            'prorate_first' => $d['prorate_first'] ?? true, 'commission_type' => $d['commission_type'] ?? 'percent',
            'commission_bp' => $d['commission_bp'] ?? 1000, 'deposit_months' => $d['deposit_months'] ?? 1,
            'deposit_held_by' => $d['deposit_held_by'] ?? 'agency', 'late_fee_type' => $d['late_fee_type'] ?? 'none',
            'late_fee_to' => $d['late_fee_to'] ?? 'agency',
        ]);
    }

    public static function isOwnerMode(int $companyId): bool
    {
        return self::settings($companyId)->mode === 'owner';
    }

    /** Owner mode's one landlord, "You" (KR-06): created when first needed. */
    public static function selfLandlord(int $companyId): RentalLandlord
    {
        $self = RentalLandlord::withoutGlobalScopes()->where('company_id', $companyId)->where('is_self', true)->first();
        if ($self !== null) {
            return $self;
        }
        $company = Company::withoutGlobalScopes()->find($companyId);

        return RentalLandlord::withoutGlobalScopes()->create(['company_id' => $companyId, 'name' => (string) ($company?->name ?: 'You'),
            'is_self' => true, 'commission_type' => 'none', 'payout_method' => 'cash', 'status' => 'active']);
    }

    /** The next number in a sequence ("R-000123"). Runs under the account's lock. */
    public static function nextNumber(int $companyId, string $sequence): string
    {
        [$prefix, $digits] = self::NUMBERS[$sequence] ?? throw new \InvalidArgumentException("Unknown sequence {$sequence}.");

        return RentalLedger::locked($companyId, function () use ($companyId, $sequence, $prefix, $digits) {
            $col = "seq_{$sequence}";
            $s = RentalSetting::withoutGlobalScopes()->where('company_id', $companyId)->lockForUpdate()->first();
            $n = (int) $s->{$col} + 1;
            $s->forceFill([$col => $n])->save();

            return $prefix.str_pad((string) $n, $digits, '0', STR_PAD_LEFT);
        });
    }

    /** The first run (Setup): mode and defaults. Owner mode gets its "You" landlord. */
    public function setup(Company $company, User $by, array $data): RentalSetting
    {
        RentalAccess::authorize($by, 'rentals_settings');
        RentalsDemo::guard($company, 'changing the settings');
        $settings = $this->save($company, $by, $data, true);
        if ($settings->mode === 'owner') {
            self::selfLandlord((int) $company->id);
        }

        return $settings;
    }

    public function saveSettings(Company $company, User $by, array $data): RentalSetting
    {
        RentalAccess::authorize($by, 'rentals_settings');
        RentalsDemo::guard($company, 'changing the settings');

        return $this->save($company, $by, $data, false);
    }

    /** Why the currency may not change now, if so: payments are in it, or the typed amounts would mean something else. */
    public static function currencyLock(int $companyId): ?string
    {
        return RentalPayment::withoutGlobalScopes()->where('company_id', $companyId)->exists() ? 'The currency cannot change once payments are recorded.' : null;
    }

    /** Why the way of working may not change now, if so. A mode change after the first tenant would split the books. */
    public static function modeLock(int $companyId, string $to): ?string
    {
        if (RentalLease::withoutGlobalScopes()->where('company_id', $companyId)->exists()) {
            return 'The way you work cannot change once a tenant is on the books.';
        }
        if ($to === 'owner' && RentalLandlord::withoutGlobalScopes()->where('company_id', $companyId)->where('is_self', false)->count() > 1) {
            return 'An account with several landlords stays an agency.';
        }

        return null;
    }

    private function assertCurrencyCanChange(int $cid, string $from, string $to): void
    {
        if (($why = self::currencyLock($cid)) !== null) {
            throw BusinessRuleException::make('currency_locked', $why);
        }
        if (! in_array($to, (array) config('saas.currencies'), true)) {
            throw BusinessRuleException::make('invalid', 'Choose a currency from the list.');
        }
        $cents = fn (string $c) => in_array($c, Money::ZERO_DECIMAL, true) ? 0 : 2;
        if ($cents($from) !== $cents($to) && RentalUnit::withoutGlobalScopes()->where('company_id', $cid)->exists()) {
            throw BusinessRuleException::make('currency_locked', "Rents are already entered in {$from}, which counts cents differently from {$to}. The amounts would change.");
        }
    }

    private function assertModeCanChange(int $cid, string $to): void
    {
        if (($why = self::modeLock($cid, $to)) !== null) {
            throw BusinessRuleException::make('mode_locked', $why);
        }
    }

    /** @return array<string, mixed> */
    public static function rules(): array
    {
        return [
            'mode' => ['sometimes', 'in:agency,owner'],
            'currency' => ['sometimes', 'string', 'size:3'],
            'bill_days_before' => ['sometimes', 'integer', 'min:0', 'max:31'],
            'grace_days' => ['sometimes', 'integer', 'min:0', 'max:60'],
            'anchor' => ['sometimes', 'in:start,first'],
            'prorate_first' => ['sometimes', 'boolean'],
            'commission_type' => ['sometimes', 'in:percent,flat,none'],
            'commission_bp' => ['sometimes', 'integer', 'min:0', 'max:10000'],
            'commission_minor' => ['sometimes', 'integer', 'min:0'],
            'commission_on_items' => ['sometimes', 'boolean'],
            'deposit_months' => ['sometimes', 'integer', 'min:0', 'max:12'],
            'deposit_held_by' => ['sometimes', 'in:agency,landlord'],
            'late_fee_type' => ['sometimes', 'in:none,flat,percent'],
            'late_fee_minor' => ['sometimes', 'integer', 'min:0'],
            'late_fee_bp' => ['sometimes', 'integer', 'min:0', 'max:10000'],
            'late_fee_to' => ['sometimes', 'in:agency,landlord'],
            'payment_instructions' => ['sometimes', 'nullable', 'string', 'max:2000'],
            'receipt_footer' => ['sometimes', 'nullable', 'string', 'max:255'],
            'messages' => ['sometimes', 'nullable', 'array'],
            'messages.*' => ['nullable', 'string', 'max:1000'],
        ];
    }

    private function save(Company $company, User $by, array $data, bool $setup): RentalSetting
    {
        $cid = (int) $company->id;
        $data = array_intersect_key($data, array_flip(self::WRITABLE));
        $v = Validator::make($data, self::rules());
        if ($v->fails()) {
            throw BusinessRuleException::make('invalid', (string) $v->errors()->first(), ['errors' => $v->errors()->toArray()]);
        }

        return RentalLedger::locked($cid, function () use ($company, $cid, $data, $setup, $by) {
            $s = RentalSetting::withoutGlobalScopes()->where('company_id', $cid)->lockForUpdate()->first();
            $newCurrency = isset($data['currency']) && strtoupper($data['currency']) !== $s->currency ? strtoupper($data['currency']) : null;
            if ($newCurrency !== null) {
                $this->assertCurrencyCanChange($cid, (string) $s->currency, $newCurrency);
            }
            $newMode = ($data['mode'] ?? $s->mode) !== $s->mode ? (string) $data['mode'] : null;
            if ($newMode !== null && ! $setup) {
                $this->assertModeCanChange($cid, $newMode);
            }
            if (isset($data['currency'])) {
                $data['currency'] = strtoupper($data['currency']);
            }
            if ($newCurrency !== null) {
                // One currency for the whole account: every amount on screen is formatted from the company's (Money).
                Company::withoutGlobalScopes()->whereKey($company->id)->update(['currency' => $newCurrency]);
                Money::flush();
            }
            if ($newMode === 'owner' && ! $setup) {
                // The one landlord an agency had (with no tenancies yet) gives way to "You": its properties become the owner's.
                RentalProperty::withoutGlobalScopes()->where('company_id', $cid)->update(['landlord_id' => self::selfLandlord($cid)->id]);
            }
            $before = $s->only(array_keys($data));
            $s->forceFill($data + ($setup && $s->setup_at === null ? ['setup_at' => now()] : []))->save();
            $changed = array_keys(array_diff_assoc(array_map(self::comparable(...), $s->only(array_keys($data))), array_map(self::comparable(...), $before)));
            if ($changed !== [] || $setup) {
                RentalAudit::record($cid, $setup ? 'settings.setup' : 'settings.saved', $s, $setup ? 'Finished the setup' : 'Changed the settings', ['fields' => $changed], 'staff', (int) $by->id);
            }

            return $s->fresh();
        });
    }

    /** A setting's value as text, to see whether a save changed it (`messages` is a list). */
    private static function comparable(mixed $value): string
    {
        return is_array($value) ? (string) json_encode($value) : (string) $value;
    }
}
