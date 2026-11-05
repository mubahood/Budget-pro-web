<?php

namespace App\Services\Rentals\Reports;

use App\Exceptions\BusinessRuleException;
use App\Services\Rentals\Landlords\LandlordAccounts;
use App\Services\Rentals\Landlords\StatementBook;
use App\Services\Rentals\Landlords\StatementProof;
use App\Services\Rentals\RentalsModule;
use App\Support\Rentals\Minor;
use Illuminate\Support\Carbon;

/**
 * The landlord statement report (RentalReports registers it): what StatementBook reads for the period as tables. A closed month reads its issued copy,
 * and nothing else; any other period reads the journal, and says so in its stamp. StatementPdf draws it; in owner mode it is the property income
 * statement (KR-06). `statement_id` asks for one copy by id (a link keeps opening the copy it was made for).
 */
final class StatementReport
{
    use ReportTable;

    /** @return array<string, mixed> */
    public function landlordStatement(object $x): array
    {
        $landlord = $x->landlord ?? ($x->owner ? RentalsModule::selfLandlord($x->cid) : null)
            ?? throw BusinessRuleException::make('landlord_required', 'Choose the landlord.');
        $id = (int) ($x->params['statement_id'] ?? 0);

        return self::fromBody(StatementBook::read($landlord, (string) $x->from, (string) $x->to, $id > 0 ? $id : null), (bool) $x->owner, (string) $landlord->name, $x->cid);
    }

    /**
     * A statement's body (StatementBook::read) as the report's tables: the lines first, then each of its sections by `key`.
     *
     * @param  array<string, mixed>  $st
     * @return array<string, mixed>
     */
    private static function fromBody(array $st, bool $owner, string $name, int $cid): array
    {
        $from = (string) $st['from'];
        $to = (string) $st['to'];
        $run = (int) $st['opening_minor'];
        $rows = [['date' => $from, 'type' => '', 'description' => 'Opening balance', 'property' => null, 'unit' => null, 'tenant' => null,
            'gross_minor' => null, 'commission_minor' => null, 'amount_minor' => null, 'balance_minor' => $run]];
        foreach ((array) $st['lines'] as $l) {
            $run += (int) $l['amount_minor'];
            $rows[] = ['date' => $l['date'], 'type' => LandlordAccounts::LABELS[$l['type']] ?? RentalReports::ENTRY_LABELS[$l['type']] ?? (string) $l['type'],
                'description' => $l['description'], 'property' => $l['property'], 'unit' => $l['unit'], 'tenant' => $l['tenant'],
                'gross_minor' => (int) $l['gross_minor'], 'commission_minor' => (int) $l['commission_minor'], 'amount_minor' => (int) $l['amount_minor'], 'balance_minor' => $run];
        }
        $lines = (array) $st['lines'];
        $t = (array) $st['totals'];
        $occupancy = (array) $st['occupancy'];
        $arrears = array_map(fn ($a) => ['unit' => $a['unit'], 'tenant' => $a['tenant'], 'owed_minor' => (int) $a['owed_minor'], 'days' => (int) $a['days']], (array) $st['arrears']);
        $issued = $st['issued'] ?? null;
        $decimals = (int) ($st['decimals'] ?? Minor::decimals($cid));
        $shownName = (string) ($st['landlord']['name'] ?? $name);

        return [
            'subtitle' => $shownName.' · '.Carbon::parse($from)->format('j M Y').' to '.Carbon::parse($to)->format('j M Y'),
            'columns' => [self::c('date', 'Date', 'date'), self::c('type', 'Kind'), self::c('description', 'Description'), self::c('property', 'Property'),
                self::c('unit', 'Unit'), self::c('tenant', 'Tenant'), self::c('gross_minor', 'Collected', 'money'), self::c('commission_minor', 'Commission', 'money'),
                self::c('amount_minor', $owner ? 'Net' : 'To landlord', 'money'), self::c('balance_minor', 'Balance', 'money')],
            'rows' => $rows,
            'totals' => ['gross_minor' => array_sum(array_column($lines, 'gross_minor')), 'commission_minor' => (int) $t['commission_minor'],
                'amount_minor' => array_sum(array_column($lines, 'amount_minor')), 'balance_minor' => (int) $st['closing_minor'],
                'opening_minor' => (int) $st['opening_minor'], 'collected_minor' => (int) $t['collected_minor'], 'expenses_minor' => (int) $t['expenses_minor'],
                'payouts_minor' => (int) $t['payouts_minor'], 'deposits_minor' => (int) $t['deposits_minor'], 'other_minor' => (int) $t['other_minor'],
                'closing_minor' => (int) $st['closing_minor'], 'units' => (int) $occupancy['units'], 'occupied' => (int) $occupancy['occupied'],
                'vacant' => (int) $occupancy['vacant'], 'in_use' => (int) ($occupancy['in_use'] ?? 0), 'direct_minor' => (int) ($t['direct_minor'] ?? 0)],
            'sections' => self::sections($st, $arrears, $owner, $decimals),
            'landlord' => ['id' => (int) ($st['landlord']['id'] ?? 0), 'name' => $shownName],
            'figures' => ['statement' => ['stamp' => $st['stamp'] ?? null, 'number' => $issued['number'] ?? null, 'version' => $issued['version'] ?? null, 'issued_on' => $issued['issued_on'] ?? null,
                'issued_at_words' => $issued['issued_at_words'] ?? null, 'proof' => $issued['proof'] ?? null, 'proof_short' => isset($issued['proof']) ? StatementProof::short((string) $issued['proof']) : null,
                'replaces' => $issued['replaces'] ?? null, 'commission_rule' => $owner ? null : ($st['commission_rule'] ?? null), 'owner' => $owner, 'currency' => $st['currency'] ?? null,
                'decimals' => $decimals, 'letterhead' => $st['letterhead'] ?? null, 'held_note' => ! empty($st['held']['rows'])]],
        ];
    }

    /**
     * The sections after the summary, in the paper's order (LC-08), each dropped when it has nothing to say. The key says which is which to the paper.
     *
     * @param  array<string, mixed>  $st
     * @param  list<array<string, mixed>>  $arrears
     * @return list<array<string, mixed>>
     */
    private static function sections(array $st, array $arrears, bool $owner, int $decimals): array
    {
        // Kept first for what reads the report as tables (and this order for the files); the paper picks its sections by key, in its own order.
        $sections = [['key' => 'arrears', 'title' => 'What tenants still owe', 'columns' => [self::c('unit', 'Unit'), self::c('tenant', 'Tenant'), self::c('owed_minor', 'Owed', 'money'),
            self::c('days', 'Days', 'int')], 'rows' => $arrears, 'totals' => ['owed_minor' => array_sum(array_column($arrears, 'owed_minor'))]]];
        $units = array_map(fn ($u) => ['property' => $u['property'], 'unit' => $u['unit'], 'tenant' => self::tenantWords($u), 'rent_minor' => (int) $u['rent_minor'], 'paid_minor' => (int) $u['paid_minor'],
            'direct_minor' => (int) ($u['direct_minor'] ?? 0), 'owed_minor' => (int) $u['owed_minor'], 'behind' => self::behind($u, $decimals), 'paid_up_to' => $u['paid_up_to']], (array) ($st['units'] ?? []));
        if ($units !== []) {
            $direct = array_sum(array_column($units, 'direct_minor'));
            $columns = [self::c('property', 'Property'), self::c('unit', 'Unit'), self::c('tenant', 'Tenant'), self::c('rent_minor', 'Rent', 'money'), self::c('paid_minor', 'Paid this month', 'money')];
            if ($direct !== 0) {
                $columns[] = self::c('direct_minor', 'Paid you directly', 'money');
            }
            array_push($columns, self::c('owed_minor', 'Owed at the end', 'money'), self::c('behind', 'Months behind'), self::c('paid_up_to', 'Paid up to', 'date'));
            $sections[] = ['key' => 'units', 'title' => 'Unit by unit', 'columns' => $columns,
                'rows' => $units, 'totals' => ['paid_minor' => array_sum(array_column($units, 'paid_minor')), 'owed_minor' => array_sum(array_column($units, 'owed_minor'))] + ($direct !== 0 ? ['direct_minor' => $direct] : [])];
        }
        $expenses = array_map(fn ($e) => ['date' => $e['date'], 'what' => $e['what'], 'vendor' => $e['vendor'], 'amount_minor' => (int) $e['amount_minor'], 'receipt' => $e['receipt']], (array) ($st['expenses'] ?? []));
        if ($expenses !== []) {
            $sections[] = ['key' => 'expenses', 'title' => 'Expenses', 'columns' => [self::c('date', 'Date', 'date'), self::c('what', 'What'), self::c('vendor', 'Paid to'), self::c('amount_minor', 'Amount', 'money'), self::c('receipt', 'Receipt held')],
                'rows' => $expenses, 'totals' => ['amount_minor' => array_sum(array_column($expenses, 'amount_minor'))]];
        }
        $payouts = array_map(fn ($p) => ['number' => $p['number'], 'date' => $p['date'], 'how' => $p['how'], 'reference' => $p['reference'], 'amount_minor' => (int) $p['amount_minor'],
            'note' => (int) ($p['advance_minor'] ?? 0) > 0 ? RentalExport::money((int) $p['advance_minor'], $decimals).' paid ahead' : null], (array) ($st['payouts'] ?? []));
        if ($payouts !== []) {
            $columns = [self::c('number', 'Number'), self::c('date', 'Date', 'date'), self::c('how', 'How'), self::c('reference', 'Reference'), self::c('amount_minor', 'Amount', 'money')];
            if (array_filter(array_column($payouts, 'note')) !== []) {
                $columns[] = self::c('note', 'Note');
            }
            $sections[] = ['key' => 'payouts', 'title' => $owner ? 'Owner drawings' : 'Paid to you', 'columns' => $columns, 'rows' => $payouts, 'totals' => ['amount_minor' => array_sum(array_column($payouts, 'amount_minor'))]];
        }
        $held = (array) ($st['held'] ?? []);
        if (! empty($held['rows'])) {
            $sections[] = ['key' => 'held', 'title' => $owner ? 'Held for your tenants, not in your balance' : 'Held for you, not in your balance', 'columns' => [self::c('unit', 'Unit'), self::c('tenant', 'Tenant'), self::c('deposit_minor', 'Deposit', 'money'), self::c('credit_minor', 'Paid ahead', 'money')],
                'rows' => array_map(fn ($r) => ['unit' => $r['unit'], 'tenant' => $r['tenant'], 'deposit_minor' => (int) $r['deposit_minor'], 'credit_minor' => (int) $r['credit_minor']], (array) $held['rows']),
                'totals' => ['deposit_minor' => (int) $held['deposit_minor'], 'credit_minor' => (int) $held['credit_minor']]];
        }
        $corrections = (array) ($st['corrections'] ?? []);
        if ($corrections !== []) {
            $sections[] = ['key' => 'corrections', 'title' => 'Corrections from earlier months', 'columns' => [self::c('date', 'Date', 'date'), self::c('what', 'What'), self::c('corrects', 'Dated', 'date'), self::c('amount_minor', 'Amount', 'money')],
                'rows' => array_map(fn ($c) => ['date' => $c['date'], 'what' => $c['what'], 'corrects' => $c['corrects'], 'amount_minor' => (int) $c['amount_minor']], $corrections),
                'totals' => ['amount_minor' => array_sum(array_column($corrections, 'amount_minor'))]];
        }
        return $sections;
    }

    /** A row of the unit table's tenant: their name, or how the unit stood. @param array<string, mixed> $u */
    private static function tenantWords(array $u): string
    {
        if (! empty($u['in_use_by'])) {
            return 'Used by '.$u['in_use_by'];
        }
        if (empty($u['tenant'])) {
            return ! empty($u['vacant_since']) ? 'Vacant, since '.Carbon::parse((string) $u['vacant_since'])->format('j M') : 'Vacant';
        }

        return (string) $u['tenant'].(! empty($u['moved_out_on']) ? ' (left '.Carbon::parse((string) $u['moved_out_on'])->format('j M').')' : '');
    }

    /** "2 months + 40,000", the landlord's own words; nothing when nothing is owed. @param array<string, mixed> $u */
    private static function behind(array $u, int $decimals): ?string
    {
        $months = (int) ($u['months_behind'] ?? 0);
        $rest = (int) ($u['behind_rest_minor'] ?? 0);
        if ($months === 0 && $rest === 0) {
            return null;
        }
        $words = $months > 0 ? $months.' '.($months === 1 ? 'month' : 'months') : '';

        return trim($words.($months > 0 && $rest > 0 ? ' + ' : '').($rest > 0 ? RentalExport::money($rest, $decimals) : ''));
    }
}
