<?php

namespace App\Services\Budget;

use App\Models\BudgetProgram;
use App\Models\Company;
use App\Models\User;
use App\Services\Rentals\Reports\RentalDocument;
use App\Services\Reports\XlsxWriter;
use App\Support\Decimal;
use App\Support\LocalDate;
use App\Support\Money;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

/**
 * An event's documents as files: the committee report, budget statement, pledge list, payments, pledge cards, final
 * account and a payment's receipt, in the one paper layout the portal's documents share (RentalDocument: letterhead with the
 * account's logo, A4, page numbers, figures that line up), with the event's name and footer. Names only the account and the
 * event, never a product. Excel and CSV carry real numbers and phones as text.
 *
 * Every figure comes from EventReports. A file's name says what it is: brian-grace-wedding-budget-2026-11-05.pdf.
 */
final class EventExport
{
    public const DOCUMENTS = ['committee', 'budget', 'pledges', 'payments', 'cards', 'final'];

    /** doc => the formats it comes in. */
    public const FORMATS = ['committee' => ['pdf'], 'budget' => ['pdf', 'xlsx'], 'pledges' => ['pdf', 'xlsx', 'csv'], 'payments' => ['pdf', 'xlsx', 'csv'], 'cards' => ['pdf'], 'final' => ['pdf']];

    /** Documents that list people: only those who may take names away (BudgetAccess::canExport). */
    public const WITH_PEOPLE = ['pledges', 'payments', 'cards'];

    public const TYPES = ['pdf' => 'application/pdf', 'csv' => 'text/csv; charset=utf-8', 'xlsx' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'];

    public const RECEIPT_ROUTE = 'event.receipt';

    /** How long a receipt's link works: long enough for someone to find it again. */
    public const RECEIPT_DAYS = 30;

    public function __construct(private readonly EventReports $reports) {}

    /** May open this one document at all. */
    public static function allowed(?User $user, int $eventId, string $doc): bool
    {
        return in_array($doc, self::WITH_PEOPLE, true) ? BudgetAccess::canExport($user, $eventId) : BudgetAccess::canOpen($user, $eventId);
    }

    /** brian-grace-wedding-budget-2026-11-05.pdf */
    public static function filename(array $event, string $what, string $format, int $companyId): string
    {
        return (Str::slug((string) $event['name']) ?: 'event').'-'.$what.'-'.LocalDate::today($companyId)->toDateString().'.'.$format;
    }

    /** An amount for paper: grouped, whole shillings without cents, never through a float. */
    public static function amount(string $value, int $decimals): string
    {
        $rounded = bcadd(Decimal::of($value), '0', $decimals);
        $negative = str_starts_with($rounded, '-');
        [$whole, $cents] = array_pad(explode('.', ltrim($rounded, '-')), 2, '');
        $grouped = strrev(implode(',', str_split(strrev($whole), 3)));

        return ($negative ? '-' : '').$grouped.($cents !== '' ? '.'.$cents : '');
    }

    /** @return array{label: string, decimals: int, m: \Closure, day: \Closure, as_at: string} what every document's view needs to write money and days */
    private function format(Company $company, ?string $display = null): array
    {
        $cid = (int) $company->id;
        $decimals = Money::decimals($cid);
        $label = trim((string) $display) ?: Money::symbol($cid);

        return [
            'label' => $label, 'decimals' => $decimals,
            'm' => fn ($v) => self::amount((string) $v, $decimals),
            'day' => fn (?string $d) => $d ? Carbon::parse($d)->format('j M Y') : '',
            'as_at' => LocalDate::today($cid)->format('j M Y'),
        ];
    }

    /** A document's PDF, or a failure the caller turns into words: it never hands back an empty file. */
    private function pdf(Company $company, array $event, string $view, string $title, array $data, array $sub = [], string|array $paper = 'a4', bool $small = false): string
    {
        $cid = (int) $company->id;
        if (PHP_SAPI !== 'cli') { // on the command line there is no limit, and setting one would cut off whatever else the process is doing
            @set_time_limit(180); // a long list takes about a second per 100 people to lay out; a very big wedding must not be cut off
        }
        $f = $this->format($company);
        $doc = ['title' => $title, 'head' => RentalDocument::letterhead($cid), 'small' => $small, 'sub' => array_merge([$event['name']], $sub, ['As at '.$f['as_at']])];
        $html = view($view, ['doc' => $doc, 'event' => $event, 'footer' => $event['bottom'] ?? ''] + $f + $data)->render();
        $generated = LocalDate::today($cid)->format('j M Y');
        $bytes = RentalDocument::output($html, $paper, 'portrait', ['left' => trim($company->name.' · '.$event['name'].' · '.$title), 'generated' => $generated]);
        if (! str_starts_with($bytes, '%PDF-')) {
            throw new \RuntimeException('The document came out empty.');
        }

        return $bytes;
    }

    /**
     * One document as a file.
     *
     * @param  array<string, mixed>  $options  names (the committee report with names), blank (empty pledge cards), group, status (the pledge list as filtered)
     * @return array{bytes: string, name: string, type: string}|null null when the event is not this account's, or the document does not come in this format
     */
    public function make(Company $company, int $eventId, string $doc, string $format = 'pdf', array $options = []): ?array
    {
        if (! in_array($format, self::FORMATS[$doc] ?? [], true)) {
            return null;
        }
        $cid = (int) $company->id;
        $event = $this->reports->position($company, $eventId);
        if ($event === null) {
            return null;
        }
        $what = ['committee' => 'committee-report', 'budget' => 'budget', 'pledges' => 'pledges', 'payments' => 'payments', 'cards' => 'pledge-cards', 'final' => 'final-account'][$doc];
        $bytes = match ($doc.'.'.$format) {
            'committee.pdf' => $this->pdf($company, $event, 'events.committee-report', 'Committee report', ['r' => $this->reports->committee($company, $eventId, (bool) ($options['names'] ?? false))]),
            'budget.pdf' => $this->pdf($company, $event, 'events.budget-statement', 'Budget statement', ['b' => $this->reports->budget($cid, $eventId)]),
            'pledges.pdf' => $this->pdf($company, $event, 'events.pledge-list', 'Pledge list', ['p' => $this->reports->pledges($cid, $eventId, $this->filters($options)), 'note' => $this->filterNote($options)], array_filter([$this->filterNote($options)])),
            'payments.pdf' => $this->pdf($company, $event, 'events.payments', 'Payments', ['p' => $this->reports->payments($cid, $eventId)]),
            'cards.pdf' => $this->pdf($company, $event, 'events.pledge-cards', 'Pledge cards', ['cards' => $this->cards($cid, $eventId, ! empty($options['blank']))]),
            'final.pdf' => $this->pdf($company, $event, 'events.final-account', 'Final account', ['r' => $this->reports->finalAccount($company, $eventId)]),
            'budget.xlsx' => (function () use ($company, $event, $cid, $eventId) {
                $b = $this->reports->budget($cid, $eventId);

                return XlsxWriter::workbook([$this->budgetSheet($company, $event, $b), $this->paymentsOutSheet($company, $event, $b['payments'])]);
            })(),
            'pledges.xlsx' => XlsxWriter::workbook([$this->pledgesSheet($company, $event, $this->reports->pledges($cid, $eventId, $this->filters($options)))]),
            'payments.xlsx' => XlsxWriter::workbook([$this->paymentsSheet($company, $event, $this->reports->payments($cid, $eventId))]),
            'pledges.csv' => $this->csv(...$this->pledgesTable($company, $this->reports->pledges($cid, $eventId, $this->filters($options)))),
            'payments.csv' => $this->csv(...$this->paymentsTable($company, $this->reports->payments($cid, $eventId))),
            default => throw new \LogicException("No {$doc} as {$format}."), // FORMATS above says what exists
        };

        return ['bytes' => $bytes, 'name' => self::filename($event, $what, $format, $cid), 'type' => self::TYPES[$format]];
    }

    /** The pledge book's filters that apply to the list: its group and status. */
    private function filters(array $options): array
    {
        return array_filter(['group' => (string) ($options['group'] ?? ''), 'status' => in_array($options['status'] ?? null, EventBook::STATUSES, true) ? (string) $options['status'] : 'all'], fn ($v) => $v !== '');
    }

    private function filterNote(array $options): string
    {
        $group = (string) ($options['group'] ?? '');

        return $group === '' ? '' : EventBook::groupLabel($group === '-' ? null : $group);
    }

    /** @return list<array<string, string>> four to a page; blank ones are for writing on at a meeting */
    private function cards(int $cid, int $eventId, bool $blank): array
    {
        if ($blank) {
            return array_fill(0, 4, ['name' => '', 'phone' => '', 'pledged' => '', 'group' => '']);
        }

        return array_map(fn ($r) => ['name' => $r['name'], 'phone' => self::localPhone($r['phone']), 'pledged' => $r['pledged'], 'group' => $r['group'] === 'No group' ? '' : $r['group']], $this->reports->pledges($cid, $eventId)['rows']);
    }

    /** The WhatsApp summary: the committee report in plain words, without names unless asked and allowed. No emoji. */
    public function whatsappSummary(Company $company, int $eventId, bool $names = false): ?string
    {
        $r = $this->reports->committee($company, $eventId, $names);
        if ($r === null) {
            return null;
        }
        $f = $this->format($company);
        $e = $r['event'];
        $m = fn ($v) => $f['label'].' '.($f['m'])($v);
        $lines = [$e['name'].': committee report, '.$f['as_at']];
        if ($e['date']) {
            $lines[] = ($f['day'])($e['date']).($e['days_left'] !== null && $e['days_left'] >= 0 ? ', '.$e['days_left'].' days to go' : '');
        }
        $lines[] = '';
        $lines[] = 'Pledged: '.$m($e['pledged']);
        $lines[] = 'Collected: '.$m($e['paid']);
        $lines[] = 'Still owed: '.$m($e['owed']);
        $lines[] = 'Paid out: '.$m($e['spent']);
        $lines[] = 'In hand: '.$m($e['in_hand']);
        $lines[] = 'Budget: '.$m($e['budget']);
        $lines[] = '';
        $lines[] = 'This week: '.$m($r['week']['pledged']).' pledged, '.$m($r['week']['collected']).' collected';
        if ($r['groups'] !== []) {
            $lines[] = '';
            $lines[] = 'By group (collected of pledged):';
            foreach ($r['groups'] as $g) {
                $lines[] = $g['label'].': '.$m($g['paid']).' of '.$m($g['pledged']);
            }
        }
        if ($r['names'] !== []) {
            $lines[] = '';
            $lines[] = 'Who gave:';
            foreach ($r['names'] as $p) {
                $lines[] = $p['name'].': '.$m($p['paid']).' of '.$m($p['pledged']);
            }
        }

        return implode("\n", $lines);
    }

    /** The budget statement for a signed link (the phone's) or the portal: a whole response. */
    public function budgetResponse(BudgetProgram $program): Response
    {
        $company = Company::withoutGlobalScopes()->findOrFail($program->company_id);
        $file = $this->make($company, (int) $program->id, 'budget') ?? abort(404);

        return self::respond($file);
    }

    /** @param array{bytes: string, name: string, type: string} $file */
    public static function respond(array $file, string $disposition = 'inline'): Response
    {
        return response($file['bytes'], 200, [
            'Content-Type' => $file['type'],
            'Content-Disposition' => $disposition.'; filename="'.$file['name'].'"',
            'Content-Length' => (string) strlen($file['bytes']),
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    /**
     * A signed link to one payment's receipt, valid 30 days, showing that payment alone and nobody else's (D9). Relative, as
     * the phone's links are, so the host's proxy cannot break it. A path; give it to url() or a message.
     */
    public static function receiptLink(int $companyId, string $paymentUuid): string
    {
        return URL::temporarySignedRoute(self::RECEIPT_ROUTE, now()->addDays(self::RECEIPT_DAYS), ['uuid' => $paymentUuid, 'company' => $companyId], false);
    }

    /** What a receipt's link answers: its page, or with ?format=pdf its PDF. A bad or expired signature is the private-page answer, as the phone's links. */
    public function receiptResponse(string $uuid): Response
    {
        // `format` stays outside the signature: the same payment's PDF, which the page's own button asks for.
        if (! URL::hasValidSignature(request(), false, ['format'])) {
            return response()->view('reports.share-link-expired', [], 403);
        }
        $cid = (int) request('company');
        $company = Company::withoutGlobalScopes()->find($cid);
        $r = $company ? $this->reports->receipt($cid, $uuid) : null;
        abort_if($r === null, 404);
        $f = $this->format($company);
        $head = RentalDocument::letterhead($cid);
        if (request('format') === 'pdf') {
            $html = view('events.receipt', ['doc' => ['title' => 'Receipt', 'head' => $head, 'small' => true, 'sub' => array_filter([$r['event'], $r['number']])], 'r' => $r, 'words' => EventReports::inWords($r['amount'])] + $f)->render();
            $bytes = RentalDocument::output($html, 'a5', 'portrait', ['left' => trim($company->name.' · '.$r['event']), 'generated' => '']);

            return self::respond(['bytes' => $bytes, 'name' => 'receipt-'.(Str::slug($r['number']) ?: Str::slug($uuid)).'.pdf', 'type' => self::TYPES['pdf']]);
        }

        return response()->view('events.receipt-page', ['r' => $r, 'head' => $head, 'words' => EventReports::inWords($r['amount']), 'pdf' => request()->fullUrlWithQuery(['format' => 'pdf'])] + $f)
            ->header('X-Robots-Tag', 'noindex, nofollow');
    }

    /** +256772123456 as people write it here: 0772123456. Any other country stays as stored. */
    public static function localPhone(string $phone): string
    {
        return str_starts_with($phone, '+256') ? '0'.substr($phone, 4) : $phone;
    }

    /** @return array{0: list<string>, 1: list<list<string>>} */
    private function pledgesTable(Company $company, array $p): array
    {
        $plain = $this->plain($company);
        $rows = array_map(fn ($r) => [$r['name'], self::localPhone($r['phone']), $r['group'], $plain($r['pledged']), $plain($r['paid']), $plain($r['owed']), $r['status']], $p['rows']);

        return [['Name', 'Phone', 'Group', 'Pledged', 'Paid', 'Balance', 'Status'], $rows];
    }

    /** @return array{0: list<string>, 1: list<list<string>>} */
    private function paymentsTable(Company $company, array $p): array
    {
        $plain = $this->plain($company);
        $rows = array_map(fn ($r) => [$r['date'], $r['receipt'], $r['name'], $r['group'], $plain($r['amount']), $r['how'], $r['reference'], $r['received_by'], $r['state']], $p['rows']);

        return [['Date', 'Receipt', 'Name', 'Group', 'Amount', 'How', 'Reference', 'Received by', 'Reversed'], $rows];
    }

    /** An amount for a spreadsheet: no thousands separators, cents only where the currency has them. */
    private function plain(Company $company): \Closure
    {
        $decimals = Money::decimals((int) $company->id);

        return fn ($v) => bcadd(Decimal::of((string) $v), '0', $decimals);
    }

    /** UTF-8 with a mark so spreadsheet programs read names right; a cell that would run as a formula gets an apostrophe. Headings are row 1. */
    private function csv(array $head, array $rows): string
    {
        $h = fopen('php://temp', 'w+');
        fwrite($h, "\xEF\xBB\xBF");
        fputcsv($h, $head);
        foreach ($rows as $row) {
            fputcsv($h, array_map(fn ($v) => preg_match('/^[=+\-@\t\r]/', (string) $v) && ! preg_match('/^-?\d+(\.\d+)?$/', (string) $v) ? "'".$v : (string) $v, $row));
        }
        rewind($h);
        $out = (string) stream_get_contents($h);
        fclose($h);

        return $out;
    }

    /** A number cell for Excel. Exact enough: a spreadsheet's numbers are floats whatever we do; the file's totals come from exact sums. */
    private function num(string $v, int $d, bool $total = false): array
    {
        return ['v' => (float) $v, 's' => ($total ? 'total_money' : 'money').($d === 0 ? '0' : '2')];
    }

    /** @return array{name: string, rows: list<list<mixed>>, widths: list<int>, heading: int, last: int} */
    private function sheet(string $name, Company $company, array $event, array $headings, array $rows, ?array $total, array $widths, array $numbers): array
    {
        $head = array_map(fn ($h, $i) => ['v' => $h, 's' => in_array($i, $numbers, true) ? 'head_r' : 'head'], $headings, array_keys($headings));
        $all = [[['v' => $company->name.' · '.$event['name'].' · '.$name, 's' => 'title']], [['v' => 'As at '.LocalDate::today((int) $company->id)->format('j M Y').' · Amounts in '.Money::symbol((int) $company->id), 's' => 'note']], $head];
        array_push($all, ...$rows);
        $last = count($all);
        if ($total !== null) {
            $all[] = $total;
        }

        return ['name' => $name, 'rows' => $all, 'widths' => $widths, 'heading' => 3, 'last' => $last, 'columns' => count($headings)];
    }

    private function pledgesSheet(Company $company, array $event, array $p): array
    {
        $d = Money::decimals((int) $company->id);
        $rows = array_map(fn ($r) => [$r['name'], self::localPhone($r['phone']), $r['group'], $this->num($r['pledged'], $d), $this->num($r['paid'], $d), $this->num($r['owed'], $d), $r['status']], $p['rows']);
        $t = $p['totals'];
        $total = [['v' => 'Total ('.$t['count'].')', 's' => 'total'], ['v' => null, 's' => 'total'], ['v' => null, 's' => 'total'], $this->num($t['pledged'], $d, true), $this->num($t['paid'], $d, true), $this->num($t['owed'], $d, true), ['v' => null, 's' => 'total']];

        return $this->sheet('Pledges', $company, $event, ['Name', 'Phone', 'Group', 'Pledged', 'Paid', 'Balance', 'Status'], $rows, $total, [30, 14, 18, 14, 14, 14, 11], [3, 4, 5]);
    }

    private function paymentsSheet(Company $company, array $event, array $p): array
    {
        $d = Money::decimals((int) $company->id);
        $rows = array_map(fn ($r) => [$r['date'], $r['receipt'], $r['name'], $r['group'], $this->num($r['amount'], $d), $r['how'], $r['reference'], $r['received_by'], $r['state']], $p['rows']);
        $blank = ['v' => null, 's' => 'total'];
        $total = [['v' => 'Total', 's' => 'total'], $blank, $blank, $blank, $this->num($p['total'], $d, true), $blank, $blank, $blank, $blank];

        return $this->sheet('Payments', $company, $event, ['Date', 'Receipt', 'Name', 'Group', 'Amount', 'How', 'Reference', 'Received by', 'Reversed'], $rows, $total, [11, 17, 28, 16, 14, 13, 18, 22, 10], [4]);
    }

    private function paymentsOutSheet(Company $company, array $event, array $p): array
    {
        $d = Money::decimals((int) $company->id);
        $rows = [];
        foreach ($p['rows'] as $r) {
            $rows[] = [$r['date'], $r['line'], $r['paid_to'], $r['how'], $r['paid_by'], $r['approved_by'], $r['reference'], $r['state'], $this->num($r['amount'], $d)];
        }
        $blank = ['v' => null, 's' => 'total'];
        $total = [['v' => 'Total', 's' => 'total'], $blank, $blank, $blank, $blank, $blank, $blank, $blank, $this->num($p['total'], $d, true)];

        return $this->sheet('Payments out', $company, $event, ['Date', 'Line', 'Paid to', 'How', 'Paid by', 'Approved by', 'Reference', 'Note', 'Amount'], $rows, $total, [11, 28, 24, 13, 20, 20, 16, 22, 14], [8]);
    }

    private function budgetSheet(Company $company, array $event, array $b): array
    {
        $d = Money::decimals((int) $company->id);
        $rows = [];
        foreach ($b['categories'] as $c) {
            foreach ($c['lines'] as $l) {
                $rows[] = [$c['name'], $l['name'], $this->num($l['agreed'], $d), $this->num($l['paid'], $d), $this->num($l['left'], $d), $this->num($l['over'], $d)];
            }
        }
        $blank = ['v' => null, 's' => 'total'];
        $total = [['v' => 'Total', 's' => 'total'], $blank, $this->num($b['agreed'], $d, true), $this->num($b['paid'], $d, true), $this->num($b['left'], $d, true), $this->num($b['over'], $d, true)];

        return $this->sheet('Budget', $company, $event, ['Category', 'Line', 'Agreed', 'Paid out', 'Still to pay', 'Over by'], $rows, $total, [22, 32, 14, 14, 14, 14], [2, 3, 4, 5]);
    }
}
