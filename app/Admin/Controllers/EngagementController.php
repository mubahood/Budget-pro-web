<?php

namespace App\Admin\Controllers;

use App\Exceptions\BusinessRuleException;
use App\Http\Controllers\Controller;
use App\Models\Company;
use App\Services\Engage\MomoCollections;
use App\Services\Team\Permissions;
use Encore\Admin\Facades\Admin;
use Encore\Admin\Layout\Content;

/** Mobile money & reminders settings on the web (plan Part E2/E3). */
class EngagementController extends Controller
{
    private function company(): Company
    {
        abort_unless(Permissions::can(Admin::user(), 'manage_settings'), 403, 'Only the owner can change these settings.');

        return Company::withoutGlobalScopes()->findOrFail(Admin::user()->company_id);
    }

    public function index(Content $content, MomoCollections $momo)
    {
        $c = $this->company();

        return $content->title('Mobile money & reminders')->body(view('admin.engagement', ['c' => $c, 'networks' => $momo->networks($c)]));
    }

    public function save()
    {
        $c = $this->company();
        $c->debt_reminders_enabled = (bool) request('debt_reminders_enabled');
        $c->credit_terms_days = max(0, min(365, (int) request('credit_terms_days', 30)));
        $c->saveQuietly();
        admin_success('Saved', $c->debt_reminders_enabled ? 'Customers with overdue balances get a friendly reminder once a week.' : 'Reminders are off.');

        return redirect(admin_url('engagement'));
    }

    public function momo(MomoCollections $momo)
    {
        try {
            $momo->setup($this->company(), (string) request('phone'), (string) request('network'));
            admin_success('Mobile money ready', 'You can now request payments from customers.');
        } catch (BusinessRuleException $e) {
            admin_error('Not saved', $e->getMessage());
        }

        return redirect(admin_url('engagement'));
    }
}
