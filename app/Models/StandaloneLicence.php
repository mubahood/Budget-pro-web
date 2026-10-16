<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

/**
 * A licence to run one standalone installation (docs/STANDALONE_EDITION_PLAN.md §7.2, §11). SaaS only.
 * The uuid is the credential the update feed takes; licence.json is signed from these facts.
 *
 * A licence is entitled to every published release whose published_at falls on or before the end of
 * its updates_until day. Revoking it stops downloads and the update feed; the installed copy keeps working.
 *
 * @property string $id
 * @property int $product_id
 * @property string $customer
 * @property string|null $contact_name
 * @property string $email
 * @property string|null $phone
 * @property list<string> $domains
 * @property array<string,int|null>|null $limits
 * @property bool $white_label
 * @property \Illuminate\Support\Carbon $updates_until
 * @property string $status active | revoked
 * @property string $source online | offline
 * @property string|null $reference
 * @property \Illuminate\Support\Carbon|null $domain_changed_at
 * @property \Illuminate\Support\Carbon|null $last_link_sent_at
 * @property-read StandaloneProduct|null $product
 */
class StandaloneLicence extends Model
{
    public const ACTIVE = 'active';

    public const REVOKED = 'revoked';

    /** A buyer may move their licence to another address themselves once in this many days. */
    public const DOMAIN_CHANGE_DAYS = 30;

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'id', 'product_id', 'customer', 'contact_name', 'email', 'phone', 'domains', 'limits', 'white_label',
        'updates_until', 'status', 'source', 'reference', 'domain_changed_at', 'last_link_sent_at',
    ];

    protected $casts = [
        'domains' => 'array', 'limits' => 'array', 'white_label' => 'boolean', 'updates_until' => 'date',
        'domain_changed_at' => 'datetime', 'last_link_sent_at' => 'datetime',
    ];

    protected static function booted(): void
    {
        static::creating(function (self $licence): void {
            $licence->id ??= (string) Str::uuid();
        });
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(StandaloneProduct::class, 'product_id');
    }

    public function orders(): HasMany
    {
        return $this->hasMany(StandaloneOrder::class, 'licence_id');
    }

    public function isActive(): bool
    {
        return $this->status === self::ACTIVE;
    }

    public function domain(): string
    {
        return (string) ($this->domains[0] ?? '');
    }

    public function updatesEnded(): bool
    {
        return $this->updates_until->copy()->endOfDay()->isPast();
    }

    public function canChangeDomain(): bool
    {
        return $this->domain_changed_at === null || $this->domain_changed_at->lte(now()->subDays(self::DOMAIN_CHANGE_DAYS));
    }

    public function nextDomainChangeAt(): ?\Illuminate\Support\Carbon
    {
        return $this->canChangeDomain() ? null : $this->domain_changed_at->copy()->addDays(self::DOMAIN_CHANGE_DAYS);
    }

    /** Published releases this licence may download, newest first. */
    public function entitledReleases(): Builder
    {
        return StandaloneRelease::query()->available()
            ->where('published_at', '<=', $this->updates_until->copy()->endOfDay())
            ->orderByDesc('published_at')->orderByDesc('id');
    }

    public function entitles(StandaloneRelease $release): bool
    {
        return $this->isActive() && $release->status === StandaloneRelease::PUBLISHED && $release->published_at !== null
            && $release->published_at->lte($this->updates_until->copy()->endOfDay());
    }

    /** An active licence by its id, or null for anything else (a malformed id never reaches the database). */
    public static function activeById(mixed $id): ?self
    {
        return is_string($id) && Str::isUuid($id) ? self::query()->whereKey($id)->where('status', self::ACTIVE)->first() : null;
    }
}
