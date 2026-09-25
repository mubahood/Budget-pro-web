<?php

namespace App\Admin\Controllers;

use App\Exceptions\BusinessRuleException;
use App\Http\Controllers\Controller;
use App\Models\Company;
use App\Services\Ops\TenantDataService;
use App\Services\Team\Permissions;
use Encore\Admin\Facades\Admin;
use Encore\Admin\Layout\Content;
use Illuminate\Support\Facades\DB;

/** "Your data" (plan Part D): download everything, or delete the shop after a 30-day grace. */
class YourDataController extends Controller
{
    private function companyId(): int
    {
        abort_unless(Permissions::can(Admin::user(), 'manage_settings'), 403, 'Only the owner can manage the shop data.');

        return (int) Admin::user()->company_id;
    }

    public function index(Content $content)
    {
        $cid = $this->companyId();

        return $content->title('Your data')->description('Download everything or close the shop')->body(view('admin.your-data', [
            'requests' => DB::table('data_requests')->where('company_id', $cid)->orderByDesc('id')->limit(10)->get(),
            'scheduled' => DB::table('data_requests')->where('company_id', $cid)->where('kind', 'delete')->where('status', 'scheduled')->first(),
            'isOwner' => (int) Company::withoutGlobalScopes()->find($cid)?->owner_id === (int) Admin::user()->id,
            'graceDays' => (int) config('backup.deletion_grace_days', 30),
        ]));
    }

    public function export(TenantDataService $data)
    {
        $data->export($this->companyId(), (int) Admin::user()->id);
        admin_success('Export ready', 'Download it below. It is kept for 7 days.');

        return redirect(admin_url('your-data'));
    }

    public function download(TenantDataService $data, $id)
    {
        $r = DB::table('data_requests')->where('company_id', $this->companyId())->where('kind', 'export')->find($id);
        $path = $r ? $data->exportPath($r) : null;
        abort_if($path === null, 404);

        return response()->download($path, 'shop-data-'.now()->format('Y-m-d').'.zip');
    }

    public function delete(TenantDataService $data)
    {
        try {
            $data->requestDeletion(Company::withoutGlobalScopes()->findOrFail($this->companyId()), Admin::user(), (string) request('password'));
            admin_warning('Deletion scheduled', 'You can cancel it on this page until the date shown.');
        } catch (BusinessRuleException $e) {
            admin_error('Not scheduled', $e->getMessage());
        }

        return redirect(admin_url('your-data'));
    }

    public function cancel(TenantDataService $data)
    {
        $data->cancelDeletion($this->companyId());
        admin_success('Deletion cancelled', 'Your shop stays.');

        return redirect(admin_url('your-data'));
    }
}
