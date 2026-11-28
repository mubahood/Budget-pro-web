<?php

namespace App\Services\Unity;

use App\Models\Company;
use App\Models\SaccoMember;
use App\Models\UnityCategory;
use App\Models\UnityChapter;
use App\Models\UnitySetting;
use App\Support\LocalDate;

/**
 * The member card's QR: a signed token that names the member (their uuid) and cannot be made up or changed. Anyone may
 * check it on the public verify page; it says only the name, number, category, chapter code and whether dues are paid.
 * The signature is keyed from APP_KEY, so rotating the key makes printed cards stop verifying (print them again).
 */
final class CardTokens
{
    private const VERSION = 'unity-card v1';

    public static function token(SaccoMember $m): string
    {
        $id = str_replace('-', '', (string) $m->uuid);

        return $id.self::sign($id);
    }

    public static function verifyUrl(SaccoMember $m): string
    {
        return config('unity.verify_base').'/v/'.self::token($m);
    }

    /** The member a token names, or null when it is bad (wrong length, forged, unknown or deleted member). */
    public static function member(string $token): ?SaccoMember
    {
        $token = strtolower(trim($token));
        if (! preg_match('/^[0-9a-f]{32}[0-9a-f]{16}$/', $token)) {
            return null;
        }
        $id = substr($token, 0, 32);
        if (! hash_equals(self::sign($id), substr($token, 32))) {
            return null;
        }
        $uuid = substr($id, 0, 8).'-'.substr($id, 8, 4).'-'.substr($id, 12, 4).'-'.substr($id, 16, 4).'-'.substr($id, 20);

        return SaccoMember::withoutGlobalScopes()->where('uuid', $uuid)->where('is_deleted', 0)->whereNull('merged_into_id')->first();
    }

    /** @return array<string, mixed> the card (GET members/{id}/card, me/card) */
    public static function card(SaccoMember $m): array
    {
        $chapter = $m->chapter_id ? UnityChapter::withoutGlobalScopes()->find($m->chapter_id) : null;

        return ['member_no' => $m->member_no, 'name' => $m->full_name, 'photo_url' => UnityLinks::photo((int) $m->company_id, $m->photo_file_id ? (int) $m->photo_file_id : null),
            'category' => $m->category_id ? UnityCategory::withoutGlobalScopes()->whereKey($m->category_id)->value('name') : null,
            'chapter_code' => $chapter?->code, 'chapter' => $chapter?->name, 'status' => $m->status, 'standing' => Memberships::standingOf($m),
            'membership_ends_on' => $m->membership_ends_on?->toDateString(), 'token' => self::token($m), 'verify_url' => self::verifyUrl($m)];
    }

    /**
     * What the public verify page shows, or null (a bad token, a member of another kind of account, or a group that turned
     * the page off). state: paid_up (a current member in good standing or for life), lapsed (a current member whose dues
     * ran out) or not_current (applicant, suspended, left, deceased, rejected).
     *
     * @return array<string, mixed>|null
     */
    public static function verify(string $token): ?array
    {
        $m = self::member($token);
        if ($m === null) {
            return null;
        }
        $company = Company::withoutGlobalScopes()->find($m->company_id);
        if ($company === null || ! Memberships::isAssociation($company) || ! UnitySetting::for((int) $company->id)->verify_page) {
            return null;
        }
        $standing = Memberships::standingOf($m, LocalDate::today((int) $company->id));
        $state = ! in_array($m->status, UnityMembers::CURRENT, true) ? 'not_current'
            : (in_array($standing, [Memberships::GOOD, Memberships::LIFE], true) ? 'paid_up' : 'lapsed');
        $card = self::card($m);

        return ['group' => (string) $company->name, 'name' => $card['name'], 'photo_url' => $card['photo_url'], 'member_no' => $card['member_no'],
            'category' => $card['category'], 'chapter_code' => $card['chapter_code'], 'state' => $state,
            'membership_ends_on' => $standing === Memberships::LIFE ? null : $card['membership_ends_on'], 'life' => $standing === Memberships::LIFE];
    }

    private static function sign(string $id): string
    {
        $key = hash('sha256', 'unity-card|'.config('app.key'), true);

        return substr(hash_hmac('sha256', self::VERSION.'|'.$id, $key), 0, 16);
    }
}
