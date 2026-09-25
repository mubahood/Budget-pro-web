<?php

namespace App\Admin\Controllers;

use App\Admin\Widgets\ReturnsReportWidget;
use App\Admin\Widgets\SalesAnalyticsWidget;
use App\Http\Controllers\Controller;
use App\Models\Company;
use App\Services\Dashboard\DashboardService;
use App\Services\Team\Permissions;
use Encore\Admin\Facades\Admin;
use Encore\Admin\Layout\Content;
use Illuminate\Support\Facades\DB;

class HomeController extends Controller
{
    public function index(Content $content, DashboardService $dash)
    {
        /** @var \App\Models\User $u */
        $u = Admin::user();
        $company = Company::find($u->company_id);

        if ($company === null) {
            admin_error('Company Not Found', 'Your account is not linked to a valid company. Please contact support.');

            return $content->title('Dashboard')->body('<div class="alert alert-danger"><h4>Company not found</h4><p>Your account is not linked to a shop. Please contact support.</p></div>');
        }

        // New shops go through the setup wizard first (plan C2); owners only.
        $onboarding = app(\App\Services\Onboarding\OnboardingService::class);
        if (Permissions::can($u, 'manage_settings') && $onboarding->needsSetup($company)) {
            return redirect(admin_url('setup'));
        }

        $deletion = DB::table('data_requests')->where('company_id', $company->id)->where('kind', 'delete')->where('status', 'scheduled')->value('purge_after');
        if ($deletion) {
            admin_warning('This shop is scheduled for deletion', 'All data will be deleted on '.\Illuminate\Support\Carbon::parse($deletion)->format('d M Y').'. <a href="'.admin_url('your-data').'">Cancel it</a>.');
        }
        $checklist = $onboarding->checklist($company);
        if (! $checklist['dismissed'] && $checklist['done'] < $checklist['total']) {
            $content->row(view('admin.getting-started', ['checklist' => $checklist]));
        }

        $can = fn (string $p) => Permissions::can($u, $p);
        $range = $dash->range($company, request('range'), request('from'), request('to'));
        $cid = (int) $company->id;
        $seesMoney = $can('view_reports') || $can('view_profit') || $can('manage_finance');
        $stock = $dash->stock($company);

        $content->title($company->name)
            ->description('Welcome back, '.e($u->name).' · '.now()->setTimezone(\App\Support\LocalTime::timezone($company))->format('l, d F Y'))
            ->row(view('admin.dashboard', [
                'company' => $company,
                'range' => $range,
                'ranges' => DashboardService::RANGES,
                'can' => [
                    'sell' => $can('sell'), 'refund' => $can('refund'), 'restock' => $can('restock'), 'adjust' => $can('adjust'),
                    'finance' => $can('manage_finance'), 'profit' => $can('view_profit'), 'reports' => $seesMoney, 'products' => $can('manage_products'),
                ],
                'kpi' => $seesMoney ? $dash->kpis($cid, $range['from'], $range['to']) : null,
                'prev' => $seesMoney ? $dash->kpis($cid, $range['prev_from'], $range['prev_to']) : null,
                'methods' => $seesMoney ? $dash->collectedByMethod($cid, $range['from'], $range['to']) : [],
                'daily' => $seesMoney ? $dash->daily($cid, $range['from'], $range['to']) : null,
                'top' => $seesMoney ? $dash->topProducts($cid, $range['from'], $range['to']) : [],
                'recent' => $dash->recentSales($cid),
                'receivables' => $seesMoney || $can('sell') ? $dash->receivables($cid) : null,
                'payables' => $seesMoney || $can('restock') ? $dash->payables($cid) : null,
                'stock' => $stock,
                'alerts' => $dash->alerts($company, $stock),
            ]));

        // Deeper analytics below the day-to-day controls, for people who may see reports.
        if ($seesMoney) {
            $content->row(new SalesAnalyticsWidget())->row(new ReturnsReportWidget());
        }

        return $content;
    }
}
