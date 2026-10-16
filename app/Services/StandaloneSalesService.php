<?php

namespace App\Services;

use App\Models\StandaloneLicence;
use App\Models\StandaloneOrder;
use App\Models\StandaloneProduct;
use App\Models\StandaloneRelease;
use App\Notifications\StandaloneDownloadReady;
use App\Services\Billing\BillingService;
use App\Services\Licensing\Signer;
use App\Support\Edition;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;

/**
 * Selling the standalone edition (docs/STANDALONE_EDITION_PLAN.md §11), after True-Doctor's.
 *
 * A purchase or a renewal is an order paid through Flutterwave's hosted checkout (card, and mobile
 * money where the currency has it). It is settled only after the payment is verified with Flutterwave
 * itself, exactly once however many times the webhook and the return page arrive (lockForUpdate). A
 * purchase issues a licence; a renewal extends one. Either way the buyer is emailed a private link.
 * The price, months and limits are the ones the buyer saw at checkout. SaaS only.
 */
class StandaloneSalesService
{
    public const DOMAIN_PATTERN = '/^(?=.{4,253}$)(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z]{2,63}$/';

    public function __construct(private readonly Signer $signer)
    {
    }

    public function signingReady(): bool
    {
        try {
            $this->signer->keyId();

            return true;
        } catch (\Throwable) {
            return false;
        }
    }

    /** Selling is open: SaaS, signing works, a release is published and a product is on sale. */
    public function open(): bool
    {
        return Edition::isSaas() && $this->signingReady() && StandaloneRelease::query()->available()->exists()
            && StandaloneProduct::query()->where('is_active', true)->exists();
    }

    /**
     * Start buying $product: an order and Flutterwave's hosted checkout link.
     *
     * @param  array{business:string,name:string,email:string,phone?:?string,domain:string}  $buyer
     * @return array{order:StandaloneOrder,link:string}
     */
    public function start(StandaloneProduct $product, array $buyer, string $currency, string $redirectUrl): array
    {
        $currency = strtoupper($currency);
        $price = in_array($currency, StandaloneProduct::CURRENCIES, true) ? $product->price($currency) : null;
        if (! $product->is_active || ! $this->open()) {
            throw new \DomainException('Buying a copy is not open yet.');
        }
        if ($price === null || $price <= 0) {
            throw new \DomainException("{$product->name} is not sold in {$currency}.");
        }
        $buyer = $this->buyer($buyer);

        return $this->charge(StandaloneOrder::create([
            'reference' => StandaloneOrder::newReference(), 'kind' => 'purchase', 'product_id' => $product->id,
            'buyer' => $buyer, 'currency' => $currency, 'amount' => $price, 'months' => $product->updates_months,
            'limits' => $product->licenceLimits(), 'white_label' => (bool) $product->white_label, 'status' => 'pending',
        ]), $redirectUrl, 'Budget Pro on your own server: '.$product->name);
    }

    /** Buy another period of updates for $licence. @return array{order:StandaloneOrder,link:string} */
    public function startRenewal(StandaloneLicence $licence, string $currency, string $redirectUrl): array
    {
        $currency = strtoupper($currency);
        $product = $licence->product;
        $price = $product?->renewalPrice($currency);
        if (! $licence->isActive() || $product === null || $price === null || $price <= 0 || ! $this->open()) {
            throw new \DomainException('Online renewal is not available for this licence. Contact us and we will extend it.');
        }

        return $this->charge(StandaloneOrder::create([
            'reference' => StandaloneOrder::newReference(), 'kind' => 'renewal', 'product_id' => $product->id,
            'licence_id' => $licence->id, 'currency' => $currency, 'amount' => $price, 'months' => $product->updates_months,
            'buyer' => ['business' => $licence->customer, 'name' => $licence->contact_name, 'email' => $licence->email,
                'phone' => $licence->phone, 'domain' => $licence->domain()],
            'limits' => $licence->limits, 'white_label' => (bool) $licence->white_label, 'status' => 'pending',
        ]), $redirectUrl, 'Budget Pro: '.$product->updates_months.' more months of updates');
    }

    /**
     * Verify a BPL- payment with Flutterwave (by transaction id when the caller has one) and settle it.
     *
     * @return string settled | already_settled | mismatch | failed | pending | retry | unverified | unknown_reference
     */
    public function settle(string $reference, int|string|null $transactionId = null): string
    {
        $order = str_starts_with($reference, StandaloneOrder::PREFIX) ? StandaloneOrder::query()->where('reference', $reference)->first() : null;
        if ($order === null) {
            return 'unknown_reference';
        }
        if ($order->status === 'paid') {
            return 'already_settled';
        }
        $flw = app(FlutterwaveService::class);
        $verified = $transactionId !== null && $transactionId !== '' ? $flw->verifyTransaction($transactionId) : $flw->verifyByReference($reference);
        if (! ($verified['success'] ?? false)) {
            return ($verified['transient'] ?? false) ? 'retry' : 'unverified';
        }

        return $this->settleVerified($reference, (array) ($verified['data'] ?? []));
    }

    /**
     * Settle an order from Flutterwave's verified transaction data. Idempotent: the row is locked, a
     * paid order answers already_settled, and a payment short of the order or in another currency
     * issues nothing.
     */
    public function settleVerified(string $reference, array $data): string
    {
        if (($data['tx_ref'] ?? null) !== $reference) {
            return 'unknown_reference';
        }
        $status = strtolower((string) ($data['status'] ?? ''));

        return DB::transaction(function () use ($reference, $data, $status): string {
            $order = StandaloneOrder::query()->where('reference', $reference)->lockForUpdate()->first();
            if ($order === null) {
                return 'unknown_reference';
            }
            if ($order->status === 'paid') {
                return 'already_settled';
            }
            if (in_array($status, ['failed', 'cancelled', 'canceled', 'error'], true)) {
                $order->update(['status' => 'failed']);

                return 'failed';
            }
            if ($status !== 'successful') {
                return 'pending';
            }
            $amount = (float) ($data['amount'] ?? 0);
            if (strtoupper((string) ($data['currency'] ?? '')) !== $order->currency || $amount + 0.001 < (float) $order->amount) {
                $order->update(['status' => 'mismatch']);
                Log::warning('Standalone payment mismatch', ['reference' => $reference, 'amount' => $amount, 'currency' => $data['currency'] ?? null]);

                return 'mismatch';
            }

            if ($order->kind === 'renewal') {
                $licence = StandaloneLicence::query()->whereKey($order->licence_id)->lockForUpdate()->firstOrFail();
                $licence->update(['updates_until' => $this->extendedUntil($licence, $order->months)]);
                $reason = 'renewed';
            } else {
                $buyer = $order->buyer;
                $licence = StandaloneLicence::create([
                    'product_id' => $order->product_id, 'customer' => $buyer['business'], 'contact_name' => $buyer['name'] ?? null,
                    'email' => $buyer['email'], 'phone' => $buyer['phone'] ?? null, 'domains' => [$buyer['domain']],
                    'limits' => StandaloneProduct::normaliseLimits($order->limits), 'white_label' => (bool) $order->white_label,
                    'updates_until' => now()->addMonthsNoOverflow($order->months)->toDateString(),
                    'status' => StandaloneLicence::ACTIVE, 'source' => 'online', 'reference' => $order->reference,
                ]);
                $reason = 'purchased';
            }
            $order->update([
                'status' => 'paid', 'licence_id' => $licence->id, 'paid_at' => now(),
                'gateway_tx_id' => isset($data['id']) ? (string) $data['id'] : null,
            ]);
            DB::afterCommit(fn () => $this->sendLink($licence, $reason));

            return 'settled';
        });
    }

    /**
     * A licence for a sale made outside checkout (bank transfer, contract, cash), emailed at once.
     *
     * @param  array{business:string,name?:?string,email:string,phone?:?string,domain:string}  $buyer
     */
    public function issueOffline(StandaloneProduct $product, array $buyer, string $reference): StandaloneLicence
    {
        if (! Edition::isSaas() || ! $this->signingReady()) {
            throw new \DomainException('Set up release signing (RELEASE_SIGNING_KEY_ID / RELEASE_SIGNING_SECRET) before issuing a licence.');
        }
        $buyer = $this->buyer($buyer);
        $reference = trim($reference);
        if ($reference === '') {
            throw new \DomainException('Enter the payment or contract reference.');
        }

        $licence = StandaloneLicence::create([
            'product_id' => $product->id, 'customer' => $buyer['business'], 'contact_name' => $buyer['name'],
            'email' => $buyer['email'], 'phone' => $buyer['phone'], 'domains' => [$buyer['domain']],
            'limits' => $product->licenceLimits(), 'white_label' => (bool) $product->white_label,
            'updates_until' => now()->addMonthsNoOverflow($product->updates_months)->toDateString(),
            'status' => StandaloneLicence::ACTIVE, 'source' => 'offline', 'reference' => mb_substr($reference, 0, 190),
        ]);
        $this->sendLink($licence, 'issued');

        return $licence;
    }

    /** Another $months of updates, added to whatever is left of the current period. */
    public function extend(StandaloneLicence $licence, int $months): StandaloneLicence
    {
        if ($months < 1 || $months > 120) {
            throw new \DomainException('Extend by 1 to 120 months.');
        }
        $licence->update(['updates_until' => $this->extendedUntil($licence, $months)]);

        return $licence->refresh();
    }

    /** Move a licence to another address: the buyer once every 30 days, staff at any time. */
    public function changeDomain(StandaloneLicence $licence, string $domain, bool $byStaff = false): StandaloneLicence
    {
        $domain = $this->domain($domain);
        if (! $byStaff && ! $licence->canChangeDomain()) {
            throw new \DomainException('The address was changed recently. It can change again on '
                .$licence->nextDomainChangeAt()?->format('j M Y').', or ask us.');
        }
        $licence->update(['domains' => [$domain], 'domain_changed_at' => now()]);

        return $licence->refresh();
    }

    /** Stop downloads and the update feed. The buyer's installed copy keeps working. */
    public function revoke(StandaloneLicence $licence): StandaloneLicence
    {
        $licence->update(['status' => StandaloneLicence::REVOKED]);

        return $licence->refresh();
    }

    public function reinstate(StandaloneLicence $licence): StandaloneLicence
    {
        $licence->update(['status' => StandaloneLicence::ACTIVE]);

        return $licence->refresh();
    }

    /** Email the buyer their private link (queued; the link is made when the email is sent). */
    public function sendLink(StandaloneLicence $licence, string $reason = 'link'): void
    {
        try {
            Notification::route('mail', $licence->email)->notify(new StandaloneDownloadReady($licence->id, $reason));
        } catch (\Throwable $e) {
            Log::error('Standalone download email could not be sent', ['licence_id' => $licence->id, 'error' => $e->getMessage()]);
        }
    }

    /** The signed licence.json for $licence. @return array{licence:array<string,mixed>,key_id:string,signature:string} */
    public function document(StandaloneLicence $licence): array
    {
        return $this->signer->issue([
            'id' => $licence->id, 'customer' => $licence->customer, 'domains' => $licence->domains,
            'updates_until' => $licence->updates_until->toDateString(),
            'limits' => StandaloneProduct::normaliseLimits($licence->limits), 'white_label' => (bool) $licence->white_label,
        ]);
    }

    /** The newest release $licence may install that is newer than $version (any, for an empty or "dev" version). */
    public function latestFor(StandaloneLicence $licence, ?string $version = null): ?StandaloneRelease
    {
        $releases = $licence->entitledReleases()->get();
        if (is_string($version) && preg_match('/^\d+\.\d+\.\d+(?:-[a-z0-9.-]+)?$/i', $version)) {
            $releases = $releases->filter(fn (StandaloneRelease $r) => version_compare($r->version, $version, '>'));
        }

        return $releases->sort(fn (StandaloneRelease $a, StandaloneRelease $b) => version_compare($b->version, $a->version))->first();
    }

    /** The release's archive is on disk with the hash it was published with. */
    public function intact(StandaloneRelease $release): bool
    {
        return is_string($release->archive_path) && is_file($release->archive_path) && is_string($release->sha256)
            && hash_equals($release->sha256, (string) hash_file('sha256', $release->archive_path));
    }

    public function recordDownload(StandaloneLicence $licence, StandaloneRelease $release, ?string $ip): void
    {
        DB::table('standalone_downloads')->insert([
            'licence_id' => $licence->id, 'release_id' => $release->id, 'ip' => $ip, 'created_at' => now(),
        ]);
    }

    /** The installation guide, as shipped inside every ZIP (R9). */
    public static function guidePath(): string
    {
        return dirname(__DIR__, 2).'/resources/standalone/INSTALL.md';
    }

    /** @return array{order:StandaloneOrder,link:string} */
    private function charge(StandaloneOrder $order, string $redirectUrl, string $description): array
    {
        $buyer = $order->buyer;
        $result = app(FlutterwaveService::class)->initiatePayment([
            'tx_ref' => $order->reference, 'amount' => (float) $order->amount, 'currency' => $order->currency,
            'redirect_url' => $redirectUrl, 'payment_options' => app(BillingService::class)->paymentOptions($order->currency),
            'customer' => array_filter(['email' => $buyer['email'], 'name' => $buyer['name'] ?: $buyer['business'], 'phonenumber' => $buyer['phone'] ?? null]),
            'customizations' => ['title' => 'Budget Pro', 'description' => $description],
            'meta' => ['standalone_order' => $order->id, 'kind' => $order->kind],
        ]);
        if (! ($result['success'] ?? false) || empty($result['link'])) {
            $order->update(['status' => 'failed']);
            throw new \DomainException((string) ($result['message'] ?? 'The payment could not be started. Try again in a minute.'));
        }

        return ['order' => $order, 'link' => (string) $result['link']];
    }

    private function extendedUntil(StandaloneLicence $licence, int $months): string
    {
        $from = $licence->updates_until->isFuture() ? $licence->updates_until->copy() : now()->startOfDay();

        return $from->addMonthsNoOverflow($months)->toDateString();
    }

    /** @return array{business:string,name:?string,email:string,phone:?string,domain:string} */
    private function buyer(array $buyer): array
    {
        $business = trim((string) ($buyer['business'] ?? ''));
        $email = mb_strtolower(trim((string) ($buyer['email'] ?? '')));
        if ($business === '' || filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            throw new \DomainException('Enter the business name and a working email address.');
        }

        return [
            'business' => mb_substr($business, 0, 190), 'name' => trim((string) ($buyer['name'] ?? '')) ?: null,
            'email' => $email, 'phone' => trim((string) ($buyer['phone'] ?? '')) ?: null, 'domain' => $this->domain((string) ($buyer['domain'] ?? '')),
        ];
    }

    /** "shop.example.com": lower case, no scheme, path or trailing dot. */
    public function domain(string $domain): string
    {
        $domain = rtrim(mb_strtolower(trim($domain)), '.');
        if (! preg_match(self::DOMAIN_PATTERN, $domain)) {
            throw new \DomainException('Enter the address like shop.example.com, without https:// or a path.');
        }

        return $domain;
    }
}
