<?php

namespace App\Services\Fiscal;

use App\Exceptions\BusinessRuleException;
use App\Models\Company;
use App\Models\FiscalSetting;
use App\Models\FiscalSubmission;
use App\Models\SaleRecord;
use App\Models\SaleReturn;
use App\Models\User;
use App\Services\Notifications\Notifier;
use App\Support\StoreFeatures;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Fiscal receipts / e-invoicing (supermarket plan F2, feature `fiscal`).
 *
 * Off (the feature, or no active connector) = nothing happens: no row, no job, no HTTP call, no receipt change.
 * On: every finished sale gets a `fiscal_submissions` row after its transaction commits; `fiscal:submit-due`
 * (every minute) sends it through the shop's adapter. A failure never touches the sale: it is retried with
 * exponential backoff for 24 hours, then marked failed and the owner is told. The owner can retry, and with
 * the manual adapter the number from a separate fiscal device is typed in.
 */
class FiscalService
{
    /** Give up retrying (and tell the owner) this long after the sale was queued. */
    public const GIVE_UP_HOURS = 24;

    /** Longest wait between two tries. */
    public const MAX_DELAY_MINUTES = 120;

    public const TRIM = 16000;

    /** Adapters for this run (EFRIS keeps one session key per run). @var array<string, ?FiscalAdapter> */
    private array $adapters = [];

    // ── State ────────────────────────────────────────────────

    public static function setting(int $companyId): ?FiscalSetting
    {
        return FiscalSetting::query()->where('company_id', $companyId)->first();
    }

    /** The feature is on and a configured connector is switched on. */
    public static function active(Company|int|null $company): bool
    {
        $company = is_int($company) ? Company::withoutGlobalScopes()->find($company) : $company;
        if (! StoreFeatures::enabled($company, 'fiscal')) {
            return false;
        }
        $s = self::setting((int) $company->id);

        return $s !== null && $s->is_active && FiscalRegistry::get($s->adapter) !== null;
    }

    // ── Queueing (called by SaleService / ReturnService; never throws) ─────────

    public static function queueSale(SaleRecord $sale): void
    {
        try {
            if (! self::active((int) $sale->company_id)) {
                return;
            }
            $adapter = self::setting((int) $sale->company_id)->adapter;
            DB::afterCommit(function () use ($sale, $adapter) {
                try {
                    $exists = FiscalSubmission::query()->where('company_id', $sale->company_id)->where('sale_record_id', $sale->id)->whereNull('sale_return_id')->exists();
                    if (! $exists) {
                        FiscalSubmission::create(['company_id' => $sale->company_id, 'sale_record_id' => $sale->id, 'adapter' => $adapter, 'status' => 'pending',
                            'next_attempt_at' => FiscalRegistry::get($adapter)?->remote() ? now() : null]);
                    }
                } catch (\Throwable $e) {
                    Log::warning('fiscal: could not queue sale '.$sale->id.': '.$e->getMessage());
                }
            });
        } catch (\Throwable $e) {
            Log::warning('fiscal: could not queue sale '.$sale->id.': '.$e->getMessage());
        }
    }

    /** A credit note for a return, when the connector's country requires one and the sale was fiscalised. */
    public static function queueReturn(SaleReturn $return): void
    {
        try {
            if (! self::active((int) $return->company_id)) {
                return;
            }
            $adapter = FiscalRegistry::get(self::setting((int) $return->company_id)->adapter);
            if ($adapter === null || ! $adapter->supportsCreditNotes()) {
                return;
            }
            DB::afterCommit(function () use ($return, $adapter) {
                try {
                    FiscalSubmission::create(['company_id' => $return->company_id, 'sale_record_id' => $return->sale_record_id, 'sale_return_id' => $return->id,
                        'adapter' => $adapter->key(), 'status' => 'pending', 'next_attempt_at' => now()]);
                } catch (\Throwable $e) {
                    Log::warning('fiscal: could not queue return '.$return->id.': '.$e->getMessage());
                }
            });
        } catch (\Throwable $e) {
            Log::warning('fiscal: could not queue return '.$return->id.': '.$e->getMessage());
        }
    }

    // ── Sending ──────────────────────────────────────────────

    /** @return array{sent: int, failed: int, waiting: int} */
    public function processDue(int $limit = 100): array
    {
        $out = ['sent' => 0, 'failed' => 0, 'waiting' => 0];
        $ids = FiscalSubmission::query()->where('status', 'pending')->whereNotNull('next_attempt_at')->where('next_attempt_at', '<=', now())
            ->orderBy('next_attempt_at')->orderBy('id')->limit($limit)->pluck('id');
        foreach ($ids as $id) {
            $s = $this->process((int) $id);
            if ($s !== null) {
                $key = $s->status === 'sent' ? 'sent' : ($s->status === 'failed' ? 'failed' : 'waiting');
                $out[$key]++;
            }
        }

        return $out;
    }

    /** One try. Null when someone else is already on it. */
    public function process(int $id): ?FiscalSubmission
    {
        $now = now();
        // Claim it (the scheduler and "Retry now" never send the same row twice at once).
        $claimed = FiscalSubmission::query()->whereKey($id)->where('status', 'pending')
            ->where(fn ($q) => $q->whereNull('next_attempt_at')->orWhere('next_attempt_at', '<=', $now))
            ->update(['next_attempt_at' => $now->copy()->addMinutes(10), 'last_attempt_at' => $now]);
        if ($claimed === 0) {
            return null;
        }
        $s = FiscalSubmission::query()->findOrFail($id);
        $sale = SaleRecord::withoutGlobalScopes()->with(['saleRecordItems' => fn ($q) => $q->withoutGlobalScopes()])->find($s->sale_record_id);
        $setting = self::setting((int) $s->company_id);
        $adapter = $this->adapters[$s->adapter] ??= FiscalRegistry::get($s->adapter);

        if ($sale === null || ($s->sale_return_id === null && ($sale->voided_at !== null || $sale->status === 'Voided'))) {
            return $this->finish($s, ['status' => 'skipped', 'error' => 'The sale was voided before it was fiscalised.', 'next_attempt_at' => null]);
        }
        if ($adapter === null || $setting === null || ! $setting->is_active || $setting->adapter !== $s->adapter) {
            return $this->finish($s, ['status' => 'skipped', 'error' => 'The fiscal connector was switched off or changed.', 'next_attempt_at' => null]);
        }

        try {
            $config = $setting->decrypted();
            if ($s->sale_return_id !== null) {
                $original = FiscalSubmission::query()->where('company_id', $s->company_id)->where('sale_record_id', $s->sale_record_id)
                    ->whereNull('sale_return_id')->where('status', 'sent')->first();
                if ($original === null) {
                    $result = $this->ageOrWait($s, 'The sale has no fiscal receipt yet; the credit note waits for it.');
                    if ($result !== null) {
                        return $result;
                    }
                    $result = FiscalResult::fail('The sale was never fiscalised, so no credit note can be issued.', [], true);
                } else {
                    $return = SaleReturn::withoutGlobalScopes()->with(['items' => fn ($q) => $q->withoutGlobalScopes()])->findOrFail($s->sale_return_id);
                    $result = $adapter->creditNote($return, $sale, $original->only(['fiscal_number', 'verification_code']) + ['raw_invoice_id' => $this->invoiceId($original)], $config, (string) $setting->environment);
                }
            } else {
                $result = $adapter->submit($sale, $config, (string) $setting->environment);
            }
        } catch (\Throwable $e) {
            $result = FiscalResult::fail(mb_substr($e->getMessage(), 0, 500));
        }

        $raw = ['raw_request' => $this->trim($result->raw['request'] ?? null), 'raw_response' => $this->trim($result->raw['response'] ?? null)];
        if ($result->ok) {
            return $this->finish($s, $raw + ['status' => 'sent', 'attempts' => $s->attempts + 1, 'sent_at' => now(), 'next_attempt_at' => null, 'error' => null,
                'fiscal_number' => mb_substr((string) $result->fiscal_number, 0, 100), 'verification_code' => $result->verification_code ? mb_substr($result->verification_code, 0, 100) : null,
                'qr_payload' => $result->qr_payload]);
        }
        if ($result->deferred) {
            return $this->finish($s, ['next_attempt_at' => null, 'error' => $result->error]);
        }

        $attempts = $s->attempts + 1;
        $givingUp = $result->permanent || $s->created_at->lte(now()->subHours(self::GIVE_UP_HOURS));
        $s = $this->finish($s, $raw + ['attempts' => $attempts, 'error' => mb_substr((string) $result->error, 0, 1000),
            'status' => $givingUp ? 'failed' : 'pending',
            'next_attempt_at' => $givingUp ? null : now()->addMinutes(self::delayMinutes($attempts))]);
        if ($givingUp) {
            $this->tellOwner($s);
        }

        return $s;
    }

    /** 1, 2, 4, 8 … minutes, never more than MAX_DELAY_MINUTES. */
    public static function delayMinutes(int $attempts): int
    {
        return (int) min(self::MAX_DELAY_MINUTES, 2 ** max(0, min(10, $attempts - 1)));
    }

    /** Waiting on something else: keep retrying until the 24-hour limit. */
    private function ageOrWait(FiscalSubmission $s, string $why): ?FiscalSubmission
    {
        if ($s->created_at->lte(now()->subHours(self::GIVE_UP_HOURS))) {
            return null;
        }

        return $this->finish($s, ['attempts' => $s->attempts + 1, 'error' => $why, 'next_attempt_at' => now()->addMinutes(self::delayMinutes($s->attempts + 1))]);
    }

    private function finish(FiscalSubmission $s, array $attrs): FiscalSubmission
    {
        $s->forceFill($attrs)->save();

        return $s;
    }

    private function invoiceId(FiscalSubmission $s): ?string
    {
        $r = json_decode((string) $s->raw_response, true);

        return is_array($r) ? ($r['content']['basicInformation']['invoiceId'] ?? null) : null;
    }

    private function trim(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }
        $s = is_string($value) ? $value : json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PARTIAL_OUTPUT_ON_ERROR);

        return mb_substr((string) $s, 0, self::TRIM);
    }

    private function tellOwner(FiscalSubmission $s): void
    {
        if ($s->owner_notified) {
            return;
        }
        try {
            $company = Company::withoutGlobalScopes()->find($s->company_id);
            $owner = $company?->owner_id ? User::withoutGlobalScopes()->find($company->owner_id) : null;
            if ($owner !== null) {
                $receipt = DB::table('sale_records')->where('id', $s->sale_record_id)->value('receipt_number') ?: '#'.$s->sale_record_id;
                (new Notifier())->notify((int) $s->company_id, 'fiscal', 'A receipt was not fiscalised',
                    ($s->sale_return_id ? 'The credit note for a return on ' : 'Receipt ').$receipt.' could not be sent to the tax authority: '.mb_substr((string) $s->error, 0, 200).' Open Business settings → Fiscal to retry.',
                    ['fiscal_submission_id' => $s->id, 'sale_record_id' => $s->sale_record_id], [$owner]);
            }
            $s->forceFill(['owner_notified' => true])->save();
        } catch (\Throwable $e) {
            Log::warning('fiscal: owner notice failed for submission '.$s->id.': '.$e->getMessage());
        }
    }

    // ── Owner actions ────────────────────────────────────────

    /** Send a failed (or waiting) submission again, now. */
    public function retry(int $companyId, int $id): FiscalSubmission
    {
        $s = $this->find($companyId, $id);
        if (FiscalRegistry::get($s->adapter)?->remote() !== true) {
            throw BusinessRuleException::make('fiscal_manual', 'This receipt waits for its number to be typed in.');
        }
        if (! in_array($s->status, ['failed', 'pending'], true)) {
            throw BusinessRuleException::make('fiscal_not_retryable', 'Only a receipt that is waiting or failed can be sent again.');
        }
        // A fresh 24-hour window, and the owner is told again if it fails for good.
        $s->forceFill(['status' => 'pending', 'next_attempt_at' => now(), 'owner_notified' => false, 'created_at' => now()])->save();

        return $this->process($s->id) ?? $s->fresh();
    }

    /** Manual adapter: the number printed by the separate fiscal device. */
    public function recordManual(int $companyId, int $id, string $number, ?string $code, int $userId): FiscalSubmission
    {
        $s = $this->find($companyId, $id);
        if (FiscalRegistry::get($s->adapter)?->remote() !== false) {
            throw BusinessRuleException::make('fiscal_not_manual', 'This receipt is sent to the tax authority automatically.');
        }
        if ($s->status === 'sent') {
            throw BusinessRuleException::make('fiscal_already', 'This receipt already has a fiscal number.');
        }
        $number = trim($number);
        if ($number === '' || mb_strlen($number) > 100) {
            throw BusinessRuleException::make('fiscal_number_required', 'Type the fiscal receipt number (up to 100 characters).');
        }
        $code = $code !== null && trim($code) !== '' ? mb_substr(trim($code), 0, 100) : null;

        return $this->finish($s, ['status' => 'sent', 'fiscal_number' => $number, 'verification_code' => $code, 'sent_at' => now(),
            'next_attempt_at' => null, 'error' => null, 'entered_by' => $userId, 'attempts' => $s->attempts + 1]);
    }

    private function find(int $companyId, int $id): FiscalSubmission
    {
        $s = FiscalSubmission::query()->where('company_id', $companyId)->find($id);
        if ($s === null) {
            throw BusinessRuleException::make('fiscal_not_found', 'That fiscal receipt was not found.');
        }

        return $s;
    }

    // ── Settings ─────────────────────────────────────────────

    /**
     * What a settings screen may show: non-secret values, and for each secret only whether it is set.
     *
     * @return array{adapter: ?string, environment: string, is_active: bool, values: array<string, mixed>, configured: array<string, bool>, last_tested_at: ?Carbon, last_test_ok: ?bool, last_test_message: ?string}
     */
    public function publicSettings(int $companyId): array
    {
        $s = self::setting($companyId);
        $adapter = FiscalRegistry::get($s?->adapter);
        $config = $s?->decrypted() ?? [];
        $values = [];
        $configured = [];
        foreach ($adapter?->configFields() ?? [] as $key => $f) {
            if (str_starts_with($f[1], 'secret')) {
                $configured[$key] = ($config[$key] ?? '') !== '';
            } else {
                $values[$key] = $config[$key] ?? null;
            }
        }

        return ['adapter' => $s?->adapter, 'environment' => (string) ($s?->environment ?: 'sandbox'), 'is_active' => (bool) $s?->is_active,
            'values' => $values, 'configured' => $configured, 'last_tested_at' => $s?->last_tested_at, 'last_test_ok' => $s?->last_test_ok, 'last_test_message' => $s?->last_test_message];
    }

    /**
     * Save the connector. A secret left empty keeps the one already saved. Switching on needs every required field.
     *
     * @param  array<string, mixed>  $input
     */
    public function saveSettings(int $companyId, string $adapterKey, array $input, string $environment, bool $active, int $userId): FiscalSetting
    {
        $adapter = FiscalRegistry::get($adapterKey);
        if ($adapter === null) {
            throw BusinessRuleException::make('fiscal_adapter', 'Choose a fiscal connector.');
        }
        if (! in_array($environment, ['sandbox', 'production'], true)) {
            throw BusinessRuleException::make('fiscal_environment', 'Choose sandbox (testing) or production.');
        }
        $setting = self::setting($companyId) ?? new FiscalSetting(['company_id' => $companyId]);
        $old = $setting->adapter === $adapterKey ? $setting->decrypted() : [];
        $config = [];
        foreach ($adapter->configFields() as $key => $f) {
            $v = $input[$key] ?? null;
            $v = is_string($v) ? trim($v) : $v;
            if (str_starts_with($f[1], 'secret') && ($v === null || $v === '')) {
                $v = $old[$key] ?? '';
            }
            if (is_string($v) && mb_strlen($v) > 20000) {
                throw BusinessRuleException::make('fiscal_too_long', $f[0].' is too long.');
            }
            if ($f[1] === 'select' && $v !== null && $v !== '' && ! isset($f[4][$v])) {
                throw BusinessRuleException::make('fiscal_option', 'Choose a valid value for '.$f[0].'.');
            }
            $config[$key] = $v ?? '';
        }
        if ($active) {
            $missing = collect($adapter->configFields())->filter(fn ($f, $k) => $f[2] && ($config[$k] ?? '') === '')->map(fn ($f) => $f[0])->values()->all();
            if ($missing !== []) {
                throw BusinessRuleException::make('fiscal_incomplete', 'Fill in '.implode(', ', $missing).' before switching fiscal receipts on.');
            }
        }
        if ($adapterKey === 'efris' && ($config['private_key'] ?? '') !== '' && openssl_pkey_get_private((string) $config['private_key'], (string) ($config['key_password'] ?? '')) === false) {
            throw BusinessRuleException::make('fiscal_key', 'The private key could not be read. Paste the whole PEM text (-----BEGIN … KEY-----) and check its password.');
        }
        $setting->forceFill(['company_id' => $companyId, 'adapter' => $adapterKey, 'environment' => $environment, 'is_active' => $active, 'updated_by' => $userId]);
        $setting->encryptConfig($config);
        $setting->save();

        return $setting;
    }

    /** Calls the adapter's test with the saved settings; remembers the outcome. */
    public function testConnection(int $companyId): FiscalResult
    {
        $s = self::setting($companyId);
        $adapter = FiscalRegistry::get($s?->adapter);
        if ($s === null || $adapter === null) {
            throw BusinessRuleException::make('fiscal_not_set_up', 'Save a fiscal connector first.');
        }
        try {
            $r = $adapter->test($s->decrypted(), (string) $s->environment);
        } catch (\Throwable $e) {
            $r = FiscalResult::fail($e->getMessage());
        }
        $s->forceFill(['last_tested_at' => now(), 'last_test_ok' => $r->ok, 'last_test_message' => mb_substr((string) ($r->ok ? $r->fiscal_number : $r->error), 0, 500)])->save();

        return $r;
    }

    /** @return Collection<int, object> newest first, with the receipt number */
    public function recent(int $companyId, int $limit = 20, ?string $status = null): Collection
    {
        return DB::table('fiscal_submissions as f')->leftJoin('sale_records as s', 's.id', '=', 'f.sale_record_id')
            ->where('f.company_id', $companyId)
            ->when($status !== null, fn ($q) => $q->where('f.status', $status))
            ->orderByDesc('f.id')->limit($limit)
            ->get(['f.id', 'f.sale_record_id', 'f.sale_return_id', 'f.adapter', 'f.status', 'f.attempts', 'f.next_attempt_at', 'f.fiscal_number', 'f.verification_code',
                'f.error', 'f.created_at', 'f.sent_at', 's.receipt_number', 's.total_amount']);
    }

    /** @return array<string, int> status => count */
    public function counts(int $companyId): array
    {
        return DB::table('fiscal_submissions')->where('company_id', $companyId)->groupBy('status')->selectRaw('status, COUNT(*) AS n')->pluck('n', 'status')
            ->map(fn ($n) => (int) $n)->all();
    }

    // ── Receipts ─────────────────────────────────────────────

    /**
     * What a receipt prints: the fiscal number, verification code and QR once sent; "pending" while queued.
     * Null for a sale that was never queued (fiscal off): the receipt is unchanged.
     *
     * @return array{status: string, number: ?string, code: ?string, qr: ?string}|null
     */
    public static function forReceipt(SaleRecord $sale): ?array
    {
        try {
            $s = FiscalSubmission::query()->where('company_id', $sale->company_id)->where('sale_record_id', $sale->id)->whereNull('sale_return_id')
                ->orderByDesc('id')->first();
        } catch (\Throwable) {
            return null;
        }
        if ($s === null || $s->status === 'skipped') {
            return null;
        }

        return ['status' => $s->status, 'number' => $s->fiscal_number, 'code' => $s->verification_code,
            'qr' => $s->status === 'sent' ? ($s->qr_payload ?: $s->fiscal_number) : null];
    }
}
