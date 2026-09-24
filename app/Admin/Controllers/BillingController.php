<?php

namespace App\Admin\Controllers;

use App\Http\Controllers\Controller;
use App\Models\Company;
use Encore\Admin\Facades\Admin;
use Encore\Admin\Layout\Content;

/**
 * Tenant-facing billing pages. P0-3 ships the "subscription expired" page;
 * the full plan/usage/invoices page arrives in Phase 3 (P3-6).
 */
class BillingController extends Controller
{
    public function expired(Content $content)
    {
        $company = Company::find(Admin::user()->company_id);

        return $content
            ->title('Subscription')
            ->description('Your access has expired')
            ->body(view('admin.subscription-expired', [
                'company' => $company,
                'state' => $company?->accessState() ?? 'expired',
                'endedAt' => $company?->accessEndedAt(),
                'plan' => $company?->subscription?->plan,
            ]));
    }
}
