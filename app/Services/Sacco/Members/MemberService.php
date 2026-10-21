<?php

namespace App\Services\Sacco\Members;

use App\Exceptions\BusinessRuleException;
use App\Models\Company;
use App\Models\SaccoMember;
use App\Models\SaccoMemberDocument;
use App\Models\SaccoMemberRelation;
use App\Models\SaccoSetting;
use App\Models\StoredFile;
use App\Models\User;
use App\Services\Billing\Quotas;
use App\Services\Sacco\SaccoAccess;
use App\Services\Sacco\SaccoAudit;
use App\Services\Shop\NumberSequencer;
use App\Support\LocalDate;
use App\Support\Phone;
use App\Support\Rules\SaccoMemberRules;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * A SACCO's members (docs/akiba/04-architecture.md §4, P1.9): register (within the plan's member allowance; money is
 * never refused for a plan, ADR 0005), update, verify identity (a second officer, never the one who captured the
 * member: AK-22), change status, nominate (shares that add up to 100 % and two witnesses), import a CSV (a dry run
 * first; a row imported twice is skipped) and find likely twins (flagged, never refused: ADR 0007).
 *
 * The NIN is stored through Nin (encrypted, hashed, last four shown) and never returned; revealNin() decrypts it for
 * an officer and records the view (`sacco.sensitive_view`).
 */
final class MemberService
{
    /** The consent text's version stored with each member's consent (AK-47). */
    public const CONSENT_VERSION = '2026-10';

    /** Status changes a member may go through. */
    public const TRANSITIONS = [
        'applicant' => ['active', 'rejected'],
        'active' => ['dormant', 'suspended', 'exited', 'deceased'],
        'dormant' => ['active', 'suspended', 'exited', 'deceased'],
        'suspended' => ['active', 'exited', 'deceased'],
        'exited' => [],
        'deceased' => [],
        'rejected' => [],
    ];

    /** @var array<string, callable(Company, SaccoMember): void> what activation needs from other modules (P1.11: entrance fee, min shares) */
    private static array $activationChecks = [];

    /** @var array<string, callable(Company, SaccoMember, list<array<string, mixed>>): void> what a nomination needs (P1.11: shares) */
    private static array $nominationChecks = [];

    /** @param  callable(Company, SaccoMember): void  $check */
    public static function activationCheck(string $key, callable $check): void
    {
        self::$activationChecks[$key] = $check;
    }

    /** @param  callable(Company, SaccoMember, list<array<string, mixed>>): void  $check */
    public static function nominationCheck(string $key, callable $check): void
    {
        self::$nominationChecks[$key] = $check;
    }

    /**
     * @param  array<string, mixed>  $data  SaccoMemberRules::rules()
     * @param  array{import_key?: string, status?: string}  $options
     * @return array{member: SaccoMember, duplicates: list<array{id: int, member_no: ?string, name: string, match: string}>}
     */
    public function register(Company $company, array $data, ?User $by, array $options = []): array
    {
        $this->assertSacco($company);
        $clean = $this->validate($company, $data, true);
        app(Quotas::class)->assertCanAdd($company, 'members');
        $settings = SaccoSetting::for((int) $company->id);
        $status = $options['status'] ?? ($settings?->isGroup() ? 'active' : 'applicant');

        $member = DB::transaction(function () use ($company, $clean, $by, $options, $status) {
            $member = SaccoMember::create($clean + [
                'company_id' => $company->id, 'member_no' => NumberSequencer::next((int) $company->id, 'mem'),
                'joined_on' => $clean['joined_on'] ?? LocalDate::today((int) $company->id)->toDateString(),
                'status' => $status, 'status_changed_on' => LocalDate::today((int) $company->id)->toDateString(), 'kyc_status' => 'pending',
                'import_key' => $options['import_key'] ?? null, 'created_by_id' => $by?->id, 'updated_by_id' => $by?->id,
            ]);

            return $member;
        });

        return ['member' => $member, 'duplicates' => $this->twinsOf($member)];
    }

    /** @param  array<string, mixed>  $data */
    public function update(Company $company, int $memberId, array $data, User $by): SaccoMember
    {
        $this->assertSacco($company);
        $clean = $this->validate($company, $data, false);

        return DB::transaction(function () use ($company, $memberId, $clean, $by) {
            $member = $this->find($company, $memberId, true);
            if (isset($clean['nin_hash']) && $clean['nin_hash'] !== $member->nin_hash && $member->kyc_status === 'verified') {
                $clean['kyc_status'] = 'pending'; // a new ID number is checked again by a second officer
                $clean['kyc_verified_by_id'] = null;
                $clean['kyc_verified_at'] = null;
            }
            $member->fill($clean + ['updated_by_id' => $by->id])->save();

            return $member;
        });
    }

    /**
     * Verify (or reject) a member's identity: an officer with sacco_kyc who did not capture the member (AK-22). In
     * office mode this runs as the approved `member_kyc` request (SaccoApprovals); a group verifies directly.
     */
    public function verifyKyc(Company $company, int $memberId, User $checker, string $decision, ?string $note = null): SaccoMember
    {
        if (! in_array($decision, ['verified', 'rejected'], true)) {
            throw BusinessRuleException::make('invalid_decision', 'Verify or reject.');
        }

        return DB::transaction(function () use ($company, $memberId, $checker, $decision, $note) {
            $member = $this->find($company, $memberId, true);
            $self = SaccoAccess::assertChecker($checker, $member->created_by_id === null ? null : (int) $member->created_by_id, 'sacco_kyc');
            if ($decision === 'verified' && $member->nin_hash === null && $member->kind === 'individual') {
                throw BusinessRuleException::make('nin_required', 'Add the member\'s ID number before verifying it.');
            }
            $member->fill(['kyc_status' => $decision, 'kyc_verified_by_id' => $checker->id, 'kyc_verified_at' => now(),
                'kyc_note' => $note === null ? null : mb_substr($note, 0, 255), 'updated_by_id' => $checker->id])->save();
            SaccoAudit::record('sacco.member.kyc_'.$decision, $member, (int) $company->id, ['self_approved' => $self], (int) $checker->id);

            return $member;
        });
    }

    public function setStatus(Company $company, int $memberId, string $status, User $by, ?string $reason = null): SaccoMember
    {
        return DB::transaction(function () use ($company, $memberId, $status, $by, $reason) {
            $member = $this->find($company, $memberId, true);
            if (! in_array($status, self::TRANSITIONS[$member->status] ?? [], true)) {
                throw BusinessRuleException::make('invalid_status_change', "A member who is {$member->status} cannot become {$status}.");
            }
            if (in_array($status, ['exited', 'deceased', 'rejected', 'suspended'], true) && blank($reason)) {
                throw BusinessRuleException::make('reason_required', 'Say why: it is kept in the register.');
            }
            if ($status === 'active' && $member->status === 'applicant') {
                $settings = SaccoSetting::for((int) $company->id);
                if ($settings !== null && ! $settings->isGroup() && $member->kyc_status !== 'verified') {
                    throw BusinessRuleException::make('kyc_required', 'Verify the member\'s identity first (a second officer checks it).');
                }
                foreach (self::$activationChecks as $check) {
                    $check($company, $member);
                }
            }
            $member->fill(['status' => $status, 'status_changed_on' => LocalDate::today((int) $company->id)->toDateString(),
                'exit_reason' => in_array($status, ['exited', 'deceased', 'rejected', 'suspended'], true) ? mb_substr((string) $reason, 0, 255) : $member->exit_reason,
                'updated_by_id' => $by->id])->save();

            return $member;
        });
    }

    /**
     * Replace a member's nominees (Regs 1992 reg. 10): their portions add up to 100 % (10,000 bp), and two witnesses
     * attest the nomination. The old nominees are ended, never deleted.
     *
     * @param  list<array{name: string, relationship?: ?string, phone?: ?string, nin?: ?string, share_bp: int}>  $nominees
     * @return list<SaccoMemberRelation>
     */
    public function nominate(Company $company, int $memberId, array $nominees, string $witness1, string $witness2, ?string $signedOn, User $by, ?int $documentFileId = null): array
    {
        if ($nominees === []) {
            throw BusinessRuleException::make('invalid_nomination', 'Name at least one nominee.');
        }
        foreach ($nominees as $i => $n) {
            $v = validator($n, SaccoMemberRules::nominee());
            if ($v->fails()) {
                throw BusinessRuleException::make('invalid_nomination', 'Nominee '.($i + 1).': '.$v->errors()->first());
            }
        }
        if (array_sum(array_map(fn ($n) => (int) $n['share_bp'], $nominees)) !== 10000) {
            throw BusinessRuleException::make('nomination_total', 'The nominees\' portions must add up to 100 %.');
        }
        if (blank($witness1) || blank($witness2) || mb_strtolower(trim($witness1)) === mb_strtolower(trim($witness2))) {
            throw BusinessRuleException::make('witnesses_required', 'Two different witnesses sign a nomination.');
        }

        return DB::transaction(function () use ($company, $memberId, $nominees, $witness1, $witness2, $signedOn, $by, $documentFileId) {
            $member = $this->find($company, $memberId, true);
            foreach (self::$nominationChecks as $check) {
                $check($company, $member, $nominees);
            }
            $today = LocalDate::today((int) $company->id)->toDateString();
            SaccoMemberRelation::withoutGlobalScopes()->where('company_id', $company->id)->where('member_id', $member->id)->where('role', 'nominee')
                ->whereNull('ended_on')->get()->each(fn (SaccoMemberRelation $r) => $r->update(['ended_on' => $today]));
            $out = [];
            foreach ($nominees as $n) {
                $out[] = SaccoMemberRelation::create(['company_id' => $company->id, 'member_id' => $member->id, 'role' => 'nominee', 'name' => trim($n['name']),
                    'relationship' => $n['relationship'] ?? null, 'phone_e164' => $this->phone($company, $n['phone'] ?? null),
                    'nin_encrypted' => blank($n['nin'] ?? null) ? null : Nin::columns((string) $n['nin'])['nin_encrypted'], 'share_bp' => (int) $n['share_bp'],
                    'witness1_name' => trim($witness1), 'witness2_name' => trim($witness2), 'signed_on' => $signedOn ?? $today,
                    'document_file_id' => $this->ownFile($company, $documentFileId), 'created_by_id' => $by->id]);
            }

            return $out;
        });
    }

    /** Add a next of kin (the old one stays until ended). */
    public function addNextOfKin(Company $company, int $memberId, string $name, ?string $relationship, ?string $phone, User $by): SaccoMemberRelation
    {
        $member = $this->find($company, $memberId);
        if (blank($name)) {
            throw BusinessRuleException::make('name_required', 'Enter the next of kin\'s name.');
        }

        return SaccoMemberRelation::create(['company_id' => $company->id, 'member_id' => $member->id, 'role' => 'next_of_kin', 'name' => trim($name),
            'relationship' => $relationship, 'phone_e164' => $this->phone($company, $phone), 'created_by_id' => $by->id]);
    }

    /** End a nominee or next of kin (replaced, never deleted). */
    public function endRelation(Company $company, int $relationId, User $by): SaccoMemberRelation
    {
        $r = SaccoMemberRelation::withoutGlobalScopes()->where('company_id', $company->id)->find($relationId)
            ?? throw BusinessRuleException::make('not_found', 'That record is not in this SACCO.');
        $r->update(['ended_on' => LocalDate::today((int) $company->id)->toDateString()]);

        return $r;
    }

    /** Link an uploaded file (StoredFile, counted against storage at upload) to a member. ID scans need the member's consent (AK-31). */
    public function attachDocument(Company $company, int $memberId, string $type, int $fileId, User $by, ?string $notes = null): SaccoMemberDocument
    {
        if (! in_array($type, SaccoMemberDocument::TYPES, true)) {
            throw BusinessRuleException::make('invalid_document', 'Choose the kind of document.');
        }
        $member = $this->find($company, $memberId);
        if (in_array($type, SaccoMemberDocument::SENSITIVE, true) && $member->consent_data_at === null) {
            throw BusinessRuleException::make('consent_required', 'Record the member\'s consent before keeping a copy of their ID.');
        }
        $doc = SaccoMemberDocument::create(['company_id' => $company->id, 'member_id' => $member->id, 'type' => $type, 'file_id' => $this->ownFile($company, $fileId),
            'captured_by_id' => $by->id, 'notes' => $notes]);
        if (in_array($type, ['photo', 'signature'], true)) {
            $member->fill([$type.'_file_id' => $fileId])->save();
        }

        return $doc;
    }

    /** The member's NIN in full, for an officer who may see it; every reveal is recorded (sacco.sensitive_view). */
    public function revealNin(Company $company, int $memberId, User $by): ?string
    {
        if (! SaccoAccess::can($by, 'sacco_members') && ! SaccoAccess::can($by, 'sacco_kyc') && ! SaccoAccess::can($by, 'sacco_audit_view')) {
            throw BusinessRuleException::make('forbidden', 'Your role does not allow this. Ask the SACCO\'s manager.', ['permission' => 'sacco_members']);
        }
        $member = $this->find($company, $memberId);
        SaccoAudit::record('sacco.sensitive_view', $member, (int) $company->id, ['what' => 'nin', 'nin' => $member->nin_masked], (int) $by->id);

        return Nin::reveal($member->nin_encrypted);
    }

    /** Record that an officer opened an ID document (the download itself is the portal's). */
    public function documentViewed(Company $company, SaccoMemberDocument $doc, User $by): void
    {
        if (in_array($doc->type, SaccoMemberDocument::SENSITIVE, true)) {
            SaccoAudit::record('sacco.sensitive_view', $doc, (int) $company->id, ['what' => $doc->type, 'member_id' => $doc->member_id], (int) $by->id);
        }
    }

    /**
     * Likely twins (ADR 0007): members sharing an ID number, a phone, or a name and date of birth. Flagged for a
     * merge by an officer, never refused.
     *
     * @return list<array{match: string, members: list<array{id: int, member_no: ?string, name: string}>}>
     */
    public function duplicates(Company $company): array
    {
        $base = fn () => SaccoMember::withoutGlobalScopes()->where('company_id', $company->id)->where('is_deleted', 0)->whereNull('merged_into_id')
            ->whereNotIn('status', ['exited', 'deceased', 'rejected']);
        $out = [];
        foreach (['nin' => ['nin_hash'], 'phone' => ['phone_e164'], 'name_dob' => ['first_name', 'last_name', 'date_of_birth']] as $match => $cols) {
            $q = $base();
            foreach ($cols as $c) {
                $q->whereNotNull($c);
            }
            $keys = $q->groupBy($cols)->havingRaw('COUNT(*) > 1')->select($cols)->get();
            foreach ($keys as $k) {
                $rows = $base();
                foreach ($cols as $c) {
                    $rows->where($c, $k->{$c});
                }
                $out[] = ['match' => $match, 'members' => $rows->orderBy('id')->get()->map(fn (SaccoMember $m) => ['id' => (int) $m->id, 'member_no' => $m->member_no, 'name' => $m->full_name])->all()];
            }
        }

        return $out;
    }

    /**
     * Import members from a CSV (a header row, then one member per row: first_name, last_name, other_names, gender,
     * date_of_birth, nin, phone, email, address, district, occupation, employer_name, payroll_no, joined_on, consent).
     * A dry run says what would happen; a row already imported (same names, phone, ID and birth date) is skipped,
     * so importing a file twice adds nobody. The plan's member allowance is checked for the whole file.
     *
     * @return array{dry_run: bool, rows: int, created: int, skipped: list<array{row: int, reason: string}>, errors: list<array{row: int, message: string}>, duplicates: list<array{row: int, member_no: ?string}>}
     */
    public function import(Company $company, string $csv, User $by, bool $dryRun = true): array
    {
        $this->assertSacco($company);
        $lines = array_values(array_filter(preg_split('/\r\n|\n|\r/', trim($csv)) ?: [], fn ($l) => trim($l) !== ''));
        if (count($lines) < 2) {
            throw BusinessRuleException::make('import_empty', 'The file needs a header row and at least one member.');
        }
        $header = array_map(fn ($h) => strtolower(trim((string) $h)), str_getcsv(array_shift($lines)));
        if (! in_array('first_name', $header, true)) {
            throw BusinessRuleException::make('import_header', 'The first row must name the columns, starting with first_name.');
        }
        $plan = [];
        $result = ['dry_run' => $dryRun, 'rows' => count($lines), 'created' => 0, 'skipped' => [], 'errors' => [], 'duplicates' => []];
        $seen = [];
        foreach ($lines as $i => $line) {
            $n = $i + 2;
            $row = array_combine($header, array_pad(array_slice(str_getcsv($line), 0, count($header)), count($header), ''));
            $data = array_filter(array_map(fn ($v) => is_string($v) ? trim($v) : $v, $row), fn ($v) => $v !== '');
            $data['consent_data'] = in_array(strtolower((string) ($row['consent'] ?? '')), ['yes', 'y', '1', 'true'], true);
            unset($data['consent']);
            try {
                $clean = $this->validate($company, $data, true);
            } catch (BusinessRuleException $e) {
                $result['errors'][] = ['row' => $n, 'message' => $e->getMessage()];

                continue;
            }
            $key = hash('sha256', implode('|', [$company->id, mb_strtolower($clean['first_name']), mb_strtolower((string) ($clean['last_name'] ?? '')),
                $clean['phone_e164'] ?? '', $clean['nin_hash'] ?? '', $clean['date_of_birth'] ?? '']));
            if (isset($seen[$key]) || SaccoMember::withoutGlobalScopes()->where('company_id', $company->id)->where('import_key', $key)->exists()) {
                $result['skipped'][] = ['row' => $n, 'reason' => isset($seen[$key]) ? 'the same member twice in this file' : 'already imported'];

                continue;
            }
            $seen[$key] = true;
            $plan[] = [$n, $data, $key];
        }
        $quotas = app(Quotas::class);
        if ($plan !== [] && ! $quotas->allows($company, 'members', count($plan))) {
            $limit = $quotas->limit($company, 'members');
            throw BusinessRuleException::make('plan_limit_reached', "Your plan allows {$limit} members: this file would take you past it. Upgrade, or import fewer.",
                ['limit' => 'max_members', 'max' => $limit, 'used' => $quotas->used($company, 'members'), 'adding' => count($plan)]);
        }
        if ($dryRun || $result['errors'] !== []) {
            $result['created'] = $dryRun ? count($plan) : 0;

            return $result;
        }
        DB::transaction(function () use ($company, $by, $plan, &$result) {
            foreach ($plan as [$n, $data, $key]) {
                $r = $this->register($company, $data, $by, ['import_key' => $key]);
                $result['created']++;
                if ($r['duplicates'] !== []) {
                    $result['duplicates'][] = ['row' => $n, 'member_no' => $r['member']->member_no];
                }
            }
        });
        SaccoAudit::record('sacco.member.imported', 'sacco_members', (int) $company->id, ['created' => $result['created'], 'skipped' => count($result['skipped'])], (int) $by->id);

        return $result;
    }

    public function find(Company $company, int $memberId, bool $lock = false): SaccoMember
    {
        $q = SaccoMember::withoutGlobalScopes()->where('company_id', $company->id)->where('is_deleted', 0);

        return ($lock ? $q->lockForUpdate() : $q)->find($memberId) ?? throw BusinessRuleException::make('member_not_found', 'That member is not in this SACCO.');
    }

    /** @return list<array{id: int, member_no: ?string, name: string, match: string}> */
    private function twinsOf(SaccoMember $m): array
    {
        $q = SaccoMember::withoutGlobalScopes()->where('company_id', $m->company_id)->where('id', '!=', $m->id)->where('is_deleted', 0)->whereNull('merged_into_id');
        $out = [];
        foreach (array_filter(['nin' => $m->nin_hash ? ['nin_hash' => $m->nin_hash] : null, 'phone' => $m->phone_e164 ? ['phone_e164' => $m->phone_e164] : null,
            'name_dob' => $m->date_of_birth ? ['first_name' => $m->first_name, 'last_name' => $m->last_name, 'date_of_birth' => $m->date_of_birth->toDateString()] : null]) as $match => $where) {
            foreach ((clone $q)->where($where)->get() as $twin) {
                $out[$twin->id] ??= ['id' => (int) $twin->id, 'member_no' => $twin->member_no, 'name' => $twin->full_name, 'match' => $match];
            }
        }

        return array_values($out);
    }

    /**
     * Validate and turn a form into columns: phones to E.164, the NIN encrypted and hashed, consents stamped.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function validate(Company $company, array $data, bool $creating): array
    {
        $v = validator(array_intersect_key($data, array_flip(SaccoMemberRules::WRITABLE)), SaccoMemberRules::rules($creating));
        if ($v->fails()) {
            throw BusinessRuleException::make('invalid_member', (string) $v->errors()->first(), ['errors' => $v->errors()->toArray()]);
        }
        $in = $v->validated();
        $out = array_intersect_key($in, array_flip(['kind', 'first_name', 'last_name', 'other_names', 'gender', 'date_of_birth', 'email', 'address', 'district',
            'subcounty', 'parish', 'village', 'occupation', 'employer_name', 'payroll_no', 'joined_on', 'guardian_name']));
        foreach (['phone' => 'phone_e164', 'phone_alt' => 'phone_alt_e164', 'guardian_phone' => 'guardian_phone_e164'] as $field => $column) {
            if (array_key_exists($field, $in)) {
                $out[$column] = $this->phone($company, $in[$field]);
            }
        }
        if (array_key_exists('nin', $in)) {
            $out += blank($in['nin']) ? ['nin_encrypted' => null, 'nin_hash' => null, 'nin_last4' => null] : Nin::columns((string) $in['nin']);
        }
        if (! empty($in['consent_data'])) {
            $out['consent_data_at'] = now();
            $out['consent_version'] = self::CONSENT_VERSION;
        }
        foreach (['consent_messages' => 'consent_messages_at', 'consent_credit_bureau' => 'consent_credit_bureau_at'] as $field => $column) {
            if (array_key_exists($field, $in)) {
                $out[$column] = $in[$field] ? now() : null;
            }
        }
        if (array_key_exists('consent_messages', $in)) {
            $out['messages_opt_out_at'] = $in['consent_messages'] ? null : now();
        }
        if (! empty($out['date_of_birth']) && Carbon::parse($out['date_of_birth'])->diffInYears(now()) < 12) {
            throw BusinessRuleException::make('too_young', 'A member is 12 or older (Cooperative Societies Act s.13).');
        }
        if (! empty($out['date_of_birth']) && Carbon::parse($out['date_of_birth'])->diffInYears(now()) < 18 && blank($out['guardian_name'] ?? null) && $creating) {
            throw BusinessRuleException::make('guardian_required', 'A member under 18 needs a parent or guardian on the record (DPPA s.8).');
        }

        return $out;
    }

    private function phone(Company $company, ?string $raw): ?string
    {
        if (blank($raw)) {
            return null;
        }

        return Phone::e164((string) $raw, (string) ($company->country ?: 'UG')) ?? throw BusinessRuleException::make('invalid_phone', "\"{$raw}\" is not a phone number.");
    }

    private function ownFile(Company $company, ?int $fileId): ?int
    {
        if ($fileId === null) {
            return null;
        }

        return (int) (StoredFile::withoutGlobalScopes()->where('company_id', $company->id)->whereKey($fileId)->value('id')
            ?? throw BusinessRuleException::make('file_not_found', 'That file is not in this SACCO.'));
    }

    private function assertSacco(Company $company): void
    {
        if (! in_array('sacco', $company->modules(), true)) {
            throw BusinessRuleException::make('module_off', 'This account does not keep SACCO books.', ['module' => 'sacco']);
        }
    }
}
