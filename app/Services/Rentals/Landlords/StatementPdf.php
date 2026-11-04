<?php

namespace App\Services\Rentals\Landlords;

use App\Models\Company;
use App\Models\RentalLandlord;
use App\Services\Rentals\RentalsModule;

/**
 * A landlord's statement as a PDF (dompdf): LandlordAccounts::statement for the period, headed with the account's own
 * name; "Property income statement" in owner mode (KR-06). Plain, light, square; no other product is named (KR-05).
 */
final class StatementPdf
{
    public static function render(RentalLandlord $landlord, string $from, string $to): string
    {
        $cid = (int) $landlord->company_id;
        $owner = RentalsModule::isOwnerMode($cid);

        return app('dompdf.wrapper')->loadHTML(view('rentals.landlord-statement', [
            's' => LandlordAccounts::statement($landlord, $from, $to), 'cid' => $cid, 'owner' => $owner,
            'title' => $owner ? 'Property income statement' : 'Landlord statement',
            'account' => (string) Company::withoutGlobalScopes()->whereKey($cid)->value('name'),
        ])->render())->setPaper('a4')->output();
    }

    public static function filename(RentalLandlord $landlord, string $from, string $to): string
    {
        return 'statement-'.preg_replace('/[^A-Za-z0-9-]+/', '-', (string) ($landlord->code ?: $landlord->id)).'-'.$from.'-'.$to.'.pdf';
    }
}
