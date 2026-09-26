<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Subscriptions & billing (POWER_PLAN.md §4): annual prices, billing interval, downgrades at period end,
 * card auto-renew, invoice tax/refund/abandonment, the billing_events audit trail, and a clean-up of
 * free-text company currencies (`ugshs`, `Uganda shillings`, `UGX `) that were being charged in USD.
 * Additive and safe to re-run: every column is guarded, the currency map only touches unknown values.
 */
return new class extends Migration
{
    /** Free-text spellings seen in the wild → ISO code (lower-cased, spaces and dots removed). */
    private const CURRENCY_MAP = [
        'ugx' => 'UGX', 'ugshs' => 'UGX', 'ushs' => 'UGX', 'ush' => 'UGX', 'shs' => 'UGX', 'ugsh' => 'UGX', 'ugandashillings' => 'UGX',
        'ugandashilling' => 'UGX', 'ugandanshillings' => 'UGX', 'ugandanshilling' => 'UGX', 'shillings' => 'UGX', 'uganda' => 'UGX',
        'kes' => 'KES', 'ksh' => 'KES', 'kshs' => 'KES', 'kenyashillings' => 'KES', 'kenyanshillings' => 'KES',
        'tzs' => 'TZS', 'tsh' => 'TZS', 'tshs' => 'TZS', 'tanzaniashillings' => 'TZS', 'tanzanianshillings' => 'TZS',
        'rwf' => 'RWF', 'frw' => 'RWF', 'rwandafrancs' => 'RWF', 'rwandanfrancs' => 'RWF',
        'usd' => 'USD', '$' => 'USD', 'us$' => 'USD', 'dollars' => 'USD', 'usdollars' => 'USD',
    ];

    public function up(): void
    {
        if (! Schema::hasColumn('plans', 'price_ugx_annual')) {
            Schema::table('plans', function (Blueprint $t) {
                $t->decimal('price_ugx_annual', 12, 2)->nullable()->after('price_ugx');
            });
        }

        Schema::table('subscriptions', function (Blueprint $t) {
            if (! Schema::hasColumn('subscriptions', 'billing_interval')) {
                $t->string('billing_interval', 8)->default('month')->after('plan_id');
            }
            if (! Schema::hasColumn('subscriptions', 'pending_plan_id')) {
                $t->unsignedBigInteger('pending_plan_id')->nullable()->after('billing_interval');
            }
            if (! Schema::hasColumn('subscriptions', 'pending_change_at')) {
                $t->timestamp('pending_change_at')->nullable()->after('pending_plan_id');
            }
            if (! Schema::hasColumn('subscriptions', 'auto_renew')) {
                $t->boolean('auto_renew')->default(false)->after('pending_change_at');
            }
        });

        Schema::table('subscription_invoices', function (Blueprint $t) {
            if (! Schema::hasColumn('subscription_invoices', 'tax_amount')) {
                $t->decimal('tax_amount', 12, 2)->default(0)->after('amount');
            }
            if (! Schema::hasColumn('subscription_invoices', 'tax_rate')) {
                $t->decimal('tax_rate', 5, 2)->default(0)->after('tax_amount');
            }
            if (! Schema::hasColumn('subscription_invoices', 'refunded_at')) {
                $t->timestamp('refunded_at')->nullable()->after('paid_at');
            }
            if (! Schema::hasColumn('subscription_invoices', 'refund_amount')) {
                $t->decimal('refund_amount', 12, 2)->nullable()->after('refunded_at');
            }
            if (! Schema::hasColumn('subscription_invoices', 'abandoned_at')) {
                $t->timestamp('abandoned_at')->nullable()->after('refund_amount');
            }
        });
        if (! $this->hasIndex('subscription_invoices', 'subscription_invoices_status_created_at_index')) {
            Schema::table('subscription_invoices', fn (Blueprint $t) => $t->index(['status', 'created_at']));
        }
        if (! $this->hasIndex('subscription_invoices', 'subscription_invoices_provider_invoice_id_index')) {
            Schema::table('subscription_invoices', fn (Blueprint $t) => $t->index('provider_invoice_id'));
        }

        if (! Schema::hasTable('billing_events')) {
            Schema::create('billing_events', function (Blueprint $t) {
                $t->id();
                $t->unsignedBigInteger('company_id')->index();
                $t->unsignedBigInteger('actor_id')->nullable();
                $t->string('action', 40);
                $t->unsignedBigInteger('subscription_id')->nullable();
                $t->unsignedBigInteger('invoice_id')->nullable();
                $t->json('meta')->nullable();
                $t->string('reason', 500)->nullable();
                $t->timestamp('created_at')->nullable();
                $t->index(['company_id', 'created_at']);
            });
        }

        $this->annualPrices();
        $this->normaliseCurrencies();
    }

    /** Annual = about two months free (10 × the monthly price) unless a price was already set. */
    private function annualPrices(): void
    {
        foreach (DB::table('plans')->get() as $plan) {
            $update = [];
            if ($plan->price_ugx_annual === null && (float) $plan->price_ugx > 0) {
                $update['price_ugx_annual'] = round((float) $plan->price_ugx * 10, 2);
            }
            $prices = json_decode((string) $plan->prices, true);
            if (is_array($prices) && $prices !== []) {
                $changed = false;
                foreach ($prices as $cur => $price) {
                    if (! is_array($price) && is_numeric($price)) {
                        $prices[$cur] = ['month' => (float) $price, 'year' => round((float) $price * 10, 2)];
                        $changed = true;
                    }
                }
                if ($changed) {
                    $update['prices'] = json_encode($prices);
                }
            }
            if ($update !== []) {
                DB::table('plans')->where('id', $plan->id)->update($update);
            }
        }
    }

    private function normaliseCurrencies(): void
    {
        $allowed = (array) config('saas.currencies', ['UGX', 'KES', 'TZS', 'RWF', 'USD']);
        $default = strtoupper((string) config('saas.default_currency', 'UGX'));
        foreach (DB::table('companies')->select('currency')->distinct()->pluck('currency') as $raw) {
            $to = self::normalise($raw, $allowed, $default);
            if ($to !== $raw) {
                $q = DB::table('companies');
                $raw === null ? $q->whereNull('currency') : $q->where('currency', $raw);
                $q->update(['currency' => $to]);
            }
        }
    }

    /** Known spellings map to their code; an ISO code already allowed is kept (upper-cased); anything else is the default. */
    public static function normalise(?string $raw, array $allowed, string $default): string
    {
        $key = strtolower(str_replace([' ', '.', '-', '_'], '', trim((string) $raw)));
        if (isset(self::CURRENCY_MAP[$key])) {
            return self::CURRENCY_MAP[$key];
        }
        $upper = strtoupper(trim((string) $raw));

        return in_array($upper, $allowed, true) ? $upper : $default;
    }

    private function hasIndex(string $table, string $name): bool
    {
        return DB::table('information_schema.statistics')->where('table_schema', DB::getDatabaseName())->where('table_name', $table)->where('index_name', $name)->exists();
    }

    public function down(): void
    {
        Schema::dropIfExists('billing_events');
        // Columns are additive and harmless; leave them (the shipped phone app never reads them).
    }
};
