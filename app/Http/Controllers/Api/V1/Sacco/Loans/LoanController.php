<?php

namespace App\Http\Controllers\Api\V1\Sacco\Loans;

use App\Http\Controllers\Api\V1\Sacco\Present;
use App\Http\Controllers\Api\V1\Sacco\SaccoController;
use App\Models\SaccoLoan;
use App\Models\SaccoLoanDocument;
use App\Models\SaccoLoanGuarantor;
use App\Models\SaccoLoanRepayment;
use App\Models\SaccoLoanScheduleLine;
use App\Services\Sacco\Loans\Collateral;
use App\Services\Sacco\Loans\Committee;
use App\Services\Sacco\Loans\Contracts;
use App\Services\Sacco\Loans\Disbursement;
use App\Services\Sacco\Loans\GuarantorRecovery;
use App\Services\Sacco\Loans\Guarantors;
use App\Services\Sacco\Loans\LoanApplications;
use App\Services\Sacco\Loans\LoanBooks;
use App\Services\Sacco\Loans\Repayments;
use App\Services\Sacco\Loans\Restructure;
use App\Services\Sacco\Loans\WriteOffs;
use App\Services\Sacco\Members\MemberExit;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Loans, staff side (04 §13.2 P2 rows): the pipeline from application to payout, guarantors and collateral,
 * repayments, schedules, statements and settlement quotes, top-ups, reschedules, write-offs and recoveries. Thin:
 * every write is one service call (the service checks the permission and the rules); every read is narrowed to the
 * caller's SACCO, so another SACCO's ids answer 404.
 */
class LoanController extends SaccoController
{
    public const NOT_FOUND = ['loan_not_found', 'guarantee_not_found', 'collateral_not_found', 'member_not_found', 'product_not_found', 'account_not_found', 'not_found'];

    protected function attempt(callable $fn): JsonResponse
    {
        try {
            return $fn();
        } catch (\App\Exceptions\BusinessRuleException $e) {
            $status = $e->errorCode() === 'forbidden' ? 403 : (in_array($e->errorCode(), array_merge(self::NOT_FOUND, parent::NOT_FOUND), true) ? 404 : 422);

            return $this->error($e->getMessage(), $status, $e->toErrors());
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException) {
            return $this->notFound('Not found.');
        }
    }

    private function loan(Request $request, int $id): SaccoLoan
    {
        return LoanBooks::find($this->cid($request), $id);
    }

    private function show(Request $request, int $id, string $message = 'Saved.', int $status = 200): JsonResponse
    {
        return $this->success(LoanPresent::loan($this->loan($request, $id), true), $message, $status);
    }

    /** GET sacco/loans?status&member_id&classification&product_id&q */
    public function index(Request $request): JsonResponse
    {
        if (! $this->mayRead($request)) {
            return $this->refuseRead();
        }
        $p = SaccoLoan::withoutGlobalScopes()->where('company_id', $this->cid($request))->where('is_deleted', 0)
            ->when($request->query('status'), fn ($q, $v) => $q->whereIn('status', explode(',', (string) $v)))
            ->when($request->query('member_id'), fn ($q, $v) => $q->where('member_id', (int) $v))
            ->when($request->query('classification'), fn ($q, $v) => $q->where('classification', $v))
            ->when($request->query('product_id'), fn ($q, $v) => $q->where('product_id', (int) $v))
            ->when($request->query('q'), fn ($q, $v) => $q->where('loan_no', 'like', '%'.$v.'%'))
            ->orderByDesc('id')->paginate($this->perPage($request));

        return $this->page($p, collect($p->items())->map(fn ($l) => LoanPresent::loan($l))->all());
    }

    /** GET sacco/loans/{id} */
    public function find(Request $request, int $id): JsonResponse
    {
        if (! $this->mayRead($request)) {
            return $this->refuseRead();
        }

        return $this->attempt(fn () => $this->show($request, $id, 'Found.'));
    }

    /** POST sacco/loans (sacco_loans): a draft, or with submit=true made and submitted. */
    public function store(Request $request): JsonResponse
    {
        return $this->attempt(function () use ($request) {
            $loan = (new LoanApplications())->create($this->company($request), $request->except('submit'), $this->user($request), ['submit' => $request->boolean('submit')]);

            return $this->success(LoanPresent::loan($loan, true), 'Application saved.', 201);
        });
    }

    /** PUT sacco/loans/{id} (a draft or a submitted application) */
    public function update(Request $request, int $id): JsonResponse
    {
        return $this->attempt(function () use ($request, $id) {
            (new LoanApplications())->update($this->company($request), $id, $request->all(), $this->user($request));

            return $this->show($request, $id);
        });
    }

    /** POST sacco/loans/{id}/{action}: the pipeline's steps. */
    public function act(Request $request, int $id, string $action): JsonResponse
    {
        $company = fn () => $this->company($request);
        $user = fn () => $this->user($request);

        return $this->attempt(function () use ($request, $id, $action, $company, $user) {
            $approval = null;
            switch ($action) {
                case 'submit':
                    (new LoanApplications())->submit($company(), $id, $user());
                    break;
                case 'appraise':
                    (new LoanApplications())->appraise($company(), $id, $user(), $request->all());
                    break;
                case 'send-to-committee':
                    $approval = (new Committee())->sendToCommittee($company(), $id, $user(), $request->input('uuid'));
                    break;
                case 'request-decision':
                    $approval = (new Committee())->requestDelegated($company(), $id, $user(), $request->input('uuid'));
                    break;
                case 'decide':
                    (new Committee())->decide($company(), $id, $user(), (string) $request->input('decision'), $request->input('note'));
                    break;
                case 'appeal':
                    $approval = (new Committee())->appeal($company(), $id, $user(), (string) $request->input('reason'), null, $request->input('uuid'));
                    break;
                case 'recuse':
                    (new Committee())->recuse($company(), $id, $user(), $request->input('note'));
                    break;
                case 'contract':
                    $docs = (new Contracts())->generate($company(), $id, $user());

                    return $this->success(['key_facts_id' => (int) $docs['key_facts']->id, 'contract_id' => (int) $docs['contract']->id,
                        'loan' => LoanPresent::loan($this->loan($request, $id), true)], 'Contract and key facts made.', 201);
                case 'sign':
                    (new Contracts())->sign($company(), $id, $user(), $request->all());
                    break;
                case 'cancel':
                    $loan = $this->loan($request, $id);
                    in_array($loan->status, ['draft', 'submitted', 'appraised'], true)
                        ? (new LoanApplications())->withdraw($company(), $id, $user(), (string) $request->input('reason', 'Withdrawn'))
                        : (new Disbursement())->cancel($company(), $id, $user(), $request->all(), (string) $request->input('reason', 'Cancelled in the cooling-off'));
                    break;
                case 'disburse':
                    $approval = (new Disbursement())->request($company(), $id, $user(), $request->all(), $request->input('uuid'));
                    break;
                case 'top-up':
                    $new = (new Restructure())->applyTopUp($company(), $id, $request->all(), $user());

                    return $this->success(LoanPresent::loan($new, true), 'Top-up application submitted.', 201);
                case 'reschedule':
                    $approval = (new Restructure())->requestReschedule($company(), $id, $user(), $request->all(), $request->input('uuid'));
                    break;
                case 'write-off':
                    $approval = (new WriteOffs())->request($company(), $id, $user(), (string) $request->input('reason'), $request->input('uuid'));
                    break;
                case 'recover':
                    $change = (new WriteOffs())->recover($company(), $id, $request->all(), $user());

                    return $this->success(['change' => $change->toArray(), 'loan' => LoanPresent::loan($this->loan($request, $id))], 'Recovery recorded.', 201);
                default:
                    return $this->notFound('Not found.');
            }
            if ($approval !== null) {
                return $this->success(['approval' => Present::approval($approval->fresh(), $user()), 'loan' => LoanPresent::loan($this->loan($request, $id), true)],
                    'Waiting for a decision.', 202);
            }

            return $this->show($request, $id);
        });
    }

    /** POST sacco/loans/{id}/votes {vote, note} (sacco_loans_vote and a seat) */
    public function vote(Request $request, int $id): JsonResponse
    {
        return $this->attempt(fn () => $this->success(['tally' => (new Committee())->vote($this->company($request), $id, $this->user($request), (string) $request->input('vote'), $request->input('note')),
            'loan' => LoanPresent::loan($this->loan($request, $id))], 'Vote recorded.'));
    }

    /** GET sacco/loans/{id}/guarantors */
    public function guarantors(Request $request, int $id): JsonResponse
    {
        if (! $this->mayRead($request)) {
            return $this->refuseRead();
        }

        return $this->attempt(fn () => $this->success(SaccoLoanGuarantor::withoutGlobalScopes()->where('loan_id', $this->loan($request, $id)->id)->orderBy('id')->get()
            ->map(fn ($g) => LoanPresent::guarantor($g))->all()));
    }

    /** POST sacco/loans/{id}/guarantors {member_id, guaranteed_minor} */
    public function addGuarantor(Request $request, int $id): JsonResponse
    {
        return $this->attempt(function () use ($request, $id) {
            $this->loan($request, $id);
            $g = (new Guarantors())->add($this->company($request), $id, (int) $request->input('member_id'), is_int($request->input('guaranteed_minor')) ? $request->input('guaranteed_minor') : -1, $this->user($request));

            return $this->success(LoanPresent::guarantor($g) + ['consent_link' => $g->consent_link], 'The guarantor has been told; their consent is awaited.', 201);
        });
    }

    /** POST sacco/loans/{id}/guarantors/{g}/{consent|decline|remove|resend} */
    public function guarantorAct(Request $request, int $id, int $g, string $action): JsonResponse
    {
        return $this->attempt(function () use ($request, $id, $g, $action) {
            $this->ownGuarantee($request, $id, $g);
            $svc = new Guarantors();
            $out = match ($action) {
                'consent' => LoanPresent::guarantor($svc->consentWithForm($this->company($request), $g, (int) $request->input('file_id'), $this->user($request))),
                'decline' => LoanPresent::guarantor($svc->decline($this->cid($request), $g, $this->user($request), (string) $request->input('reason', 'Declined'))),
                'remove' => LoanPresent::guarantor($svc->remove($this->company($request), $g, $this->user($request), (string) $request->input('reason', 'Removed'))),
                'resend' => ['consent_link' => $svc->resendLink($this->company($request), $g, $this->user($request))],
                'recover' => ['approval' => Present::approval((new GuarantorRecovery())->request($this->company($request), $g, is_int($request->input('amount_minor')) ? $request->input('amount_minor') : -1,
                    $this->user($request), (string) $request->input('reason', 'Recovery from guarantor'), $request->input('uuid')), $this->user($request))],
                default => null,
            };

            return $out === null ? $this->notFound('Not found.') : $this->success($out, 'Saved.', $action === 'recover' ? 202 : 200);
        });
    }

    /** GET sacco/loans/{id}/collateral */
    public function collateral(Request $request, int $id): JsonResponse
    {
        if (! $this->mayRead($request)) {
            return $this->refuseRead();
        }

        return $this->attempt(fn () => $this->success($this->loan($request, $id)->collateral()->withoutGlobalScopes()->orderBy('id')->get()->map(fn ($c) => LoanPresent::collateral($c))->all()));
    }

    /** POST sacco/loans/{id}/collateral {type, description, value_minor, document_file_id} */
    public function addCollateral(Request $request, int $id): JsonResponse
    {
        return $this->attempt(fn () => $this->success(LoanPresent::collateral((new Collateral())->add($this->company($request), $id, $request->all(), $this->user($request))), 'Security registered.', 201));
    }

    /** POST sacco/loans/{id}/collateral/{c}/{demand|realise|release} */
    public function collateralAct(Request $request, int $id, int $c, string $action): JsonResponse
    {
        return $this->attempt(function () use ($request, $id, $c, $action) {
            $item = $this->loan($request, $id)->collateral()->withoutGlobalScopes()->whereKey($c)->first()
                ?? throw \App\Exceptions\BusinessRuleException::make('collateral_not_found', 'That security is not on this loan.');
            $svc = new Collateral();
            $out = match ($action) {
                'demand' => $svc->demandNotice($this->company($request), (int) $item->id, (string) $request->input('served_on', LoanBooks::today($this->cid($request))->toDateString()), $this->user($request)),
                'realise' => $svc->realise($this->company($request), (int) $item->id, (string) $request->input('sold_on'), (int) $request->input('realised_minor'), $this->user($request)),
                'release' => $svc->release($this->company($request), (int) $item->id, $this->user($request)),
                default => null,
            };

            return $out === null ? $this->notFound('Not found.') : $this->success(LoanPresent::collateral($out));
        });
    }

    /** POST sacco/loans/{id}/repayments {uuid, amount_minor, channel: cash|bank|savings, savings_account_id, value_date, reference, settle} (sacco_teller) */
    public function repay(Request $request, int $id): JsonResponse
    {
        return $this->attempt(function () use ($request, $id) {
            $this->loan($request, $id);
            $r = (new Repayments())->record($this->company($request), $id, $request->except(['settle', 'loan_id', 'kind']), $this->user($request), ['settle' => $request->boolean('settle')]);

            return $this->success(['repayment' => LoanPresent::repayment($r), 'loan' => LoanPresent::loan($this->loan($request, $id))], 'Repayment recorded.', 201);
        });
    }

    /** GET sacco/loans/{id}/repayments */
    public function repayments(Request $request, int $id): JsonResponse
    {
        if (! $this->mayRead($request)) {
            return $this->refuseRead();
        }

        return $this->attempt(fn () => $this->success(SaccoLoanRepayment::withoutGlobalScopes()->where('loan_id', $this->loan($request, $id)->id)->orderBy('value_date')->orderBy('id')
            ->get()->map(fn ($r) => LoanPresent::repayment($r))->all()));
    }

    /** GET sacco/loans/{id}/{schedule|statement|settlement-quote} */
    public function read(Request $request, int $id, string $what): JsonResponse
    {
        if (! $this->mayRead($request)) {
            return $this->refuseRead();
        }

        return $this->attempt(function () use ($request, $id, $what) {
            $loan = $this->loan($request, $id);

            return match ($what) {
                'schedule' => $this->success(['schedule_version' => (int) $loan->schedule_version, 'lines' => SaccoLoanScheduleLine::withoutGlobalScopes()->where('loan_id', $loan->id)
                    ->when(! $request->boolean('all_versions'), fn ($q) => $q->where('schedule_version', $loan->schedule_version))
                    ->orderBy('schedule_version')->orderBy('installment_no')->get()->map(fn ($l) => LoanPresent::line($l))->all()]),
                'statement' => $this->success(self::statement($loan)),
                'settlement-quote' => $this->success((new Repayments())->quote($this->cid($request), $id, $request->query('on'))),
                default => $this->notFound('Not found.'),
            };
        });
    }

    /** GET sacco/loans/{id}/documents/{doc}: the stored PDF, checked against its hash. */
    public function document(Request $request, int $id, int $doc): \Symfony\Component\HttpFoundation\Response
    {
        if (! $this->mayRead($request)) {
            return $this->refuseRead();
        }
        try {
            $d = SaccoLoanDocument::withoutGlobalScopes()->where('loan_id', $this->loan($request, $id)->id)->whereKey($doc)->first();
            if ($d === null) {
                return $this->notFound('Not found.');
            }
            $bytes = (new Contracts())->bytes($d);
        } catch (\App\Exceptions\BusinessRuleException $e) {
            return $this->error($e->getMessage(), in_array($e->errorCode(), self::NOT_FOUND, true) ? 404 : 422, $e->toErrors());
        }

        return response($bytes, 200, ['Content-Type' => 'application/pdf', 'Content-Disposition' => 'inline; filename="'.$d->type.'-'.$id.'.pdf"']);
    }

    /** GET sacco/guarantees?member_id: who guarantees what. */
    public function guarantees(Request $request): JsonResponse
    {
        if (! $this->mayRead($request)) {
            return $this->refuseRead();
        }
        $p = SaccoLoanGuarantor::withoutGlobalScopes()->where('company_id', $this->cid($request))
            ->when($request->query('member_id'), fn ($q, $v) => $q->where('guarantor_member_id', (int) $v))
            ->when($request->query('status'), fn ($q, $v) => $q->where('consent_status', $v))->orderByDesc('id')->paginate($this->perPage($request));

        return $this->page($p, collect($p->items())->map(fn ($g) => LoanPresent::guarantor($g))->all());
    }

    /** POST sacco/members/{id}/exit {reason, shares_to_member_id, channel, note} (sacco_members) */
    public function exit(Request $request, int $id): JsonResponse
    {
        return $this->attempt(fn () => $this->success(['approval' => Present::approval((new MemberExit())->request($this->company($request), $id, $this->user($request), $request->all(),
            $request->input('uuid')), $this->user($request))], 'The exit waits for a second officer.', 202));
    }

    /** The loan's own lines (1300/1320/1330/1340) in value-date order, with what was owed after each. @return array<string, mixed> */
    public static function statement(SaccoLoan $loan): array
    {
        $rows = DB::table('sacco_journal_lines as l')->join('sacco_journal_entries as e', 'e.id', '=', 'l.journal_entry_id')->join('sacco_ledger_accounts as a', 'a.id', '=', 'l.ledger_account_id')
            ->where('l.company_id', $loan->company_id)->where('l.sub_type', 'loan')->where('l.sub_id', $loan->id)->orderBy('l.value_date')->orderBy('l.id')
            ->get(['e.entry_no', 'e.kind', 'e.memo', 'l.value_date', 'a.code', 'a.system_key', 'l.debit_minor', 'l.credit_minor']);
        $owed = 0;
        $lines = [];
        foreach ($rows as $r) {
            $owed += (int) $r->debit_minor - (int) $r->credit_minor;
            $lines[] = ['entry_no' => $r->entry_no, 'kind' => $r->kind, 'memo' => $r->memo, 'value_date' => substr((string) $r->value_date, 0, 10), 'account' => $r->code,
                'part' => ['loans.principal' => 'principal', 'loans.interest' => 'interest', 'loans.penalty' => 'penalty', 'loans.fees' => 'fees'][$r->system_key] ?? 'principal',
                'debit_minor' => (int) $r->debit_minor, 'credit_minor' => (int) $r->credit_minor, 'owed_minor' => $owed];
        }

        return ['loan_id' => (int) $loan->id, 'loan_no' => $loan->loan_no, 'currency' => $loan->currency, 'lines' => $lines, 'owed_minor' => $owed,
            'in_suspense_minor' => (int) $loan->interest_in_suspense_minor + (int) $loan->penalty_in_suspense_minor];
    }

    private function ownGuarantee(Request $request, int $loanId, int $g): void
    {
        $loan = $this->loan($request, $loanId);
        if (! SaccoLoanGuarantor::withoutGlobalScopes()->where('loan_id', $loan->id)->whereKey($g)->exists()) {
            throw \App\Exceptions\BusinessRuleException::make('guarantee_not_found', 'That guarantee is not on this loan.');
        }
    }
}
