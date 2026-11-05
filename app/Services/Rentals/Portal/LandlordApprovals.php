<?php

namespace App\Services\Rentals\Portal;

use App\Exceptions\BusinessRuleException;
use App\Models\RentalExpense;
use App\Models\RentalLandlord;
use App\Models\RentalLogin;
use App\Models\RentalMaintenance;
use App\Models\RentalProperty;
use App\Models\RentalUnit;
use App\Models\User;
use App\Services\Rentals\Landlords\LandlordAccounts;
use App\Services\Rentals\Ledger\RentalLedger;
use App\Services\Rentals\Logins\RentalLogins;
use App\Services\Rentals\Messages\RentalMessages;
use App\Services\Rentals\RentalAccess;
use App\Services\Rentals\RentalAudit;
use App\Services\Rentals\RentalLinks;
use App\Services\Rentals\RentalsModule;

/**
 * A landlord's limit on repairs (LC-17, EL-28): "ask me above this". A repair's quote above the limit is put to the landlord, who answers on their own
 * page or tells the office, which writes down who said what and how. It warns and asks for a reason; it never blocks, because emergencies happen.
 * Nothing here moves money: nothing posts until the expense does (Expenses::record, which asks check() whether a reason is needed).
 *
 * Who sees what: the limit, the answer's note and a landlord's balance are between the office and the landlord, so a login tied to some properties gets
 * none of them (EL-20). An owner-run account has no landlord to ask.
 */
final class LandlordApprovals
{
    public const VIAS = ['portal' => 'on their own page', 'phone' => 'by phone', 'whatsapp' => 'on WhatsApp', 'other' => 'another way'];

    public const STAFF_VIAS = ['phone', 'whatsapp', 'other'];

    /**
     * What an amount charged to a property's landlord means: their limit (null: none set), whether the amount is above it, whether their answer on this
     * repair covers it, whether the office must give a reason, and their balance after it.
     *
     * @return array{landlord_id: ?int, landlord: string, limit_minor: ?int, above_limit: bool, agreed: bool, needs_reason: bool, balance_after_minor: int}
     */
    public static function check(int $companyId, ?int $propertyId, int $amount, ?int $maintenanceId = null): array
    {
        $none = ['landlord_id' => null, 'landlord' => '', 'limit_minor' => null, 'above_limit' => false, 'agreed' => false, 'needs_reason' => false, 'balance_after_minor' => 0];
        if ($propertyId === null || RentalsModule::isOwnerMode($companyId)) {
            return $none;
        }
        $property = RentalProperty::withoutGlobalScopes()->where('company_id', $companyId)->find($propertyId);
        $landlord = $property?->landlord_id === null ? null : RentalLandlord::withoutGlobalScopes()->where('company_id', $companyId)->find($property->landlord_id);
        if ($landlord === null) {
            return $none;
        }
        $limit = $landlord->expense_limit_minor;
        $above = $limit !== null && $amount > $limit;
        $ticket = $maintenanceId === null ? null : RentalMaintenance::withoutGlobalScopes()->where('company_id', $companyId)->where('property_id', $propertyId)->find($maintenanceId);
        $agreed = $ticket !== null && self::agrees($ticket, (int) $landlord->id, $amount);

        return ['landlord_id' => (int) $landlord->id, 'landlord' => (string) $landlord->name, 'limit_minor' => $limit, 'above_limit' => $above, 'agreed' => $agreed,
            'needs_reason' => $above && ! $agreed, 'balance_after_minor' => LandlordAccounts::balance($landlord) - $amount];
    }

    /** Whether this landlord said yes to this much. A yes covers the figure they were asked about, and only theirs: not more, and not a new landlord's after a handover. */
    public static function agrees(RentalMaintenance $m, int $landlordId, int $amount): bool
    {
        return $m->approval_status === 'yes' && (int) $m->approval_landlord_id === $landlordId && $m->quote_minor !== null && $amount <= (int) $m->quote_minor;
    }

    /** Whether a repair's quote is above the limit with no yes from this landlord: the office can ask, or ask again. */
    public static function needsAnswer(RentalMaintenance $m): bool
    {
        if ($m->quote_minor === null || in_array($m->status, ['done', 'cancelled'], true)) {
            return false;
        }

        return self::check((int) $m->company_id, (int) $m->property_id, (int) $m->quote_minor, (int) $m->id)['needs_reason'];
    }

    /**
     * The message that puts a repair to its landlord, ready for someone's own phone (KR-18): nothing leaves the server, here or in ask().
     *
     * @return array{text: string, whatsapp: ?string, sms: ?string, phone: ?string, landlord: string, has_sign_in: bool}
     */
    public static function message(User $by, RentalMaintenance $m, ?string $portalLink): array
    {
        self::assertOffice($by, $m);
        $cid = (int) $m->company_id;
        $landlord = self::landlordOf($m);
        $login = RentalLogins::forSubject($cid, 'landlord', (int) $landlord->id);
        $signIn = $login !== null && app(RentalLogins::class)->resolve((int) $login->id) !== null;
        $link = $signIn && $portalLink !== null ? 'Answer here: '.$portalLink : 'Please reply to this message or call us.';
        $property = (string) RentalProperty::withoutGlobalScopes()->where('company_id', $cid)->whereKey($m->property_id)->value('name');
        $unit = $m->unit_id === null ? 'the building' : (string) RentalUnit::withoutGlobalScopes()->where('company_id', $cid)->whereKey($m->unit_id)->value('code');
        $photos = array_values(array_filter((array) $m->photos, fn ($u) => is_string($u) && $u !== ''));
        $photo = $photos === [] ? null : (RentalLinks::files($cid, [$photos[0]], 'staff', (int) $by->id)[$photos[0]] ?? null);
        $text = RentalMessages::forApproval($cid, (string) $landlord->name, $unit, $property, (string) $m->title, (int) $m->quote_minor, $link, $photo);
        $phone = trim((string) $landlord->phone_e164) ?: null;

        return ['text' => $text, 'whatsapp' => RentalMessages::whatsappLink($phone, $text), 'sms' => RentalMessages::smsLink($phone, $text), 'phone' => $phone, 'landlord' => (string) $landlord->name, 'has_sign_in' => $signIn];
    }

    /** The question has been put: it shows on the landlord's own page as waiting for their answer. */
    public function ask(User $by, RentalMaintenance $m): RentalMaintenance
    {
        self::assertOffice($by, $m);
        $cid = (int) $m->company_id;

        return RentalLedger::locked($cid, function () use ($by, $m, $cid) {
            $t = $this->lockedOpen($cid, (int) $m->id);
            if (! self::needsAnswer($t)) {
                throw BusinessRuleException::make('nothing_to_ask', 'This quote does not need the landlord\'s answer.');
            }
            $landlord = self::landlordOf($t);
            $t->forceFill(['approval_status' => 'asked', 'approval_at' => null, 'approval_by_id' => $by->id, 'approval_via' => null, 'approval_note' => null, 'approval_landlord_id' => $landlord->id])->save();
            RentalAudit::record($cid, 'maintenance.approval_asked', $t, "Asked {$landlord->name} about {$t->number}", ['quote_minor' => (int) $t->quote_minor], 'staff', $by->id);

            return $t->fresh();
        });
    }

    /** The office writes down an answer it had another way: yes or no, how, and who said it. Audited, since many landlords will not sign in. */
    public function record(User $by, RentalMaintenance $m, bool $yes, string $via, string $who, ?string $note = null): RentalMaintenance
    {
        self::assertOffice($by, $m);
        if (! in_array($via, self::STAFF_VIAS, true)) {
            throw BusinessRuleException::make('via_invalid', 'Say how they answered: by phone, on WhatsApp or another way.');
        }
        $who = trim($who);
        if ($who === '') {
            throw BusinessRuleException::make('who_required', 'Say who gave the answer.');
        }
        $note = trim((string) $note);
        $cid = (int) $m->company_id;

        return RentalLedger::locked($cid, function () use ($by, $m, $yes, $via, $who, $note, $cid) {
            $t = $this->lockedOpen($cid, (int) $m->id);
            if ($t->quote_minor === null) {
                throw BusinessRuleException::make('nothing_to_ask', 'Add the quote first: that is what they are answering.');
            }
            $landlord = self::landlordOf($t);
            $t->forceFill(['approval_status' => $yes ? 'yes' : 'no', 'approval_via' => $via, 'approval_note' => mb_substr($who.($note !== '' ? ': '.$note : ''), 0, 500), 'approval_at' => now(),
                'approval_by_id' => $by->id, 'approval_landlord_id' => $landlord->id])->save();
            RentalAudit::record($cid, 'maintenance.approval_recorded', $t, ($yes ? 'Recorded a yes' : 'Recorded a no').' from '.$who.' on '.$t->number.' ('.self::VIAS[$via].')',
                ['answer' => $yes ? 'yes' : 'no', 'via' => $via, 'quote_minor' => (int) $t->quote_minor], 'staff', $by->id);

            return $t->fresh();
        });
    }

    /** A landlord answers from their own page. A repair not at their properties is not found; one the office has already settled says so. */
    public function answer(RentalLogin $login, int $ticketId, bool $yes, ?string $note = null): RentalMaintenance
    {
        if ($login->subject_type !== 'landlord') {
            throw BusinessRuleException::make('not_found', 'That repair was not found.');
        }
        $cid = (int) $login->company_id;
        $note = trim((string) $note);
        if (! $yes && $note === '') {
            throw BusinessRuleException::make('note_required', 'Say why not, in a few words, so the office knows what to do.');
        }

        return RentalLedger::locked($cid, function () use ($login, $ticketId, $yes, $note, $cid) {
            $t = RentalMaintenance::withoutGlobalScopes()->where('company_id', $cid)->whereIn('property_id', self::ownProperties($login))->lockForUpdate()->find($ticketId)
                ?? throw BusinessRuleException::make('not_found', 'That repair was not found.');
            if ($t->approval_status !== 'asked' || in_array($t->status, ['done', 'cancelled'], true)) {
                throw BusinessRuleException::make('not_waiting', 'This repair is not waiting for your answer any more.');
            }
            $t->forceFill(['approval_status' => $yes ? 'yes' : 'no', 'approval_via' => 'portal', 'approval_note' => $note !== '' ? mb_substr($note, 0, 500) : null, 'approval_at' => now(),
                'approval_by_id' => $login->id, 'approval_landlord_id' => (int) $login->subject_id])->save();
            RentalAudit::record($cid, 'maintenance.approval_answered', $t, ($yes ? 'The landlord said yes to ' : 'The landlord said no to ').$t->number, ['answer' => $yes ? 'yes' : 'no', 'quote_minor' => (int) $t->quote_minor], 'landlord', (int) $login->subject_id);

            return $t->fresh();
        });
    }

    /**
     * The repairs waiting for this landlord's answer, oldest first. What a landlord needs to decide: what, where, the quote, the details, any photo.
     *
     * @return list<array<string, mixed>>
     */
    public static function waiting(PersonView $me): array
    {
        $cid = $me->companyId();
        $rows = RentalMaintenance::withoutGlobalScopes()->where('company_id', $cid)->whereIn('property_id', self::ownProperties($me->login))->where('approval_status', 'asked')
            ->whereIn('status', ['open', 'in_progress'])->orderBy('id')->limit(100)->get();
        $present = $me->present()->prime($rows->all());

        return $rows->map(fn (RentalMaintenance $m) => ['quote_minor' => (int) $m->quote_minor] + array_intersect_key($present->maintenance($m), array_flip(['id', 'number', 'property', 'unit', 'title', 'details', 'photos', 'created_at'])))->values()->all();
    }

    /**
     * What a landlord reads beside their repairs: repair id => "You said yes on 3 Nov 2026". Only about their own answer, or the office's note of it.
     *
     * @param  iterable<array<string, mixed>>  $repairs  PersonView::repairs rows
     * @return array<int, array{word: string, note: ?string, quote_minor: ?int}>
     */
    public static function answersFor(PersonView $me, iterable $repairs): array
    {
        $ids = [];
        foreach ($repairs as $r) {
            $ids[] = (int) $r['id'];
        }
        $out = [];
        foreach (RentalMaintenance::withoutGlobalScopes()->where('company_id', $me->companyId())->whereIn('id', $ids ?: [0])->where('approval_landlord_id', (int) $me->login->subject_id)->whereIn('approval_status', ['yes', 'no'])->get() as $m) {
            $day = $m->approval_at?->format('j M Y');
            $said = $m->approval_status === 'yes' ? 'You said yes' : 'You said no';
            $out[(int) $m->id] = ['word' => $said.($m->approval_via === 'portal' ? '' : ' '.(self::VIAS[$m->approval_via] ?? '')).' on '.$day,
                'note' => $m->approval_via === 'portal' ? $m->approval_note : null, 'quote_minor' => $m->quote_minor];
        }

        return $out;
    }

    /**
     * What a landlord reads beside an expense: expense id => "You agreed on 3 Nov" or "Above your limit: the office's reason: …". Nothing for the rest.
     *
     * @param  iterable<array<string, mixed>>  $expenses  PersonView::expenses rows
     * @return array<int, string>
     */
    public static function expenseWords(PersonView $me, iterable $expenses): array
    {
        $ids = [];
        foreach ($expenses as $e) {
            $ids[] = (int) $e['id'];
        }
        $cid = $me->companyId();
        $landlordId = (int) $me->login->subject_id;
        $rows = RentalExpense::withoutGlobalScopes()->where('company_id', $cid)->where('landlord_id', $landlordId)->whereIn('id', $ids ?: [0])->get(['id', 'amount_minor', 'maintenance_id', 'over_limit_reason']);
        $tickets = RentalMaintenance::withoutGlobalScopes()->where('company_id', $cid)->whereIn('id', $rows->pluck('maintenance_id')->filter()->all() ?: [0])->get()->keyBy('id');
        $out = [];
        foreach ($rows as $e) {
            $t = $e->maintenance_id !== null ? $tickets->get($e->maintenance_id) : null;
            if ($t !== null && self::agrees($t, $landlordId, (int) $e->amount_minor)) {
                $out[(int) $e->id] = 'You agreed on '.$t->approval_at?->format('j M Y');
            } elseif (trim((string) $e->over_limit_reason) !== '') {
                $out[(int) $e->id] = 'Above your limit: the office\'s reason: '.$e->over_limit_reason;
            }
        }

        return $out;
    }

    /** Asking and writing down answers is the office's: the landlord's terms and phone stay away from a login tied to some properties, whatever its role was given. */
    private static function assertOffice(User $by, RentalMaintenance $m): void
    {
        RentalAccess::authorize($by, 'rentals_maintenance');
        RentalAccess::authorize($by, 'rentals_landlords');
        if (RentalAccess::propertyIds($by) !== null || (int) $by->company_id !== (int) $m->company_id) {
            throw BusinessRuleException::make('forbidden', 'Your role does not allow this. Ask your property manager.');
        }
        if (RentalsModule::isOwnerMode((int) $m->company_id)) {
            throw BusinessRuleException::make('agency_only', 'An owner-run account has no landlord to ask.');
        }
    }

    private function lockedOpen(int $companyId, int $id): RentalMaintenance
    {
        $t = RentalMaintenance::withoutGlobalScopes()->where('company_id', $companyId)->lockForUpdate()->find($id)
            ?? throw BusinessRuleException::make('not_found', 'That repair was not found.');
        if (in_array($t->status, ['done', 'cancelled'], true)) {
            throw BusinessRuleException::make('ticket_closed', 'This request is closed.');
        }

        return $t;
    }

    private static function landlordOf(RentalMaintenance $m): RentalLandlord
    {
        $id = RentalProperty::withoutGlobalScopes()->where('company_id', $m->company_id)->whereKey($m->property_id)->value('landlord_id');

        return RentalLandlord::withoutGlobalScopes()->where('company_id', $m->company_id)->find($id)
            ?? throw BusinessRuleException::make('not_found', 'That property has no landlord.');
    }

    /** @return list<int> the properties of a landlord's own sign-in */
    private static function ownProperties(RentalLogin $login): array
    {
        return array_map('intval', RentalProperty::withoutGlobalScopes()->where('company_id', $login->company_id)->where('landlord_id', $login->subject_id)->pluck('id')->all()) ?: [0];
    }
}
