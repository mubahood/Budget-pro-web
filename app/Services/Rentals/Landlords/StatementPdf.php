<?php

namespace App\Services\Rentals\Landlords;

use App\Models\RentalLandlord;
use App\Models\RentalStatement;
use App\Services\Rentals\Reports\RentalExport;
use App\Services\Rentals\Reports\RentalReports;

/**
 * A landlord's statement as a PDF, the one statement there is: the landlord_statement report in the one document layout, headed with the account's
 * own name; "Property income statement" in owner mode (KR-06). A closed month prints its issued copy (StatementBook). Who may see it is checked by the
 * caller; no other product is named (KR-05).
 */
final class StatementPdf
{
    /** $statementId prints that copy, whatever has happened to it since: a link keeps opening what it was made for. */
    public static function render(RentalLandlord $landlord, string $from, string $to, ?int $statementId = null): string
    {
        $cid = (int) $landlord->company_id;

        return RentalExport::pdf(self::report($landlord, $from, $to, $statementId), $cid);
    }

    /** @return array<string, mixed> the report behind the paper */
    public static function report(RentalLandlord $landlord, string $from, string $to, ?int $statementId = null): array
    {
        return (new RentalReports)->run('landlord_statement', (int) $landlord->company_id, null,
            ['landlord_id' => (int) $landlord->id, 'from' => $from, 'to' => $to] + ($statementId !== null ? ['statement_id' => $statementId] : []));
    }

    /**
     * A closed month's statements as one PDF for printing: a cover sheet with the by-landlord table for the office file, then one statement a landlord,
     * each read from its copy. $part (from 1) and PART_SIZE keep a big account inside the request limit: the cover comes with the first part.
     */
    public static function renderMany(int $companyId, string $month, int $part = 1): string
    {
        $all = StatementBook::forMonth($companyId, $month);
        uasort($all, fn (RentalStatement $a, RentalStatement $b) => strcmp((string) (((array) $a->body)['landlord']['name'] ?? ''), (string) (((array) $b->body)['landlord']['name'] ?? '')) ?: $a->id <=> $b->id);
        $reports = [];
        foreach (array_slice($all, max(0, $part - 1) * self::PART_SIZE, self::PART_SIZE) as $s) {
            $body = (array) $s->body;
            $landlord = RentalLandlord::withoutGlobalScopes()->where('company_id', $companyId)->findOrFail((int) $s->landlord_id);
            $reports[] = self::report($landlord, (string) $body['from'], (string) $body['to'], (int) $s->id);
        }

        return RentalExport::statementsPdf($reports, $all, $companyId, $month, $part);
    }

    /** How many statements one bulk PDF holds. */
    public const PART_SIZE = 50;

    /** How many parts a month's bulk PDF has. */
    public static function parts(int $companyId, string $month): int
    {
        return max(1, (int) ceil(count(StatementBook::forMonth($companyId, $month)) / self::PART_SIZE));
    }

    public static function filename(RentalLandlord $landlord, string $from, string $to): string
    {
        return 'statement-'.preg_replace('/[^A-Za-z0-9-]+/', '-', (string) ($landlord->code ?: $landlord->id)).'-'.$from.'-'.$to.'.pdf';
    }
}
