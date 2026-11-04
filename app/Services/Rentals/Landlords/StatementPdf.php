<?php

namespace App\Services\Rentals\Landlords;

use App\Models\RentalLandlord;
use App\Services\Rentals\Reports\RentalExport;
use App\Services\Rentals\Reports\RentalReports;

/**
 * A landlord's statement as a PDF, the one statement there is: the landlord_statement report (LandlordAccounts::statement
 * for the period) in the one document layout, headed with the account's own name; "Property income statement" in owner
 * mode (KR-06). Who may see it is checked by the caller; no other product is named (KR-05).
 */
final class StatementPdf
{
    public static function render(RentalLandlord $landlord, string $from, string $to): string
    {
        $cid = (int) $landlord->company_id;

        return RentalExport::pdf((new RentalReports)->run('landlord_statement', $cid, null, ['landlord_id' => (int) $landlord->id, 'from' => $from, 'to' => $to]), $cid);
    }

    public static function filename(RentalLandlord $landlord, string $from, string $to): string
    {
        return 'statement-'.preg_replace('/[^A-Za-z0-9-]+/', '-', (string) ($landlord->code ?: $landlord->id)).'-'.$from.'-'.$to.'.pdf';
    }
}
